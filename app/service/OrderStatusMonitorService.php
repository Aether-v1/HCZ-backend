<?php
namespace app\service;

use think\facade\Db;
use think\facade\Log;
use app\service\TelegramService;
use app\service\OrderService;

class OrderStatusMonitorService
{
    // 扫描最近N分钟内的订单（避免全表扫描）
    const SCAN_MINUTES = 60;
    // 每次处理的最大订单数
    const BATCH_LIMIT = 50;

    /**
     * 定时任务入口：检查并通知状态变更的订单
     */
        public function checkAndNotifyChangedOrders()
        {
            Log::info('===== 开始执行订单状态变更检测任务（最终版） =====');
    
            try {
                // 1. 筛选条件：未通知 + 最近60分钟更新 + 充值业务 + 有效状态
                $timeThreshold = date('Y-m-d H:i:s', strtotime("-".self::SCAN_MINUTES." minutes"));
                $changedOrders = Db::name('cz_order')  // 明确使用cz_order表
                    ->where('notify_status', 0)        // 未通知
                    ->where('update_time', '>=', $timeThreshold)  // 最近更新
                    ->where('type', 1)                 // 只处理充值业务
                    ->where('status', 'in', [0, 1, 2, 3])  // 有效充值状态
                    ->field('id, uid, order_number, amount_money, status, update_time, create_time')
                    ->limit(self::BATCH_LIMIT)
                    ->select()
                    ->toArray();
    
                Log::info('订单扫描结果', [
                    '时间范围' => $timeThreshold . ' 至今',
                    '待处理订单数' => count($changedOrders)
                ]);
    
                if (empty($changedOrders)) {
                    Log::info('===== 无待处理订单，任务结束 =====');
                    return ['code' => 1, 'msg' => '无状态变更的订单'];
                }
    
                // 2. 处理订单通知
                $telegramService = new TelegramService();
                $processedIds = [];
    
                foreach ($changedOrders as $order) {
                    $orderId = $order['id'];
                    $currentStatus = $order['status'];
                    
                    Log::info('开始处理订单', [
                        'order_id' => $orderId,
                        'order_number' => $order['order_number'],
                        '当前状态' => $currentStatus . '(' . OrderService::getStatusText($currentStatus) . ')',
                        '用户ID' => $order['uid']
                    ]);
    
                    // 检查状态是否真的变更
                    if ($this->hasStatusChanged($orderId, $currentStatus)) {
                        // 发送通知
                        $notifyResult = $telegramService->sendOrderStatusNotification(
                            $order['uid'],
                            $order['order_number'],
                            $order['amount_money'] . ' 元',
                            OrderService::getStatusText($currentStatus),
                            $order['update_time']
                        );
    
                        if ($notifyResult) {
                            $processedIds[] = $orderId;
                            $this->logNotifyResult($orderId, $order['uid'], $currentStatus, 1);
                            Log::info('订单通知发送成功', ['order_id' => $orderId]);
                        } else {
                            $this->logNotifyResult($orderId, $order['uid'], $currentStatus, 0, '通知发送失败');
                            Log::warning('订单通知发送失败', ['order_id' => $orderId]);
                        }
                    } else {
                        $processedIds[] = $orderId;
                        Log::info('订单状态未变更，无需通知', ['order_id' => $orderId]);
                    }
                }
    
                // 3. 标记已处理的订单
                if (!empty($processedIds)) {
                    Db::name('cz_order')
                        ->where('id', 'in', $processedIds)
                        ->update(['notify_status' => 1]);
                    Log::info('已标记 ' . count($processedIds) . ' 个订单为已通知');
                }
    
                Log::info('===== 订单检测任务完成 =====');
                return ['code' => 1, 'msg' => '处理完成', 'data' => count($processedIds)];
    
            } catch (\Exception $e) {
                Log::error('订单检测任务异常', [
                    '错误' => $e->getMessage(),
                    '堆栈' => $e->getTraceAsString()
                ]);
                return ['code' => 0, 'msg' => '任务失败：' . $e->getMessage()];
            }
        }

    /**
     * 检查订单状态是否真的发生了有效变更
     */
        private function hasStatusChanged($orderId, $currentStatus)
        {
            $lastNotifyStatus = $this->getLastNotifiedStatus($orderId);
            
            Log::info('订单状态对比', [
                'order_id' => $orderId,
                '当前状态' => $currentStatus . '(' . OrderService::getStatusText($currentStatus) . ')',
                '上次通知状态' => $lastNotifyStatus . '(' . OrderService::getStatusText($lastNotifyStatus) . ')'
            ]);
            
            // 从未通知过，且当前状态不是初始状态（0-待充值）
            if ($lastNotifyStatus === null) {
                return $currentStatus != OrderService::STATUS_PENDING;
            }
            
            // 当前状态与上次通知状态不同
            return $currentStatus != $lastNotifyStatus;
        }

    /**
     * 获取订单的状态历史记录
     */
    private function getOrderStatusHistory($orderId)
    {
        // 方案A：如果存在订单操作日志表（推荐方案）
        if ($this->tableExists('order_log')) {
            $lastLog = Db::name('order_log')
                ->where('order_id', $orderId)
                ->order('id desc')
                ->field('status')
                ->find();

            return [
                'previous_status' => $lastLog['status'] ?? null,
                'last_notify_status' => $this->getLastNotifiedStatus($orderId)
            ];
        }

        // 方案B：如果没有日志表，使用订单表自身的信息（精度较低）
        $order = Db::name('order')
            ->where('id', $orderId)
            ->field('status, create_time')
            ->find();

        // 尝试从订单表中获取上次状态（如果有多个状态更新记录）
        // 注意：此方案只能检测到最后一次状态，无法获取完整历史
        return [
            'previous_status' => $order['status'] ?? null,
            'last_notify_status' => $this->getLastNotifiedStatus($orderId)
        ];
    }

    /**
     * 获取订单上次通知的状态
     */
    private function getLastNotifiedStatus($orderId)
    {
        if ($this->tableExists('order_notify_log')) {
            return Db::name('order_notify_log')
                ->where('order_id', $orderId)
                ->order('id desc')
                ->value('status');
        }
        return null;
    }

    /**
     * 记录通知结果到日志表
     */
    private function logNotifyResult($orderId, $userId, $status, $result, $errorMsg = '')
    {
        if (!$this->tableExists('order_notify_log')) {
            return false;
        }

        return Db::name('order_notify_log')->insert([
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => $status,
            'status_text' => OrderService::getStatusText($status),
            'notify_time' => date('Y-m-d H:i:s'),
            'notify_result' => $result,
            'error_msg' => $errorMsg
        ]);
    }

    /**
     * 检查数据表是否存在
     */
    private function tableExists($tableName)
    {
        try {
            $prefix = Db::getConfig('prefix');
            $table = $prefix . $tableName;
            return Db::query("SHOW TABLES LIKE '{$table}'") ? true : false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
    