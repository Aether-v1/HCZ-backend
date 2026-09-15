<?php
namespace app\service\telegram;

use app\service\TelegramService;
use think\facade\Log;
use think\facade\Cache;

class TimerManager
{
    /** @var TelegramService 主服务实例 */
    private $telegramService;
    
    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }
    
    /**
     * 处理定时消息设置命令
     */
    public function handleSetTimerCommand($chatId, $params)
    {
        if (count($params) < 2) {
            $this->telegramService->sendBasicReply($chatId, "用法：\n" .
                "1. 一次性定时：/settimer [分钟] [消息内容]\n" .
                "2. 每日定时：/settimer daily [时间(HH:MM)] [消息内容]");
            return;
        }
        
        // 生成唯一任务ID
        $timerId = uniqid('timer_');
        $message = '';
        $timerData = [
            'id' => $timerId,
            'chat_id' => $chatId,
            'created_at' => time(),
            'status' => 'active'
        ];
        
        // 处理每日定时任务
        if (strtolower($params[0]) === 'daily') {
            if (count($params) < 3) {
                $this->telegramService->sendBasicReply($chatId, "每日定时用法：/settimer daily [时间(HH:MM)] [消息内容]");
                return;
            }
            
            $timeStr = $params[1];
            if (!preg_match('/^\d{2}:\d{2}$/', $timeStr)) {
                $this->telegramService->sendBasicReply($chatId, "时间格式错误，请使用HH:MM格式，例如：14:30");
                return;
            }
            
            // 计算首次执行时间
            list($hours, $minutes) = explode(':', $timeStr);
            $executeTime = strtotime(date('Y-m-d ' . $hours . ':' . $minutes));
            
            // 如果时间已过，设置为明天
            if ($executeTime <= time()) {
                $executeTime += 86400; // 加一天
            }
            
            $timerData['type'] = $this->telegramService->getConstant('timer_type_daily', 'daily');
            $timerData['time'] = $timeStr; // 保存原始时间字符串
            $timerData['execute_time'] = $executeTime;
            $timerData['message'] = implode(' ', array_slice($params, 2));
            
            $message = "⏰ 已设置每日定时消息，将在每天 {$timeStr} 发送：\n{$timerData['message']}";
        } 
        // 处理一次性定时任务
        else {
            $minutes = (int)$params[0];
            if ($minutes <= 0) {
                $this->telegramService->sendBasicReply($chatId, "分钟数必须大于0");
                return;
            }
            
            $timerData['type'] = $this->telegramService->getConstant('timer_type_once', 'once');
            $timerData['execute_time'] = time() + ($minutes * 60);
            $timerData['message'] = implode(' ', array_slice($params, 1));
            
            $message = "⏰ 已设置定时消息，将在 {$minutes} 分钟后发送：\n{$timerData['message']}";
        }
        
        // 保存定时任务
        $this->saveTimer($timerData);
        
        $this->telegramService->sendBasicReply($chatId, $message . "\n任务ID: {$timerId}（用于删除任务）");
    }
    
    /**
     * 查看所有定时任务
     */
    public function handleListTimersCommand($chatId)
    {
        $timers = $this->getAllTimers($chatId, true);
        
        if (empty($timers)) {
            $this->telegramService->sendBasicReply($chatId, "当前没有定时任务");
            return;
        }
        
        $message = "⏰ 定时任务列表：\n\n";
        foreach ($timers as $timer) {
            $typeText = $timer['type'] === $this->telegramService->getConstant('timer_type_daily', 'daily') ? "每日" : "一次性";
            $timeText = $timer['type'] === $this->telegramService->getConstant('timer_type_daily', 'daily') 
                ? $timer['time'] 
                : date('Y-m-d H:i', $timer['execute_time']);
            
            $statusText = ($timer['status'] ?? 'active') === 'failed' ? ' [failed]' : '';
            $message .= "ID: {$timer['id']}{$statusText}\n";
            $message .= "类型: {$typeText}\n";
            $message .= "时间: {$timeText}\n";
            $message .= "消息: {$timer['message']}\n\n";
        }
        
        $this->telegramService->sendBasicReply($chatId, $message . "使用 /deltimer [任务ID] 删除指定任务");
    }
    
    /**
     * 删除定时任务
     */
    public function handleDeleteTimerCommand($chatId, $params)
    {
        if (count($params) < 1) {
            $this->telegramService->sendBasicReply($chatId, "用法：/deltimer [任务ID]");
            return;
        }
        
        $timerId = $params[0];
        $result = $this->deleteTimer($chatId, $timerId);
        
        if ($result) {
            $this->telegramService->sendBasicReply($chatId, "✅ 任务 {$timerId} 已成功删除");
        } else {
            $this->telegramService->sendBasicReply($chatId, "❌ 未找到ID为 {$timerId} 的任务");
        }
    }
    
    /**
     * 处理定时消息发送（B03-T：per-timer Redis claim + send 返回值检查 + retry/backoff）
     *
     * Delivery Guarantee: AT-LEAST-ONCE（Redis claim 仅并发控制，不保证 exactly-once）
     */
    public function processTimers()
    {
        try {
            $timers = $this->getAllTimers();
            $currentTime = time();
            $redis = Cache::store('redis')->handler();

            foreach ($timers as $timer) {
                if ($timer['execute_time'] > $currentTime) {
                    continue; // 未到期
                }
                // 失败重试等待：未到 next_retry_at 则跳过（避免高频重试）
                if (isset($timer['next_retry_at']) && $timer['next_retry_at'] > $currentTime) {
                    continue;
                }

                $timerId = $timer['id'];

                // 1. per-timer Redis claim（SET key owner_token NX EX 120）；claim 失败=他实例持有，跳过
                $ownerToken = $this->acquireClaim($timerId, $redis);
                if ($ownerToken === null) {
                    continue;
                }

                try {
                    // 2. 发送并检查返回值（bool）
                    $sent = $this->telegramService->sendBasicReply($timer['chat_id'], $timer['message']);

                    if ($sent) {
                        // 成功：commit timer state
                        if ($timer['type'] === $this->telegramService->getConstant('timer_type_once', 'once')) {
                            $this->deleteTimer($timer['chat_id'], $timerId);
                        } else if ($timer['type'] === $this->telegramService->getConstant('timer_type_daily', 'daily')) {
                            $timer['execute_time'] = strtotime('+1 day', $timer['execute_time']);
                            // 成功推进时刷新 TTL（daily 长期有效，不因原始 30 天 TTL 消失）
                            Cache::store('redis')->set(
                                $this->telegramService->getCachePrefix() . "timer:{$timerId}",
                                $timer,
                                30 * 86400
                            );
                        }
                    } else {
                        // 失败：不删除、不推进，进入 retry/backoff
                        $this->recordSendFailure($timer, $redis);
                    }
                } finally {
                    // 3. 释放 claim：Lua compare-and-delete（仅 owner 可释放；crash 时由 TTL 兜底）
                    $this->releaseClaim($timerId, $ownerToken, $redis);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('处理定时消息失败', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 获取 per-timer claim：SET key owner_token NX EX 120
     *
     * @return string|null 成功返回 owner_token；他实例持有 claim 时返回 null
     */
    private function acquireClaim($timerId, $redis)
    {
        $claimKey = $this->telegramService->getCachePrefix() . "timer_claim:{$timerId}";
        $ownerToken = uniqid('tok_', true) . '_' . bin2hex(random_bytes(8));
        $ok = $redis->rawCommand('SET', $claimKey, $ownerToken, 'NX', 'EX', 120);
        return $ok ? $ownerToken : null;
    }

    /**
     * 释放 claim：Lua GET -> compare owner_token -> DEL（原子，防止误释放他实例 claim）
     */
    private function releaseClaim($timerId, $ownerToken, $redis)
    {
        $claimKey = $this->telegramService->getCachePrefix() . "timer_claim:{$timerId}";
        $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        try {
            $redis->eval($lua, [$claimKey, $ownerToken], 1);
        } catch (\Throwable $e) {
            // 释放失败由 claim TTL（120s）自动兜底
            Log::warning('释放 timer claim 失败（TTL 将自动过期兜底）', [
                'timer_id' => $timerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 记录发送失败并安排重试（attempts / last_attempt / next_retry_at / status）
     * backoff：attempt1 -> +60s；attempt2 -> +300s；attempt3 -> +3600s；max=3 -> status=failed（不无限重试）
     */
    private function recordSendFailure($timer, $redis)
    {
        $timerId = $timer['id'];
        $attempts = (int)($timer['attempts'] ?? 0) + 1;
        $timer['attempts'] = $attempts;
        $timer['last_attempt'] = time();

        $backoffMap = [1 => 60, 2 => 300, 3 => 3600];
        $maxAttempts = 3;

        if ($attempts >= $maxAttempts) {
            $timer['status'] = 'failed'; // 不无限重试；failed timer 保留数据供查看/删除
            $timer['next_retry_at'] = 0;
        } else {
            $timer['status'] = 'active';
            $timer['next_retry_at'] = time() + ($backoffMap[$attempts] ?? 3600);
        }

        Cache::store('redis')->set(
            $this->telegramService->getCachePrefix() . "timer:{$timerId}",
            $timer,
            30 * 86400
        );

        Log::warning('定时消息发送失败，已安排重试', [
            'timer_id' => $timerId,
            'attempts' => $attempts,
            'next_retry_at' => $timer['next_retry_at'],
            'status' => $timer['status'],
        ]);
    }    
    /**
     * 保存定时任务
     */
    private function saveTimer($timerData)
    {
        // 保存单个任务详情
        $timerKey = $this->telegramService->getCachePrefix() . "timer:{$timerData['id']}";
        Cache::store('redis')->set($timerKey, $timerData, 30 * 86400); // 保存30天
        
        // 添加到任务列表
        $listKey = $this->telegramService->getCachePrefix() . $this->telegramService->getConstant('timer_list_key', 'tg_timers_list');
        $timers = Cache::store('redis')->get($listKey, []);
        $timers[] = $timerData['id'];
        $timers = array_unique($timers); // 去重
        Cache::store('redis')->set($listKey, $timers, 30 * 86400);
    }
    
    /**
     * 获取所有定时任务
     */
    private function getAllTimers($chatId = null, $includeFailed = false)
    {
        $listKey = $this->telegramService->getCachePrefix() . $this->telegramService->getConstant('timer_list_key', 'tg_timers_list');
        $timerIds = Cache::store('redis')->get($listKey, []);
        $timers = [];
        
        foreach ($timerIds as $timerId) {
            $timerKey = $this->telegramService->getCachePrefix() . "timer:{$timerId}";
            $timer = Cache::store('redis')->get($timerKey);
            
            $isActive = $timer && ($timer['status'] ?? '') === 'active';
            $isFailed = $includeFailed && $timer && ($timer['status'] ?? '') === 'failed';
            if ($isActive || $isFailed) {
                // 如果指定了chatId，只返回该聊天的任务
                if ($chatId === null || $timer['chat_id'] == $chatId) {
                    $timers[] = $timer;
                }
            }
        }
        
        // 按执行时间排序
        usort($timers, function($a, $b) {
            return $a['execute_time'] - $b['execute_time'];
        });
        
        return $timers;
    }
    
    /**
     * 删除定时任务
     */
    private function deleteTimer($chatId, $timerId)
    {
        $timerKey = $this->telegramService->getCachePrefix() . "timer:{$timerId}";
        $timer = Cache::store('redis')->get($timerKey);
        
        // 验证任务是否属于当前聊天
        if (!$timer || $timer['chat_id'] != $chatId) {
            return false;
        }
        
        // 删除任务详情
        Cache::store('redis')->delete($timerKey);
        
        // 从任务列表中移除
        $listKey = $this->telegramService->getCachePrefix() . $this->telegramService->getConstant('timer_list_key', 'tg_timers_list');
        $timers = Cache::store('redis')->get($listKey, []);
        $index = array_search($timerId, $timers);
        
        if ($index !== false) {
            array_splice($timers, $index, 1);
            Cache::store('redis')->set($listKey, $timers, 30 * 86400);
        }
        
        return true;
    }
}
    