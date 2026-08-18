<?php

declare(strict_types=1);

namespace app\service;

use Exception;
use think\facade\Log;

class BepusdtService
{
    public function isEnabled(): bool
    {
        $enabledEnv = env('BEPUSDT_ENABLED');
        if ($enabledEnv !== null && $enabledEnv !== '') {
            return filter_var($enabledEnv, FILTER_VALIDATE_BOOL);
        }

        return $this->getBaseUrl() !== '' && $this->getApiToken() !== '';
    }

    public function createTransaction(array $payload): array
    {
        $baseUrl = $this->getBaseUrl();
        $apiToken = $this->getApiToken();

        if ($baseUrl === '' || $apiToken === '') {
            throw new Exception('BEpusdt 配置不完整');
        }

        $baseUrl = $this->normalizeSafeUrl($baseUrl, 'BEpusdt base_url');

        $params = [
            'order_id' => (string)($payload['order_id'] ?? ''),
            'name' => (string)($payload['title'] ?? $payload['name'] ?? ''),
            'amount' => (float)($payload['amount'] ?? 0),
            'notify_url' => $this->normalizeSafeUrl((string)($payload['notify_url'] ?? ''), 'BEpusdt notify_url'),
            'redirect_url' => $this->normalizeOptionalSafeUrl((string)($payload['redirect_url'] ?? ''), 'BEpusdt redirect_url'),
            'trade_type' => (string)($payload['trade_type'] ?? ''),
            'fiat' => (string)($payload['fiat'] ?? ''),
        ];

        $params['signature'] = $this->sign($params, $apiToken);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($baseUrl, '/') . '/api/v1/order/create-transaction',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception('BEpusdt 下单失败: ' . $error);
        }

        $json = json_decode((string)$response, true);

        $safeRequest = [
            'base_url' => rtrim($baseUrl, '/'),
            'params' => [
                'order_id' => $params['order_id'] ?? '',
                'name' => $params['name'] ?? '',
                'amount' => $params['amount'] ?? '',
                'notify_url' => $params['notify_url'] ?? '',
                'redirect_url' => $params['redirect_url'] ?? '',
                'trade_type' => $params['trade_type'] ?? '',
                'fiat' => $params['fiat'] ?? '',
            ],
            'http_code' => $httpCode,
        ];

        if (!is_array($json)) {
            Log::error('BEpusdt createTransaction invalid json', [
                'request' => $safeRequest,
                'response' => (string)$response,
            ]);
            throw new Exception('BEpusdt 下单返回异常: 响应不是有效 JSON');
        }

        $isSuccess =
            (isset($json['status_code']) && (int)$json['status_code'] === 200)
            || (
                in_array(strtolower((string)($json['message'] ?? '')), ['success', 'ok'], true)
                && is_array($json['data'] ?? null)
            );

        if (!$isSuccess) {
            Log::error('BEpusdt createTransaction business failed', [
                'request' => $safeRequest,
                'response' => $json,
            ]);
            throw new Exception(
                'BEpusdt 下单返回异常: '
                . (string)($json['message'] ?? $json['msg'] ?? '未知错误')
            );
        }

        $data = (array)($json['data'] ?? []);
        $data['_raw'] = $json;

        return $data;
    }

    public function verifyNotify(array $payload): bool
    {
        $apiToken = $this->getApiToken();
        $signature = strtolower(trim((string)($payload['signature'] ?? '')));
        if ($signature === '' || $apiToken === '') {
            return false;
        }

        $expected = strtolower($this->sign($payload, $apiToken));

        return strlen($signature) === strlen($expected) && hash_equals($expected, $signature);
    }

    private function sign(array $payload, string $apiToken): string
    {
        unset($payload['signature']);
        $payload = array_filter($payload, static fn ($value) => $value !== '' && $value !== null);
        ksort($payload, SORT_STRING);

        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return md5(implode('&', $pairs) . $apiToken);
    }

    private function getBaseUrl(): string
    {
        $baseUrl = trim((string)config('bepusdt.base_url'));
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        $fallback = trim((string)getConfig('bepusdt_base_url'));
        return $fallback === '' ? '' : rtrim($fallback, '/');
    }

    private function getApiToken(): string
    {
        $apiToken = trim((string)config('bepusdt.api_token'));
        if ($apiToken !== '') {
            return $apiToken;
        }

        return trim((string)getConfig('bepusdt_api_token'));
    }

    private function normalizeOptionalSafeUrl(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return $this->normalizeSafeUrl($value, $label);
    }

    private function normalizeSafeUrl(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new Exception($label . ' 未配置');
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw new Exception($label . ' 非法');
        }

        $parts = parse_url($value);
        if ($parts === false) {
            throw new Exception($label . ' 非法');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''), '[]');
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $user !== '' || $pass !== '') {
            throw new Exception($label . ' 非法');
        }

        $normalizedHost = strtolower($host);
        if ($normalizedHost === 'localhost' || str_ends_with($normalizedHost, '.local')) {
            throw new Exception($label . ' 指向了本地地址');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && !$this->isPublicIpAddress($host)) {
            throw new Exception($label . ' 指向了非公网地址');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false && !$this->hostResolvesToPublicIps($host)) {
            throw new Exception($label . ' 指向了非公网地址');
        }

        return $value;
    }

    private function hostResolvesToPublicIps(string $host): bool
    {
        $resolvedIps = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $candidate = (string)($record['ip'] ?? ($record['ipv6'] ?? ''));
                    if ($candidate !== '') {
                        $resolvedIps[] = $candidate;
                    }
                }
            }
        }

        if ($resolvedIps === [] && function_exists('gethostbynamel')) {
            $fallbackIps = @gethostbynamel($host);
            if (is_array($fallbackIps)) {
                $resolvedIps = $fallbackIps;
            }
        }

        if ($resolvedIps === []) {
            return true;
        }

        foreach ($resolvedIps as $resolvedIp) {
            if (!$this->isPublicIpAddress($resolvedIp)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIpAddress(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
