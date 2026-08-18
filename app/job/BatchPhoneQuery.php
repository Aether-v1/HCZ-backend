<?php
namespace app\job;

use think\queue\Job;
use app\service\PointsService;
use app\common\library\TelegramHelper;
use app\service\TelegramService;
use think\facade\Log;
use think\facade\Config;

class BatchPhoneQuery
{
    /**
     * 执行队列任务
     * @param Job $job
     * @param array $data 任务数据
     */
    public function fire(Job $job, $data)
    {
        try {
            Log::info('开始执行批量话费查询队列任务', [
                'task_id' => $job->getJobId(),
                'user_id' => $data['user_id'] ?? '未知',
                'tg_user_id' => $this->hashLogIdentifier($data['tg_user_id'] ?? null),
                'chat_id' => $this->hashLogIdentifier($data['chat_id'] ?? null),
                'phone_count' => isset($data['phone_numbers']) && is_array($data['phone_numbers']) ? count($data['phone_numbers']) : 0,
                'invalid_count' => isset($data['invalid_numbers']) && is_array($data['invalid_numbers']) ? count($data['invalid_numbers']) : 0,
                'created_at' => isset($data['created_at']) ? date('Y-m-d H:i:s', $data['created_at']) : '未知'
            ]);
            
            // 验证数据完整性
            if (!$this->validateJobData($data)) {
                Log::error('批量查询任务数据不完整，终止执行', [
                    'data_keys' => is_array($data) ? array_keys($data) : [],
                    'data_count' => is_array($data) ? count($data) : 0,
                    'phone_count' => isset($data['phone_numbers']) && is_array($data['phone_numbers']) ? count($data['phone_numbers']) : 0,
                ]);
                $job->delete();
                return;
            }
            
            // 执行查询
            $result = $this->processBatchQuery($data);
            
            if ($result) {
                Log::info('批量话费查询队列任务执行成功', [
                    'job_id' => $job->getJobId(),
                    'user_id' => $data['user_id']
                ]);
            } else {
                Log::error('批量话费查询队列任务执行失败', [
                    'job_id' => $job->getJobId(),
                    'user_id' => $data['user_id']
                ]);
            }
            
            // 删除任务
            $job->delete();
            
        } catch (\Exception $e) {
            Log::error('批量话费查询队列任务异常', $this->buildThrowableLogContext($e, array_merge([
                'task_id' => $job->getJobId(),
                'step' => 'fire',
            ], $this->buildTaskSummary(is_array($data) ? $data : []))));
            
            // 失败次数过多则删除任务，避免无限重试
            if ($job->attempts() > 3) {
                Log::error('批量查询任务达到最大重试次数，将被删除', [
                    'task_id' => $job->getJobId(),
                    'user_id' => $data['user_id'] ?? '未知'
                ]);
                $job->delete();
            }
        }
    }
    
