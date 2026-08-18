<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\BankCard;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\User as UserModel;
use app\service\TransactionOrderService;
use app\service\UserFundLedgerService;
use Exception;
use think\facade\Db;
use think\facade\Log;

trait TransactionActions
{
    private function uploadTransactionProofForBuyer(int $uid, int $orderId = 0, string $orderNumber = ''): array
    {
        $order = null;
        if ($orderId > 0) {
            $order = TransactionOrder::where('uid', $uid)->find($orderId);
        } elseif ($orderNumber !== '') {
            $order = TransactionOrder::where('uid', $uid)->where('order_number', $orderNumber)->find();
        }

        if (!$order) {
            throw new Exception('交易订单不存在');
        }

        if ((int)($order['status'] ?? 0) !== 0) {
            throw new Exception('当前订单状态不可提交凭证');
        }

        $statusMeta = $this->transactionStatusMeta($order);
        if (!empty($statusMeta['expired'])) {
            throw new Exception('订单已超时取消，请重新下单');
        }

        $source = $this->extractProofUploadSource(['voucher_image', 'image']);
        if ($source === null) {
            throw new Exception('请选择交易凭证图片');
        }

        $storedPath = $this->persistPrivateProofUpload(
            $source,
            'trade',
            (string)($order['order_number'] ?? ''),
            (string)($order['voucher_image'] ?? '')
        );

        $order->voucher_image = $storedPath;
        if ($order->save() === false) {
            throw new Exception('上传失败');
        }

        $proofViewUrl = $this->buildTradeProofViewUrl((string)($order['order_number'] ?? ''), $storedPath);

        return [
            'order_number' => (string)($order['order_number'] ?? ''),
            'voucher_image' => $proofViewUrl,
            'proof_view_url' => $proofViewUrl,
        ];
    }

    protected function handleTransactionTradingDetailsPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $TransactionOrder_info = null;
        $transactionOrderId = (int)($post_info['id'] ?? ($post_info['order_id'] ?? 0));
        if ($transactionOrderId > 0 && $user_info) {
            $TransactionOrder_info = TransactionOrder::where('uid', $user_info['id'])->find($transactionOrderId);
        }
        if (!$TransactionOrder_info && $user_info && !empty($post_info['order_number'])) {
            $TransactionOrder_info = TransactionOrder::where('uid', $user_info['id'])
                ->where('order_number', (string)$post_info['order_number'])
                ->find();
        }
        switch ($action) {
            case 'acepted_submit':
            case 'accepted_submit':
                if (empty($post_info['password'])) {
                    return show(500, 'error', '请输入登录密码');
                }
                if ($user_info && password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
                    try {
                        (new TransactionOrderService())->releaseBySeller((int)$user_info['id'], (int)($post_info['id'] ?? 0));
                        return show(200, 'success', '操作成功');
                    } catch (\Throwable $e) {
                        $message = $e->getMessage();
                        if (!in_array($message, ['交易订单不存在', '当前订单状态不可放币', '用户不存在', '买家不存在', '挂单不存在', '操作失败'], true)) {
                            $message = '操作失败';
                        }
                        return show(500, 'error', $message);
                    }
                }
                return show(500, 'error', '登录密码错误');

            case 'confirm':
                try {
                    (new TransactionOrderService())->markBuyerPaid(
                        (int)($user_info['id'] ?? 0),
                        $transactionOrderId,
                        trim((string)($post_info['order_number'] ?? ''))
                    );
                    return show(200, 'success', '操作成功');
                } catch (\Throwable $e) {
                    return show(500, 'error', $e->getMessage() ?: '请求失败');
                }

            case 'cancel':
                try {
                    (new TransactionOrderService())->cancelPendingOrder(
                        (int)($user_info['id'] ?? 0),
                        $transactionOrderId,
                        trim((string)($post_info['order_number'] ?? ''))
                    );
                    return show(200, 'success', '取消成功');
                } catch (\Throwable $e) {
                    return show(500, 'error', $e->getMessage() ?: '请求失败');
                }

            case 'image':
                try {
                    $result = $this->uploadTransactionProofForBuyer(
                        (int)($user_info['id'] ?? 0),
                        $transactionOrderId,
                        trim((string)($post_info['order_number'] ?? ''))
                    );
                    return show(200, 'success', '上传成功', $result);
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if ($message === '交易订单不存在') {
                        return show(404, 'error', $message);
                    }
                    return show(500, 'error', $message ?: '上传失败');
                }

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleTransactionBuyPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        switch ($action) {
            case 'submit':
                if (empty($post_info['pay_amount'])) {
                    return show(500, 'error', '请输入购买数量');
                }
                if (empty($post_info['remittance_user_name'])) {
                    return show(500, 'error', '请输入您的真实姓名');
                }
                $payAmount = round((float)$post_info['pay_amount'], 2);
                if ($payAmount <= 0) {
                    return show(500, 'error', '购买数量无效');
                }
                $pid = (int)($post_info['transact_id'] ?? 0);
                if ($pid <= 0) {
                    return show(500, 'error', '挂单不存在');
                }

                $order_number = '';
                try {
                    Db::startTrans();

                    // 对挂单行加 FOR UPDATE 锁，串行化同一挂单的并发下单
                    $product = TransactionProduct::where('id', $pid)->lock(true)->find();
                    if (!$product || (int)$product['status'] !== 1) {
                        Db::rollback();
                        return show(500, 'error', '售卖交易已下架或取消');
                    }
                    if ((float)$product['min_limit'] > $payAmount || (float)$product['max_limit'] < $payAmount) {
                        Db::rollback();
                        return show(500, 'error', '超出购买限制');
                    }

                    // sell_account 语义：初始挂单量 - 已完成订单量
                    // 已占用量：待汇款(0) + 已汇款(1) 的活跃订单 pay_amount 之和
                    // 剩余可售 = sell_account - 已占用量
                    // 使用 lock(true) 强制当前读，确保看到已提交的最新活跃订单
                    $committedAmount = (float)Db::name('transaction_order')
                        ->where('pid', $pid)
                        ->whereIn('status', [0, 1])
                        ->lock(true)
                        ->sum('pay_amount');
                    $available = round((float)$product['sell_account'] - $committedAmount, 2);
                    if ($available + 0.005 < $payAmount) {
                        Db::rollback();
                        return show(500, 'error', '超出最高出售数量');
                    }

                    $order_number = date('Ymd') . randomkeys(6, 'number');
                    $unitPrice = (float)$product['unit_price'];
                    $transactionFees = (float)(getConfig('transaction_fees') ?? 0);

                    TransactionOrder::create([
                        'uid' => $user_info['id'],
                        'sell_uid' => $product['uid'],
                        'pid' => $product['id'],
                        'order_number' => $order_number,
                        'pay_amount' => $payAmount,
                        'payment_amount' => round($payAmount * $unitPrice, 2),
                        'remittance_user_name' => trim((string)$post_info['remittance_user_name']),
                        'bank_card_info' => $product['bank_card_info'],
                        'unit_price' => $unitPrice,
                        'transaction_fees' => $transactionFees,
                        'usdt_amount' => round(max(0, $payAmount - $transactionFees), 2),
                    ]);

                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('transaction buy submit error', [
                        'pid' => $pid,
                        'uid' => (int)($user_info['id'] ?? 0),
                        'pay_amount' => $payAmount,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                    return show(500, 'error', '下单失败，请重试');
                }

                return show(200, 'success', '确认成功', [
                    'order_number' => $order_number,
                    'orderNumber' => $order_number,
                ]);

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleTransactionMySalePost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $TransactionProduct_info = TransactionProduct::where('uid', $user_info['id'])->find($post_info['id']);
        switch ($action) {
            case 'status_operate':
                if (!$TransactionProduct_info) {
                    return show(500, 'error', '记录不存在');
                }
                if ($post_info['status'] == 1 || $post_info['status'] == 2) {
                    $TransactionProduct_info->status = $post_info['status'];
                    $TransactionProduct_info->save();
                    return show(200, 'success', '操作成功');
                }
                if ($post_info['status'] == 3 && $TransactionProduct_info['status'] != 3) {
                    try {
                        Db::startTrans();
                        $TransactionProduct_info = TransactionProduct::where('uid', (int)($user_info['id'] ?? 0))->lock(true)->find($post_info['id']);
                        if (!$TransactionProduct_info || (int)($TransactionProduct_info['status'] ?? 0) === 3) {
                            Db::rollback();
                            return show(500, 'error', '操作失败');
                        }

                        $refundSellAccount = round((float)($TransactionProduct_info['sell_account'] ?? 0), 2);
                        $TransactionProduct_info->status = $post_info['status'];
                        $TransactionProduct_info->save();

                        $lockedUser = UserModel::where('id', (int)($user_info['id'] ?? 0))->lock(true)->find();
                        if (!$lockedUser) {
                            throw new Exception('用户不存在');
                        }
                        if ($refundSellAccount > 0) {
                            $this->releaseTransactionListingWallet($lockedUser, $TransactionProduct_info, $refundSellAccount, 'user', (int)($lockedUser['id'] ?? 0), 0.0);
                        }

                        Db::commit();
                        return show(200, 'success', '操作成功');
                    } catch (\Throwable $e) {
                        Db::rollback();
                        Log::error('transaction my sale status_operate error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                        return show(500, 'error', '操作失败');
                    }
                }
                return show(500, 'error', '操作失败');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleTransactionSaleEditPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $TransactionProduct_info = TransactionProduct::where('uid', $this->user_info['id'])->find($post_info['id'] ?? '');
        switch ($action) {
            case 'submit':
                if (empty($post_info['sell_account'])) {
                    return show(500, 'error', '请输入挂单数量');
                }
                if ($post_info['sell_account'] < getConfig('transaction_mini_quantity')) {
                    return show(500, 'error', '最低挂单数' . getConfig('transaction_mini_quantity') . '起');
                }
                if (empty($post_info['unit_price'])) {
                    return show(500, 'error', '请输入单价价格');
                }
                if (empty($post_info['min_limit'])) {
                    return show(500, 'error', '请输入最小额度');
                }
                if (empty($post_info['max_limit'])) {
                    return show(500, 'error', '请输入最大额度');
                }
                if ($post_info['min_limit'] > $post_info['max_limit']) {
                    return show(500, 'error', '最小额度不能大于最大额度');
                }
                $BankCard_info = BankCard::where('uid', $this->user_info['id'])->where('default_selection', 1)->find();
                if (!$BankCard_info) {
                    return show(500, 'error', '请选择收款卡号');
                }

                try {
                    Db::startTrans();
                    $lockedUser = UserModel::where('id', $this->user_info['id'])->lock(true)->find();
                    if (!$lockedUser) {
                        throw new Exception('用户不存在');
                    }

                    $lockedTransactionProduct = null;
                    if (!empty($post_info['id'])) {
                        $lockedTransactionProduct = TransactionProduct::where('uid', $this->user_info['id'])->lock(true)->find($post_info['id']);
                    }

                    $targetSellAccount = round((float)$post_info['sell_account'], 2);
                    $currentSellAccount = round((float)($lockedTransactionProduct['sell_account'] ?? 0), 2);
                    $availableSellAccount = round((float)($lockedUser['balance'] ?? 0) + $currentSellAccount, 2);
                    if ($availableSellAccount < $targetSellAccount) {
                        throw new Exception('可用余额已不足');
                    }

                    $defaultBankCard = BankCard::where('uid', $this->user_info['id'])->where('default_selection', 1)->find();
                    if ($lockedTransactionProduct) {
                        $lockedTransactionProduct->sell_account = $targetSellAccount;
                        $lockedTransactionProduct->unit_price = $post_info['unit_price'];
                        $lockedTransactionProduct->min_limit = $post_info['min_limit'];
                        $lockedTransactionProduct->max_limit = $post_info['max_limit'];
                        $lockedTransactionProduct->bank_card_info = $defaultBankCard;
                        $lockedTransactionProduct->save();

                        $deltaAmount = round($targetSellAccount - $currentSellAccount, 2);
                        if ($deltaAmount > 0) {
                            $this->freezeTransactionListingWallet($lockedUser, $lockedTransactionProduct, $deltaAmount, $targetSellAccount);
                        } elseif ($deltaAmount < 0) {
                            $this->releaseTransactionListingWallet($lockedUser, $lockedTransactionProduct, abs($deltaAmount), 'user', (int)($lockedUser['id'] ?? 0), $targetSellAccount);
                        }

                        Db::commit();
                        return show(200, 'success', '修改成功');
                    }

                    $createdTransactionProduct = TransactionProduct::create([
                        'uid' => $lockedUser['id'],
                        'sell_account' => $targetSellAccount,
                        'unit_price' => $post_info['unit_price'],
                        'min_limit' => $post_info['min_limit'],
                        'max_limit' => $post_info['max_limit'],
                        'bank_card_info' => $defaultBankCard,
                    ]);
                    $this->freezeTransactionListingWallet($lockedUser, $createdTransactionProduct, $targetSellAccount, $targetSellAccount);

                    Db::commit();
                    return show(200, 'success', '保存成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('transaction sale edit submit error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                    return show(500, 'error', $e->getMessage() ?: '保存失败');
                }

            case 'bank_card':
                $BankCard_info = BankCard::where('uid', $this->user_info['id'])->where('default_selection', 1)->find();
                return show(200, 'success', '获取成功', $BankCard_info);

            default:
                return show(500, 'error', '请求出错');
        }
    }

    private function transactionListingBizNo(TransactionProduct $listing): string
    {
        return 'listing:' . (int)($listing['id'] ?? 0);
    }

    private function transactionListingRequestNo(string $action, TransactionProduct $listing, ?float $targetSellAccount = null): string
    {
        $bizNo = $this->transactionListingBizNo($listing);
        if ($targetSellAccount === null) {
            return $action . ':' . $bizNo;
        }

        return $action . ':' . $bizNo . ':target:' . number_format($targetSellAccount, 2, '.', '');
    }

    private function freezeTransactionListingWallet(UserModel $user, TransactionProduct $listing, float $amount, ?float $targetSellAccount = null): array
    {
        $bizNo = $this->transactionListingBizNo($listing);

        return (new UserFundLedgerService())->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_BALANCE,
            UserFundLedgerService::WALLET_FROZEN,
            round($amount, 2),
            [
                'biz_type' => 'transaction_listing',
                'biz_id' => (int)($listing['id'] ?? 0),
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'out_change_type' => 'transaction_listing_freeze',
                'in_change_type' => 'transaction_listing_freeze',
                'operator_type' => 'user',
                'operator_id' => (int)($user['id'] ?? 0),
                'status' => 'done',
                'request_no' => $this->transactionListingRequestNo('transaction_listing_freeze', $listing, $targetSellAccount),
                'remark' => 'transaction listing freeze',
                'idempotent' => true,
                'extra' => [
                    'source' => 'transaction_sale_edit_submit',
                    'target_sell_account' => $targetSellAccount,
                ],
            ]
        );
    }

    private function releaseTransactionListingWallet(UserModel $user, TransactionProduct $listing, float $amount, string $operatorType, int $operatorId, ?float $targetSellAccount = 0.0): array
    {
        $bizNo = $this->transactionListingBizNo($listing);

        return (new UserFundLedgerService())->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            UserFundLedgerService::WALLET_BALANCE,
            round($amount, 2),
            [
                'biz_type' => 'transaction_listing',
                'biz_id' => (int)($listing['id'] ?? 0),
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'out_change_type' => 'transaction_listing_release',
                'in_change_type' => 'transaction_listing_release',
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'status' => 'done',
                'request_no' => $this->transactionListingRequestNo('transaction_listing_release', $listing, $targetSellAccount),
                'remark' => 'transaction listing release',
                'idempotent' => true,
                'extra' => [
                    'source' => 'transaction_listing_release',
                    'target_sell_account' => $targetSellAccount,
                ],
            ]
        );
    }

    protected function handleApiTransactionOrderDetail(string $order_number)
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        TransactionOrder::expirePendingOrders($uid);
        $order = TransactionOrder::where('order_number', $order_number)
            ->where(function ($query) use ($uid) {
                $query->where('uid', $uid)->whereOr('sell_uid', $uid);
            })
            ->find();
        if (!$order) {
            return show(404, 'error', '交易订单不存在', null, 404);
        }

        $seller = UserModel::field('id,nickname,avatar,mobile')->find((int)($order['sell_uid'] ?? 0));
        $buyer = UserModel::field('id,nickname,avatar,mobile')->find((int)($order['uid'] ?? 0));
        $product = TransactionProduct::find((int)($order['pid'] ?? 0));
        $bankInfo = $this->parseBankCardInfo($order['bank_card_info'] ?? []);
        $statusMeta = $this->transactionStatusMeta($order);

        return show(200, 'success', '查询成功', [
            'id' => (int)($order['id'] ?? 0),
            'order_number' => (string)($order['order_number'] ?? ''),
            'payment_amount' => (float)($order['payment_amount'] ?? 0),
            'pay_amount' => (float)($order['pay_amount'] ?? 0),
            'unit_price' => (float)($order['unit_price'] ?? 0),
            'usdt_amount' => (float)($order['usdt_amount'] ?? 0),
            'transaction_fees' => (float)($order['transaction_fees'] ?? 0),
            'remittance_user_name' => (string)($order['remittance_user_name'] ?? ''),
            'voucher_image' => $this->buildTradeProofViewUrl((string)($order['order_number'] ?? ''), (string)($order['voucher_image'] ?? '')),
            'proof_view_url' => $this->buildTradeProofViewUrl((string)($order['order_number'] ?? ''), (string)($order['voucher_image'] ?? '')),
            'create_time' => (string)($order['create_time'] ?? ''),
            'submit_time' => (string)($order['submit_time'] ?? ''),
            'cancel_time' => (string)($order['cancel_time'] ?? ''),
            'complete_time' => (string)($order['complete_time'] ?? ''),
            'bank_card_info' => $bankInfo['raw'],
            'seller_info' => $seller ?: [],
            'buyer_info' => $buyer ?: [],
            'product_info' => $product ? [
                'id' => (int)($product['id'] ?? 0),
                'sell_account' => (float)($product['sell_account'] ?? 0),
                'min_limit' => (float)($product['min_limit'] ?? 0),
                'max_limit' => (float)($product['max_limit'] ?? 0),
            ] : [],
            'payment_info' => [
                'payment_method' => $bankInfo['payment_method'],
                'account_name' => $bankInfo['account_name'],
                'bank_account' => $bankInfo['bank_account'],
                'bank_name' => $bankInfo['bank_name'],
                'wechat_account' => $bankInfo['wechat_account'],
                'alipay_account' => $bankInfo['alipay_account'],
                'wallet_address' => $bankInfo['wallet_address'],
                'mobile' => $bankInfo['mobile'],
            ],
            'status' => $statusMeta['status'],
            'effective_status' => $statusMeta['effective_status'],
            'status_text' => $statusMeta['status_text'],
            'expired' => $statusMeta['expired'],
            'expire_time' => $statusMeta['expire_time'],
        ]);
    }

    protected function handleApiTransactionOrderRelease()
    {
        $post = $this->readRequestPayload();
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $order = null;
        $id = (int)($post['id'] ?? 0);
        $orderNumber = trim((string)($post['order_number'] ?? ''));
        $seller = UserModel::where('id', $uid)->find();
        if (!$seller) {
            return $this->apiError('用户不存在', 404);
        }

        if (!empty($seller->twofa_enabled)) {
            $twofaCode = trim((string)($post['twofa_code'] ?? ''));
            $twofaResult = $this->verifyUserTwofaCode($seller, $twofaCode);
            if (empty($twofaResult['ok'])) {
                return $this->apiError((string)($twofaResult['message'] ?? '2FA验证失败'), 400);
            }
        } else {
            $password = trim((string)($post['password'] ?? ''));
            if ($password === '') {
                return $this->apiError('请输入登录密码', 400);
            }
            if (!password_verify($password . ($seller->salt ?? ''), $seller->password)) {
                return $this->apiError('登录密码错误', 400);
            }
        }

        if ($id > 0) {
            $order = TransactionOrder::where('sell_uid', $uid)->find($id);
        } elseif ($orderNumber !== '') {
            $order = TransactionOrder::where('sell_uid', $uid)->where('order_number', $orderNumber)->find();
        }

        if (!$order) {
            return $this->apiError('交易订单不存在', 404);
        }
        if ((int)($order['status'] ?? 0) !== 1) {
            return $this->apiError('当前订单状态不可放币', 400);
        }

        try {
            $updatedOrder = (new TransactionOrderService())->releaseBySeller($uid, (int)($order['id'] ?? 0));
            return $this->apiOk('放币成功', [
                'id' => (int)$updatedOrder['id'],
                'order_number' => (string)($updatedOrder['order_number'] ?? ''),
                'status' => 3,
                'status_text' => '已完成',
                'usdt_amount' => $this->directMoney((float)($updatedOrder['usdt_amount'] ?? 0), 6),
            ]);
        } catch (\Throwable $e) {
            Log::error('api_transaction_order_release error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $order['id'] ?? 0,
            ]);
            return $this->apiError('放币失败：' . $e->getMessage(), 500);
        }
    }

    protected function handleApiTransactionMarket()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        TransactionOrder::expirePendingOrders($uid);

        $onlyMine = (int)$this->request->get('user_status', 0) === 1;
        $sortDesc = (int)$this->request->get('upper_lower', 0) === 1;
        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(50, (int)$this->request->get('pageSize', 10)));

        $query = TransactionProduct::where('status', 1);
        if ($onlyMine) {
            $query->where('uid', $uid);
        }
        $query->order('unit_price', $sortDesc ? 'desc' : 'asc')->order('id', 'desc');

        $total = (int)(clone $query)->count();
        $rows = $query->page($page, $pageSize)->select();
        $list = [];
        foreach ($rows as $row) {
            $seller = UserModel::field('id,avatar,nickname,mobile')->find((int)($row['uid'] ?? 0));
            $completedQuery = TransactionOrder::where('pid', (int)($row['id'] ?? 0))->where('status', 3);
            $list[] = [
                'id' => (int)($row['id'] ?? 0),
                'uid' => (int)($row['uid'] ?? 0),
                'sell_account' => (float)($row['sell_account'] ?? 0),
                'unit_price' => (float)($row['unit_price'] ?? 0),
                'min_limit' => (float)($row['min_limit'] ?? 0),
                'max_limit' => (float)($row['max_limit'] ?? 0),
                'status' => (int)($row['status'] ?? 0),
                'create_time' => (string)($row['create_time'] ?? ''),
                'user_info' => $seller ? [
                    'id' => (int)($seller['id'] ?? 0),
                    'avatar' => (string)($seller['avatar'] ?? ''),
                    'nickname' => (string)($seller['nickname'] ?? ''),
                    'mobile' => (string)($seller['mobile'] ?? ''),
                ] : [],
                'TransactionOrder_count' => (int)$completedQuery->count(),
                'pay_amount_s' => (float)$completedQuery->sum('pay_amount'),
            ];
        }

        return $this->apiOk('查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'TransactionProduct_count' => (int)TransactionProduct::where('uid', $uid)->count(),
            'TransactionOrder_count' => (int)TransactionOrder::where('uid', $uid)->whereIn('status', [0, 1])->count(),
            'sort' => $sortDesc ? 'desc' : 'asc',
            'onlyMine' => $onlyMine ? 1 : 0,
        ]);
    }

    protected function handleApiTransactionOrders()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        TransactionOrder::expirePendingOrders($uid);

        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(50, (int)$this->request->get('pageSize', 10)));
        $tab = trim((string)$this->request->get('tab', 'all'));

        $query = TransactionOrder::where(function ($q) use ($uid) {
            $q->where('uid', $uid)->whereOr('sell_uid', $uid);
        });

        if ($tab === 'pending_payment') {
            $query->where('status', 0);
        } elseif ($tab === 'paid') {
            $query->where('status', 1);
        } elseif ($tab === 'cancelled') {
            $query->where('status', 2);
        } elseif ($tab === 'completed') {
            $query->where('status', 3);
        }

        $query->order('id', 'desc');

        $total = (int)$query->count();
        $rows = $query->page($page, $pageSize)->select();

        $list = [];
        foreach ($rows as $row) {
            $statusMeta = $this->transactionStatusMeta($row);
            $isBuyer = (int)($row['uid'] ?? 0) === $uid;
            $counterpartyId = $isBuyer ? (int)($row['sell_uid'] ?? 0) : (int)($row['uid'] ?? 0);
            $counterparty = UserModel::field('id,nickname,avatar,mobile')->find($counterpartyId);

            $list[] = [
                'id' => (int)($row['id'] ?? 0),
                'order_number' => (string)($row['order_number'] ?? ''),
                'role' => $isBuyer ? 'buyer' : 'seller',
                'payment_amount' => $this->directMoney((float)($row['payment_amount'] ?? 0)),
                'pay_amount' => $this->directMoney((float)($row['pay_amount'] ?? 0), 6),
                'unit_price' => $this->directMoney((float)($row['unit_price'] ?? 0)),
                'usdt_amount' => $this->directMoney((float)($row['usdt_amount'] ?? 0), 6),
                'transaction_fees' => $this->directMoney((float)($row['transaction_fees'] ?? 0), 6),
                'remittance_user_name' => (string)($row['remittance_user_name'] ?? ''),
                'voucher_image' => $this->buildTradeProofViewUrl((string)($row['order_number'] ?? ''), (string)($row['voucher_image'] ?? '')),
                'proof_view_url' => $this->buildTradeProofViewUrl((string)($row['order_number'] ?? ''), (string)($row['voucher_image'] ?? '')),
                'status' => $statusMeta['status'],
                'effective_status' => $statusMeta['effective_status'],
                'status_text' => $statusMeta['status_text'],
                'expired' => $statusMeta['expired'],
                'expire_time' => $statusMeta['expire_time'],
                'remaining_seconds' => (int)($statusMeta['remaining_seconds'] ?? 0),
                'create_time' => (string)($row['create_time'] ?? ''),
                'submit_time' => (string)($row['submit_time'] ?? ''),
                'cancel_time' => (string)($row['cancel_time'] ?? ''),
                'complete_time' => (string)($row['complete_time'] ?? ''),
                'counterparty' => $counterparty ? [
                    'id' => (int)($counterparty['id'] ?? 0),
                    'nickname' => (string)($counterparty['nickname'] ?? ''),
                    'avatar' => (string)($counterparty['avatar'] ?? ''),
                    'mobile' => $this->directMaskMobile((string)($counterparty['mobile'] ?? '')),
                ] : null,
            ];
        }

        return $this->apiOk('查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'tab' => $tab,
        ]);
    }

    protected function handleApiTransactionMySale()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        TransactionOrder::expirePendingOrders($uid);

        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(50, (int)$this->request->get('pageSize', 10)));
        $status = trim((string)$this->request->get('status', ''));

        $query = TransactionProduct::where('uid', $uid);
        if ($status !== '' && in_array($status, ['1', '2', '3'], true)) {
            $query->where('status', (int)$status);
        }
        $query->order('id', 'desc');

        $total = (int)$query->count();
        $rows = $query->page($page, $pageSize)->select();

        $list = [];
        foreach ($rows as $row) {
            $statusValue = (int)($row['status'] ?? 0);
            $statusTextMap = [
                1 => '上架中',
                2 => '已下架',
                3 => '已结束',
            ];

            $orderCount = (int)TransactionOrder::where('pid', (int)$row['id'])->count();
            $completedCount = (int)TransactionOrder::where('pid', (int)$row['id'])->where('status', 3)->count();

            $list[] = [
                'id' => (int)($row['id'] ?? 0),
                'sell_account' => $this->directMoney((float)($row['sell_account'] ?? 0), 6),
                'unit_price' => $this->directMoney((float)($row['unit_price'] ?? 0)),
                'min_limit' => $this->directMoney((float)($row['min_limit'] ?? 0)),
                'max_limit' => $this->directMoney((float)($row['max_limit'] ?? 0)),
                'status' => $statusValue,
                'status_text' => $statusTextMap[$statusValue] ?? '未知状态',
                'create_time' => (string)($row['create_time'] ?? ''),
                'order_count' => $orderCount,
                'completed_count' => $completedCount,
                'bank_card_info' => $this->parseBankCardInfo($row['bank_card_info'] ?? []),
            ];
        }

        return $this->apiOk('查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'status' => $status,
        ]);
    }

    protected function handleApiTransactionBuy()
    {
        return $this->handleTransactionBuyPost('submit');
    }

    protected function handleApiTransactionOrderProofImage()
    {
        return $this->handleTransactionTradingDetailsPost('image');
    }

    protected function handleApiTransactionOrderCancel()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid > 0) {
            TransactionOrder::expirePendingOrders($uid);
        }
        return $this->handleTransactionTradingDetailsPost('cancel');
    }

    protected function handleApiTransactionOrderProofSubmit()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid > 0) {
            TransactionOrder::expirePendingOrders($uid);
        }
        $post = $this->request->post();
        $orderId = (int)($post['id'] ?? 0);
        if ($orderId > 0 && $uid > 0) {
            $order = TransactionOrder::where('uid', $uid)->find($orderId);
            if ($order) {
                $statusMeta = $this->transactionStatusMeta($order);
                if (!empty($statusMeta['expired'])) {
                    return $this->apiError('订单已超时取消，请重新下单', 400, [
                        'status' => (int)($order['status'] ?? 0),
                        'effective_status' => (int)($statusMeta['effective_status'] ?? 0),
                        'expire_time' => (string)($statusMeta['expire_time'] ?? ''),
                    ]);
                }
                if ((int)($order['status'] ?? 0) !== 0) {
                    return $this->apiError('当前订单状态不可提交凭证', 400, [
                        'status' => (int)($order['status'] ?? 0),
                        'effective_status' => (int)($statusMeta['effective_status'] ?? 0),
                    ]);
                }
            }
        }
        if ($this->requestHasProofUploadInput(['voucher_image', 'image'])) {
            try {
                $this->uploadTransactionProofForBuyer(
                    $uid,
                    $orderId,
                    trim((string)($post['order_number'] ?? ''))
                );
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $status = $message === '交易订单不存在' ? 404 : 400;
                return $this->apiError($message ?: '上传失败', $status);
            }
        }
        return $this->handleTransactionTradingDetailsPost('confirm');
    }
}
