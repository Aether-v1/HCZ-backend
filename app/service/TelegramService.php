<?php
namespace app\service;

use app\common\library\TelegramHelper;
use app\model\User as UserModel;
use app\service\PointsService;
use app\service\UserService;
use app\service\telegram\CommandHandler;
use app\service\telegram\MessageHandler;
use app\service\telegram\QueryHandler;
use app\service\telegram\BindingManager;
use app\service\telegram\TimerManager;
use think\facade\Config;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Queue;
use think\db\exception\DbException;
use think\facade\Db;

class TelegramService
{
    /** @var CommandHandler 命令处理器 */
    private $commandHandler;

    /** @var MessageHandler 消息处理器 */
    private $messageHandler;

    /** @var QueryHandler 查询处理器 */
    private $queryHandler;

    /** @var BindingManager 绑定管理器 */
    private $bindingManager;

    /** @var TimerManager 定时任务管理器 */
    private $timerManager;

    public function __construct()
    {
        // 初始化组件
        $this->commandHandler = new CommandHandler($this);
        $this->messageHandler = new MessageHandler($this);
        $this->queryHandler = new QueryHandler($this);
        $this->bindingManager = new BindingManager($this);
        $this->timerManager = new TimerManager($this);
    }

    /**
     * 获取配置常量（从配置文件读取）
     */
    public function getConstant($key, $default = null)
    {
        return Config::get("telegram.bot_constants.{$key}", $default);
    }

