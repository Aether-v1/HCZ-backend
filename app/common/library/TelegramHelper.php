<?php
namespace app\common\library;

use app\service\PointsService;
use think\facade\Config;
use think\facade\Log;
use app\service\TelegramService;

class TelegramHelper
{
    private static function redactTelegramUrlForLog(string $url): string
    {
        return (string) preg_replace('#/bot[^/]+/#', '/bot***REDACTED***/', $url);
    }

    private static function summarizeJsonResponseForLog($responseData): array
    {
        if (!is_array($responseData)) {
            return [
                'response_type' => gettype($responseData),
            ];
        }

        return [
            'ok' => isset($responseData['ok']) ? (bool) $responseData['ok'] : null,
            'error_code' => $responseData['error_code'] ?? null,
            'status' => $responseData['status'] ?? null,
            'code' => $responseData['code'] ?? null,
            'message' => $responseData['message'] ?? ($responseData['description'] ?? null),
            'keys' => array_keys($responseData),
        ];
    }

    private static function sanitizeAsyncLogContent($content)
    {
        if (is_array($content)) {
            $sanitized = [];
            foreach ($content as $key => $value) {
                $sanitized[$key] = self::sanitizeAsyncLogValue($key, $value);
            }

            return $sanitized;
        }

        if (is_object($content)) {
            $objectVars = get_object_vars($content);
            $sanitized = [];
            foreach ($objectVars as $key => $value) {
                $sanitized[$key] = self::sanitizeAsyncLogValue($key, $value);
            }

            return $sanitized;
        }

        return self::sanitizeAsyncLogString((string) $content);
    }

    private static function sanitizeAsyncLogValue($key, $value)
    {
        $normalizedKey = strtolower((string) $key);

        if (is_array($value) || is_object($value)) {
            if (in_array($normalizedKey, ['response', 'response_body', 'payload', 'raw_payload', 'post_data', 'request_body'], true)) {
                return '[REDACTED]';
            }

            return self::sanitizeAsyncLogContent($value);
        }

        $value = (string) $value;

        if (str_contains($normalizedKey, 'token') || str_contains($normalizedKey, 'secret') || str_contains($normalizedKey, 'key')) {
            return self::hashAsyncLogValue($value);
        }

        if (str_contains($normalizedKey, 'url')) {
            return self::sanitizeAsyncLogString($value);
        }

        if (in_array($normalizedKey, ['response', 'response_body', 'payload', 'raw_payload', 'post_data', 'request_body'], true)) {
            return '[REDACTED]';
        }

        return self::sanitizeAsyncLogString($value);
    }

    private static function sanitizeAsyncLogString(string $content): string
    {
        $content = self::redactTelegramUrlForLog($content);

        $content = preg_replace('/([?&](?:token|secret|key)=)[^&\s"\']*/iu', '$1***REDACTED***', $content) ?? $content;
        $content = preg_replace('/\b(response_body|payload|raw_payload|post_data|request_body)\s*[:=]\s*.+$/imu', '$1=[REDACTED]', $content) ?? $content;

        return $content;
    }

    private static function hashAsyncLogValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return substr(hash('sha256', $value), 0, 12);
    }

    /**
     * 验证Telegram请求的合法性
     */
