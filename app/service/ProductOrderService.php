<?php
declare (strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\User as UserModel;
use Exception;
use think\facade\Db;
use think\facade\Log;

class ProductOrderService
{
    public function confirmReceiptByUser(int $userId, int $orderId, int $targetConfirmStatus, array $context = []): array
    {
        if ($userId <= 0) {
            throw new Exception('用户不存在');
        }
        if ($orderId <= 0) {
            throw new Exception('订单不存在');
        }
        if (!in_array($targetConfirmStatus, [2, 3], true)) {
            throw new Exception('confirm_status 参数有误');
        }

        $orderSnapshot = [];
        Db::startTrans();
        try {
            $order = Order::scopeUserVisible(Order::where('uid', $userId))
                ->where('id', $orderId)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new Exception('订单不存在');
            }

            if ((int)($order['status'] ?? 0) !== 2) {
                throw new Exception('订单当前状态不可确认');
            }

            $currentConfirmStatus = (int)($order['confirm_status'] ?? 0);
            if (in_array($currentConfirmStatus, [2, 3], true)) {
                throw new Exception('订单已处理，请勿重复提交');
            }

            $needReleaseFrozen = $targetConfirmStatus === 2 && $currentConfirmStatus !== 2;

            if ($needReleaseFrozen) {
                $user = UserModel::where('id', $userId)->lock(true)->find();
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                $this->deductFrozenForCompletedOrder($user, $order, 'user', $userId, $targetConfirmStatus, $currentConfirmStatus, $context);
            }

            $order->confirm_status = $targetConfirmStatus;
            if ($order->save() === false) {
                throw new Exception('订单保存失败');
            }

            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::warning('product order confirm receipt failed', array_merge($context, [
                'user_id' => $userId,
                'order_id' => $orderId,
                'target_confirm_status' => $targetConfirmStatus,
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }

        Log::info('product order confirm receipt success', array_merge($context, [
            'user_id' => $userId,
            'order_id' => $orderId,
            'target_confirm_status' => $targetConfirmStatus,
        ]));

        return $orderSnapshot;
    }

    public function confirmReceipt(int $orderId, int $targetConfirmStatus, array $context = []): array
    {
        if ($orderId <= 0) {
            throw new Exception('订单不存在');
        }
        if (!in_array($targetConfirmStatus, [2, 3], true)) {
            throw new Exception('confirm_status 参数有误');
        }

        $operatorType = trim((string)($context['operator_type'] ?? 'system'));
        $operatorId = (int)($context['operator_id'] ?? 0);
        $orderSnapshot = [];

        Db::startTrans();
        try {
            $order = Order::where('id', $orderId)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new Exception('订单不存在');
            }

            if ((int)($order['status'] ?? 0) !== 2) {
                throw new Exception('订单当前状态不可确认');
            }

            $currentConfirmStatus = (int)($order['confirm_status'] ?? 0);
            if (in_array($currentConfirmStatus, [2, 3], true)) {
                throw new Exception('订单已处理，请勿重复提交');
            }

            if ($targetConfirmStatus === 2) {
                $user = UserModel::where('id', (int)($order['uid'] ?? 0))->lock(true)->find();
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                $this->deductFrozenForCompletedOrder($user, $order, $operatorType, $operatorId, $targetConfirmStatus, $currentConfirmStatus, $context);
            }

            $order->confirm_status = $targetConfirmStatus;
            if ($order->save() === false) {
                throw new Exception('订单保存失败');
            }

            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::warning('product order confirm receipt failed', array_merge($context, [
                'order_id' => $orderId,
                'target_confirm_status' => $targetConfirmStatus,
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }

        Log::info('product order confirm receipt success', array_merge($context, [
            'order_id' => $orderId,
            'target_confirm_status' => $targetConfirmStatus,
        ]));

        return $orderSnapshot;
    }

    private function deductFrozenForCompletedOrder(UserModel $user, Order $order, string $operatorType, int $operatorId, int $targetConfirmStatus, int $currentConfirmStatus, array $context = []): void
    {
        (new UserFundLedgerService())->changeLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            -1 * (float)order_actual_pay_usdt($order),
            [
                'biz_type' => 'product_order',
                'biz_id' => (int)($order['id'] ?? 0),
                'biz_no' => (string)($order['order_number'] ?? ''),
                'order_number' => (string)($order['order_number'] ?? ''),
                'change_type' => 'product_order_deduct',
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'status' => 'done',
                'request_no' => 'product_order_deduct:' . (string)($order['order_number'] ?? ''),
                'remark' => 'product order deduct frozen amount on completion',
                'idempotent' => true,
                'extra' => [
                    'source' => (string)($context['source'] ?? 'product_order_confirm'),
                    'target_confirm_status' => $targetConfirmStatus,
                    'previous_confirm_status' => $currentConfirmStatus,
                ],
            ]
        );
    }
}
