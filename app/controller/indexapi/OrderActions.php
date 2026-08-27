<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\common\service\SubstationPriceService;
use app\common\service\SubstationService;
use app\model\BankCard;
use app\model\Batch;
use app\model\Order;
use app\model\PaymentVoucher as PaymentVoucherModel;
use app\model\Product;
use app\model\TransactionOrder;
use app\model\User as UserModel;
use app\service\UserFundLedgerService;
use app\service\ProductOrderService;
use app\service\telegram\OrderTelegramNotifier;
use Exception;
use think\facade\Db;
use think\facade\Log;

trait OrderActions
{
protected function handleOrderQuery()
{
    $post_info = $this->request->post();
    $uid = (int)($this->user_info['id'] ?? 0);
    if ($uid <= 0) {
        return show(401, 'error', '请先登录');
    }

    if (empty($post_info['order_content'])) {
        return show(500, 'error', '请输入下单账号');
    }

    $orderContent = trim($post_info['order_content']);
    if ($orderContent === '') {
        return show(500, 'error', '请输入下单账号');
    }

    $likeCondition = '%]' . addslashes($orderContent) . '"%';
    $Order = Order::where('uid', $uid)
        ->where('order_info', 'like', $likeCondition)
        ->field(['order_number', 'status', 'amount_money', 'create_time'])
        ->order('id', 'desc')
        ->select()
        ->toArray();

    $orders = [];
    foreach ($Order as $item) {
        $orders[] = [
            'order_no' => (string)($item['order_number'] ?? ''),
            'status' => (int)($item['status'] ?? 0),
            'amount' => round((float)($item['amount_money'] ?? 0), 2),
            'create_time' => (string)($item['create_time'] ?? ''),
        ];
    }

    if (count($orders) > 0) {
        return show(200, 'success', '查询成功', $orders);
    }
    return show(500, 'error', '暂无数据');
}

protected function handleOutOrderPost(string $action)
{
    $post_info = $this->request->post();
    $user_info = UserModel::where('id', $this->user_info['id'])->find();
    $order_info = Order::where('uid', $user_info['id'])->find($post_info['id'] ?? '');
    switch ($action) {
        case 'received':
            if ($order_info) {
                if ($post_info['confirm_status'] == 1) {
                    $confirm_status = 3;
                } elseif ($post_info['confirm_status'] == 2) {
                    $confirm_status = 2;
                } else {
                    return show(500, 'error', '参数有误');
                }
                try {
                    (new ProductOrderService())->confirmReceiptByUser(
                        (int)$user_info['id'],
                        (int)($post_info['id'] ?? 0),
                        $confirm_status,
                        ['source' => 'legacy_web']
                    );
                    return show(200, 'success', '操作成功');
                } catch (\Throwable $e) {
                    return show(500, 'error', $e->getMessage() ?: '请求失败');
                }
            }
            return show(500, 'error', '请求失败');

        default:
            return show(500, 'error', '请求出错');
    }
}

