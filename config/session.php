<?php
// +---------------------------------------------------------------------- 
// | 会话设置
// +---------------------------------------------------------------------- 

return [
    'name'                  => 'PHPSESSID',
    'var_session_id'        => '',
    'type'                  => 'cache',
    'store'                 => 'redis',
    'expire'                => 7200,
    'prefix'                => 'sess_',
    'auto_update_timestamp' => true,
    'id_generator'          => function () {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $bytes = openssl_random_pseudo_bytes(32);
            if ($bytes === false) {
                throw new \RuntimeException('安全会话ID生成失败');
            }

            return bin2hex($bytes);
        }
    },
    'cookie'                => [
        'lifetime' => 7200,
        'path'     => '/',
        'domain'   => env('COOKIE_DOMAIN'),
        'samesite' => env('COOKIE_SAMESITE'),
        'secure'   => env('COOKIE_SECURE'),
        'httponly' => true,
    ],
    'enable'                => true,
];
