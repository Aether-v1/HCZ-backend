<?php

if (!function_exists('parseDomainBindConfig')) {
    function parseDomainBindConfig(string $raw): array
    {
        $result = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            [$domain, $app] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if ($domain !== '' && $app !== '') {
                $result[$domain] = $app;
            }
        }

        return $result;
    }
}

if (!function_exists('parseCommaSeparatedEnvConfig')) {
    function parseCommaSeparatedEnvConfig(string $envKey, array $default = []): array
    {
        $raw = trim((string) env($envKey, ''));
        if ($raw === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($value) {
            return $value !== '';
        }));
    }
}

if (!function_exists('resolveRequiredSecretConfigValue')) {
    function resolveRequiredSecretConfigValue(string $envKey, string $label): string
    {
        $value = trim((string) env($envKey, ''));
        if ($value !== '') {
            return $value;
        }

        $fileEnvKey = $envKey . '_FILE';
        $configuredPath = trim((string) env($fileEnvKey, ''));
        if ($configuredPath === '') {
            throw new RuntimeException($label . '未配置，请通过 .env 中的 ' . $envKey . ' 或 ' . $fileEnvKey . ' 提供');
        }

        $realPath = realpath($configuredPath);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException($label . '安全文件路径无效或不可读');
        }

        $projectRoot = realpath((string) root_path());
        if ($projectRoot !== false) {
            $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
            $normalizedPath = str_replace('\\', '/', $realPath);
            if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '/')) {
                throw new RuntimeException($label . '安全文件路径不能位于项目仓库内');
            }
        }

        $fileValue = trim((string) @file_get_contents($realPath));
        if ($fileValue === '') {
            throw new RuntimeException($label . '安全文件内容为空');
        }

        return $fileValue;
    }
}

return [
    'url'              => '',
    'app_host'         => env('APP_HOST', ''),
    'app_namespace'    => '',
    'with_route'       => true,
    'default_app'      => 'index',
    'default_timezone' => 'Asia/Shanghai',

    'app_key'          => resolveRequiredSecretConfigValue('APP_KEY', '应用密钥(app_key)'),
    'cron_secret'      => resolveRequiredSecretConfigValue('CRON_SECRET', '定时任务密钥(cron_secret)'),

    'app_map'          => [],
    'domain_bind'      => parseDomainBindConfig(env(
        'APP_DOMAIN_BIND',
        'api.example.com:index,admin.example.com:index'
    )),
    'deny_app_list'    => [],

    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',
    'error_message'    => '页面错误，请稍后再试',
    'show_error_msg'   => false,

    'app_debug'        => false,
    'app_trace'        => false,

    'admin_ip_whitelist' => parseCommaSeparatedEnvConfig('ADMIN_IP_WHITELIST', [
        '127.0.0.1',
    ]),
    'admin_trusted_proxy_ips' => parseCommaSeparatedEnvConfig('ADMIN_TRUSTED_PROXY_IPS', [
        '127.0.0.1',
        '::1',
    ]),
    'admin_real_ip_headers' => parseCommaSeparatedEnvConfig('ADMIN_REAL_IP_HEADERS', [
        'CF-Connecting-IP',
        'X-Forwarded-For',
        'X-Real-IP',
    ]),

    'csrf_enabled' => true,
    'csrf' => [
        'token_name' => '_csrf_token',
        'expire'     => 7200,
        'except'     => [
            'api/auth/login',
            // Telegram webhook uses the real route registered in route/app.php.
            'robot/webhook',
            'api/callback/*',
        ],
    ],

    'session' => [
        'id'              => '',
        'var_session_id'  => '',
        'prefix'          => 'think',
        'type'            => 'file',
        'auto_start'      => true,
        'cookie_domain'   => '',
        'cookie_path'     => '/',
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'None',
    ],
];
