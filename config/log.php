<?php

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
return [
    // 默认日志记录通道
    'default'      => 'file',
    // 日志记录级别
    'level'        => ['error', 'warning', 'info'],
    // 日志类型记录的通道 ['error'=>'email',...]
    'type_channel' => [],
    // 关闭全局日志写入
    'close'        => false,
    // 全局日志处理 支持闭包
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录（修改为明确路径）
            'path'           => runtime_path() . 'log' . DIRECTORY_SEPARATOR,
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别（错误日志单独存放）
            'apart_level'    => ['error'],
            // 最大日志文件数量（保留30天日志）
            'max_files'      => 30,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入（生产环境建议开启）
            'realtime_write' => true,
        ],
        // 其它日志通道配置
    ],

];
    