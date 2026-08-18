<?php
namespace app\service\telegram;

use app\common\library\TelegramHelper;
use app\service\TelegramService;
use app\service\PointsService;
use app\service\UserService;
use think\facade\Log;
use think\facade\Config;
use think\db\exception\DbException;
use think\facade\Db;

class CommandHandler
{
    /** @var TelegramService 主服务实例 */
    private $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * 处理命令
     */
    public function handleCommand($chatId, $tgUserId, $tgUsername, $text, $messageId)
    {
        $this->telegramService->recordRequest();

        $command = trim($this->parseCommand((string)$text));
        $command = preg_replace('/\s+/u', ' ', $command);

        Log::debug('处理命令', $this->sanitizeLogContext([
            'raw_text_len' => $this->getLogTextLength($text),
            'raw_text_hash' => $this->hashLogText($text),
            'command' => $command,
            'chat_id' => $chatId,
            'tg_user_id' => $tgUserId,
            'message_id' => $messageId,
        ]));

        // 检查是否为管理员命令
        $adminCommands = ['/ban', '/user', '/adjustpoints', '/system', '/settimer', '/timers', '/deltimer'];
        if (in_array($command, $adminCommands, true)) {
            // 去掉命令前缀，只保留参数部分（兼容带@机器人名的情况，比如 /adjustpoints@MyBot）
            $paramText = trim((string)preg_replace('/^\/' . preg_quote(ltrim($command, '/'), '/') . '(@\w+)?\s*/u', '', (string)$text));
            // 按任意空格拆分参数（兼容多个空格/制表符）
            $params = preg_split('/\s+/u', $paramText);
            // 过滤空参数
            $params = array_filter($params, function ($item) {
                return !empty(trim((string)$item));
            });
            // 重置数组索引
            $params = array_values($params);

            $this->handleAdminCommand($chatId, $tgUserId, $command, $params);
            return;
        }

        // 兼容带 emoji、额外空格、不同前缀的按钮文本
        if ($command === '/start') {
            $this->handleStartCommand($chatId);
            return;
        }

        if ($command === '/bind' || strpos($command, '绑定') !== false) {
            $this->handleBindCommand($chatId, $tgUserId, (string)$text, (string)$tgUsername);
            return;
        }

        if ($command === '/account' || strpos($command, '账户查询') !== false) {
            $this->handleAccountQuery($chatId, $tgUserId, $messageId);
            return;
        }

        if ($command === '/orders' || strpos($command, '查询订单') !== false) {
            $params = preg_split('/\s+/u', trim((string)$text));
            $page = isset($params[1]) && is_numeric($params[1]) ? (int)$params[1] : 1;
            $this->handleOrderQuery($chatId, $tgUserId, $messageId, $page);
            return;
        }

        if ($command === '/phone' || strpos($command, '话费查询') !== false) {
            $this->handlePhoneCommand($chatId);
            return;
        }

        if ($command === '/electricity' || strpos($command, '电费查询') !== false) {
            $this->handleElectricityCommand($chatId);
            return;
        }

        if (strpos($command, '官网地址') !== false) {
            $this->handleWebsiteCommand($chatId);
            return;
        }

        if (strpos($command, '官方客服') !== false) {
            $this->handleSupportCommand($chatId);
            return;
        }

        if ($command === '/unbind' || $command === '解绑') {
            $this->handleUnbindCommand($chatId, $tgUserId);
            return;
        }

        if ($command === '/adminhelp' || strpos($command, '机器人管理') !== false) {
            $this->handleAdminHelpCommand($chatId, $tgUserId);
            return;
        }

        Log::info('未匹配到命令处理分支', $this->sanitizeLogContext([
            'raw_text_len' => $this->getLogTextLength($text),
            'raw_text_hash' => $this->hashLogText($text),
            'normalized_command' => $command,
            'chat_id' => $chatId,
            'tg_user_id' => $tgUserId,
            'message_id' => $messageId,
        ]));
    }

