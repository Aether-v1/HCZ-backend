<?php

return [
    'alias' => [
        'csrf' => \think\middleware\Csrf::class,
        'user_csrf' => \app\middleware\CsrfCheck::class,
    ],

    'priority' => [
        \think\middleware\SessionInit::class,
        \app\middleware\LegacyUserFrontendDisabled::class,
        \app\middleware\CsrfCheck::class,
        \think\middleware\Csrf::class,
    ],

    'middleware' => [
        \think\middleware\SessionInit::class,
        \app\middleware\LegacyUserFrontendDisabled::class,
        \app\middleware\CsrfCheck::class,
        [
            \think\middleware\Csrf::class,
            [
                'except' => [
                    'GET' => [
                        'index',
                        'home/*',
                        'help/*',
                    ],
                    'POST' => [
                        'epay_notify_url',
                        'api/*',
                        'login_post',
                        'register_post',
                        'robot/webhook',
                    ],
                ],
            ],
        ],
    ],
];
