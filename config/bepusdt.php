<?php

$cfg = static function (string $envKey, $default = '') {
    $envValue = env($envKey);
    return ($envValue !== null && $envValue !== '') ? $envValue : $default;
};

$enabledEnv = env('BEPUSDT_ENABLED');
$enabled = null;
if ($enabledEnv !== null && $enabledEnv !== '') {
    $enabled = filter_var($enabledEnv, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
}

$baseUrl = rtrim((string)$cfg('BEPUSDT_BASE_URL', ''), '/');
$apiToken = (string)$cfg('BEPUSDT_API_TOKEN', '');

return [
    'enabled'      => $enabled !== null ? (bool)$enabled : ($baseUrl !== '' && $apiToken !== ''),
    'base_url'     => $baseUrl,
    'api_token'    => $apiToken,
    'notify_url'   => (string)$cfg('BEPUSDT_NOTIFY_URL', ''),
    'redirect_url' => (string)$cfg('BEPUSDT_REDIRECT_URL', ''),
    'trade_type'   => (string)$cfg('BEPUSDT_TRADE_TYPE', 'usdt.bsc'),
    'fiat'         => (string)$cfg('BEPUSDT_FIAT', 'CNY'),
];
