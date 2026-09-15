<?php

return [
    'alias' => [
        'user_csrf' => \app\middleware\CsrfCheck::class,
    ],

    'priority' => [
        \app\middleware\RequestIdMiddleware::class,
        \think\middleware\SessionInit::class,
        \app\middleware\CorsMiddleware::class,
        \app\middleware\LegacyUserFrontendDisabled::class,
        \app\middleware\CsrfCheck::class,
    ],

    'middleware' => [
        \app\middleware\RequestIdMiddleware::class,
        \think\middleware\SessionInit::class,
        \app\middleware\CorsMiddleware::class,
        \app\middleware\LegacyUserFrontendDisabled::class,
        \app\middleware\CsrfCheck::class,
    ],
];
