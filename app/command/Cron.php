<?php
declare(strict_types=1);

namespace app\command;

use app\model\Order;
use app\model\Recharge;
use app\model\TransactionOrder;
use app\service\ProductOrderService;
use app\service\RefundIntentService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Log;

class Cron extends Command
{
    protected function configure(): void
    {
        $this->setName('cron')
            ->addOption('data', null, Option::VALUE_NONE, '执行订单/充值数据维护')
            ->addOption('refund', null, Option::VALUE_NONE, '执行积分返还意图恢复（INFO-022-A Outbox Worker）')
            ->setDescription('系统数据处理');
    }

    protected function execute(Input $input, Output $output): void
    {
        // INFO-022-A: 积分返还意图恢复 Worker（Outbox crash recovery）
        if ($input->getOption('refund')) {
            try {
                $result = $this->processRefundIntents();
                $output->writeln(sprintf(
                    '返还意图处理完成: 处理 %d 条, 成功 %d 条, 失败 %d 条, 回收 stale lease %d 条',
                    $result['processed'],
                    $result['succeeded'],
                    $result['failed'],
                    $result['reclaimed']
                ));
            } catch (\Throwable $e) {
                $output->writeln('返还意图处理失败: ' . $e->getMessage());
                Log::error('cron_refund_intents_failed', ['message' => $e->getMessage()]);
            }
            return;
        }

        if (!$input->getOption('data')) {
            $output->writeln('请使用 --data 执行数据维护任务，或 --refund 执行积分返还意图恢复');
            return;
        }

        try {
            $summary = $this->processDataTasks();
            $output->writeln(sprintf(
                '执行完成: 交易订单取消 %d 条, 充值订单取消 %d 条, 订单自动确认 %d 条, 历史订单归档 %d 条',
                $summary['transaction_orders_cancelled'],
                $summary['recharges_cancelled'],
                $summary['orders_auto_confirmed'],
                $summary['orders_archived']
            ));
        } catch (\Throwable $e) {
            $output->writeln('执行失败: ' . $e->getMessage());
        }
    }

    private function processDataTasks(): array
    {
        $now = time();
        $currentTime = date('Y-m-d H:i:s', $now);

        $transactionOrdersCancelled = (int) TransactionOrder::where('status', TransactionOrder::STATUS_PENDING)
            ->where('create_time', '<', date('Y-m-d H:i:s', $now - 20 * 60))
            ->update([
                'status' => TransactionOrder::STATUS_CANCELLED,
                'cancel_time' => $currentTime,
            ]);

        // Pre-R1 Batch A: status=2 语义变更为 EXPIRED（支付窗口超时，但 Provider 仍可能已收款）
        // 迟到付款经过签名+金额验证后仍可通过 RechargeSettlementService 入账。
        // cancel_source='cron' 区分 Cron 超时与用户主动取消（后者不自动入账）。
        $rechargesCancelled = (int) Recharge::where('status', Recharge::STATUS_PENDING)
            ->where('create_time', '<', date('Y-m-d H:i:s', $now - 20 * 60))
            ->update([
                'status' => Recharge::STATUS_EXPIRED,
                'cancel_time' => $currentTime,
                'cancel_source' => Recharge::CANCEL_SOURCE_CRON,
            ]);

        $ordersAutoConfirmed = 0;
        $autoConfirmOrders = Order::where('status', \app\model\Order::STATUS_COMPLETED)
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
                // F12 修复：禁止空 catch。
                // 单笔自动确认失败不得阻塞整个 Cron；订单保持 status=2 / confirm_status=1，
                // 下次 Cron 会继续重试（confirmReceipt 内部事务回滚 + 账本幂等，不会重复扣款/重复释放冻结）。
                Log::error('cron_auto_confirm_failed', [
                    'order_id' => (int)($autoConfirmOrder['id'] ?? 0),
                    'order_no' => (string)($autoConfirmOrder['order_number'] ?? ''),
                    'uid' => (int)($autoConfirmOrder['uid'] ?? 0),
                    'message' => $e->getMessage(),
                    'time' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // F3 修复：禁止物理删除历史订单（订单是财务对账/售后/退款/审计的原始记录）。
        // 改为“归档”：仅对已完成（status=2）且超过保留期的订单打 archived 标记，记录保留不删除。
        // 需要 cz_order 新增列 archived / archived_time（见 secure-keys/order_archive_schema.sql）。
        // 归档列未就绪时跳过并告警，绝不回退为物理删除。
        $ordersArchived = 0;
        try {
            $ordersArchived = (int) Order::where('complete_time', '<', date('Y-m-d H:i:s', $now - 30 * 24 * 60 * 60))
                ->where('status', 2)
                ->where('archived', 0)
                ->update([
                    'archived' => 1,
                    'archived_time' => $currentTime,
                ]);
        } catch (\Throwable $e) {
            Log::error('cron_order_archive_failed', [
                'message' => $e->getMessage(),
                'hint' => 'cz_order 缺少 archived/archived_time 列，请先执行 secure-keys/order_archive_schema.sql',
                'time' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'transaction_orders_cancelled' => $transactionOrdersCancelled,
            'recharges_cancelled' => $rechargesCancelled,
            'orders_auto_confirmed' => $ordersAutoConfirmed,
            'orders_archived' => $ordersArchived,
        ];
    }

    /**
     * INFO-022-A: 处理待恢复的积分返还意图（Outbox Worker）
     *
     * 流程：回收 stale lease → 原子 claim pending intent → 调用 PointsService::addPoints(refundKey)
     * 同一 refund_key 最多成功增加一次积分（refund_intent.uk_refund_key + points_record.uk_refund_key 双层唯一约束）。
     *
     * @return array{processed:int, succeeded:int, failed:int, reclaimed:int}
     */
    private function processRefundIntents(): array
    {
        $intentService = new RefundIntentService();
        return $intentService->processPendingIntents(RefundIntentService::BATCH_SIZE);
    }
}