    /**
     * 处理机器人管理命令（返回所有命令说明）
     */
    private function handleAdminHelpCommand($chatId, $tgUserId)
    {
        // 基础命令说明
        $basicCommands = "📱 基础功能命令：\n" .
            "/start - 开始使用机器人，显示欢迎信息\n" .
            "/bind 绑定码 - 绑定网站账号\n" .
            "/unbind - 解绑网站账号\n" .
            "/account - 查询账户信息（余额、积分等）\n" .
            "/orders - 查询我的订单记录\n" .
            "/phone - 查看话费查询说明\n" .
            "批量查话费\n[手机号1]\n[手机号2] - 批量查询多个手机号话费\n" .
            "/electricity - 查看电费查询说明\n" .
            "批量查电费\n[户号1]\n[户号2] - 批量查询多个电费户号\n\n" .
            "签到 - 每日签到获取积分\n" .
            "/unbind - 解绑网站账号\n";

        // 管理员命令说明
        $adminCommands = "";
        if (in_array($tgUserId, $this->telegramService->getAdminUserIds(), true)) {
            $adminCommands = "🔐 管理员命令：\n" .
                "/ban [用户ID] [时长(分钟)] - 在群组中禁言指定用户，默认60分钟\n" .
                "/user [TG用户ID] - 查询指定TG用户的绑定信息\n" .
                "/adjustpoints [TG用户ID] [积分数量] - 调整用户积分（正数增加，负数减少）\n" .
                "/system - 查看系统状态（请求量、活跃用户、队列等）\n" .
                "/settimer [分钟] [消息] - 设置一次性定时消息\n" .
                "/settimer daily [时间(HH:MM)] [消息] - 设置每日定时消息\n" .
                "/timers - 查看所有定时任务列表\n" .
                "/deltimer [任务ID] - 删除指定定时任务\n\n";
        }

        $this->telegramService->sendBasicReply(
            $chatId,
            "🤖 机器人命令说明\n\n" .
            $basicCommands .
            $adminCommands .
            "ℹ️ 提示：命令可通过左侧菜单直接选择，无需手动输入"
        );
    }

    /**
     * 处理/start命令
     */
    private function handleStartCommand($chatId)
    {
        $botIntroduction = "👋 欢迎使用酷充站Pro-BOT！\n\n" .
                          "我是您的个人助手，可以帮助您：\n" .
                          "- 查询账户信息\n" .
                          "- 查询订单信息\n" .
                          "- 查询手机话费（发送手机号码即可）\n" .
                          "- 批量查（发送\"批量查话费+多行号码\"）\n" .
                          "- 查询电费（发送电费户号即可）\n" .
                          "- 批量查（发送\"批量查电费+多行户号\"）\n" .
                          "- 查看官方网站\n" .
                          "- 联系官方客服\n" .
                          "- 每日签到获取积分\n\n" .
                          "要使用完整功能，请先在平台获取绑定码，再发送 /bind 绑定码 完成绑定。";

        $this->telegramService->sendBasicReply($chatId, $botIntroduction);
        $this->telegramService->sendMainMenu($chatId);
    }

    /**
     * 处理/bind命令
     */
    public function handleBindCommand($chatId, $tgUserId, string $text = '', string $tgUsername = '')
    {
        // 检查是否在群聊中
        if (\app\common\library\TelegramHelper::isGroupChat($chatId)) {
            $this->telegramService->sendBasicReply($chatId, 
                "为了保护您的账号安全，请在机器人私聊窗口发送 /bind 绑定码 完成绑定。\n"
            );
            return;
        }

        $normalizedText = preg_replace('/\s+/u', ' ', trim($text));
        $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);

        if (!preg_match('/^\/bind(?:@\w+)?(?:\s+(.*))?$/u', (string)$normalizedText, $matches)) {
            $this->telegramService->sendBasicReply($chatId, "绑定命令格式错误，请发送 /bind 绑定码");
            return;
        }

        $bindCode = strtoupper(trim((string)($matches[1] ?? '')));
        if ($bindCode === '') {
            if ($bindInfo) {
                $this->telegramService->sendBasicReply($chatId, "您已经绑定了账号，无需重复绑定。如需解绑，请发送 /unbind");
                return;
            }

            $this->telegramService->sendBasicReply($chatId, "请先在平台 TG绑定 页面获取绑定码，再发送 /bind 绑定码，例如：/bind A1B2C3D4");
            return;
        }

        $result = $this->telegramService->bindUserByCode($bindCode, (int)$tgUserId, (int)$chatId, $tgUsername);
        $this->telegramService->sendBasicReply($chatId, (string)($result['message'] ?? '绑定失败'));