    protected function handleOrderPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $order_info = Order::where('uid', $user_info['id'])->find($post_info['id'] ?? '');
        switch ($action) {
            case 'cancel':
                if ($order_info && empty($order_info['status'])) {
                    try {
                        $this->directCancelOrderRefund((int)$user_info['id'], (int)$post_info['id']);
                        return show(200, 'success', '取消成功');
                    } catch (\Throwable $e) {
                        Log::error('index order_post cancel error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                        return show(500, 'error', '取消失败');
                    }
                }
                return show(500, 'error', '订单不可取消');

            case 'del':
                if ($order_info && $order_info['status'] == 2) {
                    // F2 修复：不再物理删除，改为用户侧软删除（保留订单记录用于对账/退款）
                    if (!Order::supportsUserSoftDelete()) {
                        return show(500, 'error', '订单软删除尚未启用，请先执行数据库升级');
                    }
                    if ($order_info->markUserDeleted() === false) {
                        return show(500, 'error', '删除失败');
                    }
                    return show(200, 'success', '删除成功');
                }
                return show(500, 'error', '请求失败');

            case 'info':
                if ($order_info) {
                    $info = '';
                    foreach ($order_info['order_info'] as $item) {
                        if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
                            $result = checkIfImageExists($matches[2]);
                            if ($result == 1) {
                                $info .= '<div class="title">' . $matches[1] . '：</div>
                                
                                <article class="upload-piclist upload-piclist_4">
                                    <div class="upload-Picitem upload-Picitem_4">
                                        <img src="' . $matches[2] . '" alt="pic">
                                    </div>
                                </article>';
                            } else {
                                $info .= '<div class="title">' . $matches[1] . '：' . $matches[2] . '</div>';
                            }
                        }
                    }

                    return show(200, 'success', '获取成功', $info);
                }
                return show(500, 'error', '请求失败');

            case 'user_on_line_status':
                $user_info->on_line_status = $post_info['on_line_status'];
                $user_info->save();
                return show(200, 'success', '提交成功');

            case 'order_on_line_status':
                $order_info->on_line_status = $post_info['on_line_status'];
                $order_info->save();
                return show(200, 'success', '提交成功');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleApiOrderQuery()
    {
        return $this->apiFromLegacyResult($this->handleOrderQuery(), '查询成功');
    }

    protected function handleApiOrderDelete()
    {
        $post = $this->request->post();
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }
        if (!Order::supportsUserSoftDelete()) {
            return $this->apiError('订单软删除尚未启用，请先执行数据库升级', 500);
        }

        $orderId = trim((string)($post['id'] ?? $post['del_id'] ?? ''));
        $orderNumber = trim((string)($post['order_number'] ?? ''));

        $order = null;
        if ($orderId !== '') {
            $order = Order::where('uid', $uid)->where('id', $orderId)->find();
            if (!$order && $orderNumber === '') {
                $orderNumber = $orderId;
            }
        }

        if (!$order && $orderNumber !== '') {
            $order = Order::where('uid', $uid)->where('order_number', $orderNumber)->find();
        }

        if (!$order || $order->isUserDeleted()) {
            return $this->apiError('订单不存在或已删除', 404);
        }

        try {
            $deleted = $order->markUserDeleted();
            if ($deleted === false) {
                return $this->apiError('删除失败', 500);
            }
        } catch (\Throwable $e) {
            Log::error('api_order_delete error: ' . $e->getMessage(), [
                'uid' => $uid,
                'order_id' => (int)($order['id'] ?? 0),
                'order_number' => (string)($order['order_number'] ?? ''),
            ]);
            return $this->apiError('删除失败', 500);
        }

        return $this->apiOk('删除成功，订单已从前台隐藏');
    }

    protected function handleApiOrderList()
    {
        $uid = (int)($this->user_info['id'] ?? 0);
        if ($uid <= 0) {
            return $this->apiError('请先登录', 401);
        }

        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->get('pageSize', $this->request->get('page_size', 100))));
        $status = (int)$this->request->get('status', 4);
        $statusKey = trim((string)$this->request->get('statusKey', $this->request->get('status_key', '')));
        $content = trim((string)$this->request->get('content', ''));
        $productType = trim((string)$this->request->get('productType', $this->request->get('product_type', '')));

        $query = Order::userVisibleQuery($uid);
        if ($content !== '') {
            $query->where('order_number|order_info', 'like', '%' . $content . '%');
        }
        if ($productType !== '') {
            $query->where('product_type', $productType);
        }
        if ($statusKey !== '' && $statusKey !== 'all') {
            switch ($statusKey) {
                case 'pending_charge':
                    $query->where('status', 0);
                    break;
                case 'processing':
                    $query->where('status', 1);
                    break;
                case 'pending_confirm':
                    $query->where('status', 2)->where('confirm_status', 1);
                    break;
                case 'completed':
                    $query->where('status', 2)->where('confirm_status', 'in', '0,2');
                    break;
                case 'not_received':
                    $query->where('status', 2)->where('confirm_status', 3);
                    break;
                case 'cancelled':
                    $query->where('status', 3);
                    break;
                default:
                    return $this->apiError('status_key 参数有误', 400);
            }
        } elseif ($status !== 4) {
            $query->where('status', $status);
        }
        $query->order('id', 'desc');

        $total = (int)$query->count();
        $rows = $query->page($page, $pageSize)->select();
        $list = [];
        foreach ($rows as $row) {
            $data = $row->toArray();
            $productInfo = $data['product_info'] ?? [];
            if (is_string($productInfo)) {
                $decoded = json_decode($productInfo, true);
                $productInfo = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($productInfo)) {
                $productInfo = [];
            }
            $productId = (int)($data['product_id'] ?? 0);
            if (!$productInfo && $productId > 0) {
                $product = Product::find($productId);
                if ($product) {
                    $productInfo = $product->toArray();
                }
            }
            if (empty($data['product_name'])) {
                $data['product_name'] = (string)($productInfo['name'] ?? '未知产品');
            }
            if (empty($data['product_image'])) {
                $data['product_image'] = (string)($productInfo['image'] ?? '');
            }
            $data['product_info'] = $productInfo;
            $list[] = $data;
        }

        return $this->apiOk('查询成功', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $pageSize)),
            'status' => $status,
            'statusKey' => $statusKey !== '' ? $statusKey : ($status === 4 ? 'all' : (string)$status),
            'content' => $content,
            'productType' => $productType,
        ]);
    }

    protected function handleApiOrderDetail(string $order_number)
    {
        $order = Order::userVisibleQuery((int)$this->user_info['id'])->where('order_number', $order_number)->find();
        if (!$order) {
            return show(404, 'error', '订单不存在', null, 404);
        }
        $data = $order->toArray();
        $refundWalletAmount = order_refund_usdt($data);
        $data['amount_received_raw'] = round((float)($data['amount_received'] ?? 0), 2);
        $data['amount_received'] = order_display_received_cny($data);
        $data['amount_received_display'] = $this->directMoney($data['amount_received']);
        $data['refund_wallet_amount'] = $this->directMoney($refundWalletAmount);
        $data['refund_wallet_amount_raw'] = round($refundWalletAmount, 2);
        $data['has_wallet_refund'] = $refundWalletAmount > 0 ? 1 : 0;
        $data['refund_wallet_text'] = $refundWalletAmount > 0 ? '已退款到钱包 ' . $this->directMoney($refundWalletAmount) . ' USDT' : '';
        if (empty($data['product_name'])) {
            $product = Product::find((int)($data['product_id'] ?? 0));
            if ($product) {
                $data['product_name'] = (string)($product['name'] ?? '');
                $data['product_image'] = (string)($product['image'] ?? '');
            }
        }
        return show(200, 'success', '查询成功', $data);
    }

