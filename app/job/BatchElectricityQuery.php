<?php
namespace app\job;

use think\queue\Job;
use app\service\TelegramService;
use app\service\PointsService;
use app\common\library\TelegramHelper;
use think\facade\Log;
use think\facade\Config;

class BatchElectricityQuery
{
    public function fire(Job $job, $data)
    {
        try {
            $telegramService = new TelegramService();
            $chatId = $data['chat_id'];
            $userId = $data['user_id'];
            $accountNumbers = $data['account_numbers'];
            $invalidNumbers = $data['invalid_numbers'];
            $checkPoints = $data['check_points'];
            
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
            
            // 为查询失败的账号返还积分
            if (!empty($failedAccounts)) {
                $pointsService = new PointsService();
                $refundPoints = count($failedAccounts) * $checkPoints;
                $pointsService->adjustPoints(
                    $userId, 
                    $refundPoints, 
                    "批量电费查询失败返还积分，共" . count($failedAccounts) . "个账号"
                );
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
                
                // 返还所有积分
                $pointsService = new PointsService();
                $pointsService->adjustPoints(
                    $data['user_id'], 
                    $data['total_points'], 
                    "批量电费查询任务失败，全额返还积分"
                );
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