    /**
     * 设置Telegram机器人左侧命令菜单
     */
    public function setBotCommands()
    {
        try {
            $botToken = Config::get('telegram.bot_token');
            if (empty($botToken)) {
                Log::error('设置机器人命令失败：机器人凭证未配置');
                return false;
            }

            $url = "https://api.telegram.org/bot{$botToken}/setMyCommands";

            $commands = [
                ['command' => 'start', 'description' => '开始使用机器人'],
                ['command' => 'bind', 'description' => '使用绑定码绑定账号'],
                ['command' => 'unbind', 'description' => '解绑账号'],
                ['command' => 'adminhelp', 'description' => '机器人管理（命令说明）']
            ];

            $postData = ['commands' => json_encode($commands, JSON_UNESCAPED_UNICODE)];

            Log::info('尝试设置机器人命令', ['commands' => $commands, 'api' => 'setMyCommands']);

            $response = TelegramHelper::httpRequestWithRetry(
                $url, 
                $postData
            );

            if ($response === false) {
                Log::error('设置命令菜单多次尝试后仍失败');
                return false;
            }

            return TelegramHelper::parseApiResponse($response, '设置命令菜单');
        } catch (\Throwable $e) {
            Log::error('设置机器人命令菜单失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    public function setWebhookFromConfig(): array
    {
        $payload = [
            'url' => trim((string) Config::get('telegram.webhook_url', '')),
        ];

        if ($payload['url'] === '') {
            return ['code' => 0, 'msg' => 'telegram.webhook_url 未配置'];
        }

        $secret = trim((string) Config::get('telegram.webhook_secret', ''));
        if ($secret !== '') {
            $payload['secret_token'] = $secret;
        }

        $allowedUpdates = Config::get('telegram.webhook_allowed_updates', []);
        if (is_array($allowedUpdates) && !empty($allowedUpdates)) {
            $payload['allowed_updates'] = json_encode(array_values($allowedUpdates), JSON_UNESCAPED_UNICODE);
        }

        $maxConnections = (int) Config::get('telegram.webhook_max_connections', 40);
        if ($maxConnections > 0) {
            $payload['max_connections'] = $maxConnections;
        }

        return $this->callTelegramApi('setWebhook', $payload, '设置Webhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->callTelegramApi('getWebhookInfo', [], '获取Webhook信息');
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        $payload = [];
        if ($dropPendingUpdates) {
            $payload['drop_pending_updates'] = 'true';
        }

        return $this->callTelegramApi('deleteWebhook', $payload, '删除Webhook');
    }

    /**
     * 处理个人消息
     */
    public function handlePrivateMessage($message)
    {
        return $this->messageHandler->handlePrivateMessage($message);
    }

    /**
     * 处理群组消息
     */
    public function handleGroupMessage($message)
    {
        return $this->messageHandler->handleGroupMessage($message);
    }

    /**
     * 处理分页回调查询
     */
    public function handleCallbackQuery($callbackQuery)
    {
        return $this->queryHandler->handleCallbackQuery($callbackQuery);
    }

    /**
     * 处理签到功能
     */
    public function handleCheckIn($chatId, $tgUserId)
    {
        return $this->commandHandler->handleCheckIn($chatId, $tgUserId);
    }

    /**
     * 处理单个手机号话费查询
     */
    public function handlePhoneBalanceQuery($chatId, $tgUserId, $phoneNumber, $messageId = null)
    {
        return $this->queryHandler->handlePhoneBalanceQuery($chatId, $tgUserId, $phoneNumber, $messageId);
    }

    /**
     * 处理单个电费户号查询
     */
    public function handleElectricityQuery($chatId, $tgUserId, $accountNumber, $messageId = null)
    {
        return $this->queryHandler->handleElectricityQuery($chatId, $tgUserId, $accountNumber, $messageId);
    }

    /**
     * 批量查询话费加入队列
     */
    public function queueBatchPhoneQuery($chatId, $tgUserId, $text, $messageId = null)
    {
        return $this->queryHandler->queueBatchPhoneQuery($chatId, $tgUserId, $text, $messageId);
    }

    /**
     * 批量查询电费加入队列
     */
    public function queueBatchElectricityQuery($chatId, $tgUserId, $text, $messageId = null)
    {
        return $this->queryHandler->queueBatchElectricityQuery($chatId, $tgUserId, $text, $messageId);
    }

    /**
     * 发送主菜单
     */
    public function sendMainMenu($chatId)
    {
        try {
            $keyboard = [
                ['📊 账户查询', '📋 查询订单'],
                ['⚡ 电费查询', '📱 话费查询'],
                ['🔍 官网地址', '👨‍💼 官方客服']
            ];

            $response = $this->sendKeyboardReply($chatId, "请选择需要的功能：", $keyboard);

            if ($response === false) {
                Log::error('发送键盘消息失败，使用基础回复');
                $menuText = "请选择需要的功能：\n1. 📊 账户查询\n2. 📋 查询订单\n3. ⚡ 电费查询\n4. 📱 话费查询\n5. 🔍 官网地址\n6. 👨‍💼 官方客服";
                $this->sendBasicReply($chatId, $menuText);
            }

        } catch (\Throwable $e) {
            Log::error('发送主菜单失败', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);

            $menuText = "请选择需要的功能：\n1. 📊 账户查询\n2. 📋 查询订单\n3. ⚡ 电费查询\n4. 📱 话费查询\n5. 🔍 官网地址\n6. 👨‍💼 官方客服";
            $this->sendBasicReply($chatId, $menuText);
        }
    }

    /**
     * 发送订单状态变更通知
     */
    public function sendOrderStatusNotification(
        int $userId,
        string $orderNumber,
        string $amount,
        string $newStatusText,
        string $updateTime = '',
        string $bizType = '订单'
    ): bool {
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id, telegram_id, tg_is_bind')
                ->find();

            Log::info('订单通知 - 用户查询结果', [
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'user_data' => $user,
                'tg_is_bind' => $user['tg_is_bind'] ?? '未查询到',
            ]);

            if (!$user) {
                Log::warning('订单通知 - 用户不存在', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                ]);
                return false;
            }

            if (empty($user['tg_is_bind']) || (int)$user['tg_is_bind'] !== 1) {
                Log::warning('订单通知 - 用户未绑定Telegram', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'tg_is_bind' => $user['tg_is_bind'] ?? null,
                ]);
                return false;
            }

            if (empty($user['telegram_id']) || !is_numeric((string)$user['telegram_id'])) {
                Log::warning('订单通知 - Telegram ID无效', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'telegram_id' => $user['telegram_id'] ?? null,
                ]);
                return false;
            }

            $chatId = (int)$user['telegram_id'];
            $updateTime = $updateTime ?: date('Y-m-d H:i:s');

            $message = "📊 <b>订单状态更新</b>\n";
            $message .= "类型：{$bizType}\n";
            $message .= "订单号：{$orderNumber}\n";
            $message .= "金额：{$amount}\n";
            $message .= "当前状态：<b>{$newStatusText}</b>\n";
            $message .= "更新时间：{$updateTime}";

            $sendResult = TelegramHelper::sendBasicReply(
                $chatId,
                $message,
                null
            );

            if ($sendResult) {
                Log::info('订单通知 - 发送成功', [
                    'user_id' => $userId,
                    'chat_id' => $chatId,
                    'order_number' => $orderNumber,
                    'biz_type' => $bizType,
                    'status_text' => $newStatusText,
                ]);
                return true;
            }

            Log::error('订单通知 - 发送失败', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'order_number' => $orderNumber,
                'biz_type' => $bizType,
                'status_text' => $newStatusText,
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('订单通知 - 发送异常', [
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'biz_type' => $bizType ?? '订单',
                'error' => $e->getMessage(),
                'module' => 'TelegramService',
            ]);
            return false;
        }
    }

    public function sendOrderCompletedNotification(
        int $userId,
        string $orderNumber,
        string $amount,
        string $bizType = '订单',
        string $completeTime = ''
    ): bool {
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->field('id, telegram_id, tg_is_bind')
                ->find();

            if (!$user) {
                Log::warning('订单完成通知 - 用户不存在', ['user_id' => $userId, 'order_number' => $orderNumber]);
                return false;
            }

            if (empty($user['tg_is_bind']) || (int)$user['tg_is_bind'] !== 1) {
                Log::info('订单完成通知 - 用户未绑定TG', ['user_id' => $userId, 'order_number' => $orderNumber]);
                return false;
            }

            if (empty($user['telegram_id']) || !is_numeric((string)$user['telegram_id'])) {
                Log::warning('订单完成通知 - telegram_id无效', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'telegram_id' => $user['telegram_id'] ?? null,
                ]);
                return false;
            }

            $chatId = (int)$user['telegram_id'];
            $completeTime = $completeTime ?: date('Y-m-d H:i:s');

            $message = "✅ 订单已完成\n";
            $message .= "类型：{$bizType}\n";
            $message .= "订单号：{$orderNumber}\n";
            $message .= "金额：{$amount}\n";
            $message .= "完成时间：{$completeTime}";

            $result = TelegramHelper::sendBasicReply(
                $chatId,
                $message,
                null
            );

            Log::info('订单完成通知结果', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'order_number' => $orderNumber,
                'biz_type' => $bizType,
                'result' => $result ? 'success' : 'fail',
            ]);