protected function handleApiOrderCancel()
{
    $post = $this->request->post();
    if (empty($post)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $post = $json;
        }
    }

    $uid = (int)($this->user_info['id'] ?? 0);
    if ($uid <= 0) {
        return $this->apiError('请先登录', 401);
    }

    $order = null;
    $id = (int)($post['id'] ?? 0);
    $orderNumber = trim((string)($post['order_number'] ?? ''));
    if ($id <= 0 && $orderNumber === '') {
        return $this->apiError('缺少订单ID', 400);
    }
    try {
        $result = $this->directCancelOrderRefund($uid, $id, $orderNumber);
        $order = $result['order'];
        $refundAmount = $result['refund_amount'];
        return $this->apiOk('取消成功', [
            'id' => (int)$order['id'],
            'order_number' => (string)($order['order_number'] ?? ''),
            'status' => 3,
            'status_text' => '已取消',
            'refund_amount' => $this->directMoney($refundAmount),
        ]);
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        if ($message === '订单不存在') {
            return $this->apiError('订单不存在', 404);
        }
        if ($message === '当前订单不可取消') {
            return $this->apiError('当前订单不可取消', 400);
        }
        if ($message === '用户不存在') {
            return $this->apiError('用户不存在', 404);
        }
        Log::error('api_order_cancel error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'order_id' => $id,
        ]);
        return $this->apiError('取消失败：' . $e->getMessage(), 500);
    }
}

