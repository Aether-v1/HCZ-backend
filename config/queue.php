<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    // 默认队列驱动：
    // - 开发环境可使用 sync，同步执行任务，便于调试
    // - 生产环境建议改为 redis，并启动 queue:work 消费者
    'default'     => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => env('QUEUE_NAME', 'default'),
            'table'      => env('QUEUE_TABLE', 'jobs'),
            'connection' => env('QUEUE_DB_CONNECTION'),
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => env('QUEUE_NAME', 'default'),
            'host'       => env('QUEUE_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
            'port'       => (int) env('QUEUE_REDIS_PORT', env('REDIS_PORT', 6379)),
            'password'   => env('QUEUE_REDIS_PASSWORD', env('REDIS_PASSWORD', '')),
            'select'     => (int) env('QUEUE_REDIS_DB', 0),
            'timeout'    => (int) env('QUEUE_REDIS_TIMEOUT', 0),
            'persistent' => (bool) env('QUEUE_REDIS_PERSISTENT', false),
        ],
    ],
    'failed'      => [
        // 生产环境建议至少记录失败任务，便于排查
        'type'  => env('QUEUE_FAILED_DRIVER', 'none'),
        'table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],
];