    /**
     * 验证任务数据
     */
    private function validateJobData($data)
    {
        $requiredFields = [
            'chat_id', 'user_id', 'tg_user_id', 
            'phone_numbers', 'check_points', 'total_points'
        ];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                Log::error('批量查询任务缺少必要字段', ['field' => $field]);
                return false;
            }
        }
        
        if (!is_array($data['phone_numbers']) || empty($data['phone_numbers'])) {
            Log::error('批量查询任务手机号列表无效', [
                'phone_count' => is_array($data['phone_numbers']) ? count($data['phone_numbers']) : 0,
                'phone_numbers_type' => gettype($data['phone_numbers']),
            ]);
            return false;
        }
        
        // 验证积分参数有效性
        if (!is_numeric($data['check_points']) || (int)$data['check_points'] <= 0) {
            Log::error('批量查询任务积分参数无效', [
                'check_points' => $data['check_points'],
                'user_id' => $data['user_id']
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * 处理批量查询
     */
    private function processBatchQuery($data)
    {
        $chatId = $data['chat_id'];
        $userId = $data['user_id'];
        $tgUserId = $data['tg_user_id'];
        $phoneNumbers = $data['phone_numbers'];
        $invalidNumbers = $data['invalid_numbers'] ?? [];
        $checkPoints = (int)$data['check_points'];
        $totalPoints = $data['total_points'];
        
        $successCount = 0;
        $failCount = 0;
        // 修改响应文本初始内容
        $responseText = "📱 批量话费查询结果：\n\n";
        $pointsService = new PointsService();
        $telegramService = new TelegramService();
        
        try {
            $total = count($phoneNumbers);
            foreach ($phoneNumbers as $index => $phoneNumber) {
                try {
                    // 调用话费查询方法
                    $result = TelegramHelper::queryPhoneBalance($phoneNumber);
                    
                    if ($result['code'] == 200) {
                        $data = $result['data'];
                        $successCount++;
                        
                        // 使用与单个查询相同的格式化逻辑
                        $maskedPhone = TelegramHelper::maskPhoneNumber($data['mobile']);
                        $phoneText = "✅查询成功！扣除{$checkPoints}积分！\n";
                        $phoneText .= "📱手机号码：{$maskedPhone}";
                        
                        // 检查是否为携号转网用户
                        if (!empty($data['number_portability']) && $data['number_portability'] == 1) {
                            $phoneText .= "（携转）\n";
                            $phoneText .= "🌏运  营  商：{$data['net']} {$data['now_isp']}\n";
                            $phoneText .= "🔄携号转网：{$data['init_isp']} ➡️ {$data['now_isp']}\n";
                        } else {
                            $phoneText .= "\n";
                            $phoneText .= "🌏运  营  商：{$data['net']} {$data['now_isp']}\n";
                        }
                        
                        $phoneText .= "💰当前余额：{$data['mobile_fee']}\n\n";
                        $responseText .= $phoneText;
                    } else {
                        $failCount++;
                        $maskedPhone = TelegramHelper::maskPhoneNumber($phoneNumber);
                        $responseText .= "❌查询失败：{$maskedPhone}\n";
                        $responseText .= "错误信息：{$result['msg']}\n";
                        
                        // 为查询失败的号码返还积分，并检查结果
                        $refundResult = $pointsService->addPoints(
                            $userId, 
                            $checkPoints, 
                            "手机号{$phoneNumber}话费查询失败，返还积分"
                        );
                        
                        if ($refundResult['code'] != 1) {
                            Log::error("批量查询：失败号码积分返还失败", [
                                'user_id' => $userId,
                                'phone' => $this->maskPhoneForLog($phoneNumber),
                                'points' => $checkPoints,
                                'error' => $refundResult['msg']
                            ]);
                            $responseText .= "⚠️ 该号码积分返还失败，请联系客服\n\n";
                        } else {
                            $responseText .= "💎 已返还{$checkPoints}积分\n\n";
                            Log::info("批量查询：失败号码积分返还成功", [
                                'user_id' => $userId,
                                'phone' => $this->maskPhoneForLog($phoneNumber),
                                'points' => $checkPoints
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    $failCount++;
                    $maskedPhone = TelegramHelper::maskPhoneNumber($phoneNumber);
                    $responseText .= "❌查询异常：{$maskedPhone}\n";
                    $responseText .= "错误信息：{$errorMsg}\n";
                    
                    // 为查询异常的号码返还积分，增加异常捕获
                    try {
                        $refundResult = $pointsService->addPoints(
                            $userId, 
                            $checkPoints, 
                            "手机号{$phoneNumber}话费查询异常，返还积分"
                        );
                        
                        if ($refundResult['code'] != 1) {
                            Log::error("批量查询：异常号码积分返还失败", [
                                'user_id' => $userId,
                                'phone' => $this->maskPhoneForLog($phoneNumber),
                                'points' => $checkPoints,
                                'error' => $refundResult['msg']
                            ]);
                            $responseText .= "⚠️ 该号码积分返还失败，请联系客服\n\n";
                        } else {
                            $responseText .= "💎 已返还{$checkPoints}积分\n\n";
                            Log::info("批量查询：异常号码积分返还成功", [
                                'user_id' => $userId,
                                'phone' => $this->maskPhoneForLog($phoneNumber),
                                'points' => $checkPoints
                            ]);
                        }
                    } catch (\Exception $refundE) {
                        Log::error("批量查询：异常号码积分返还抛出异常", [
                            'user_id' => $userId,
                            'phone' => $this->maskPhoneForLog($phoneNumber),
                            'points' => $checkPoints,
                            'error' => $refundE->getMessage(),
                            'step' => 'refund_exception',
                            'file' => $refundE->getFile(),
                            'line' => $refundE->getLine(),
                            'trace' => (bool) Config::get('app.app_debug', false) ? $refundE->getTraceAsString() : null
                        ]);
                        $responseText .= "⚠️ 该号码积分返还异常，请联系客服\n\n";
                    }
                }
                
                // 控制API请求频率
                usleep(500000); // 500毫秒
            }
            
            // 处理无效号码的积分返还
            if (!empty($invalidNumbers)) {
                $invalidCount = count($invalidNumbers);
                $responseText .= "⚠️ 以下号码格式无效，已跳过并返还积分：\n";
                
                foreach ($invalidNumbers as $number) {
                    $responseText .= TelegramHelper::maskPhoneNumber($number) . "\n";
                }
                $responseText .= "\n";
                
                // 返还无效号码的积分
                $refundPoints = $invalidCount * $checkPoints;
                $refundResult = $pointsService->addPoints(
                    $userId, 
                    $refundPoints, 
                    "批量查询无效号码{$invalidCount}个，返还积分"
                );
                
                if ($refundResult['code'] != 1) {
                    Log::error("批量查询：无效号码积分返还失败", [
                        'user_id' => $userId,
                        'invalid_count' => $invalidCount,
                        'total_points' => $refundPoints,
                        'error' => $refundResult['msg']
                    ]);
                    $responseText .= "⚠️ 无效号码积分返还失败，请联系客服\n\n";
                } else {
                    $responseText .= "💎 已返还无效号码积分共计{$refundPoints}积分\n\n";
                    Log::info("批量查询：无效号码积分返还成功", [
                        'user_id' => $userId,
                        'invalid_count' => $invalidCount,
                        'total_points' => $refundPoints
                    ]);
                }
            }
            
            $responseText .= "📊 查询完成：成功{$successCount}个，失败{$failCount}个\n";
            $responseText .= "💎 共扣除" . ($successCount * $checkPoints) . "积分";
            
            // 发送结果
            $telegramService->sendBasicReply($chatId, $responseText);
            
            Log::info('批量话费查询完成', [
                'user_id' => $userId,
                'total' => count($phoneNumbers),
                'success' => $successCount,
                'fail' => $failCount,
                'invalid' => count($invalidNumbers)
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('批量话费查询处理异常', $this->buildThrowableLogContext($e, [
                'step' => 'process_batch_query',
                'user_id' => $userId,
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'phone_count' => count($phoneNumbers),
                'invalid_count' => count($invalidNumbers),
            ]));
            
            // 发送错误通知
            $telegramService->sendBasicReply(
                $chatId, 
                "批量查询过程中出现错误：" . $e->getMessage() . "，已尝试为您返还积分。如有问题请联系客服。"
            );
            
            return false;
        }
    }

    private function buildTaskSummary(array $data): array
    {
        return [
            'user_id' => $data['user_id'] ?? null,
            'tg_user_id' => $this->hashLogIdentifier($data['tg_user_id'] ?? null),
            'chat_id' => $this->hashLogIdentifier($data['chat_id'] ?? null),
            'phone_count' => isset($data['phone_numbers']) && is_array($data['phone_numbers']) ? count($data['phone_numbers']) : 0,
            'invalid_count' => isset($data['invalid_numbers']) && is_array($data['invalid_numbers']) ? count($data['invalid_numbers']) : 0,
        ];
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

    private function maskPhoneForLog($phone): string
    {
        $phone = trim((string) $phone);
        if (preg_match('/^(\d{3})\d{4}(\d{4})$/', $phone, $matches)) {
            return $matches[1] . '****' . $matches[2];
        }

        return $phone === '' ? '' : substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}