        if (!empty($result['success'])) {
            $this->telegramService->sendMainMenu($chatId);
        }
    }

    /**
     * 处理/unbind命令
     */
    private function handleUnbindCommand($chatId, $tgUserId)
    {
        $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);

        if (!$bindInfo) {
            $this->telegramService->sendBasicReply($chatId, "您尚未绑定账号，无需解绑。");
            return;
        }

        $result = $this->telegramService->unbindByUserId((int)$bindInfo['user_id']);
        $this->telegramService->sendBasicReply($chatId, (string)($result['message'] ?? '解绑失败，请稍后再试。'));
    }

    /**
     * 处理账户查询命令
     */
    public function handleAccountQuery($chatId, $tgUserId, $replyToMessageId = null)
    {
        try {
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }

            // 查询账户信息
            $userService = new UserService();
            $accountInfo = $userService->getBalance($userId);

            if (!$accountInfo || !isset($accountInfo['available'], $accountInfo['points'])) {
                throw new \Exception('账户信息格式错误');
            }

            // 查询订单统计
            $orderStats = $this->getOrderStats($userId);
            $totalOrders = $orderStats['pending'] + $orderStats['processing'] + $orderStats['completed'];

            // 构建回复
            $messageText = "📊 账户信息\n";
            $messageText .= "• 可用余额：{$accountInfo['available']}\n";
            $messageText .= "• 积分：{$accountInfo['points']}\n\n";
            $messageText .= "📝 订单统计\n";
            $messageText .= "• 订单总数：{$totalOrders}\n";
            $messageText .= "• 待充值：{$orderStats['pending']}\n";
            $messageText .= "• 充值中：{$orderStats['processing']}\n";
            $messageText .= "• 已完成：{$orderStats['completed']}";

            $this->telegramService->sendBasicReply($chatId, $messageText, $replyToMessageId);

        } catch (\Exception $e) {
            Log::error('账户查询异常', $this->buildThrowableLogContext($e, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $replyToMessageId,
            ]));
            $this->telegramService->sendBasicReply($chatId, "查询过程中出现错误，请稍后重试。", $replyToMessageId);
        }
    }

    /**
     * 处理订单查询命令
     */
    public function handleOrderQuery($chatId, $tgUserId, $replyToMessageId = null, $page = 1)
    {
        try {
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }

            if (!class_exists('\app\service\OrderService')) {
                throw new \Exception('订单服务未找到');
            }

            $orderService = new \app\service\OrderService();
            // 每页显示5个订单
            $perPage = 5;
            // 使用OrderService的常量进行状态筛选
            $statusFilter = [
                \app\service\OrderService::STATUS_PENDING,
                \app\service\OrderService::STATUS_PROCESSING,
                \app\service\OrderService::STATUS_COMPLETED
            ];

            // 获取符合条件的订单总数（用于分页）
            $totalOrders = $orderService->getUserOrdersCount($userId, $statusFilter);
            // 计算总页数
            $totalPages = max(1, ceil($totalOrders / $perPage));
            // 确保页码在有效范围内
            $currentPage = max(1, min($page, $totalPages));

            // 获取当前页的订单
            $orders = $orderService->getUserOrdersByPage(
                $userId, 
                $currentPage, 
                $perPage,
                $statusFilter
            );

            if (empty($orders)) {
                $this->telegramService->sendBasicReply($chatId, "未查询到您的订单信息。", $replyToMessageId);
                return;
            }

            // 格式化订单信息
            $messageText = "您的订单列表（第{$currentPage}/{$totalPages}页）：\n\n";
            foreach ($orders as $index => $order) {
                $orderNumber = \app\common\library\TelegramHelper::maskOrderNumber($order['order_number'] ?? '未知');
                $amount = $order['amount'] ?? '0.00 元';
                $status = \app\service\OrderService::getStatusText($order['status'] ?? 0);
                $createTime = isset($order['create_time']) ? date('Y-m-d H:i', strtotime($order['create_time'])) : '未知时间';

                $formattedInfo = \app\common\library\TelegramHelper::formatOrderInfo(
                    $order['order_info'] ?? '',
                    $order['product_type'] ?? 0,
                    $order['product_info'] ?? ''
                );

                $messageText .= ($index + 1 + ($currentPage - 1) * $perPage) . ". 订单号：{$orderNumber}\n";
                $messageText .= "   充值信息：{$formattedInfo}\n";
                $messageText .= "   金额：{$amount}\n";
                $messageText .= "   状态：{$status}\n";
                $messageText .= "   时间：{$createTime}\n\n";
            }

            // 添加平台查询提示
            $messageText .= "只显示部分订单，更多订单详情请前往平台查询！\n";

            // 构建键盘按钮
            $keyboard = [];

            // "登录官网"按钮
            $keyboard[] = [
                [
                    'text' => '登录官网',
                    'url' => 'https://your-frontend-domain.com/login'
                ]
            ];

            // 分页按钮
            $pageButtons = [];

            // 添加首页按钮
            if ($currentPage > 1) {
                $pageButtons[] = [
                    'text' => '首页',
                    'callback_data' => "order_page_1"
                ];
            }

            // 添加上一页按钮
            if ($currentPage > 1) {
                $pageButtons[] = [
                    'text' => '上一页',
                    'callback_data' => "order_page_" . ($currentPage - 1)
                ];
            }

            // 添加页码按钮
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $startPage + 4);

            for ($i = $startPage; $i <= $endPage; $i++) {
                $pageButtons[] = [
                    'text' => $i == $currentPage ? "{$i}" : "{$i}",
                    'callback_data' => "order_page_{$i}"
                ];
            }

            // 添加下一页按钮
            if ($currentPage < $totalPages) {
                $pageButtons[] = [
                    'text' => '下一页',
                    'callback_data' => "order_page_" . ($currentPage + 1)
                ];
            }

            // 添加末页按钮
            if ($currentPage < $totalPages) {
                $pageButtons[] = [
                    'text' => '末页',
                    'callback_data' => "order_page_{$totalPages}"
                ];
            }

            // 添加分页按钮
            if (!empty($pageButtons)) {
                $keyboard[] = $pageButtons;
            }

            // 发送带分页按钮的消息
            $this->telegramService->sendInlineKeyboardReply($chatId, $messageText, $keyboard, $replyToMessageId);

        } catch (\Exception $e) {
            Log::error('订单查询异常', $this->buildThrowableLogContext($e, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
                'message_id' => $replyToMessageId,
            ]));
            $this->telegramService->sendBasicReply($chatId, "查询过程中出现错误，请稍后重试。", $replyToMessageId);
        }
    }

    /**
     * 处理话费查询命令
     */
    private function handlePhoneCommand($chatId)
    {
        $checkPoints = $this->telegramService->getConstant('balance_check_points', 3);
        $this->telegramService->sendBasicReply($chatId, "请直接发送手机号码查询话费，每次查询将扣除{$checkPoints}积分。\n如需批量查询，请发送\"批量查话费+多行号码\"，例如：\n批量查话费\n13800138000\n13900139000");
    }

    /**
     * 处理电费查询命令
     */
    private function handleElectricityCommand($chatId)
    {
        $checkPoints = $this->telegramService->getConstant('electricity_check_points', 3);
        $this->telegramService->sendBasicReply($chatId, "请直接发送电费户号查询电费，每次查询将扣除{$checkPoints}积分。\n如需批量查询，请发送\"批量查电费+多行户号\"，例如：\n批量查电费\n1000123456\n1000654321");
    }

    /**
     * 处理官网地址命令
     */
    private function handleWebsiteCommand($chatId)
    {
        $officialSite = \think\facade\Config::get('telegram.official_info.website');
        if (empty($officialSite)) {
            Log::error('官网地址配置不存在');
            $this->telegramService->sendBasicReply($chatId, "官网地址配置错误，请联系客服。");
        } else {
            $this->telegramService->sendBasicReply($chatId, "官方网站地址：\n" . $officialSite);
        }
    }

    /**
     * 处理客服命令
     */
    private function handleSupportCommand($chatId)
    {
        $support = \think\facade\Config::get('telegram.official_info.support_username');
        if (empty($support)) {
            Log::error('客服账号配置不存在');
            $this->telegramService->sendBasicReply($chatId, "客服账号配置错误，请联系管理员。");
        } else {
            $this->telegramService->sendBasicReply($chatId, "官方客服TG账号：\n@" . $support);
        }
    }

    /**
     * 处理签到功能
     */
    public function handleCheckIn($chatId, $tgUserId)
    {
        try {
            $userId = $this->telegramService->checkUserBinding($chatId, $tgUserId);
            if ($userId === false) {
                return;
            }

            // 检查今日是否已签到
            $checkinCacheKey = $this->telegramService->getCachePrefix() . "checkin:{$userId}:" . date('Ymd');
            if (\think\facade\Cache::has($checkinCacheKey)) {
                $this->telegramService->sendBasicReply($chatId, "您今天已经签过到了，明天再来吧！");
                return;
            }

            // 执行签到
            $pointsService = new PointsService();
            $checkinResult = $pointsService->signIn($userId);

            if ($checkinResult['code'] != 1) {
                throw new \Exception($checkinResult['msg'] ?? '签到失败');
            }

            // 记录签到状态
            $secondsToMidnight = strtotime('tomorrow') - time();
            \think\facade\Cache::set($checkinCacheKey, 1, $secondsToMidnight);

            // 发送结果
            $pointsEarned = $checkinResult['points'] ?? 0;
            $continuousDays = $checkinResult['continuous_days'] ?? 1;

            $replyMessage = $continuousDays > 1 
                ? "签到成功！您获得了 {$pointsEarned} 积分，当前连续签到 {$continuousDays} 天。"
                : "签到成功！您获得了 {$pointsEarned} 积分。";

            $this->telegramService->sendBasicReply($chatId, $replyMessage);

        } catch (\Exception $e) {
            Log::error('签到处理失败', $this->buildThrowableLogContext($e, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
            ]));
            $this->telegramService->sendBasicReply($chatId, "签到失败：" . $e->getMessage() . "，请稍后重试。");
        }
    }

    /**
     * 解析命令
     */
    private function parseCommand($text)
    {
        $command = trim((string)$text);

        // 提取带@的命令，兼容带参数的情况
        if (preg_match('/^\/([a-zA-Z0-9_]+)(@\w+)?(?:\s+.*)?$/u', $command, $matches)) {
            return '/' . $matches[1];
        }

        // 修正拼写错误
        if ($command === '/bing') {
            return '/bind';
        }

        return $command;
    }

    /**
     * 获取订单统计信息
     */
    private function getOrderStats($userId)
    {
        try {
            if (!class_exists('\app\service\OrderService') || 
                !method_exists('\app\service\OrderService', 'getOrderStatusCounts')) {
                throw new \Exception('订单服务方法不存在');
            }

            $orderService = new \app\service\OrderService();
            $orderStats = $orderService->getOrderStatusCounts($userId);

            return array_merge([
                'pending' => 0,
                'processing' => 0,
                'completed' => 0
            ], is_array($orderStats) ? $orderStats : []);

        } catch (\Exception $e) {
            Log::error('获取订单统计失败', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return ['pending' => 0, 'processing' => 0, 'completed' => 0];
        }
    }

    /**
     * 管理员命令处理
     */
    private function handleAdminCommand($chatId, $tgUserId, $command, $params)
    {
        // 验证管理员权限
        if (!in_array($tgUserId, $this->telegramService->getAdminUserIds(), true)) {
            $this->telegramService->sendBasicReply($chatId, "❌ 您没有执行该命令的权限");
            return;
        }

        switch ($command) {
            case '/ban':
                $this->handleBanCommand($chatId, $params);
                break;

            case '/user':
                $this->handleUserInfoCommand($chatId, $params);
                break;

            case '/adjustpoints':
                $this->handleAdjustPointsCommand($chatId, $params);
                break;

            case '/system':
                $this->handleSystemStatusCommand($chatId);
                break;

            case '/settimer':
                $this->telegramService->getTimerManager()->handleSetTimerCommand($chatId, $params);
                break;

            case '/timers':
                $this->telegramService->getTimerManager()->handleListTimersCommand($chatId);
                break;

            case '/deltimer':
                $this->telegramService->getTimerManager()->handleDeleteTimerCommand($chatId, $params);
                break;

            case '/adminhelp':
                $this->handleAdminHelpCommand($chatId, $tgUserId);
                break;

            default:
                $this->telegramService->sendBasicReply($chatId, "❓ 未知的管理员命令");
        }
    }

    /**
     * 处理禁言命令
     */
    private function handleBanCommand($chatId, $params)
    {
        if (count($params) < 1) {
            $this->telegramService->sendBasicReply($chatId, "用法：/ban [用户ID] [时长(分钟，可选，默认60分钟)]");
            return;
        }

        $targetUserId = $params[0];
        $duration = isset($params[1]) ? (int)$params[1] * 60 : 3600; // 转换为秒

        // 检查是否在群组中
        if (!\app\common\library\TelegramHelper::isGroupChat($chatId)) {
            $this->telegramService->sendBasicReply($chatId, "❌ 禁言命令只能在群组中使用");
            return;
        }

        // 执行禁言
        $result = \app\common\library\TelegramHelper::banUser(
            $chatId, 
            $targetUserId, 
            $duration,
            $this->telegramService->getConstant('api_retry_attempts', 3),
            $this->telegramService->getConstant('api_retry_delay', 100000)
        );

        if ($result) {
            $this->telegramService->sendBasicReply($chatId, "✅ 用户 {$targetUserId} 已被禁言 " . ($duration / 60) . " 分钟");
        } else {
            $this->telegramService->sendBasicReply($chatId, "❌ 禁言操作失败");
        }
    }

    /**
     * 处理用户信息查询命令
     */
    private function handleUserInfoCommand($chatId, $params)
    {
        if (count($params) < 1) {
            $this->telegramService->sendBasicReply($chatId, "用法：/user [TG用户ID]");
            return;
        }

        $tgUserId = $params[0];
        $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);

        if (!$bindInfo) {
            $this->telegramService->sendBasicReply($chatId, "未找到该TG用户的绑定信息");
            return;
        }

        try {
            $user = \app\model\User::find($bindInfo['user_id']);
            if (!$user) {
                $this->telegramService->sendBasicReply($chatId, "未找到对应的用户记录");
                return;
            }

            $pointsService = new PointsService();
            $points = $pointsService->getUserPointsBalance($user->id);

            $message = "📋 用户信息\n";
            $message .= "TG用户ID：{$tgUserId}\n";
            $message .= "平台用户ID：{$user->id}\n";
            $message .= "手机号：" . \app\common\library\TelegramHelper::maskPhoneNumber($user->mobile) . "\n";
            $message .= "用户名：{$user->username}\n";
            $message .= "积分：{$points}\n";
            $message .= "绑定时间：{$user->update_time}\n";

            $this->telegramService->sendBasicReply($chatId, $message);

        } catch (\Exception $e) {
            Log::error('查询用户信息失败', $this->buildThrowableLogContext($e, [
                'tg_user_id' => $tgUserId,
                'chat_id' => $chatId,
            ]));
            $this->telegramService->sendBasicReply($chatId, "查询用户信息失败：" . $e->getMessage());
        }
    }

    /**
     * 处理积分调整命令
     */
    private function handleAdjustPointsCommand($chatId, $params)
    {
        // 过滤空参数
        $params = array_filter($params, function ($item) {
            return !empty(trim((string)$item));
        });
        $params = array_values($params);

        if (count($params) < 2) {
            $this->telegramService->sendBasicReply($chatId, "用法：/adjustpoints [TG用户ID] [积分数量，正数增加，负数减少]");
            Log::warning('积分调整命令参数不足', [
                'params_count' => count($params),
                'target_tg_user_id' => $this->hashLogIdentifier($params[0] ?? ''),
            ]);
            return;
        }

        // TG用户ID：去掉所有非数字字符
        $tgUserId = preg_replace('/\D/u', '', (string)$params[0]);
        // 积分数量：保留负号和数字
        $pointsRaw = trim((string)$params[1]);
        $points = (int)preg_replace('/[^\d\-]/u', '', $pointsRaw);

        if ($points === 0) {
            $this->telegramService->sendBasicReply($chatId, "❌ 积分数量不能为0，请输入正数（增加）或负数（减少）");
            return;
        }

        $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);
        if (!$bindInfo) {
            $this->telegramService->sendBasicReply($chatId, "未找到该TG用户的绑定信息");
            Log::info('积分调整失败：用户未绑定', $this->sanitizeLogContext(['tg_user_id' => $tgUserId]));
            return;
        }

        try {
            $pointsService = new PointsService();
            $action = $points > 0 ? '增加' : '减少';

            $result = $pointsService->adjustPoints(
                $bindInfo['user_id'], 
                $points, 
                "管理员手动{$action}积分"
            );

            if ($result['code'] == 1) {
                $newBalance = $pointsService->getUserPointsBalance($bindInfo['user_id']);
                $this->telegramService->sendBasicReply($chatId, "✅ 成功为用户 {$tgUserId}{$action}{$points} 积分，当前积分：{$newBalance}");
                Log::info('积分调整成功', $this->sanitizeLogContext([
                    'tg_user_id' => $tgUserId,
                    'user_id' => $bindInfo['user_id'],
                    'points' => $points,
                    'new_balance' => $newBalance
                ]));
            } else {
                $this->telegramService->sendBasicReply($chatId, "❌ 积分调整失败：{$result['msg']}");
                Log::warning('积分调整失败', $this->sanitizeLogContext([
                    'tg_user_id' => $tgUserId,
                    'points' => $points,
                    'result_code' => $result['code'] ?? null,
                    'result_message' => $result['msg'] ?? null,
                ]));
            }

        } catch (\Exception $e) {
            Log::error('调整用户积分失败', $this->buildThrowableLogContext($e, [
                'tg_user_id' => $tgUserId,
                'points' => $points,
            ]));
            $this->telegramService->sendBasicReply($chatId, "调整积分失败：" . $e->getMessage());
        }
    }

    /**
     * 处理系统状态查询命令
     */
    private function handleSystemStatusCommand($chatId)
    {
        $cacheKey = $this->telegramService->getCachePrefix() . "system_status";
        $status = \think\facade\Cache::get($cacheKey);

        // 如果缓存不存在或已过期，重新生成
        if (!$status) {
            try {
                // 获取队列任务数
                $queueStats = $this->getQueueStats();

                // 获取今日请求量
                $dailyRequests = $this->getDailyRequestCount();

                // 获取在线用户数（最近10分钟有活动的用户）
                $activeUsers = $this->getActiveUserCount();

                // 构建系统状态信息
                $status = "📊 系统状态\n";
                $status .= "🕒 统计时间：" . date('Y-m-d H:i:s') . "\n\n";
                $status .= "📥 今日请求量：{$dailyRequests}\n";
                $status .= "👥 活跃用户数（10分钟内）：{$activeUsers}\n\n";
                $status .= "📋 队列状态：\n";

                foreach ($queueStats as $queue => $count) {
                    $status .= "  - {$queue}：{$count} 个任务\n";
                }

                // 缓存系统状态
                \think\facade\Cache::set($cacheKey, $status, $this->telegramService->getConstant('system_stats_ttl', 60));

            } catch (\Exception $e) {
                Log::error('获取系统状态失败', ['error' => $e->getMessage()]);
                $this->telegramService->sendBasicReply($chatId, "获取系统状态失败：" . $e->getMessage());
                return;
            }
        }

        $this->telegramService->sendBasicReply($chatId, $status);
    }

    /**
     * 获取队列统计信息
     */
    private function getQueueStats()
    {
        try {
            $queues = [
                $this->telegramService->getConstant('batch_query_queue', 'batchPhoneQuery'),
                $this->telegramService->getConstant('batch_electricity_queue', 'batchElectricityQuery'),
                'default',
                'notify'
            ];

            $stats = [];
            foreach ($queues as $queue) {
                $stats[$queue] = \think\facade\Queue::size($queue);
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error('获取队列统计失败', ['error' => $e->getMessage()]);
            return ['error' => '无法获取队列信息'];
        }
    }

    /**
     * 获取今日请求量
     */
    private function getDailyRequestCount()
    {
        $today = date('Ymd');
        $count = \think\facade\Cache::get($this->telegramService->getConstant('daily_request_count_key', 'tg_daily_requests') . ":{$today}", 0);
        return $count;
    }

    /**
     * 获取活跃用户数
     */
    private function getActiveUserCount()
    {
        try {
            $prefix = $this->telegramService->getCachePrefix() . "rate_limit:";
            $keys = \think\facade\Cache::store('redis')->getKeys($prefix . '*');
            return count($keys);
        } catch (\Exception $e) {
            Log::error('获取活跃用户数失败', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function getLogTextLength($text): int
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
        foreach (['tg_user_id', 'chat_id', 'message_id', 'group_id', 'tg_chat_id', 'target_tg_user_id'] as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = $this->hashLogIdentifier($context[$key]);
            }
        }

        return $context;
    }

    private function buildThrowableLogContext(\Throwable $e, array $context = []): array
    {
        $logContext = array_merge($this->sanitizeLogContext($context), [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        if ((bool) Config::get('app.app_debug', false)) {
            $logContext['trace'] = $e->getTraceAsString();
        }

        return $logContext;
    }
}