protected function handleApiOrderConfirmReceipt()
{
    $post = $this->request->post();
    if (empty($post)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $post = $json;
        }
    }

    $uid = (int)($this->user_info['id'] ?? 0);
    if ($uid <= 0) {
        return $this->apiError('请先登录', 401);
    }

    $order = null;
    $id = (int)($post['id'] ?? 0);
    $orderNumber = trim((string)($post['order_number'] ?? ''));

    if ($id > 0) {
        $order = Order::userVisibleQuery($uid)->find($id);
    } elseif ($orderNumber !== '') {
        $order = Order::userVisibleQuery($uid)->where('order_number', $orderNumber)->find();
    }

    if (!$order) {
        return $this->apiError('订单不存在', 404);
    }

    $confirmStatusInput = (int)($post['confirm_status'] ?? 0);
    if (!in_array($confirmStatusInput, [1, 2], true)) {
        return $this->apiError('confirm_status 参数有误', 400);
    }

    $targetConfirmStatus = $confirmStatusInput === 1 ? 3 : 2;
    try {
        $updatedOrder = (new ProductOrderService())->confirmReceiptByUser(
            $uid,
            (int)($order['id'] ?? 0),
            $targetConfirmStatus,
            ['source' => 'api_web']
        );
        return $this->apiOk('操作成功', [
            'id' => (int)$updatedOrder['id'],
            'order_number' => (string)($updatedOrder['order_number'] ?? ''),
            'confirm_status' => $targetConfirmStatus,
            'confirm_status_text' => $targetConfirmStatus === 3 ? '未收到' : '已确认到账',
        ]);
    } catch (\Throwable $e) {
        Log::error('api_order_confirm_receipt error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'order_id' => $order['id'] ?? 0,
        ]);
        $message = $e->getMessage();
        if (in_array($message, ['订单不存在', '用户不存在'], true)) {
            return $this->apiError($message, 404);
        }
        if (in_array($message, ['confirm_status 参数有误', '订单当前状态不可确认', '订单已处理，请勿重复提交'], true)) {
            return $this->apiError($message, 400);
        }
        return $this->apiError('操作失败：' . $message, 500);
    }
}

protected function handlePaymentVoucher(string $action)
{
    $post_info = $this->request->post();
    $orderId = (int)($post_info['order_id'] ?? 0);
    if (empty($orderId)) {
        return show(500, 'error', '订单不存在');
    }

    $ownerOrder = Order::where('id', $orderId)->where('uid', (int)($this->user_info['id'] ?? 0))->find();
    $ownerTransactionOrder = null;
    if (!$ownerOrder) {
        $ownerTransactionOrder = TransactionOrder::where('id', $orderId)->where('uid', (int)($this->user_info['id'] ?? 0))->find();
        if (!$ownerTransactionOrder) {
            $ownerTransactionOrder = TransactionOrder::where('id', $orderId)->where('sell_uid', (int)($this->user_info['id'] ?? 0))->find();
        }
    }
    if (!$ownerOrder && !$ownerTransactionOrder) {
        return show(500, 'error', '订单不存在');
    }
    switch ($action) {
        case 'edit':
            $payment_voucher_list = PaymentVoucherModel::where('order_id', $orderId)->find();
            if ($payment_voucher_list) {
                $payment_voucher_list->order_id = $orderId;
                if ($post_info['name']) {
                    $payment_voucher_list->name = $post_info['name'];
                }
                if ($post_info['money']) {
                    $payment_voucher_list->money = $post_info['money'];
                }
                if ($post_info['title1']) {
                    $payment_voucher_list->title1 = $post_info['title1'];
                }
                if ($post_info['remark1']) {
                    $payment_voucher_list->remark1 = $post_info['remark1'];
                }
                if ($post_info['title2']) {
                    $payment_voucher_list->title2 = $post_info['title2'];
                }
                if ($post_info['remark2']) {
                    $payment_voucher_list->remark2 = $post_info['remark2'];
                }
                if ($post_info['title3']) {
                    $payment_voucher_list->title3 = $post_info['title3'];
                }
                if ($post_info['remark3']) {
                    $payment_voucher_list->remark3 = $post_info['remark3'];
                }
                if ($post_info['title4']) {
                    $payment_voucher_list->title4 = $post_info['title4'];
                }
                if ($post_info['remark4']) {
                    $payment_voucher_list->remark4 = $post_info['remark4'];
                }
                if ($post_info['title5']) {
                    $payment_voucher_list->title5 = $post_info['title5'];
                }
                if ($post_info['remark5']) {
                    $payment_voucher_list->remark5 = $post_info['remark5'];
                }
                if ($post_info['title6']) {
                    $payment_voucher_list->title6 = $post_info['title6'];
                }
                if ($post_info['remark6']) {
                    $payment_voucher_list->remark6 = $post_info['remark6'];
                }
                if ($post_info['title7']) {
                    $payment_voucher_list->title7 = $post_info['title7'];
                }
                if ($post_info['remark7']) {
                    $payment_voucher_list->remark7 = $post_info['remark7'];
                }
                if ($post_info['title8']) {
                    $payment_voucher_list->title8 = $post_info['title8'];
                }
                if ($post_info['remark8']) {
                    $payment_voucher_list->remark8 = $post_info['remark8'];
                }
                $payment_voucher_list->save();
                return show(200, 'success', '操作成功');
            }

            $data = [];
            $data['order_id'] = $orderId;
            if ($post_info['name']) {
                $data['name'] = $post_info['name'];
            }
            if ($post_info['money']) {
                $data['money'] = $post_info['money'];
            }
            if ($post_info['title1']) {
                $data['title1'] = $post_info['title1'];
            }
            if ($post_info['remark1']) {
                $data['remark1'] = $post_info['remark1'];
            }
            if ($post_info['title2']) {
                $data['title2'] = $post_info['title2'];
            }
            if ($post_info['remark2']) {
                $data['remark2'] = $post_info['remark2'];
            }
            if ($post_info['title3']) {
                $data['title3'] = $post_info['title3'];
            }
            if ($post_info['remark3']) {
                $data['remark3'] = $post_info['remark3'];
            }
            if ($post_info['title4']) {
                $data['title4'] = $post_info['title4'];
            }
            if ($post_info['remark4']) {
                $data['remark4'] = $post_info['remark4'];
            }
            if ($post_info['title5']) {
                $data['title5'] = $post_info['title5'];
            }
            if ($post_info['remark5']) {
                $data['remark5'] = $post_info['remark5'];
            }
            if ($post_info['title6']) {
                $data['title6'] = $post_info['title6'];
            }
            if ($post_info['remark6']) {
                $data['remark6'] = $post_info['remark6'];
            }
            if ($post_info['title7']) {
                $data['title7'] = $post_info['title7'];
            }
            if ($post_info['remark7']) {
                $data['remark7'] = $post_info['remark7'];
            }
            if ($post_info['title8']) {
                $data['title8'] = $post_info['title8'];
            }
            if ($post_info['remark8']) {
                $data['remark8'] = $post_info['remark8'];
            }
            PaymentVoucherModel::create($data);
            return show(200, 'success', '操作成功');

        default:
            return show(500, 'error', '请求出错');
    }
}

