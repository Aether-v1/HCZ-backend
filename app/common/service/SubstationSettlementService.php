<?php
declare (strict_types=1);

namespace app\common\service;

use app\model\Order;
use app\model\Substation;
use app\model\SubstationIncomeLog;
use think\Exception;
use think\facade\Db;

class SubstationSettlementService
{
    public static function settleCompletedOrder(int $orderId, int $operatorId = 0): void
    {
        Db::transaction(function () use ($orderId, $operatorId) {
            $order = Order::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new Exception('订单不存在');
            }
            if ((int)($order['status'] ?? 0) !== 2) {
                throw new Exception('订单未完成，不能结算分站收益');
            }
            if ((int)($order['substation_income_status'] ?? 0) === 1) {
                return;
            }

            $substationId = (int)($order['substation_id'] ?? 0);
            $amountUsdt = self::resolveOrderMarkupUsdt($order);
            if ($substationId <= 0 || $amountUsdt <= 0) {
                $order->substation_income_status = 1;
                $order->substation_income_time = date('Y-m-d H:i:s');
                $order->save();
                return;
            }

            $substation = Substation::where('id', $substationId)->lock(true)->find();
            if (!$substation) {
                throw new Exception('分站不存在');
            }

            $before = round((float)($substation['wallet_balance'] ?? 0), 2);
            $after = round($before + $amountUsdt, 2);
            $substation->wallet_balance = $after;
            $substation->wallet_total_income = round((float)($substation['wallet_total_income'] ?? 0) + $amountUsdt, 2);
            $substation->wallet_total_transferred = round((float)($substation['wallet_total_transferred'] ?? 0), 2);
            // 兼容旧后台列表展示
            $substation->income_balance = $after;
            $substation->income_total = round((float)($substation['income_total'] ?? 0) + $amountUsdt, 2);
            $substation->settled_income_total = round((float)($substation['settled_income_total'] ?? 0) + $amountUsdt, 2);
            $substation->update_time = date('Y-m-d H:i:s');
            if ($substation->save() === false) {
                throw new Exception('分站钱包入账失败');
            }

            $logExists = SubstationIncomeLog::where('scene', 'substation_wallet_income')
                ->where('order_number', (string)$order['order_number'])
                ->find();
            if (!$logExists) {
                $log = SubstationIncomeLog::create([
                    'substation_id' => $substationId,
                    'uid' => (int)$substation['uid'],
                    'order_id' => (int)$order['id'],
                    'order_number' => (string)$order['order_number'],
                    'product_id' => (int)($order['product_id'] ?? 0),
                    'tier_key' => (string)($order['tier_key'] ?? ''),
                    'scene' => 'substation_wallet_income',
                    'change_type' => 1,
                    'amount' => $amountUsdt,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'remark' => '订单完成，分站收益转入分站钱包',
                    'operator_id' => $operatorId,
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
                if (!$log) {
                    throw new Exception('分站钱包流水写入失败');
                }
            }

            $order->substation_income_status = 1;
            $order->substation_income_time = date('Y-m-d H:i:s');
            if ($order->save() === false) {
                throw new Exception('订单收益状态更新失败');
            }
        });
    }

    public static function resolveOrderMarkupUsdt($order): float
    {
        $markup = round((float)($order['substation_markup_amount'] ?? 0), 2);
        if ($markup <= 0) {
            return 0.0;
        }
        $rate = round((float)($order['rate'] ?? getConfig('rate') ?? 0), 6);
        if ($rate <= 0) {
            throw new Exception('订单汇率缺失，无法结算分站收益');
        }
        return round($markup / $rate, 2);
    }
}
