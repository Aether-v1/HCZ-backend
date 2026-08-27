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
        // F14：给出安全默认值。生产 HTTPS 部署必须通过 .env 显式设置
        // COOKIE_SECURE=true + COOKIE_SAMESITE=Lax（或 Strict；跨站部署需 None 且必须配 Secure=true）。
        // 默认：Secure=false（仅本地 HTTP 开发）、SameSite=Lax（现代浏览器默认即 Lax，显式声明更稳妥）。
        'samesite' => env('COOKIE_SAMESITE', 'Lax'),
        'secure'   => env('COOKIE_SECURE', false),
        'httponly' => true,
    ],
    'enable'                => true,
];
