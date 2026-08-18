<?php

if (!function_exists('parseTelegramIdList')) {
    function parseTelegramIdList(string $raw): array
    {
        $items = array_filter(array_map('trim', explode(',', $raw)), static fn ($item) => $item !== '');
        return array_map('intval', $items);
    }
}

if (!function_exists('parseTelegramAllowedUpdates')) {
    function parseTelegramAllowedUpdates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('strval', $decoded), static fn ($item) => $item !== ''));
            }
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($item) => $item !== ''));
    }
}

if (!function_exists('parseTelegramStringList')) {
    function parseTelegramStringList(string $raw): array
    {
        $items = array_filter(array_map('trim', explode(',', $raw)), static fn ($item) => $item !== '');
        return array_values($items);
    }
}

$telegramBotUsername = ltrim((string) env('TELEGRAM_BOT_USERNAME', ''), '@');
$telegramBotUrl = $telegramBotUsername !== '' ? 'https://t.me/' . $telegramBotUsername : '';

return [
    // 机器人API令牌，从@BotFather获取
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    'bot_username' => $telegramBotUsername,

    'bot_url' => $telegramBotUrl,
    
    // 安全令牌，用于验证Telegram请求（必须与Webhook设置一致）
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),

    'webhook_allowed_updates' => parseTelegramAllowedUpdates(env('TELEGRAM_WEBHOOK_ALLOWED_UPDATES', 'message,callback_query')),

    'webhook_max_connections' => (int) env('TELEGRAM_WEBHOOK_MAX_CONNECTIONS', 40),
    
    // API请求地址
    'api_url' => 'https://api.telegram.org/bot',
    
    // 代理配置（如果需要）
    'proxy' => env('TELEGRAM_PROXY', ''), 
    
    // 网站域名，用于生成绑定链接
    'website_url' => env('TELEGRAM_WEBSITE_URL', 'https://your-frontend-domain.com'),
    
    // 官方验证信息
    'official_info' => [
        'website' => env('TELEGRAM_OFFICIAL_WEBSITE', 'https://your-official-site.com'),
        'support_username' => env('TELEGRAM_SUPPORT_USERNAME', 'your_support_bot')
    ],
    
    // 话费查询API配置
    'phone_bill_api' => [
        'url' => env('PHONE_BILL_API_URL', 'https://api.xleyou.com/api/huafei/get_zonghe'),
        'ckey' => env('PHONE_BILL_API_CKEY', '')
    ],
        
    // 电费查询API配置
    'electricity_bill_api' => [
        'url' => env('ELECTRICITY_BILL_API_URL', 'https://api.xleyou.com/api/dianfei/get_dfcx'),
        'ckey' => env('ELECTRICITY_BILL_API_CKEY', '')
    ],

    'usdt_query_api' => [
        'url' => env('USDT_QUERY_API_URL', ''),
        'network' => strtoupper((string) env('USDT_QUERY_NETWORK', 'TRC20')),
        'supported_networks' => array_values(array_unique(array_map('strtoupper', parseTelegramStringList(env('USDT_QUERY_SUPPORTED_NETWORKS', env('USDT_QUERY_NETWORK', 'TRC20')))))),
        'connect_timeout' => max(1, (int) env('USDT_QUERY_API_CONNECT_TIMEOUT', 3)),
        'timeout' => max(1, (int) env('USDT_QUERY_API_TIMEOUT', 5)),
        'retry_attempts' => max(1, (int) env('USDT_QUERY_API_RETRY_ATTEMPTS', 1)),
        'rate_limit_ttl' => max(3, (int) env('USDT_QUERY_RATE_LIMIT_TTL', 5)),
        'cache_ttl' => max(10, (int) env('USDT_QUERY_CACHE_TTL', 15)),
        'network_param_name' => env('USDT_QUERY_NETWORK_PARAM', 'network'),
        'api_key' => env('USDT_QUERY_API_KEY', ''),
        'api_key_header' => env('USDT_QUERY_API_KEY_HEADER', 'X-API-Key'),
    ],
        
    // 消息模板
    'message_templates' => [
        'order_notify' => "📝 新订单通知\n订单号：%s\n金额：%s\n状态：%s\n时间：%s",
        'recharge_notify' => "💸 充值成功\n金额：%s\n到账时间：%s\n当前余额：%s",
        'transaction_notify' => "🔄 交易通知\n类型：%s\n金额：%s\n时间：%s\n备注：%s",
        'sign_in_success' => "✅ 签到成功\n获得积分：%d\n连续签到：%d天",
        'balance_query' => "💰 账户余额\n可用金额：%s\$\n冻结金额：%s\$\n总积分：%d",
        'bind_success' => "🔗 账号绑定成功\n您现在可以使用所有功能了",
        'bind_failed' => "❌ 绑定失败\n验证码无效或已过期",
        'unbind_success' => "🔓 账号已成功解绑",
        'command_not_found' => "❓ 未知命令\n请使用键盘选择功能或输入 /start 重新开始"
    ],
    
    // 机器人核心常量配置
    'bot_constants' => [
        // 官方群组ID
        'official_group_ids' => parseTelegramIdList(env('TELEGRAM_OFFICIAL_GROUP_IDS', '-1000000000000,-1000000000001')),
        
        // 限制同一用户请求频率（秒）
        'rate_limit_seconds' => 2,
        
        // 调整耗时警告阈值（毫秒）
        'process_time_warning_threshold' => 1500,

        // Webhook update 去重保留时间（秒）
        'webhook_update_dedupe_ttl' => 172800,
        
        // 签到缓存时间（秒，24小时）
        'checkin_cache_duration' => 86400,
        
        // 话费查询积分消耗
        'balance_check_points' => 3,
        
        // 批量查询最大号码数量
        'max_batch_numbers' => 5,
        
        // API调用重试次数
        'api_retry_attempts' => 3,
        
        // API调用重试延迟（微秒）
        'api_retry_delay' => 100000,
        
        // 批量查询队列名称
        'batch_query_queue' => 'batchPhoneQuery',

        // 批量电费查询队列名称
        'batch_electricity_queue' => 'batchElectricityQuery',

        // 批量查询trace保留时间（秒）
        'batch_trace_ttl' => 172800,
        
        // 重复查询检查时间（秒）
        'duplicate_check_ttl' => 30,
        
        // 缓存前缀
        'cache_prefix' => 'tg_',
        
        // 系统统计缓存时间（秒）
        'system_stats_ttl' => 60,
        
        // 每日请求计数缓存键
        'daily_request_count_key' => 'tg_daily_requests',
        
        // 通知重试次数
        'notify_retry_attempts' => 2,
        
        // 通知重试延迟（微秒）
        'notify_retry_delay' => 200000,
        
        // 定时任务类型：一次性
        'timer_type_once' => 'once',
        
        // 定时任务类型：每日
        'timer_type_daily' => 'daily',
        
        // 定时任务列表缓存键
        'timer_list_key' => 'tg_timers_list',
        
        // 管理员TG用户ID列表
        'admin_user_ids' => parseTelegramIdList(env('TELEGRAM_ADMIN_USER_IDS', '123456789,987654321'))
    ],
    
    // 是否启用基础回复
    'basic_reply' => false,
    
    // API请求超时设置（秒）
    'timeout' => 8,
    
    // 消息长度限制（Telegram默认4096字符）
    'message_max_length' => 4096
];
