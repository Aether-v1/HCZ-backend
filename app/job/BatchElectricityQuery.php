<?php
namespace app\job;

use think\queue\Job;
use app\service\TelegramService;
use app\service\PointsService;
use app\service\RefundIntentService;
use app\common\library\TelegramHelper;
use think\facade\Log;
use think\facade\Config;
use think\facade\Cache;

class BatchElectricityQuery
{
    /** 返还幂等键 TTL（秒），与 QueryHandler batch_trace_ttl 默认值一致 */
    const REFUND_KEY_TTL = 172800;

    /**
     * 积分返还幂等执行：同一 refund identity 最多成功一次 addPoints。
     * 部分返还与最终全额返还共享 event='account' 键，确保每户在整个 Job 生命周期内最多返还一次。
     * 非空 trace_id → 构造 refund_key → 进入 DB Outbox（RefundIntent），crash 后 Worker 可恢复。
     * 空 trace_id 无法安全构造确定性幂等键，fail-closed 拒绝静默直接返还（F14/F15）。
     * @return array{code:int,msg:string}
     */
    private function refundPointsOnce($userId, $points, $traceId, $refundEvent, $businessItem, $reason)
    {
        $points = (int)$points;
        if ($points <= 0) {
            return ['code' => 1, 'msg' => '无需返还积分'];
        }

        $traceId = (string)$traceId;

        // F15: 空 trace_id 无法建立幂等身份。不能静默直接 addPoints（会绕过 Outbox，
        // 且在 job 重投时可能重复返还）。fail-closed：明确失败 + critical 日志，由运营介入。
        if ($traceId === '') {
            Log::critical('批量电费查询返还缺少trace_id，拒绝静默直接返还（需人工处理）', [
                'user_id' => $userId,
                'points' => $points,
                'refund_event' => $refundEvent,
                'business_item' => (string)$businessItem,
            ]);
            return ['code' => 0, 'msg' => '积分返还失败：缺少幂等身份(trace_id)，需人工处理'];
        }

        // INFO-022-A: 使用 DB Outbox 持久化返还义务，crash 后 Worker 可恢复
        // partial 与 final 共享同一 account key 语义（INFO-018-A 已验证）
        $key = "tg:batch:refund:{$traceId}:{$refundEvent}";
        $item = (string)$businessItem;
        if ($item !== '') {
            $key .= ":{$item}";
        }

        try {
            $intentService = new RefundIntentService();
            return $intentService->createAndProcess($key, (int)$userId, $points, $reason);
        } catch (\Throwable $e) {
            Log::critical('批量电费积分返还 Outbox 处理失败', [
                'user_id' => $userId,
                'trace_id' => $traceId,
                'refund_event' => $refundEvent,
                'points' => $points,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return ['code' => 0, 'msg' => '积分返还失败: ' . $e->getMessage()];
        }
    }

    public function fire(Job $job, $data)
    {
        try {
            $telegramService = new TelegramService();
            $chatId = $data['chat_id'];
            $userId = $data['user_id'];
            $accountNumbers = $data['account_numbers'];
            $invalidNumbers = $data['invalid_numbers'];
            $checkPoints = $data['check_points'];
            // F14: 在查询循环前保存 Job 原始 trace_id，避免后续 $data 被查询结果遮蔽后丢失
            $jobTraceId = $data['trace_id'] ?? '';
            
            // 发送开始处理通知
            $telegramService->sendBasicReply($chatId, "开始处理您的批量电费查询请求，共" . count($accountNumbers) . "个户号...");
            
            $results = [];
            $failedAccounts = [];
            
            // 逐个查询
            foreach ($accountNumbers as $account) {
                $result = TelegramHelper::queryElectricityBalance($account);
                
                if ($result['code'] == 200 && !empty($result['data'])) {
                    $results[] = [
                        'account' => $account,
                        'success' => true,
                        'data' => $result['data']
                    ];
                } else {
                    $results[] = [
                        'account' => $account,
                        'success' => false,
                        'message' => $result['msg'] ?? '查询失败'
                    ];
                    $failedAccounts[] = $account;
                }
            }
            
            // 为查询失败的账号逐户返还积分（幂等：每户最多返还一次）
            if (!empty($failedAccounts)) {
                foreach ($failedAccounts as $account) {
                    $this->refundPointsOnce(
                        $userId, 
                        $checkPoints, 
                        $jobTraceId,
                        'account',
                        $account,
                        "批量电费查询失败返还积分，账号{$account}"
                    );
                }
            }
            
            // 构建结果消息
            $message = "📊 批量电费查询结果：\n\n";
            
            // 处理无效户号
            if (!empty($invalidNumbers)) {
                $message .= "❌ 无效户号：\n" . implode("\n", $invalidNumbers) . "\n\n";
            }
            
            // 处理成功结果
            $successCount = 0;
            foreach ($results as $item) {
                $maskedAccount = TelegramHelper::maskElectricityAccount($item['account']);
                
                if ($item['success']) {
                    $successCount++;
                    $data = $item['data'];
                    $message .= "✅ {$maskedAccount}\n";
                    $message .= "   总余额：{$data['balance']}\n";
                    $message .= "   可用余额：{$data['availableBalance']}\n";
                    if ($data['owedBalance'] > 0) {
                        $message .= "   ⚠️欠费：{$data['owedBalance']}\n";
                    }
                    $message .= "\n";
                } else {
                    $message .= "❌ {$maskedAccount}：{$item['message']}\n\n";
                }
            }
            
            // 处理积分返还信息
            if (!empty($failedAccounts)) {
                $message .= "💎 已为" . count($failedAccounts) . "个查询失败的户号返还积分，共" . (count($failedAccounts) * $checkPoints) . "积分\n";
            }
            
            $message .= "📝 共查询" . count($accountNumbers) . "个户号，成功{$successCount}个，失败" . count($failedAccounts) . "个";
            
            // 发送结果
            $telegramService->sendBasicReply($chatId, $message);
            
            // 任务执行成功，删除任务
            $job->delete();
            return true;
            
        } catch (\Exception $e) {
            Log::error('批量电费查询任务失败', $this->buildThrowableLogContext($e, [
                'task_id' => $job->getJobId(),
                'step' => 'fire',
                'user_id' => $data['user_id'] ?? null,
                'tg_user_id' => $this->hashLogIdentifier($data['tg_user_id'] ?? null),
                'chat_id' => $this->hashLogIdentifier($data['chat_id'] ?? null),
                'account_count' => isset($data['account_numbers']) && is_array($data['account_numbers']) ? count($data['account_numbers']) : 0,
                'invalid_count' => isset($data['invalid_numbers']) && is_array($data['invalid_numbers']) ? count($data['invalid_numbers']) : 0,
                'data_keys' => is_array($data) ? array_keys($data) : [],
                'data_count' => is_array($data) ? count($data) : 0,
            ]));
            
            // 尝试再次执行任务
            if ($job->attempts() < 3) {
                $job->release(60); // 60秒后重试
            } else {
                // 多次失败后删除任务
                $job->delete();
                
                // 通知用户
                $telegramService = new TelegramService();
                $telegramService->sendBasicReply($data['chat_id'], "批量电费查询处理失败，请稍后重试。已为您返还所有积分。");
                
                // 逐户补返所有未返还账号（与部分返还共享 account 幂等键，每户最多一次）
                $allAccounts = isset($data['account_numbers']) && is_array($data['account_numbers']) ? $data['account_numbers'] : [];
                $checkPts = isset($data['check_points']) ? (int)$data['check_points'] : 0;
                foreach ($allAccounts as $account) {
                    $this->refundPointsOnce(
                        $data['user_id'], 
                        $checkPts, 
                        $jobTraceId,
                        'account',
                        $account,
                        "批量电费查询任务失败全额返还积分，账号{$account}"
                    );
                }
            }
            
            return false;
        }
    }

    private function buildThrowableLogContext(\Throwable $e, array $context = []): array
    {
        $logContext = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        foreach ($context as $key => $value) {
            if ($value !== null) {
                $logContext[$key] = $value;
            }
        }

        if ((bool) Config::get('app.app_debug', false)) {
            $logContext['trace'] = $e->getTraceAsString();
        }

        return $logContext;
    }

    private function hashLogIdentifier($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', (string) $value), 0, 12);
    }
}