protected function handleProductPost(string $action)
{
    $post_info = $this->request->post();
    $Product = Product::find($post_info['product_id']);
    $user_info = UserModel::where('id', $this->user_info['id'])->find();
    switch ($action) {
        case 'confirm_recharge':
            if (empty($post_info['amount_money'])) {
                return show(500, 'error', '请输入充值金额');
            }
            if ($post_info['batch_type'] == 0) {
                $array = json_decode($post_info['order_info'], true);
                foreach ($array as $item) {
                    if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
                        if ($matches[2] == '') {
                            foreach ($Product['order_info'] as $vo) {
                                if ($vo['name'] == $matches[1]) {
                                    if ($vo['type'] == 1) {
                                        return show(500, 'error', '请输入' . $matches[1]);
                                    }
                                    if ($vo['type'] == 2 || $vo['type'] == 3) {
                                        return show(500, 'error', '请选择' . $matches[1]);
                                    }
                                    if ($vo['type'] == 4) {
                                        return show(500, 'error', '请上传' . $matches[1]);
                                    }
                                }
                            }
                        }
                    }
                }
                $amount_money = $post_info['amount_money'];
            } elseif ($post_info['batch_type'] == 1) {
                $batch_ok_count = Batch::where('uid', $this->user_info['id'])->where('status', 0)->count();
                if (empty($batch_ok_count)) {
                    return show(500, 'error', '请导入充值号码');
                }
                $amount_money = $post_info['amount_money'] * $batch_ok_count;
            }
            $substationContext = SubstationService::resolveByRequest($this->request);
            $substationId = (int)($substationContext['substation_id'] ?? 0);
            return show(200, 'success', '查询成功', ['balance' => $user_info['balance'], 'discount' => SubstationPriceService::resolveDiscountPreview((int)$post_info['product_id'], (float)$amount_money, $substationId)]);

        case 'confirm_payment':
            if (empty($post_info['amount_money'])) {
                return show(500, 'error', '请输入充值金额');
            }
            $miniRechargeAmount = (float)($Product['mini_recharge_amount'] ?? 0);
            if ($miniRechargeAmount > 0 && (float)$post_info['amount_money'] < $miniRechargeAmount) {
                return show(500, 'error', '充值金额不能低于 ' . $Product['mini_recharge_amount']);
            }
            $createdOrders = [];
            // F7 修复：第三方余额查询（phone_yue，HTTP）从数据库事务与用户行锁内移出。
            // phone_yue 结果仅作为订单信息快照（phone_yue_a / phone_yue 字段）存储，
            // 不参与金额计算 / 冻结 / 余额判断（余额判断在事务内基于 FOR UPDATE 后的最新余额），
            // 因此移出事务不会破坏资金一致性。
            // 收益：事务保持纯 DB 本地，不再在持有用户行锁期间等待第三方 HTTP（最长 10s/次，
            // batch 模式为 N 次），避免长期占用数据库连接并阻塞该用户的提现/返佣/充值/调账。
            $phoneYueByNumber = [];
            if ($post_info['batch_type'] == 0) {
                $preOrderArray = json_decode($post_info['order_info'], true);
                if (is_array($preOrderArray)) {
                    foreach ($preOrderArray as $preItem) {
                        if (preg_match('/\[(.*?)\](.*)/', $preItem, $preMatches)
                            && $preMatches[2] !== ''
                            && phone_info($preMatches[2])
                            && $Product['product_type'] == 1) {
                            $phoneYueByNumber[$preMatches[2]] = phone_yue($preMatches[2]);
                        }
                    }
                }
            } elseif ($post_info['batch_type'] == 1) {
                $preBatchData = Batch::where('uid', $this->user_info['id'])->where('status', 0)->select();
                foreach ($preBatchData as $preVo) {
                    $preNumber = (string)($preVo['number'] ?? '');
                    if ($preNumber !== '' && phone_info($preNumber) && $Product['product_type'] == 1) {
                        $phoneYueByNumber[$preNumber] = phone_yue($preNumber);
                    }
                }
            }
            try {
                Db::startTrans();
                $user_info = UserModel::where('id', $this->user_info['id'])->lock(true)->find();
                if (!$user_info) {
                    throw new Exception('用户不存在');
                }
                if ($post_info['batch_type'] == 0) {
                    $array = json_decode($post_info['order_info'], true);
                    foreach ($array as $item) {
                        if (preg_match('/\[(.*?)\](.*)/', $item, $matches)) {
                            if ($matches[2] == '') {
                                foreach ($Product['order_info'] as $vo) {
                                    if ($vo['name'] == $matches[1]) {
                                        if ($vo['type'] == 1) {
                                            throw new Exception('请输入' . $matches[1]);
                                        }
                                        if ($vo['type'] == 2 || $vo['type'] == 3) {
                                            throw new Exception('请选择' . $matches[1]);
                                        }
                                        if ($vo['type'] == 4) {
                                            throw new Exception('请上传' . $matches[1]);
                                        }
                                    }
                                }
                            } elseif (phone_info($matches[2]) && $Product['product_type'] == 1) {
                                $phone_yue = $phoneYueByNumber[$matches[2]] ?? 0.00;
                            }
                        }
                    }
                    $substationContext = SubstationService::resolveByRequest($this->request);
                    $substationId = (int)($substationContext['substation_id'] ?? 0);
                    $substationUid = (int)($substationContext['substation_uid'] ?? 0);
                    $priceInfo = SubstationPriceService::resolveDiscountPreview((int)$Product['id'], (float)$post_info['amount_money'], $substationId);
                    $finalCnyAmount = round((float)$priceInfo['cnyAmount'], 2);
                    if ($finalCnyAmount > $user_info['balance']) {
                        throw new Exception('账户余额不足');
                    }
                    $productInfoSnapshot = is_array($Product->toArray()) ? $Product->toArray() : (array)$Product;
                    $productInfoSnapshot['substation_tier_snapshot'] = [
                        'substation_id' => $substationId,
                        'substation_uid' => $substationUid,
                        'tier_key' => $priceInfo['tier_key'],
                        'platform_price' => $priceInfo['platform_settlement_price'],
                        'substation_price' => $priceInfo['final_price'],
                        'commission_base' => $priceInfo['commission_base'],
                        'markup_amount' => $priceInfo['markup_amount'],
                    ];
                    $createdOrder = Order::create([
                        'uid' => $this->user_info['id'],
                        'product_id' => $Product['id'],
                        'substation_id' => $substationId ?: null,
                        'substation_uid' => $substationUid ?: null,
                        'tier_key' => $priceInfo['tier_key'],
                        'product_info' => $productInfoSnapshot,
                        'order_number' => date('Ymd') . randomkeys(6, 'number'),
                        'amount_money' => $post_info['amount_money'] ?? '',
                        'cny_amount' => $finalCnyAmount,
                        'platform_price_snapshot' => $priceInfo['platform_settlement_price'],
                        'substation_price_snapshot' => $priceInfo['final_price'],
                        'commission_base_snapshot' => $priceInfo['commission_base'],
                        'substation_markup_amount' => $priceInfo['markup_amount'],
                        'discount_amount' => $priceInfo['discountAmount'] ?? '0.00',
                        'discount' => $priceInfo['final_discount'] ?? 0,
                        'rate' => getConfig('rate'),
                        'order_info' => $post_info['order_info'],
                        'type' => $Product['type'],
                        'product_type' => $Product['product_type'],
                        'phone_yue_a' => $phone_yue ?? 0.00,
                    ]);
                    $this->freezeProductOrderPayment($user_info, $createdOrder, (float)$finalCnyAmount);
                    $createdOrders[] = $createdOrder->toArray();
                } elseif ($post_info['batch_type'] == 1) {
                    $batch_data = $preBatchData ?? collect();
                    if (count($batch_data) === 0) {
                        throw new Exception('请导入充值号码');
                    }
                    $substationContext = SubstationService::resolveByRequest($this->request);
                    $substationId = (int)($substationContext['substation_id'] ?? 0);
                    $substationUid = (int)($substationContext['substation_uid'] ?? 0);
                    foreach ($batch_data as $vo) {
                        $priceInfo = SubstationPriceService::resolveDiscountPreview((int)$Product['id'], (float)$post_info['amount_money'], $substationId);
                        $finalCnyAmount = round((float)$priceInfo['cnyAmount'], 2);
                        if ($finalCnyAmount > $user_info['balance']) {
                            throw new Exception('账户余额不足');
                        }

                        $phone_yue = 0.00;
                        if (phone_info($vo['number']) && $Product['product_type'] == 1) {
                            $phone_yue = $phoneYueByNumber[(string)$vo['number']] ?? 0.00;
                        }

                        $productInfoSnapshot = is_array($Product->toArray()) ? $Product->toArray() : (array)$Product;
                        $productInfoSnapshot['substation_tier_snapshot'] = [
                            'substation_id' => $substationId,
                            'substation_uid' => $substationUid,
                            'tier_key' => $priceInfo['tier_key'],
                            'platform_price' => $priceInfo['platform_settlement_price'],
                            'substation_price' => $priceInfo['final_price'],
                            'commission_base' => $priceInfo['commission_base'],
                            'markup_amount' => $priceInfo['markup_amount'],
                        ];
                        $createdOrder = Order::create([
                            'uid' => $this->user_info['id'],
                            'product_id' => $Product['id'],
                            'substation_id' => $substationId ?: null,
                            'substation_uid' => $substationUid ?: null,
                            'tier_key' => $priceInfo['tier_key'],
                            'product_info' => $productInfoSnapshot,
                            'order_number' => date('Ymd') . randomkeys(6, 'number'),
                            'amount_money' => $post_info['amount_money'] ?? '',
                            'cny_amount' => $finalCnyAmount,
                            'platform_price_snapshot' => $priceInfo['platform_settlement_price'],
                            'substation_price_snapshot' => $priceInfo['final_price'],
                            'commission_base_snapshot' => $priceInfo['commission_base'],
                            'substation_markup_amount' => $priceInfo['markup_amount'],
                            'discount_amount' => $priceInfo['discountAmount'] ?? '0.00',
                            'discount' => $priceInfo['final_discount'] ?? 0,
                            'rate' => getConfig('rate'),
                            'order_info' => ['[充值号码]' . $vo['number']],
                            'type' => $Product['type'],
                            'product_type' => $Product['product_type'],
                            'phone_yue' => $phone_yue,
                        ]);
                        $this->freezeProductOrderPayment($user_info, $createdOrder, (float)$finalCnyAmount);
                        $createdOrders[] = $createdOrder->toArray();
                    }
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                return show(500, 'error', $e->getMessage() ?: '支付失败');
            }

            $url = '/order';
            return show(200, 'success', '支付成功', $url);

        case 'discount':
            if ($post_info['batch_type'] == 0) {
                $amount_money = $post_info['amount_money'];
            } elseif ($post_info['batch_type'] == 1) {
                $batch_ok_count = Batch::where('uid', $this->user_info['id'])->where('status', 0)->count();
                $amount_money = $post_info['amount_money'] * $batch_ok_count;
            }
            $substationContext = SubstationService::resolveByRequest($this->request);
            $substationId = (int)($substationContext['substation_id'] ?? 0);
            return show(200, 'success', '查询成功', SubstationPriceService::resolveDiscountPreview((int)$post_info['product_id'], (float)$amount_money, $substationId));

        default:
            return show(500, 'error', '请求出错');
    }
}

