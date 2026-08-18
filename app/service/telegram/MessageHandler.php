<?php
namespace app\service\telegram;

use app\common\library\TelegramHelper;
use app\service\TelegramService;
use think\facade\Log;
use think\facade\Cache;
use app\service\telegram\UsdtQueryHandler;

class MessageHandler
{
    /** @var TelegramService 主服务实例 */
    private $telegramService;
    
    /** @var UsdtQueryHandler USDT查询处理器 */
    private $usdtQueryHandler;
    
    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
        
        try {
            $this->usdtQueryHandler = new UsdtQueryHandler($telegramService);
            Log::info('UsdtQueryHandler初始化成功'); // 新增日志
        } catch (\Throwable $e) {
            Log::error('UsdtQueryHandler初始化失败', [
                'error' => $e->getMessage()
            ]);
            $this->usdtQueryHandler = null;
        }
    }
    
    /**
     * 处理个人消息
     */
public function handlePrivateMessage($message)
{
    $step = "初始化消息处理";
    $chatId = $message['chat']['id'] ?? null;
    try {
        if (!isset($message['chat']['id'])) {
            Log::error('消息验证失败: 缺少 chat_id');
            return;
        }
        
        $chatId = $message['chat']['id'];
        $tgUserId = $message['from']['id'] ?? 0;
        $text = $message['text'] ?? '';
        $messageId = $message['message_id'] ?? null;
        $tgUsername = $message['from']['username'] ?? '';
        
        Log::info('收到个人消息', [
            'tg_user_id' => $this->hashLogIdentifier($tgUserId),
            'chat_id' => $this->hashLogIdentifier($chatId),
            'message_len' => $this->getLogMessageLength($text),
            'message_hash' => $this->hashLogText($text),
            'message_id' => $this->hashLogIdentifier($messageId),
            'is_potential_usdt_address' => $this->usdtQueryHandler ? $this->usdtQueryHandler->looksLikeWalletQueryCandidate(trim($text)) : false
        ]);
        
        if ($tgUserId <= 0) {
            Log::warning('消息缺少 from.id，按降级模式继续处理', $this->sanitizeLogContext([
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]));
        }

        $step = '检查频率限制';
        $rateLimitPassed = $tgUserId > 0
            ? (bool) $this->callSafely('频率限制检查失败', function () use ($tgUserId) {
                return $this->telegramService->checkRateLimit($tgUserId);
            }, true, [
                'tg_user_id' => $tgUserId,
            ])
            : true;

        if (!$rateLimitPassed) {
            Log::info('个人消息频率限制触发', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            $this->safeSendBasicReply($chatId, '操作过于频繁，请稍后再试', null, [
                'tg_user_id' => $tgUserId,
                'message_id' => $messageId,
            ]);
            return;
        }

        $trimmedText = trim($text);

        if ($this->usdtQueryHandler) {
            Log::info('开始USDT地址候选检查', ['text' => $this->hashLogText($text)]);
            $step = 'USDT地址候选检查';
            $isUsdtCandidate = (bool) $this->callSafely('USDT地址候选检查失败', function () use ($trimmedText) {
                return $this->usdtQueryHandler->looksLikeWalletQueryCandidate($trimmedText);
            }, false, [
                'text' => $this->hashLogText($trimmedText),
                'chat_id' => $chatId,
                'tg_user_id' => $tgUserId,
            ]);

            Log::info('USDT地址候选检查结果', [
                'text' => $this->hashLogText($trimmedText),
                'is_candidate' => $isUsdtCandidate,
                'length' => strlen($trimmedText),
                'starts_with_t' => (strpos($trimmedText, 'T') === 0),
                'starts_with_0x' => (stripos($trimmedText, '0x') === 0)
            ]);
            
            if ($isUsdtCandidate) {
                $maskedAddress = $this->callSafely('USDT地址脱敏失败', function () use ($trimmedText) {
                    return $this->usdtQueryHandler->maskAddress($trimmedText);
                }, $trimmedText, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                ]);
                Log::info('检测到有效USDT地址，开始处理查询', ['address' => $maskedAddress]);
                $step = '处理USDT查询';
                $this->callSafely('USDT查询处理失败', function () use ($chatId, $tgUserId, $trimmedText, $messageId) {
                    $this->usdtQueryHandler->handleUsdtQuery($chatId, $tgUserId, $trimmedText, $messageId);
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
                return;
            } else {
                Log::info('未检测到有效USDT地址，继续处理其他逻辑', ['text' => $this->hashLogText($trimmedText)]);
            }
        } else {
            Log::error('usdtQueryHandler未初始化，无法处理USDT查询');
        }
        
        // 检查绑定流程（添加日志）
        $bindingProcessKey = $this->telegramService->getCachePrefix() . "binding:{$tgUserId}";
        $bindingProcess = Cache::get($bindingProcessKey);
        Log::info('绑定流程检查', [
            'has_binding_process' => (bool)$bindingProcess,
            'binding_process_key' => $this->hashLogIdentifier($bindingProcessKey)
        ]);
        
        if ($bindingProcess) {
            Log::info('检测到旧版绑定流程缓存，已自动下线', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            Cache::delete($bindingProcessKey);
            $this->safeSendBasicReply($chatId, '旧版手机号密码绑定流程已下线，请先在平台 TG绑定 页面获取绑定码，再发送 /bind 绑定码 完成绑定。', null, [
                'tg_user_id' => $tgUserId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        if (stripos(trim($text), '批量查话费') === 0) {
            Log::info('检测到批量查话费命令', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            $step = '批量话费查询';
            $this->callSafely('批量话费查询处理失败', function () use ($chatId, $tgUserId, $text, $messageId) {
                $this->telegramService->getQueryHandler()->queueBatchPhoneQuery($chatId, $tgUserId, $text, $messageId);
            }, null, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        if (stripos(trim($text), '批量查电费') === 0) {
            Log::info('检测到批量查电费命令', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            $step = '批量电费查询';
            $this->callSafely('批量电费查询处理失败', function () use ($chatId, $tgUserId, $text, $messageId) {
                $this->telegramService->getQueryHandler()->queueBatchElectricityQuery($chatId, $tgUserId, $text, $messageId);
            }, null, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        if (TelegramHelper::isValidPhoneNumber($text)) {
            Log::info('检测到有效手机号，处理话费查询', ['phone' => $this->maskPhoneForLog($text)]);
            $step = '话费查询';
            $this->callSafely('话费查询处理失败', function () use ($chatId, $tgUserId, $text, $messageId) {
                $this->telegramService->getQueryHandler()->handlePhoneBalanceQuery($chatId, $tgUserId, $text, $messageId);
            }, null, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        if (TelegramHelper::isValidElectricityAccount($text)) {
            Log::info('检测到有效电费户号，处理电费查询', ['account' => $this->maskAccountForLog($text)]);
            $step = '电费查询';
            $this->callSafely('电费查询处理失败', function () use ($chatId, $tgUserId, $text, $messageId) {
                $this->telegramService->getQueryHandler()->handleElectricityQuery($chatId, $tgUserId, $text, $messageId);
            }, null, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        if ($text === '签到') {
            Log::info('检测到签到命令', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            $step = '签到';
            $this->callSafely('签到处理失败', function () use ($chatId, $tgUserId) {
                $this->telegramService->handleCheckIn($chatId, $tgUserId);
            }, null, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }
        
        Log::info('消息未匹配任何特殊处理，进入命令处理', ['text' => $this->hashLogText($text)]);
        $step = '命令处理';
        $this->callSafely('命令处理失败', function () use ($chatId, $tgUserId, $tgUsername, $text, $messageId) {
            $this->telegramService->getCommandHandler()->handleCommand($chatId, $tgUserId, $tgUsername, $text, $messageId);
        }, null, [
            'tg_user_id' => $tgUserId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
        
    } catch (\Throwable $e) {
        Log::error('个人消息处理失败', [
            'step' => $step,
            'error' => $e->getMessage(),
            'module' => 'MessageHandler',
            'message_id' => $this->hashLogIdentifier($message['message_id'] ?? 'unknown')
        ]);
        
        if (isset($chatId)) {
            $this->safeSendBasicReply($chatId, '操作过程中出现错误，请稍后重试。', null, [
                'step' => $step,
                'message_id' => $message['message_id'] ?? 'unknown',
            ]);
        }
    }
}
    
    /**
     * 处理群组消息
     */
    public function handleGroupMessage($message)
    {
        $step = "初始化群组消息处理";
        try {
            if (!isset($message['from']['id'], $message['chat']['id'])) {
                Log::warning("无效的群组消息数据，缺少必要字段");
                return;
            }
            
            $tgUserId = $message['from']['id'];
            $groupId = $message['chat']['id'];
            $messageId = $message['message_id'] ?? 'unknown';
            $chatId = $groupId;
            $messageType = TelegramHelper::getMessageType($message);
            $messageText = isset($message['text']) ? trim($message['text']) : '';
            
            if ($this->usdtQueryHandler) {
                $isUsdtCandidate = $messageType === 'text'
                    ? (bool) $this->callSafely('群组USDT地址候选检查失败', function () use ($messageText) {
                        return $this->usdtQueryHandler->looksLikeWalletQueryCandidate(trim($messageText));
                    }, false, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ])
                    : false;

                if ($isUsdtCandidate) {
                    $this->callSafely('群组USDT查询处理失败', function () use ($chatId, $tgUserId, $messageText, $messageId) {
                        $this->usdtQueryHandler->handleUsdtQuery($chatId, $tgUserId, trim($messageText), $messageId);
                    }, null, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                    return;
                }
            } else {
                Log::error('usdtQueryHandler未初始化，无法处理USDT查询');
            }
            
            // 解析命令
            $parsedCommand = $this->parseCommand($messageText);
            
            Log::info("收到群组消息", [
                'tg_user_id' => $this->hashLogIdentifier($tgUserId),
                'group_id' => $this->hashLogIdentifier($groupId),
                'message_type' => $messageType,
                'parsed_command' => $parsedCommand
            ]);
            
            // 处理命令
            if ($messageType === 'text') {
                $this->callSafely('群组命令处理失败', function () use ($chatId, $tgUserId, $messageText, $parsedCommand, $messageId, $message) {
                    $this->handleGroupCommand(
                        $chatId,
                        $tgUserId,
                        $messageText,
                        $parsedCommand,
                        $messageId,
                        $message
                    );
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'parsed_command' => $parsedCommand,
                ]);
                return;
            }
            
        } catch (\Throwable $e) {
            Log::error("群组消息处理异常", [
                'step' => $step,
                'error' => $e->getMessage(),
                'message_id' => $this->hashLogIdentifier($message['message_id'] ?? 'unknown')
            ]);
        }
    }
    
    /**
     * 处理群组命令
     */
    private function handleGroupCommand($chatId, $tgUserId, $originalText, $command, $messageId, $message)
    {
        switch ($command) {
            case '签到':
                $this->callSafely('群组签到处理失败', function () use ($chatId, $tgUserId) {
                    $this->telegramService->handleCheckIn($chatId, $tgUserId);
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
                break;
                
            case '账户查询':
            case '📊 账户查询':
            case '/account':
                $this->callSafely('群组账户查询处理失败', function () use ($chatId, $tgUserId, $messageId) {
                    $this->telegramService->getCommandHandler()->handleAccountQuery($chatId, $tgUserId, $messageId);
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
                break;
                
            case '查询订单':
            case '📋 查询订单':
            case '/orders':
                $this->callSafely('群组订单查询处理失败', function () use ($chatId, $tgUserId, $messageId) {
                    $this->telegramService->getCommandHandler()->handleOrderQuery($chatId, $tgUserId, $messageId);
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
                break;
                
            case '话费查询':
            case '📱 话费查询':
            case '/phone':
                $checkPoints = $this->telegramService->getConstant('balance_check_points', 3);
                $this->safeSendBasicReply($chatId, "请直接发送手机号码查询话费，每次查询将扣除{$checkPoints}积分。", $messageId, [
                    'tg_user_id' => $tgUserId,
                ]);
                break;
                
            case '电费查询':
            case '⚡ 电费查询':
            case '/electricity':
                $checkPoints = $this->telegramService->getConstant('electricity_check_points', 3);
                $this->safeSendBasicReply($chatId, "请直接发送电费户号查询电费，每次查询将扣除{$checkPoints}积分。", $messageId, [
                    'tg_user_id' => $tgUserId,
                ]);
                break;
                
            case '/bind':
                $this->callSafely('群组绑定命令处理失败', function () use ($chatId, $tgUserId, $originalText, $message) {
                    $this->telegramService->getCommandHandler()->handleBindCommand(
                        $chatId,
                        $tgUserId,
                        (string) $originalText,
                        (string) ($message['from']['username'] ?? '')
                    );
                }, null, [
                    'tg_user_id' => $tgUserId,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
                break;
                
            default:
                if (stripos(trim($command), '批量查话费') === 0) {
                    $this->callSafely('群组批量话费查询处理失败', function () use ($chatId, $tgUserId, $originalText, $messageId) {
                        $this->telegramService->queueBatchPhoneQuery($chatId, $tgUserId, $originalText, $messageId);
                    }, null, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                } elseif (stripos(trim($command), '批量查电费') === 0) {
                    $this->callSafely('群组批量电费查询处理失败', function () use ($chatId, $tgUserId, $originalText, $messageId) {
                        $this->telegramService->queueBatchElectricityQuery($chatId, $tgUserId, $originalText, $messageId);
                    }, null, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                } elseif (\app\common\library\TelegramHelper::isValidPhoneNumber($command)) {
                    $this->callSafely('群组话费查询处理失败', function () use ($chatId, $tgUserId, $command, $messageId) {
                        $this->telegramService->handlePhoneBalanceQuery($chatId, $tgUserId, $command, $messageId);
                    }, null, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                } elseif (\app\common\library\TelegramHelper::isValidElectricityAccount($command)) {
                    $this->callSafely('群组电费查询处理失败', function () use ($chatId, $tgUserId, $command, $messageId) {
                        $this->telegramService->handleElectricityQuery($chatId, $tgUserId, $command, $messageId);
                    }, null, [
                        'tg_user_id' => $tgUserId,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                }
        }
    }

    private function safeSendBasicReply($chatId, $text, $messageId = null, array $context = [])
    {
        $context['chat_id'] = $chatId;
        $context['message_id'] = $messageId;
        $context = $this->sanitizeLogContext($context);

        $this->callSafely('发送消息失败', function () use ($chatId, $text, $messageId) {
            $this->telegramService->sendBasicReply($chatId, $text, $messageId);
        }, null, $context);
    }

    private function callSafely(string $logMessage, callable $callback, $default = null, array $context = [])
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::error($logMessage, array_merge($this->sanitizeLogContext($context), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));

            return $default;
        }
    }

    private function getLogMessageLength($text): int
    {
        return mb_strlen((string) $text, 'UTF-8');
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
        foreach (['tg_user_id', 'chat_id', 'message_id', 'group_id', 'binding_process_key'] as $key) {
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

        if (strlen($phone) <= 7) {
            return str_repeat('*', max(3, strlen($phone)));
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -4);
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
    
    /**
     * 解析命令
     */
    private function parseCommand($text)
    {
        $command = $text;
        
        // 提取带@的命令
        if (preg_match('/^\/([a-zA-Z0-9_]+)(@\w+)?(?:\s+.*)?$/u', $text, $matches)) {
            $command = '/' . $matches[1];
        }
        
        // 修正拼写错误
        if ($command === '/bing') {
            $command = '/bind';
        }
        
        return $command;
    }
}
    