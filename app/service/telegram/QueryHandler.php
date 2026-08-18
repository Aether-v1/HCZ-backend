<?php
namespace app\service\telegram;

use app\service\TelegramService;
use app\service\PointsService;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Queue;

class QueryHandler
{
    /** @var TelegramService 主服务实例 */
    private $telegramService;
    
    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }
    
    /**
     * 处理分页回调查询
     */
    public function handleCallbackQuery($callbackQuery)
    {
        try {
            $data = $callbackQuery['data'];
            $message = $callbackQuery['message'];
            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $tgUserId = $callbackQuery['from']['id'];
            
            // 处理订单分页回调
            if (preg_match('/^order_page_(\d+)$/', $data, $matches)) {
                $page = (int)$matches[1];
                // 先删除原消息
                $this->telegramService->deleteMessage($chatId, $messageId);
                // 发送新的分页内容
                $this->telegramService->getCommandHandler()->handleOrderQuery($chatId, $tgUserId, null, $page);
                // 回复回调
                $this->telegramService->answerCallbackQuery($callbackQuery['id']);
                return true;
            }

            $orderCallbackService = new TelegramOrderCallbackService($this->telegramService);
            if ($orderCallbackService->handleProductReceiptCallback($callbackQuery)) {
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('处理回调查询异常', [
                'error' => $e->getMessage(),
                'callback_data_hash' => $this->hashLogText($callbackQuery['data'] ?? ''),
                'tg_user_id' => $this->hashLogIdentifier($callbackQuery['from']['id'] ?? null),
                'chat_id' => $this->hashLogIdentifier($callbackQuery['message']['chat']['id'] ?? null),
                'message_id' => $this->hashLogIdentifier($callbackQuery['message']['message_id'] ?? null),
            ]);
            return false;
        }
    }
    
    /**
     * 处理单个手机号话费查询
     */
    public function handlePhoneBalanceQuery($chatId, $tgUserId, $phoneNumber, $messageId = null)
    {
        try {
            // 群聊中删除消息
            $isGroup = $messageId && \app\common\library\TelegramHelper::isGroupChat($chatId);
            if ($isGroup) {
                $this->telegramService->deleteMessage($chatId, $messageId);
            }
            
            // 检查重复查询
            $duplicateKey = $this->telegramService->getCachePrefix() . "phone_query:{$tgUserId}:{$phoneNumber}";
            if (Cache::has($duplicateKey)) {
                $this->telegramService->sendBasicReply($chatId, "您刚刚已经查询过该手机号的话费，请勿重复发送。");
                return;
            }
            
            // 检查用户绑定
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }
            
            // 发送处理中提示
            $this->telegramService->sendBasicReply($chatId, "正在查询话费信息，请稍候...");
            
            // 检查积分
            $checkPoints = $this->telegramService->getConstant('balance_check_points', 3);
            $pointsService = new PointsService();
            $userPoints = $pointsService->getUserPointsBalance($userId);
            
            if ($userPoints < $checkPoints) {
                $this->telegramService->sendBasicReply($chatId, "您的积分不足，每次查询话费需要{$checkPoints}积分。当前积分：{$userPoints}");
                return;
            }
            
            // 扣除积分
            $deductResult = $pointsService->deductPoints(
                $userId, 
                $checkPoints, 
                "查询手机号{$phoneNumber}话费"
            );
            
            if ($deductResult['code'] != 1) {
                throw new \Exception($deductResult['msg'] ?? '扣除积分失败');
            }
            
            // 查询话费
            $result = \app\common\library\TelegramHelper::queryPhoneBalance($phoneNumber);
            
            if ($result['code'] != 200) {
                throw new \Exception($result['msg'] ?? '查询话费失败，请稍后重试');
            }
            
            // 标记为已查询
            Cache::set($duplicateKey, 1, $this->telegramService->getConstant('duplicate_check_ttl', 30));
            
            // 构建回复
            $data = $result['data'];
            $maskedPhone = \app\common\library\TelegramHelper::maskPhoneNumber($data['mobile']);
            $responseText = "✅查询成功！扣除{$checkPoints}积分！\n";
            $responseText .= "📱手机号码：{$maskedPhone}";
            
            if (!empty($data['number_portability']) && $data['number_portability'] == 1) {
                $responseText .= "（携转）\n";
                $responseText .= "🌏运  营  商：{$data['net']} {$data['now_isp']}\n";
                $responseText .= "🔄携号转网：{$data['init_isp']} ➡️ {$data['now_isp']}\n";
            } else {
                $responseText .= "\n";
                $responseText .= "🌏运  营  商：{$data['net']} {$data['now_isp']}\n";
            }
            
            $responseText .= "💰当前余额：{$data['mobile_fee']}";
            
            $this->telegramService->sendBasicReply($chatId, $responseText);
            
        } catch (\Exception $e) {
            Log::error('话费查询失败', $this->sanitizeLogContext([
                'error' => $e->getMessage(),
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'phone' => $this->maskPhoneForLog($phoneNumber),
            ]));
            
            // 尝试返还积分
            if (isset($userId, $checkPoints)) {
                \app\common\library\TelegramHelper::refundPoints($userId, $checkPoints, $phoneNumber);
            }
            
            $errorMsg = "话费查询失败：" . $e->getMessage() . "，已为您返还积分。";
            $this->telegramService->sendBasicReply($chatId, $errorMsg);
        }
    }
    
    /**
     * 处理单个电费户号查询
     */
    public function handleElectricityQuery($chatId, $tgUserId, $accountNumber, $messageId = null)
    {
        try {
            // 群聊中删除消息
            $isGroup = $messageId && \app\common\library\TelegramHelper::isGroupChat($chatId);
            if ($isGroup) {
                $this->telegramService->deleteMessage($chatId, $messageId);
            }
            
            // 检查重复查询
            $duplicateKey = $this->telegramService->getCachePrefix() . "electricity_query:{$tgUserId}:{$accountNumber}";
            if (Cache::has($duplicateKey)) {
                $this->telegramService->sendBasicReply($chatId, "您刚刚已经查询过该户号的电费，请勿重复发送。");
                return;
            }
            
            // 检查用户绑定
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }
            
            // 发送处理中提示
            $this->telegramService->sendBasicReply($chatId, "正在查询电费信息，请稍候...");
            
            // 检查积分
            $checkPoints = $this->telegramService->getConstant('electricity_check_points', 3);
            $pointsService = new PointsService();
            $userPoints = $pointsService->getUserPointsBalance($userId);
            
            if ($userPoints < $checkPoints) {
                $this->telegramService->sendBasicReply($chatId, "您的积分不足，每次查询电费需要{$checkPoints}积分。当前积分：{$userPoints}");
                return;
            }
            
            // 扣除积分
            $deductResult = $pointsService->deductPoints(
                $userId, 
                $checkPoints, 
                "查询电费户号{$accountNumber}电费"
            );
            
            if ($deductResult['code'] != 1) {
                throw new \Exception($deductResult['msg'] ?? '扣除积分失败');
            }
            
            // 查询电费
            $result = \app\common\library\TelegramHelper::queryElectricityBalance($accountNumber);
            
            if ($result['code'] != 200) {
                throw new \Exception($result['msg'] ?? '查询电费失败，请稍后重试');
            }
            
            // 标记为已查询
            Cache::set($duplicateKey, 1, $this->telegramService->getConstant('duplicate_check_ttl', 30));
            
            // 构建回复
            $data = $result['data'];
            $maskedAccount = \app\common\library\TelegramHelper::maskElectricityAccount($accountNumber);
            $responseText = "✅查询成功！扣除{$checkPoints}积分！\n";
            $responseText .= "⚡电费户号：{$maskedAccount}\n";
            $responseText .= "💰当前总余额：{$data['balance']}\n";
            $responseText .= "💳可用余额：{$data['availableBalance']}\n";
            
            if ($data['owedBalance'] > 0) {
                $responseText .= "⚠️欠费金额：{$data['owedBalance']}";
            }
            
            $this->telegramService->sendBasicReply($chatId, $responseText);
            
        } catch (\Exception $e) {
            Log::error('电费查询失败', $this->sanitizeLogContext([
                'error' => $e->getMessage(),
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'account' => $this->maskAccountForLog($accountNumber),
            ]));
            
            // 尝试返还积分
            if (isset($userId, $checkPoints)) {
                \app\common\library\TelegramHelper::refundPoints($userId, $checkPoints, $accountNumber);
            }
            
            $errorMsg = "电费查询失败：" . $e->getMessage() . "，已为您返还积分。";
            $this->telegramService->sendBasicReply($chatId, $errorMsg);
        }
    }
    
    /**
     * 批量查询话费加入队列
     */
    public function queueBatchPhoneQuery($chatId, $tgUserId, $text, $messageId = null)
    {
        $pointsDeducted = false;
        $queued = false;
        $traceId = null;

        try {
            // 群聊中删除消息
            if ($messageId && \app\common\library\TelegramHelper::isGroupChat($chatId)) {
                $this->telegramService->deleteMessage($chatId, $messageId);
            }
            
            // 检查用户绑定
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }
            
            // 提取手机号
            list($phoneNumbers, $invalidNumbers) = $this->extractPhoneNumbers($text);
            
            if (empty($phoneNumbers)) {
                $this->telegramService->sendBasicReply($chatId, "未找到有效的手机号，请检查格式后重试。");
                return;
            }
            
            // 限制最大查询数量
            $maxBatchNumbers = $this->telegramService->getConstant('max_batch_numbers', 5);
            $originalCount = count($phoneNumbers);
            if ($originalCount > $maxBatchNumbers) {
                $phoneNumbers = array_slice($phoneNumbers, 0, $maxBatchNumbers);
                $this->telegramService->sendBasicReply($chatId, "一次最多查询{$maxBatchNumbers}个手机号，已自动截取前{$maxBatchNumbers}个。");
            }
            
            // 检查积分
            $checkPoints = $this->telegramService->getConstant('balance_check_points', 3);
            $totalPointsNeeded = count($phoneNumbers) * $checkPoints;
            
            $pointsService = new PointsService();
            $userPoints = $pointsService->getUserPointsBalance($userId);
            
            if ($userPoints < $totalPointsNeeded) {
                $this->telegramService->sendBasicReply($chatId, "您的积分不足，查询" . count($phoneNumbers) . "个手机号需要{$totalPointsNeeded}积分。当前积分：{$userPoints}");
                return;
            }

            $traceId = $this->generateBatchTraceId('phone');
            
            // 扣除积分
            $deductResult = $pointsService->deductPoints(
                $userId, 
                $totalPointsNeeded, 
                "批量查询" . count($phoneNumbers) . "个手机号话费 [trace:{$traceId}]"
            );
            
            if ($deductResult['code'] != 1) {
                throw new \Exception($deductResult['msg'] ?? '扣除积分失败');
            }

            $pointsDeducted = true;
            $this->storeBatchTrace($traceId, [
                'biz_type' => 'phone',
                'status' => 'deducted',
                'user_id' => $userId,
                'tg_user_id' => (string) $tgUserId,
                'chat_id' => (string) $chatId,
                'points' => $totalPointsNeeded,
                'item_count' => count($phoneNumbers),
                'created_at' => time(),
            ]);
            
            // 加入队列
            $jobData = [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'tg_user_id' => $tgUserId,
                'phone_numbers' => $phoneNumbers,
                'invalid_numbers' => $invalidNumbers,
                'check_points' => $checkPoints,
                'total_points' => $totalPointsNeeded,
                'trace_id' => $traceId,
                'created_at' => time()
            ];
            
            $queueName = $this->telegramService->getConstant('batch_query_queue', 'batchPhoneQuery');
            $result = Queue::push(\app\job\BatchPhoneQuery::class, $jobData, $queueName);
            
            if ($result === false) {
                throw new \Exception('将任务加入队列失败');
            }

            $queued = true;
            $this->storeBatchTrace($traceId, [
                'biz_type' => 'phone',
                'status' => 'queued',
                'queue_name' => $queueName,
                'queue_job_id' => (string) $result,
                'queued_at' => time(),
            ]);

            $this->telegramService->sendBasicReply($chatId, "已收到批量查询请求，正在处理" . count($phoneNumbers) . "个手机号的话费信息...\n结果将在几分钟内发送给您。");
            
        } catch (\Exception $e) {
            Log::error('批量查询处理失败', $this->sanitizeLogContext([
                'error' => $e->getMessage(),
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'trace_id' => $traceId,
                'phone_count' => isset($phoneNumbers) && is_array($phoneNumbers) ? count($phoneNumbers) : 0,
                'invalid_count' => isset($invalidNumbers) && is_array($invalidNumbers) ? count($invalidNumbers) : 0,
            ]));

            $message = '批量查询任务提交失败，请稍后重试。';
            if ($pointsDeducted && !$queued && isset($pointsService, $userId, $totalPointsNeeded, $traceId)) {
                $refundResult = $this->refundBatchPointsOnce(
                    $pointsService,
                    $userId,
                    $totalPointsNeeded,
                    "批量话费查询入队失败返还积分 [trace:{$traceId}]",
                    $traceId
                );

                if (($refundResult['code'] ?? 0) == 1) {
                    $message = "批量查询任务提交失败，已返还{$totalPointsNeeded}积分，请稍后重试。";
                } else {
                    $message = '批量查询任务提交失败，积分返还异常，请联系客服。';
                }
            }

            $this->telegramService->sendBasicReply($chatId, $message);
        }
    }
    
    /**
     * 批量查询电费加入队列
     */
    public function queueBatchElectricityQuery($chatId, $tgUserId, $text, $messageId = null)
    {
        $pointsDeducted = false;
        $queued = false;
        $traceId = null;

        try {
            // 群聊中删除消息
            if ($messageId && \app\common\library\TelegramHelper::isGroupChat($chatId)) {
                $this->telegramService->deleteMessage($chatId, $messageId);
            }
            
            // 检查用户绑定
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }
            
            // 提取电费户号
            list($accountNumbers, $invalidNumbers) = $this->extractElectricityAccounts($text);
            
            if (empty($accountNumbers)) {
                $this->telegramService->sendBasicReply($chatId, "未找到有效的电费户号，请检查格式后重试。");
                return;
            }
            
            // 限制最大查询数量
            $maxBatchNumbers = $this->telegramService->getConstant('max_batch_numbers', 5);
            $originalCount = count($accountNumbers);
            if ($originalCount > $maxBatchNumbers) {
                $accountNumbers = array_slice($accountNumbers, 0, $maxBatchNumbers);
                $this->telegramService->sendBasicReply($chatId, "一次最多查询{$maxBatchNumbers}个电费户号，已自动截取前{$maxBatchNumbers}个。");
            }
            
            // 检查积分
            $checkPoints = $this->telegramService->getConstant('electricity_check_points', 3);
            $totalPointsNeeded = count($accountNumbers) * $checkPoints;
            
            $pointsService = new PointsService();
            $userPoints = $pointsService->getUserPointsBalance($userId);
            
            if ($userPoints < $totalPointsNeeded) {
                $this->telegramService->sendBasicReply($chatId, "您的积分不足，查询" . count($accountNumbers) . "个电费户号需要{$totalPointsNeeded}积分。当前积分：{$userPoints}");
                return;
            }

            $traceId = $this->generateBatchTraceId('electricity');
            
            // 扣除积分
            $deductResult = $pointsService->deductPoints(
                $userId, 
                $totalPointsNeeded, 
                "批量查询" . count($accountNumbers) . "个电费户号电费 [trace:{$traceId}]"
            );
            
            if ($deductResult['code'] != 1) {
                throw new \Exception($deductResult['msg'] ?? '扣除积分失败');
            }

            $pointsDeducted = true;
            $this->storeBatchTrace($traceId, [
                'biz_type' => 'electricity',
                'status' => 'deducted',
                'user_id' => $userId,
                'tg_user_id' => (string) $tgUserId,
                'chat_id' => (string) $chatId,
                'points' => $totalPointsNeeded,
                'item_count' => count($accountNumbers),
                'created_at' => time(),
            ]);
            
            // 加入队列
            $jobData = [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'tg_user_id' => $tgUserId,
                'account_numbers' => $accountNumbers,
                'invalid_numbers' => $invalidNumbers,
                'check_points' => $checkPoints,
                'total_points' => $totalPointsNeeded,
                'trace_id' => $traceId,
                'created_at' => time()
            ];
            
            $queueName = $this->telegramService->getConstant('batch_electricity_queue', 'batchElectricityQuery');
            $result = Queue::push(\app\job\BatchElectricityQuery::class, $jobData, $queueName);
            
            if ($result === false) {
                throw new \Exception('将任务加入队列失败');
            }

            $queued = true;
            $this->storeBatchTrace($traceId, [
                'biz_type' => 'electricity',
                'status' => 'queued',
                'queue_name' => $queueName,
                'queue_job_id' => (string) $result,
                'queued_at' => time(),
            ]);

            $this->telegramService->sendBasicReply($chatId, "已收到批量查询请求，正在处理" . count($accountNumbers) . "个电费户号的电费信息...\n结果将在几分钟内发送给您。");
            
        } catch (\Exception $e) {
            Log::error('批量电费查询处理失败', $this->sanitizeLogContext([
                'error' => $e->getMessage(),
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'trace_id' => $traceId,
                'account_count' => isset($accountNumbers) && is_array($accountNumbers) ? count($accountNumbers) : 0,
                'invalid_count' => isset($invalidNumbers) && is_array($invalidNumbers) ? count($invalidNumbers) : 0,
            ]));

            $message = '批量电费查询任务提交失败，请稍后重试。';
            if ($pointsDeducted && !$queued && isset($pointsService, $userId, $totalPointsNeeded, $traceId)) {
                $refundResult = $this->refundBatchPointsOnce(
                    $pointsService,
                    $userId,
                    $totalPointsNeeded,
                    "批量电费查询入队失败返还积分 [trace:{$traceId}]",
                    $traceId
                );

                if (($refundResult['code'] ?? 0) == 1) {
                    $message = "批量电费查询任务提交失败，已返还{$totalPointsNeeded}积分，请稍后重试。";
                } else {
                    $message = '批量电费查询任务提交失败，积分返还异常，请联系客服。';
                }
            }

            $this->telegramService->sendBasicReply($chatId, $message);
        }
    }
    
    /**
     * 提取并验证手机号
     */
    private function extractPhoneNumbers($text)
    {
        $lines = explode("\n", $text);
        array_shift($lines); // 移除第一行"批量查话费"
        
        $phoneNumbers = [];
        $invalidNumbers = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                $normalized = \app\common\library\TelegramHelper::normalizePhoneNumber($trimmedLine);
                if ($normalized) {
                    $phoneNumbers[] = $normalized;
                } else {
                    $invalidNumbers[] = $trimmedLine;
                }
            }
        }
        
        return [$phoneNumbers, $invalidNumbers];
    }
    
    /**
     * 提取并验证电费户号
     */
    private function extractElectricityAccounts($text)
    {
        $lines = explode("\n", $text);
        array_shift($lines); // 移除第一行"批量查电费"
        
        $accountNumbers = [];
        $invalidNumbers = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                if (\app\common\library\TelegramHelper::isValidElectricityAccount($trimmedLine)) {
                    $accountNumbers[] = $trimmedLine;
                } else {
                    $invalidNumbers[] = $trimmedLine;
                }
            }
        }
        
        return [$accountNumbers, $invalidNumbers];
    }

    private function generateBatchTraceId(string $bizType): string
    {
        try {
            return sprintf('tg-%s-%s', $bizType, bin2hex(random_bytes(8)));
        } catch (\Throwable $e) {
            Log::warning('生成批量查询trace_id失败，使用降级方案', [
                'biz_type' => $bizType,
                'error' => $e->getMessage(),
            ]);
            return sprintf('tg-%s-%s', $bizType, uniqid('', true));
        }
    }

    private function storeBatchTrace(string $traceId, array $payload): void
    {
        $cacheKey = $this->getBatchTraceCacheKey($traceId);
        $ttl = (int) $this->telegramService->getConstant('batch_trace_ttl', 172800);

        try {
            $current = Cache::store('redis')->get($cacheKey, []);
            if (!is_array($current)) {
                $current = [];
            }

            $current = array_merge($current, $payload, [
                'trace_id' => $traceId,
                'updated_at' => time(),
            ]);

            Cache::store('redis')->set($cacheKey, $current, $ttl);
        } catch (\Throwable $e) {
            Log::error('批量查询trace记录失败', $this->sanitizeLogContext([
                'error' => $e->getMessage(),
                'trace_id' => $traceId,
            ]));
        }
    }

    private function refundBatchPointsOnce(PointsService $pointsService, int $userId, int $points, string $reason, string $traceId): array
    {
        if ($points <= 0) {
            return ['code' => 1, 'msg' => '无需返还积分'];
        }

        $refundKey = $this->getBatchRefundCacheKey($traceId);
        $ttl = (int) $this->telegramService->getConstant('batch_trace_ttl', 172800);

        try {
            $reserved = Cache::store('redis')->handler()->set($refundKey, (string) time(), ['nx', 'ex' => $ttl]);
            if (!$reserved) {
                Log::warning('批量查询积分返还已执行，跳过重复返还', [
                    'user_id' => $userId,
                    'trace_id' => $traceId,
                    'points' => $points,
                ]);
                return ['code' => 1, 'msg' => '积分已返还'];
            }
        } catch (\Throwable $e) {
            Log::error('批量查询积分返还幂等锁失败', [
                'user_id' => $userId,
                'trace_id' => $traceId,
                'points' => $points,
                'error' => $e->getMessage(),
            ]);
            return ['code' => 0, 'msg' => '积分返还幂等校验失败'];
        }

        try {
            $result = $pointsService->addPoints($userId, $points, $reason);
            if (($result['code'] ?? 0) != 1) {
                Log::error('批量查询积分返还失败', [
                    'user_id' => $userId,
                    'trace_id' => $traceId,
                    'points' => $points,
                    'result_message' => $result['msg'] ?? null,
                ]);
                return $result;
            }

            $this->storeBatchTrace($traceId, [
                'status' => 'refunded',
                'refund_points' => $points,
                'refund_reason' => $reason,
                'refunded_at' => time(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('批量查询积分返还异常', [
                'user_id' => $userId,
                'trace_id' => $traceId,
                'points' => $points,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['code' => 0, 'msg' => '积分返还异常'];
        }
    }

    private function getBatchTraceCacheKey(string $traceId): string
    {
        return "tg:batch:trace:{$traceId}";
    }

    private function getBatchRefundCacheKey(string $traceId): string
    {
        return "tg:batch:refund:{$traceId}";
    }

    private function hashLogText($text): string
    {
        return substr(hash('sha256', (string) $text), 0, 12);
    }

    private function hashLogIdentifier($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', (string) $value), 0, 12);
    }

    private function sanitizeLogContext(array $context): array
    {
        foreach (['tg_user_id', 'chat_id', 'message_id', 'group_id'] as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = $this->hashLogIdentifier($context[$key]);
            }
        }

        return $context;
    }

    private function maskPhoneForLog($phone): string
    {
        $phone = trim((string) $phone);
        if (preg_match('/^(\d{3})\d{4}(\d{4})$/', $phone, $matches)) {
            return $matches[1] . '****' . $matches[2];
        }

        return $phone === '' ? '' : substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    private function maskAccountForLog($account): string
    {
        $account = trim((string) $account);
        $length = strlen($account);
        if ($length <= 6) {
            return str_repeat('*', max(3, $length));
        }

        return substr($account, 0, 3) . str_repeat('*', $length - 6) . substr($account, -3);
    }
}
    