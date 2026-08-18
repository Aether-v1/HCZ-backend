<?php
// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动（保持file不影响，Session会单独指定redis）
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => '',
            'expire'     => 10800,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        // Redis缓存配置（会话专用，重点优化）
        'redis' => [
            'type'       => 'redis',       // 驱动类型必须为redis（正确）
            'host'       => '127.0.0.1',   // 确认Redis服务器地址（本地正确）
            'port'       => 6379,          // 端口（默认正确）
            'password'   => '',            // 若Redis无密码则留空（正确）
            'select'     => 0,             // 数据库编号（0-15，确保可写入）
            'timeout'    => 3,             // 超时时间缩短为3秒（避免长期阻塞）
            'prefix'     => 'cache_',      // 增加缓存前缀（与Session前缀区分）
            'expire'     => 0,             // 由Session控制过期时间（正确）
            'persistent' => false,         // 关闭长连接（避免部分环境连接复用问题）
            // 新增：Redis连接参数（解决部分环境兼容问题）
            'params'     => [
                'database' => 0, // 显式指定数据库（与select一致）
            ],
            'tag_prefix' => 'tag_redis:',
        ],
    ],
];
    