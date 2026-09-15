<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'cron' => 'app\command\Cron',
        'fund-log:index' => 'app\command\FundLogIndex',
        'migrate' => 'app\command\MigrateCommand',
        'tg:webhook:set' => 'app\command\TgWebhookSet',
        'tg:webhook:info' => 'app\command\TgWebhookInfo',
        'tg:webhook:delete' => 'app\command\TgWebhookDelete',
        'tg:commands:sync' => 'app\command\TgCommandsSync',
        'timer:process' => 'app\command\TimerProcess',
    ],
];