            return (bool)$result;
        } catch (\Throwable $e) {
            Log::error('订单完成通知异常', [
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 处理定时消息发送
     */
    public function processTimers()
    {
        return $this->timerManager->processTimers();
    }

    /**
     * 记录请求计数
     */
    public function recordRequest()
    {
        $today = date('Ymd');
        $key = $this->getConstant('daily_request_count_key', 'tg_daily_requests') . ":{$today}";
        Cache::store('redis')->inc($key, 1);
        $expire = strtotime('tomorrow') - time() + 3600;
        Cache::store('redis')->expire($key, $expire);
    }

    /**
     * 发送基础文本回复
     */
    public function sendBasicReply($chatId, $text, $replyToMessageId = null)
    {
        return TelegramHelper::sendBasicReply(
            $chatId, 
            $text, 
            $replyToMessageId
        );
    }

    /**
     * 发送带键盘的回复
     */
    public function sendKeyboardReply($chatId, $text, $keyboard)
    {
        return TelegramHelper::sendKeyboardReply($chatId, $text, $keyboard);
    }

    /**
     * 发送带inline键盘的回复
     */
    public function sendInlineKeyboardReply($chatId, $text, $inlineKeyboard, $replyToMessageId = null)
    {
        return TelegramHelper::sendInlineKeyboardReply($chatId, $text, $inlineKeyboard, $replyToMessageId);
    }

    public function editMessageText($chatId, $messageId, $text, $inlineKeyboard = null)
    {
        return TelegramHelper::editMessageText($chatId, $messageId, $text, $inlineKeyboard);
    }

    public function editMessageReplyMarkup($chatId, $messageId, $inlineKeyboard = [])
    {
        return TelegramHelper::editMessageReplyMarkup($chatId, $messageId, $inlineKeyboard);
    }

    /**
     * 回复回调查询
     */
    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false)
    {
        return TelegramHelper::answerCallbackQuery(
            $callbackQueryId, 
            $text, 
            $showAlert
        );
    }

    /**
     * 删除消息
     */
    public function deleteMessage($chatId, $messageId)
    {
        return TelegramHelper::deleteMessage($chatId, $messageId);
    }

    /**
     * 检查用户请求频率限制
     */
    public function checkRateLimit($tgUserId)
    {
        $rateLimit = $this->getConstant('rate_limit_seconds', 2);
        $cacheKey = $this->getCachePrefix() . "rate_limit:{$tgUserId}";
        $lastRequest = Cache::get($cacheKey);

        if ($lastRequest && (time() - $lastRequest) < $rateLimit) {
            return false;
        }

        try {
            Cache::store('redis')->tag('rate_limit')->set($cacheKey, time(), $rateLimit);
        } catch (\Exception $e) {
            Log::error('频率限制缓存设置失败', [
                'key' => $cacheKey,
                'error' => $e->getMessage()
            ]);
        }

        return true;
    }

    /**
     * 获取用户绑定信息
     */
    public function getUserBindInfo($tgUserId)
    {
        return $this->bindingManager->getUserBindInfo($tgUserId);
    }

    /**
     * 检查用户绑定状态
     */
    public function checkUserBinding($chatId, $tgUserId)
    {
        return $this->bindingManager->checkUserBinding($chatId, $tgUserId);
    }

    public function generateBindCodeForUser(int $userId): array
    {
        return $this->bindingManager->generateBindCodeForUser($userId);
    }

    public function getBindingStatusForUser(int $userId): array
    {
        return $this->bindingManager->getBindingStatusForUser($userId);
    }

    public function bindUserByCode(string $bindCode, int $tgUserId, int $tgChatId, string $tgUsername = ''): array
    {
        return $this->bindingManager->bindUserByCode($bindCode, $tgUserId, $tgChatId, $tgUsername);
    }

    public function unbindByUserId(int $userId): array
    {
        return $this->bindingManager->unbindByUserId($userId);
    }

    /**
     * 获取缓存前缀
     */
    public function getCachePrefix()
    {
        return $this->getConstant('cache_prefix', 'tg_');
    }

    /**
     * 获取管理员用户ID列表
     */
    public function getAdminUserIds()
    {
        return $this->getConstant('admin_user_ids', [123456789, 987654321]);
    }

    private function callTelegramApi(string $method, array $postData, string $action): array
    {
        try {
            $botToken = trim((string) Config::get('telegram.bot_token', ''));
            if ($botToken === '') {
                Log::error($action . '失败：机器人凭证未配置');
                return ['code' => 0, 'msg' => 'telegram.bot_token 未配置'];
            }

            $url = "https://api.telegram.org/bot{$botToken}/{$method}";
            $response = TelegramHelper::httpRequestWithRetry($url, $postData);
            if ($response === false) {
                Log::error($action . '请求失败', ['api' => $method]);
                return ['code' => 0, 'msg' => $action . '请求失败'];
            }

            $responseData = json_decode($response, true);
            if (!is_array($responseData)) {
                Log::error($action . '响应解析失败', [
                    'api' => $method,
                    'json_error' => json_last_error_msg(),
                ]);
                return ['code' => 0, 'msg' => $action . '响应解析失败'];
            }

            if (empty($responseData['ok'])) {
                Log::error($action . '接口返回失败', [
                    'api' => $method,
                    'error_code' => $responseData['error_code'] ?? null,
                    'description' => $responseData['description'] ?? null,
                ]);
                return ['code' => 0, 'msg' => $responseData['description'] ?? ($action . '失败')];
            }

            return [
                'code' => 1,
                'msg' => $action . '成功',
                'data' => $responseData['result'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error($action . '异常', [
                'api' => $method,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['code' => 0, 'msg' => $action . '异常'];
        }
    }

    // 以下为各组件的getter方法，方便组件间调用
    public function getCommandHandler()
    {
        return $this->commandHandler;
    }

    public function getMessageHandler()
    {
        return $this->messageHandler;
    }

    public function getQueryHandler()
    {
        return $this->queryHandler;
    }

    public function getBindingManager()
    {
        return $this->bindingManager;
    }

    public function getTimerManager()
    {
        return $this->timerManager;
    }
}