public static function validateTelegramRequest()
    {
        try {
            $requestHeaderToken = '';
            if (function_exists('request')) {
                $requestHeaderToken = trim((string) request()->header('X-Telegram-Bot-Api-Secret-Token', ''));
            }
            // 与config/telegram.php中的键匹配
            $secretToken = Config::get('telegram.webhook_secret');
            
            // 获取请求头（方式正确，HTTP头在$_SERVER中会转为大写+下划线格式）
            $receivedToken = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
            $secretToken = trim((string) $secretToken);
            if ($requestHeaderToken !== '') {
                $receivedToken = $requestHeaderToken;
            }
            
            // 输出实际读取到的配置值
            Log::debug('验证secret_token', [
                'config_key' => 'telegram.webhook_secret',
                'secretToken_exists' => $secretToken !== '',
                'receivedToken_exists' => $receivedToken !== ''
            ]);
            
            if ($secretToken === '') {
                Log::error('secret_token配置不存在（配置键：telegram.webhook_secret）');
                throw new \Exception('secret_token configuration missing');
            }
            
            if ($receivedToken === '' || !hash_equals($secretToken, $receivedToken)) {
                Log::error('请求验证失败 - token不匹配', [
                    'received_length' => strlen($receivedToken),
                    'expected' => $secretToken ? '已配置（长度：' . strlen($secretToken) . '）' : '未配置'
                ]);
                throw new \Exception('Invalid request token');
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('请求验证失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 带重试机制的HTTP请求
     */
    public static function httpRequestWithRetry($url, $postData = [], $curlOptions = [])
    {
        $attempts = 0;
        $maxAttempts = Config::get('telegram.bot_constants.api_retry_attempts', 3);
        $retryDelay = Config::get('telegram.bot_constants.api_retry_delay', 100000);
        $lastErrorMessage = '';
        
        while ($attempts < $maxAttempts) {
            $attempts++;
            Log::debug("HTTP请求尝试 #{$attempts}", ['url' => self::redactTelegramUrlForLog((string) $url)]);
            
            $ch = curl_init();
            
            // 基础选项
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            
            // POST数据
            if (!empty($postData)) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }
            
            // 额外选项
            foreach ($curlOptions as $option => $value) {
                curl_setopt($ch, $option, $value);
            }
            
            // 执行请求
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errorNo = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseBody = is_string($response) ? $response : '';
            curl_close($ch);
            
            // 检查结果
            if ($errorNo === CURLE_OK && $httpCode >= 200 && $httpCode < 300) {
                Log::debug("HTTP请求成功", ['url' => self::redactTelegramUrlForLog((string) $url), 'attempts' => $attempts]);
                return $response;
            }
            
            // 记录错误
            $lastErrorMessage = sprintf(
                'HTTP请求失败 | url=%s | attempt=%d | curl_errno=%d | curl_error=%s | http_code=%d | response_length=%d',
                self::redactTelegramUrlForLog((string) $url),
                $attempts,
                $errorNo,
                $error === '' ? '-' : $error,
                $httpCode,
                strlen($responseBody)
            );

            Log::warning($lastErrorMessage);
            
            // 重试延迟
            if ($attempts < $maxAttempts) {
                usleep($retryDelay * $attempts); // 指数退避
            }
        }
        
        if ($lastErrorMessage === '') {
            $lastErrorMessage = sprintf(
                'HTTP请求达到最大重试次数 | url=%s | curl_errno=%d | curl_error=%s | http_code=%d | response_length=%d',
                self::redactTelegramUrlForLog((string) $url),
                0,
                '-',
                0,
                0
            );
        } else {
            $lastErrorMessage = str_replace('HTTP请求失败', 'HTTP请求达到最大重试次数', $lastErrorMessage);
        }

        Log::error($lastErrorMessage);
        return false;
    }
    
    /**
     * 解析API响应
     */
    public static function parseApiResponse($response, $action)
    {
        $responseData = json_decode($response, true);
        $responseBody = is_string($response) ? $response : '';
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("{$action}响应解析失败", [
                'error' => json_last_error_msg(),
                'response_length' => strlen($responseBody),
            ]);
            return false;
        }
        
        if (!$responseData || !$responseData['ok']) {
            Log::error("{$action}API返回错误", array_merge([
                'response_length' => strlen($responseBody),
            ], self::summarizeJsonResponseForLog($responseData)));
            return false;
        }
        
        return true;
    }

    public static function parseApiResult($response, $action): array
    {
        $responseData = json_decode((string) $response, true);
        $responseBody = is_string($response) ? $response : '';

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("{$action}响应解析失败", [
                'error' => json_last_error_msg(),
                'response_length' => strlen($responseBody),
            ]);

            return [
                'ok' => false,
                'result' => null,
                'description' => json_last_error_msg(),
            ];
        }

        if (!$responseData || empty($responseData['ok'])) {
            Log::error("{$action}API返回错误", array_merge([
                'response_length' => strlen($responseBody),
            ], self::summarizeJsonResponseForLog($responseData)));

            return [
                'ok' => false,
                'result' => $responseData['result'] ?? null,
                'description' => (string)($responseData['description'] ?? $responseData['message'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'result' => $responseData['result'] ?? null,
            'description' => '',
        ];
    }
    
    /**
     * 发送基础文本回复
     */
    public static function sendBasicReply($chatId, $text, $replyToMessageId = null)
    {
        $startTime = microtime(true);
        
        try {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('发送回复失败：bot_token不存在');
                return false;
            }
            
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            
            $postFields = [
                'chat_id' => $chatId,
                'text' => $text
            ];
            
            if ($replyToMessageId) {
                $postFields['reply_to_message_id'] = $replyToMessageId;
            }
            
            $response = self::httpRequestWithRetry($url, $postFields, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20
            ]);
            
            if ($response === false) {
                Log::error('回复发送失败', ['chat_id' => $chatId]);
                return false;
            }
            
            return self::parseApiResponse($response, '发送回复');
        } catch (\Exception $e) {
            Log::error('发送回复异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 发送带键盘的回复
     */
    public static function sendKeyboardReply($chatId, $text, $keyboard)
    {
        try {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('发送键盘回复失败：bot_token不存在');
                return false;
            }
            
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            
            $replyMarkup = [
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];
            
            $postFields = [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE)
            ];
            
            $response = self::httpRequestWithRetry($url, $postFields, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20
            ]);
            
            if ($response === false) {
                Log::error('键盘回复发送失败', ['chat_id' => $chatId]);
                return false;
            }
            
            return self::parseApiResponse($response, '发送键盘回复');
        } catch (\Exception $e) {
            Log::error('发送键盘回复异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 删除消息
     */
    public static function deleteMessage($chatId, $messageId)
    {
        try {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('删除消息失败：bot_token不存在');
                return false;
            }
            
            $url = "https://api.telegram.org/bot{$botToken}/deleteMessage";
            
            $postFields = [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ];
            
            $response = self::httpRequestWithRetry($url, $postFields, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20
            ]);
            
            if ($response === false) {
                Log::error('删除消息失败', ['chat_id' => $chatId, 'message_id' => $messageId]);
                return false;
            }
            
            return self::parseApiResponse($response, '删除消息');
        } catch (\Exception $e) {
            Log::error('删除消息异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 调用API查询手机号话费
     */
    public static function queryPhoneBalance($phoneNumber)
{
    try {
        $apiUrl = Config::get('telegram.phone_bill_api.url');
        $ckey = Config::get('telegram.phone_bill_api.ckey');
        
        if (empty($apiUrl) || empty($ckey)) {
            throw new \Exception('话费查询API配置不完整');
        }

        if (!self::isSafeOutboundApiUrl((string) $apiUrl)) {
            throw new \Exception('话费查询API地址不安全');
        }
        
        // 标准化手机号
        $normalizedPhone = self::normalizePhoneNumber($phoneNumber);
        if (!$normalizedPhone) {
            throw new \Exception("手机号格式无效: {$phoneNumber}");
        }
        
        // 构建请求
        $postData = [
            'mobile' => $normalizedPhone,
            'ckey' => $ckey
        ];
        
        $apiUrlWithParams = $apiUrl . (strpos($apiUrl, '?') === false ? '?' : '&') . 
                           'ckey=' . urlencode($ckey) . 
                           '&mobile=' . urlencode($normalizedPhone);
        
        $response = self::httpRequestWithRetry($apiUrlWithParams, $postData, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'ckey: ' . $ckey,
                'User-Agent: CoolChargeBot/1.0'
            ]
        ]);
        
        if ($response === false) {
            throw new \Exception('API请求失败');
        }
        
        // 解析响应
        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("API响应解析失败: " . json_last_error_msg());
        }
        
        if (!$responseData) {
            throw new \Exception("API返回空数据");
        }
        
        // 处理错误码
        if (isset($responseData['code']) && $responseData['code'] != 200) {
            $errorMsg = $responseData['msg'] ?? "API错误 ({$responseData['code']})";
            throw new \Exception($errorMsg);
        }

        // ==============================================
        // 关键修改1：调整必要字段验证（只保留API实际返回的核心字段）
        // ==============================================
        // 原代码要求 now_isp、net，改为只校验必须的 mobile、mobile_fee
        $requiredFields = ['mobile', 'mobile_fee']; 
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($responseData['data'][$field])) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            throw new \Exception("API返回数据不完整，缺少字段：" . implode(',', $missingFields));
        }

        // ==============================================
        // 关键修改2：手动映射字段（适配代码后续使用的 now_isp、net）
        // ==============================================
        // 把API返回的 isp 映射为 now_isp
        $responseData['data']['now_isp'] = $responseData['data']['isp'] ?? '';
        // 把API返回的 province（省份）映射为 net（也可以用 city 或 isp 替代）
        $responseData['data']['net'] = $responseData['data']['province'] ?? $responseData['data']['isp'] ?? '';
        // 补充代码可能用到的 number_portability 字段（默认0，无携转）
        $responseData['data']['number_portability'] = 0;

        return $responseData;
    } catch (\Exception $e) {
        Log::error('话费查询API失败: ' . $e->getMessage());
        throw $e;
    }
}
    
    /**
     * 返还积分
     */
    public static function refundPoints($userId, $points, $phoneNumber)
    {
        try {
            $pointsService = new PointsService();
            
            // 第一次尝试
            $addResult = $pointsService->addPoints(
                $userId, 
                $points, 
                "查询手机号{$phoneNumber}话费失败，返还积分"
            );
            
            if ($addResult['code'] == 1) {
                return true;
            }
            
            // 第二次尝试
            usleep(500000);
            $addResult = $pointsService->addPoints(
                $userId, 
                $points, 
                "查询手机号{$phoneNumber}话费失败，二次返还积分"
            );
            
            return $addResult['code'] == 1;
            
        } catch (\Exception $e) {
            Log::critical('积分返还失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证手机号格式
     */
    public static function isValidPhoneNumber($phone)
    {
        $pattern = '/^(?:\+?86)?1[3-9]\d{9}$/';
        return preg_match($pattern, $phone) === 1;
    }
    
    /**
     * 标准化手机号格式
     */
    public static function normalizePhoneNumber($phone)
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($cleaned) == 11 && substr($cleaned, 0, 1) == '1') {
            return $cleaned;
        } elseif (strlen($cleaned) == 13 && substr($cleaned, 0, 2) == '86') {
            return substr($cleaned, 2);
        } elseif (strlen($cleaned) == 12 && substr($cleaned, 0, 3) == '086') {
            return substr($cleaned, 3);
        }
        
        return false;
    }
    
    /**
     * 手机号脱敏处理
     */
    public static function maskPhoneNumber($phone)
    {
        if (strlen($phone) == 11) {
            return substr($phone, 0, 3) . '****' . substr($phone, 7);
        }
        return $phone;
    }
    
    /**
     * 订单号脱敏处理
     */
    public static function maskOrderNumber($orderNumber)
    {
        if ($orderNumber === '未知') {
            return $orderNumber;
        }
        
        $length = strlen($orderNumber);
        
        if ($length <= 8) {
            return $orderNumber;
        }
        
        $prefix = substr($orderNumber, 0, 4);
        $suffix = substr($orderNumber, -4);
        $starsLength = $length - 8;
        $stars = str_repeat('*', $starsLength > 8 ? 8 : $starsLength);
        
        return $prefix . $stars . $suffix;
    }
    
    /**
     * 格式化订单信息
     */
    public static function formatOrderInfo($orderInfo, $productType, $productInfo)
    {
        // 产品类型0：显示产品名称
        if ($productType == 0) {
            $productData = json_decode($productInfo, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($productData['name'])) {
                return $productData['name'];
            }
            return '未知产品';
        }
        
        // 产品类型1：手机号脱敏
        if ($productType == 1) {
            $phonePattern = '/1[3-9]\d{9}/';
            preg_match($phonePattern, $orderInfo, $matches);
            
            if (empty($matches)) {
                return '无有效充值号码';
            }
            
            $phone = $matches[0];
            if (strlen($phone) == 11) {
                return substr($phone, 0, 3) . '****' . substr($phone, 7);
            }
            
            return $phone;
        }
        
        // 产品类型2、3：账号脱敏
        if (in_array($productType, [2, 3])) {
            $accountPatterns = [
                '/缴费户号[:=]\s*(\d{1,20})/',
                '/中燃账号[:=]\s*(\d{1,20})/'
            ];
            
            $accountNumber = null;
            foreach ($accountPatterns as $pattern) {
                if (preg_match($pattern, $orderInfo, $matches)) {
                    $accountNumber = $matches[1];
                    break;
                }
            }
            
            if ($accountNumber === null) {
                $fallbackPattern = '/\b\d{1,20}\b/';
                if (preg_match($fallbackPattern, $orderInfo, $matches)) {
                    $accountNumber = $matches[0];
                } else {
                    return '无有效账号信息';
                }
            }
            
            $length = strlen($accountNumber);
            if ($length <= 4) {
                $maskedAccount = $accountNumber;
            } elseif ($length <= 10) {
                $maskedAccount = substr($accountNumber, 0, 3) 
                    . str_repeat('*', $length - 5) 
                    . substr($accountNumber, -2);
            } else {
                $maskedAccount = substr($accountNumber, 0, 3) 
                    . str_repeat('*', $length - 7) 
                    . substr($accountNumber, -4);
            }
            
            return $maskedAccount;
        }
        
        return $orderInfo;
    }
    
    /**
     * 禁言用户
     */
    public static function banUser($chatId, $userId, $duration = 3600)
    {
        try {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('禁言用户失败：bot_token不存在');
                return false;
            }
            
            $url = "https://api.telegram.org/bot{$botToken}/restrictChatMember";
            
            $postData = [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'until_date' => time() + $duration,
                'permissions' => json_encode([
                    'can_send_messages' => false,
                    'can_send_media_messages' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info' => false,
                    'can_invite_users' => false,
                    'can_pin_messages' => false
                ])
            ];
            
            $response = self::httpRequestWithRetry($url, $postData);
            
            if ($response === false) {
                Log::error('禁言用户多次尝试后仍失败', [
                    'chat_id' => $chatId,
                    'user_id' => $userId
                ]);
                return false;
            }
            
            $result = self::parseApiResponse($response, '禁言用户');
            return $result === true;
            
        } catch (\Exception $e) {
            Log::error('禁言用户失败', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                'user_id' => $userId
            ]);
            return false;
        }
    }
    
    /**
     * 判断是否为群组聊天
     */
    public static function isGroupChat($chatId)
    {
        return $chatId < 0;
    }
    
    /**
     * 确定消息类型
     */
    public static function getMessageType($message)
    {
        if (isset($message['text'])) return 'text';
        if (isset($message['photo'])) return 'photo';
        if (isset($message['video'])) return 'video';
        if (isset($message['audio'])) return 'audio';
        if (isset($message['document'])) return 'document';
        if (isset($message['sticker'])) return 'sticker';
        if (isset($message['animation'])) return 'animation';
        if (isset($message['voice'])) return 'voice';
        return 'unknown';
    }
    
    /**
     * 异步日志记录
     */
    public static function asyncLog($file, $content)
    {
        try {
            $content = self::sanitizeAsyncLogContent($content);

            if (is_array($content) || is_object($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            
            $logContent = date('Y-m-d H:i:s') . ' ' . $content . "\n";
            
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $writeResult = file_put_contents($file, $logContent, FILE_APPEND | LOCK_EX);
            if ($writeResult === false) {
                throw new \Exception('日志写入失败');
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('日志写入失败: ' . $e->getMessage());
            return false;
        }
    }
    
        /**
     * 发送带inline键盘的消息
     */
        public static function sendInlineKeyboardReply($chatId, $text, $inlineKeyboard, $replyToMessageId = null)
        {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('发送Inline键盘回复失败：bot_token不存在');
                return false;
            }
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            
            $data = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => $inlineKeyboard
                ])
            ];
            
            if ($replyToMessageId) {
                $data['reply_to_message_id'] = $replyToMessageId;
            }
            
            // 关键修改：使用已存在的httpRequestWithRetry()替代未定义的httpRequest()
            $response = self::httpRequestWithRetry($url, $data, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20
            ]);

            if ($response === false) {
                Log::error('Inline键盘回复发送失败', ['chat_id' => $chatId]);
                return false;
            }

            $parsed = self::parseApiResult($response, '发送Inline键盘回复');
            return $parsed['ok'] ? $parsed['result'] : false;
        }

        /**
         * 回复回调查询
         */
        public static function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false)
        {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('回复回调查询失败：bot_token不存在');
                return false;
            }
            $url = "https://api.telegram.org/bot{$botToken}/answerCallbackQuery";
            
            $data = [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => $showAlert
            ];
            
            // 关键修改：使用已存在的httpRequestWithRetry()替代未定义的httpRequest()
            $response = self::httpRequestWithRetry($url, $data, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20
            ]);

            if ($response === false) {
                Log::error('回复回调查询失败', ['callback_query_id' => $callbackQueryId]);
                return false;
            }

            return self::parseApiResponse($response, '回复回调查询');
        }

        public static function editMessageText($chatId, $messageId, $text, $inlineKeyboard = null)
        {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('编辑消息失败：bot_token不存在');
                return false;
            }

            $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
            $data = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if (is_array($inlineKeyboard)) {
                $data['reply_markup'] = json_encode([
                    'inline_keyboard' => $inlineKeyboard,
                ], JSON_UNESCAPED_UNICODE);
            }

            $response = self::httpRequestWithRetry($url, $data, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);

            if ($response === false) {
                Log::error('编辑消息失败', ['chat_id' => $chatId, 'message_id' => $messageId]);
                return false;
            }

            return self::parseApiResponse($response, '编辑消息');
        }

        public static function editMessageReplyMarkup($chatId, $messageId, $inlineKeyboard = [])
        {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('编辑消息按钮失败：bot_token不存在');
                return false;
            }

            $url = "https://api.telegram.org/bot{$botToken}/editMessageReplyMarkup";
            $data = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode([
                    'inline_keyboard' => $inlineKeyboard,
                ], JSON_UNESCAPED_UNICODE),
            ];

            $response = self::httpRequestWithRetry($url, $data, [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);

            if ($response === false) {
                Log::error('编辑消息按钮失败', ['chat_id' => $chatId, 'message_id' => $messageId]);
                return false;
            }

            return self::parseApiResponse($response, '编辑消息按钮');
        }
    /**
 * 验证电费户号格式
 */
