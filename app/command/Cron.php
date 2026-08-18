<?php
declare(strict_types=1);

namespace app\command;

use app\model\Order;
use app\model\Recharge;
use app\model\TransactionOrder;
use app\service\ProductOrderService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class Cron extends Command
{
    protected function configure(): void
    {
        $this->setName('cron')
            ->addOption('data', null, Option::VALUE_NONE, '执行订单/充值数据维护')
            ->setDescription('系统数据处理');
    }

    protected function execute(Input $input, Output $output): void
    {
        if (!$input->getOption('data')) {
            $output->writeln('请使用 --data 执行数据维护任务');
            return;
        }

        try {
            $summary = $this->processDataTasks();
            $output->writeln(sprintf(
                '执行完成: 交易订单取消 %d 条, 充值订单取消 %d 条, 订单自动确认 %d 条, 30 天前订单清理 %d 条',
                $summary['transaction_orders_cancelled'],
                $summary['recharges_cancelled'],
                $summary['orders_auto_confirmed'],
                $summary['orders_deleted']
            ));
        } catch (\Throwable $e) {
            $output->writeln('执行失败: ' . $e->getMessage());
        }
    }

    private function processDataTasks(): array
    {
        $now = time();
        $currentTime = date('Y-m-d H:i:s', $now);

        $transactionOrdersCancelled = (int) TransactionOrder::where('status', 0)
            ->where('create_time', '<', date('Y-m-d H:i:s', $now - 20 * 60))
            ->update([
                'status' => 2,
                'cancel_time' => $currentTime,
            ]);

        $rechargesCancelled = (int) Recharge::where('status', 0)
            ->where('create_time', '<', date('Y-m-d H:i:s', $now - 20 * 60))
            ->update([
                'status' => 2,
                'cancel_time' => $currentTime,
            ]);

        $ordersAutoConfirmed = 0;
        $autoConfirmOrders = Order::where('status', 2)
            ->where('confirm_status', 1)
            ->where('complete_time', '<', date('Y-m-d H:i:s', $now - 10 * 60))
            ->select();
        $productOrderService = new ProductOrderService();
        foreach ($autoConfirmOrders as $autoConfirmOrder) {
            try {
                $productOrderService->confirmReceipt((int)($autoConfirmOrder['id'] ?? 0), 2, [
                    'source' => 'cron_auto_confirm',
                    'operator_type' => 'system',
                    'operator_id' => 0,
                ]);
                $ordersAutoConfirmed++;
            } catch (\Throwable $e) {
            }
        }

        $ordersDeleted = (int) Order::where('complete_time', '<', date('Y-m-d H:i:s', $now - 30 * 24 * 60 * 60))
            ->delete();

        return [
            'transaction_orders_cancelled' => $transactionOrdersCancelled,
            'recharges_cancelled' => $rechargesCancelled,
            'orders_auto_confirmed' => $ordersAutoConfirmed,
            'orders_deleted' => $ordersDeleted,
        ];
    }
}
