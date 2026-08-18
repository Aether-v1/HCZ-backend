<?php
// ----------------------------------------------------------------------
// Cookie 配置
// 说明：
// 1. 生产跨站场景必须使用 SameSite=None + Secure=true
// 2. 这要求前后端都运行在 HTTPS 下，否则浏览器会拒绝 Cookie
// 3. 单主域方案下，后端 Cookie 固定属于 .your-backend-domain.com
// 4. 前端域名是前端调用方，不应成为后端 Session Cookie 所属域
// ----------------------------------------------------------------------

return [
    'prefix'   => '',
    'expire'   => 0,
    'path'     => '/',
    'domain'   => env('COOKIE_DOMAIN'),
    'secure'   => env('COOKIE_SECURE'),
    'httponly' => true,
    'samesite' => env('COOKIE_SAMESITE'),
];
