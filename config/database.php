<?php
// +----------------------------------------------------------------------
// | 数据库设置
// +----------------------------------------------------------------------

return [
    // 默认使用的数据库连接配置
    'default'         => env('DB_DRIVER', 'mysql'),

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳字段
    'auto_timestamp'  => true,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 时间字段配置
    'datetime_field'  => '',

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            'type'            => env('DB_TYPE', ''),
            'hostname'        => env('DB_HOST', ''),
            'database'        => env('DB_NAME', ''),
            'username'        => env('DB_USER', ''),
            'password'        => env('DB_PASS', ''),
            'hostport'        => env('DB_PORT', ''),
            'params'          => [
                // 开启持久连接（减少TCP握手开销）
                PDO::ATTR_PERSISTENT => true,
                // 错误模式：抛出异常（便于调试）
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
            'charset'         => env('DB_CHARSET', 'utf8'),
            'prefix'          => env('DB_PREFIX', 'cz_'),
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            // 关键：开启断线重连
            'break_reconnect' => true,
            // 生产环境关闭SQL监听（减少性能消耗）
            'trigger_sql'     => env('APP_DEBUG', false),
            'fields_cache'    => false,
            // 关键：添加连接池配置（解决频繁创建连接问题）
            'pool' => [
                'min_connections' => 5,    // 最小空闲连接
                'max_connections' => 50,   // 最大连接数（根据服务器调整）
                'connect_timeout' => 10,   // 连接超时
                'wait_timeout' => 3,       // 获取连接等待超时
                'heartbeat' => 60,         // 心跳检测
                'max_idle_time' => 300,    // 连接最大空闲时间（5分钟）
            ],
        ],
    ],
];
