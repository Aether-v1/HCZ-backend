<?php
declare (strict_types=1);

namespace app\service;

use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\User as UserModel;
use app\service\UserFundLedgerService;
use app\service\telegram\OrderTelegramNotifier;
use Exception;
use think\facade\Db;
use think\facade\Log;

class TransactionOrderService
{
    private OrderTelegramNotifier $notifier;

    public function __construct(?OrderTelegramNotifier $notifier = null)
    {
        $this->notifier = $notifier ?: new OrderTelegramNotifier();
    }

    public function markBuyerPaid(int $buyerId, int $orderId = 0, string $orderNumber = ''): array
    {
        $orderSnapshot = [];

        Db::startTrans();
        try {
            $order = $this->lockBuyerOrder($buyerId, $orderId, $orderNumber);
            if (!$order) {
                throw new Exception('交易订单不存在');
            }
            if ((int)($order['status'] ?? 0) !== 0) {
                throw new Exception('当前订单状态不可提交凭证');
            }
            if ($this->isExpiredPendingOrder($order)) {
                throw new Exception('订单已超时取消，请重新下单');
            }

            $order->status = 1;
            $order->submit_time = date('Y-m-d H:i:s');
            if ($order->save() === false) {
                throw new Exception('操作失败');
            }

            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        try {
            $this->notifier->notifyUsdtOrderPendingSellerConfirm($orderSnapshot);
        } catch (\Throwable $notifyException) {
            Log::error('usdt order notify failed', [
                'order_id' => (int)($orderSnapshot['id'] ?? 0),
                'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                'uid' => (int)($orderSnapshot['uid'] ?? 0),
                'action' => 'usdt_order_pending_seller_confirm_notify',
                'error_message' => $notifyException->getMessage(),
            ]);
        }
        return $orderSnapshot;
    }

    public function cancelPendingOrder(int $buyerId, int $orderId = 0, string $orderNumber = '', string $reason = '用户取消'): array
    {
        $orderSnapshot = [];

        Db::startTrans();
        try {
            $order = $this->lockBuyerOrder($buyerId, $orderId, $orderNumber);
            if (!$order) {
                throw new Exception('交易订单不存在');
            }
            if ((int)($order['status'] ?? 0) !== 0) {
                throw new Exception('当前订单不可取消');
            }

            $order->status = 2;
            if (empty($order['cancel_time'])) {
                $order->cancel_time = date('Y-m-d H:i:s');
            }
            if ($order->save() === false) {
                throw new Exception('取消失败');
            }

            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $orderSnapshot;
    }

    public function releaseBySeller(int $sellerId, int $orderId = 0, string $orderNumber = ''): array
    {
        $orderSnapshot = [];

        Db::startTrans();
        try {
            $query = TransactionOrder::where('sell_uid', $sellerId)->lock(true);
            $order = $orderId > 0
                ? $query->find($orderId)
                : $query->where('order_number', $orderNumber)->find();

            if (!$order) {
                throw new Exception('交易订单不存在');
            }
            if ((int)($order['status'] ?? 0) !== 1) {
                throw new Exception('当前订单状态不可放币');
            }

            $seller = UserModel::where('id', $sellerId)->lock(true)->find();
            if (!$seller) {
                throw new Exception('用户不存在');
            }

            $product = TransactionProduct::where('id', (int)$order['pid'])->lock(true)->find();
            if (!$product) {
                throw new Exception('挂单不存在');
            }

            $buyer = UserModel::where('id', (int)$order['uid'])->lock(true)->find();
            if (!$buyer) {
                throw new Exception('买家不存在');
            }

            // 正常情况下 sell_account 应 >= order.pay_amount
            // sell_account = 初始挂单量 - 已完成订单量，当前订单为已汇款(1)状态，其金额包含在 sell_account 中
            // 若 sell_account < pay_amount，说明存在历史超卖数据，禁止静默按不足金额放币
            $releasedAmount = round((float)($order['pay_amount'] ?? 0), 2);
            if ($releasedAmount <= 0) {
                throw new Exception('订单金额无效');
            }
            if ((float)($product['sell_account'] ?? 0) + 0.005 < $releasedAmount) {
                Log::error('transaction release insufficient sell_account', [
                    'order_id' => (int)($order['id'] ?? 0),
                    'order_number' => (string)($order['order_number'] ?? ''),
                    'pid' => (int)($product['id'] ?? 0),
                    'sell_account' => (float)($product['sell_account'] ?? 0),
                    'pay_amount' => $releasedAmount,
                    'seller_uid' => (int)($seller['id'] ?? 0),
                    'buyer_uid' => (int)($buyer['id'] ?? 0),
                ]);
                throw new Exception('挂单剩余数量不足，无法放币，请联系客服');
            }

            // 手续费金额校验：必须满足 usdt_amount + transaction_fees == pay_amount
            $transactionFees = round((float)($order['transaction_fees'] ?? 0), 2);
            $buyerIncomeAmount = round((float)($order['usdt_amount'] ?? 0), 2);
            if ($transactionFees < -0.005) {
                throw new Exception('订单手续费异常：不能为负数');
            }
            if ($transactionFees > $releasedAmount + 0.005) {
                throw new Exception('订单手续费异常：不能大于订单金额');
            }
            if ($buyerIncomeAmount < -0.005) {
                throw new Exception('买家到账金额异常：不能为负数');
            }
            if (abs($buyerIncomeAmount + $transactionFees - $releasedAmount) > 0.005) {
                throw new Exception('订单金额不匹配：usdt_amount + transaction_fees != pay_amount');
            }

            $order->status = 3;
            $order->complete_time = date('Y-m-d H:i:s');
            if ($order->save() === false) {
                throw new Exception('操作失败');
            }

            $product->sell_account = round((float)($product['sell_account'] ?? 0) - $releasedAmount, 2);
            if ($product->sell_account < -0.005) {
                throw new Exception('挂单数量异常，放币已终止');
            }
            if ($product->save() === false) {
                throw new Exception('操作失败');
            }

                        if ($releasedAmount > 0) {
                (new UserFundLedgerService())->changeLockedUserWallet(
                    $seller,
                    UserFundLedgerService::WALLET_FROZEN,
                    -1 * $releasedAmount,
                    [
                        'biz_type' => 'transaction_order',
                        'biz_id' => (int)($order['id'] ?? 0),
                        'biz_no' => (string)($order['order_number'] ?? ''),
                        'order_number' => (string)($order['order_number'] ?? ''),
                        'change_type' => 'transaction_deduct',
                        'operator_type' => 'user',
                        'operator_id' => (int)($seller['id'] ?? 0),
                        'status' => 'done',
                        'request_no' => 'transaction_deduct:' . (string)($order['order_number'] ?? ''),
                        'remark' => 'transaction seller deduct frozen amount',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'transaction_order_release_by_seller',
                            'listing_id' => (int)($product['id'] ?? 0),
                            'pay_amount' => round((float)($order['pay_amount'] ?? 0), 2),
                            'released_amount' => round((float)$releasedAmount, 2),
                        ],
                    ]
                );
            }
            if ($buyerIncomeAmount > 0) {
                (new UserFundLedgerService())->changeLockedUserWallet(
                    $buyer,
                    UserFundLedgerService::WALLET_BALANCE,
                    $buyerIncomeAmount,
                    [
                        'biz_type' => 'transaction_order',
                        'biz_id' => (int)($order['id'] ?? 0),
                        'biz_no' => (string)($order['order_number'] ?? ''),
                        'order_number' => (string)($order['order_number'] ?? ''),
                        'change_type' => 'transaction_buyer_income',
                        'operator_type' => 'user',
                        'operator_id' => (int)($seller['id'] ?? 0),
                        'status' => 'done',
                        'request_no' => 'transaction_buyer_income:' . (string)($order['order_number'] ?? ''),
                        'remark' => 'transaction buyer income on seller release',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'transaction_order_release_by_seller',
                            'listing_id' => (int)($product['id'] ?? 0),
                            'buyer_uid' => (int)($buyer['id'] ?? 0),
                            'seller_uid' => (int)($seller['id'] ?? 0),
                            'income_amount' => $buyerIncomeAmount,
                        ],
                    ]
                );
            }

            // 平台手续费记账（纯流水，不更新 cz_user，与放币同一事务，幂等 request_no）
            if ($transactionFees > 0.005) {
                (new UserFundLedgerService())->recordPlatformIncome($transactionFees, [
                    'biz_type' => 'transaction_order',
                    'biz_id' => (int)($order['id'] ?? 0),
                    'biz_no' => (string)($order['order_number'] ?? ''),
                    'order_number' => (string)($order['order_number'] ?? ''),
                    'change_type' => 'transaction_fee_income',
                    'operator_type' => 'system',
                    'operator_id' => 0,
                    'status' => 'done',
                    'request_no' => 'transaction_fee:' . (string)($order['order_number'] ?? ''),
                    'remark' => 'C2C transaction fee income',
                    'extra' => [
                        'source' => 'transaction_order_release_by_seller',
                        'listing_id' => (int)($product['id'] ?? 0),
                        'pay_amount' => $releasedAmount,
                        'buyer_income' => $buyerIncomeAmount,
                        'transaction_fees' => $transactionFees,
                    ],
                ]);
            }

            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        try {
            $this->notifier->notifyUsdtOrderReleased($orderSnapshot);
        } catch (\Throwable $notifyException) {
            Log::error('usdt order notify failed', [
                'order_id' => (int)($orderSnapshot['id'] ?? 0),
                'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                'uid' => (int)($orderSnapshot['uid'] ?? 0),
                'action' => 'usdt_order_released_notify',
                'error_message' => $notifyException->getMessage(),
            ]);
        }
        return $orderSnapshot;
    }

    public function expirePendingOrders(?int $uid = null): int
    {
        $expireBefore = date('Y-m-d H:i:s', time() - TransactionOrder::pendingTimeoutSeconds());
        $query = TransactionOrder::where('status', 0)->where('create_time', '<', $expireBefore);

        if (!empty($uid)) {
            $query->where(function ($builder) use ($uid) {
                $builder->where('uid', $uid)->whereOr('sell_uid', $uid);
            });
        }

        $orderIds = $query->column('id');
        $count = 0;
        foreach ($orderIds as $orderId) {
            $expired = $this->expirePendingOrderById((int)$orderId);
            if ($expired !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function expirePendingOrderById(int $orderId): ?array
    {
        Db::startTrans();
        try {
            $order = TransactionOrder::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                Db::rollback();
                return null;
            }
            if ((int)($order['status'] ?? 0) !== 0 || !$this->isExpiredPendingOrder($order)) {
                Db::rollback();
                return null;
            }

            $order->status = 2;
            if (empty($order['cancel_time'])) {
                $order->cancel_time = date('Y-m-d H:i:s');
            }
            if ($order->save() === false) {
                throw new Exception('超时取消失败');
            }

            $snapshot = $order->toArray();
            Db::commit();
            return $snapshot;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::warning('expire pending transaction order failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function lockBuyerOrder(int $buyerId, int $orderId = 0, string $orderNumber = ''): ?TransactionOrder
    {
        $query = TransactionOrder::where('uid', $buyerId)->lock(true);
        if ($orderId > 0) {
            return $query->find($orderId);
        }
        if ($orderNumber !== '') {
            return $query->where('order_number', $orderNumber)->find();
        }

        return null;
    }

    private function isExpiredPendingOrder($order): bool
    {
        $create = strtotime((string)($order['create_time'] ?? '')) ?: 0;
        if ($create <= 0) {
            return false;
        }

        return time() > ($create + TransactionOrder::pendingTimeoutSeconds());
    }
}