private function freezeProductOrderPayment(UserModel $user, Order $order, float $amount): void
{
    (new UserFundLedgerService())->transferLockedUserWallet(
        $user,
        UserFundLedgerService::WALLET_BALANCE,
        UserFundLedgerService::WALLET_FROZEN,
        round($amount, 2),
        [
            'biz_type' => 'product_order',
            'biz_id' => (int)($order['id'] ?? 0),
            'biz_no' => (string)($order['order_number'] ?? ''),
            'order_number' => (string)($order['order_number'] ?? ''),
            'out_change_type' => 'product_order_freeze',
            'in_change_type' => 'product_order_freeze',
            'operator_type' => 'user',
            'operator_id' => (int)($user['id'] ?? 0),
            'status' => 'done',
            'request_no' => 'product_order_freeze:' . (string)($order['order_number'] ?? ''),
            'remark' => 'product order freeze on payment',
            'idempotent' => true,
            'extra' => [
                'source' => 'handleProductPost_confirm_payment',
                'product_id' => (int)($order['product_id'] ?? 0),
            ],
        ]
    );
}

protected function handleQueryBusinessPagePost(string $action)
{
    $post_info = $this->request->post();
    $product = Product::find($post_info['product_id']);
    $user_info = UserModel::where('id', $this->user_info['id'])->find();
    switch ($action) {
        case 'confirm_submit':
            if (empty($post_info['clue'])) {
                return show(500, 'error', '请输入线索');
            }
            if (empty($post_info['image'])) {
                return show(500, 'error', '请上传图片');
            }
            return show(200, 'success', '查询成功', ['balance' => $user_info['balance'], 'price' => number_format($product['quiry_price'] / getConfig('rate') ?? 0, 2, '.', '')]);

        case 'confirm_payment':
            $amount = number_format($product['quiry_price'] / getConfig('rate') ?? 0, 2, '.', '');
            if ($amount > $user_info['balance']) {
                return show(500, 'error', '账户余额不足');
            }
            try {
                Db::startTrans();
                $user_info = UserModel::where('id', $this->user_info['id'])->lock(true)->find();
                if (!$user_info) {
                    throw new Exception('用户不存在');
                }
                if ($amount > $user_info['balance']) {
                    throw new Exception('账户余额不足');
                }
                $createdOrder = Order::create([
                    'uid' => $this->user_info['id'],
                    'product_id' => $product['id'],
                    'product_info' => $product,
                    'order_number' => date('Ymd') . randomkeys(6, 'number'),
                    'order_info' => $post_info['order_info'],
                    'cny_amount' => $amount,
                    'rate' => getConfig('rate'),
                    'type' => $product['type'],
                ]);
                $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
                    $user_info,
                    UserFundLedgerService::WALLET_BALANCE,
                    -1 * round((float)$amount, 2),
                    [
                        'biz_type' => 'product_order',
                        'biz_id' => (int)($createdOrder['id'] ?? 0),
                        'biz_no' => (string)($createdOrder['order_number'] ?? ''),
                        'order_number' => (string)($createdOrder['order_number'] ?? ''),
                        'change_type' => 'product_order_deduct',
                        'operator_type' => 'user',
                        'operator_id' => (int)($user_info['id'] ?? 0),
                        'status' => 'done',
                        'request_no' => 'product_order_deduct:' . (string)($createdOrder['order_number'] ?? ''),
                        'remark' => 'product order payment deduct',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'handleQueryBusinessPagePost_confirm_payment',
                        ],
                    ]
                );
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                return show(500, 'error', $e->getMessage() ?: '支付失败');
            }

            return show(200, 'success', '支付成功');

        case 'discount':
            return show(200, 'success', '查询成功', discount($post_info['product_id'], $post_info['amount_money']));

        default:
            return show(500, 'error', '请求出错');
    }
}
}