public static function isValidElectricityAccount($account)
{
    // 根据实际电费户号规则调整正则表达式
    return preg_match('/^\d{8,15}$/', $account);
}

/**
 * 掩码处理电费户号（显示前3位和后3位，中间用*代替）
 */
public static function maskElectricityAccount($account)
{
    $length = strlen($account);
    if ($length <= 6) {
        return $account;
    }
    return substr($account, 0, 3) . str_repeat('*', $length - 6) . substr($account, -3);
}

/**
 * 查询电费余额
 */
public static function queryElectricityBalance($accountNumber)
{
    try {
        // 修复1：从配置文件获取API URL（避免硬编码，便于维护）
        $apiConfig = Config::get('telegram.electricity_bill_api');
        
        // 修复2：使用正确的路径获取ckey
        $ckey = $apiConfig['ckey'] ?? '';
        $apiUrl = $apiConfig['url'] ?? 'https://api.xleyou.com/api/dianfei/get_dfcx';
        
        // 完善配置检查：同时验证URL和ckey
        if (empty($apiUrl) || empty($ckey)) {
            $error = empty($apiUrl) ? 'API地址未配置' : 'ckey未配置';
            Log::error("查询电费失败：{$error}");
            return ['code' => 500, 'msg' => '系统配置错误'];
        }

        if (!self::isSafeOutboundApiUrl((string) $apiUrl)) {
            Log::error('查询电费失败：API地址不安全', [
                'host' => (string) parse_url((string) $apiUrl, PHP_URL_HOST),
            ]);
            return ['code' => 500, 'msg' => '系统配置错误'];
        }
        
        $params = [
            // 注意：电费查询通常用"account"而非"mobile"，建议确认API参数名
            'account' => $accountNumber  // 可能需要根据API文档修正参数名
        ];
        
        $headers = [
            'ckey: ' . $ckey
        ];
        
        Log::info('查询电费', [
            'account' => self::maskElectricityAccount($accountNumber),
            'url' => $apiUrl
        ]);
        
        $response = self::httpRequestWithRetry(
            $apiUrl,
            $params,
            [
                CURLOPT_HTTPHEADER => $headers,
            ]
        );
        
        if ($response === false) {
            Log::error('查询电费API调用失败', ['account' => self::maskElectricityAccount($accountNumber)]);
            return ['code' => 503, 'msg' => '查询服务暂时不可用'];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('解析电费查询结果失败', [
                'response_length' => strlen((string) $response),
                'error' => json_last_error_msg()
            ]);
            return ['code' => 500, 'msg' => '解析查询结果失败'];
        }
        
        if (isset($result['code']) && $result['code'] == 200) {
            return [
                'code' => 200,
                'data' => $result['data'] ?? []
            ];
        } else {
            $errorMsg = $result['message'] ?? '查询失败';
            Log::warning('电费查询返回错误', [
                'account' => self::maskElectricityAccount($accountNumber),
                'code' => $result['code'] ?? 'unknown',
                'message' => $errorMsg
            ]);
            return ['code' => $result['code'] ?? 400, 'msg' => $errorMsg];
        }
        
    } catch (\Exception $e) {
        Log::error('查询电费异常', [
            'account' => self::maskElectricityAccount($accountNumber),
            'error' => $e->getMessage()
        ]);
        return ['code' => 500, 'msg' => '查询过程中发生错误'];
    }
}

private static function isSafeOutboundApiUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = trim((string)($parts['host'] ?? ''), '[]');
    $user = (string)($parts['user'] ?? '');
    $pass = (string)($parts['pass'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $user !== '' || $pass !== '') {
        return false;
    }

    $normalizedHost = strtolower($host);
    if ($normalizedHost === 'localhost' || str_ends_with($normalizedHost, '.local')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return self::isPublicIpAddress($host);
    }

    $resolvedIps = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $candidate = (string)($record['ip'] ?? ($record['ipv6'] ?? ''));
                if ($candidate !== '') {
                    $resolvedIps[] = $candidate;
                }
            }
        }
    }

    if ($resolvedIps === [] && function_exists('gethostbynamel')) {
        $fallbackIps = @gethostbynamel($host);
        if (is_array($fallbackIps)) {
            $resolvedIps = $fallbackIps;
        }
    }

    foreach ($resolvedIps as $resolvedIp) {
        if (!self::isPublicIpAddress($resolvedIp)) {
            return false;
        }
    }

    return true;
}

private static function isPublicIpAddress(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}
}
