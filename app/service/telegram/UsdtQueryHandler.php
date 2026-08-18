<?php

namespace app\service\telegram;

use app\service\TelegramService;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;

class UsdtQueryHandler
{
    /** @var TelegramService */
    private $telegramService;

    /** @var string */
    private $apiUrl = '';

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function handleUsdtQuery($chatId, $tgUserId, $address, $messageId)
    {
        $normalizedAddress = trim((string)$address);
        $boundUserId = $this->resolveBoundUserId($tgUserId);

        try {
            Log::info('enter usdt query flow', [
                'chat_id' => $this->hashIdentifier($chatId),
                'tg_user_id' => $this->hashIdentifier($tgUserId),
                'user_id' => $this->hashIdentifier($boundUserId),
                'address' => $this->maskAddress($normalizedAddress),
            ]);

            $networkDecision = $this->resolveQueryNetwork($normalizedAddress);
            if (($networkDecision['status'] ?? '') !== 'ok') {
                Log::warning('invalid usdt address rejected', [
                    'chat_id' => $this->hashIdentifier($chatId),
                    'tg_user_id' => $this->hashIdentifier($tgUserId),
                    'user_id' => $this->hashIdentifier($boundUserId),
                    'address' => $this->maskAddress($normalizedAddress),
                    'decision' => $networkDecision['status'] ?? 'invalid',
                    'network' => $networkDecision['network'] ?? null,
                ]);

                $this->telegramService->sendBasicReply($chatId, (string)($networkDecision['user_message'] ?? '钱包地址格式不正确，请确认后重试。'), $messageId);
                return;
            }

            $network = (string)$networkDecision['network'];
            $cacheKey = $this->buildCacheKey($network, $normalizedAddress);
            $cachedResult = Cache::store('redis')->get($cacheKey);
            if (is_array($cachedResult) && !empty($cachedResult['data']) && is_array($cachedResult['data'])) {
                Log::info('usdt query cache hit', [
                    'chat_id' => $this->hashIdentifier($chatId),
                    'tg_user_id' => $this->hashIdentifier($tgUserId),
                    'user_id' => $this->hashIdentifier($boundUserId),
                    'network' => $network,
                    'address' => $this->maskAddress($normalizedAddress),
                ]);

                $this->telegramService->sendBasicReply($chatId, $this->formatResponse($cachedResult['data'], $network), $messageId);
                return;
            }

            if (!$this->acquireRateLimit($chatId, $tgUserId, $boundUserId, $network, $normalizedAddress)) {
                $this->telegramService->sendBasicReply($chatId, '查询过于频繁，请稍后再试。', $messageId);
                return;
            }

            $result = $this->queryUsdtBalance($normalizedAddress, $network);

            if (($result['status'] ?? '') === 'success' && !empty($result['data']) && is_array($result['data'])) {
                Cache::store('redis')->set($cacheKey, [
                    'data' => $result['data'],
                    'network' => $network,
                    'cached_at' => time(),
                ], $this->getCacheTtl());

                Log::info('usdt query success', [
                    'chat_id' => $this->hashIdentifier($chatId),
                    'tg_user_id' => $this->hashIdentifier($tgUserId),
                    'user_id' => $this->hashIdentifier($boundUserId),
                    'network' => $network,
                    'address' => $this->maskAddress($normalizedAddress),
                    'balance_bucket' => $this->getBalanceRangeForLog($result['data']['USDT_Balance'] ?? null),
                ]);

                $reply = $this->formatResponse($result['data'], $network);
                $this->telegramService->sendBasicReply($chatId, $reply, $messageId);
                return;
            }

            Log::warning('usdt query api returned failure', [
                'chat_id' => $this->hashIdentifier($chatId),
                'tg_user_id' => $this->hashIdentifier($tgUserId),
                'user_id' => $this->hashIdentifier($boundUserId),
                'network' => $network,
                'address' => $this->maskAddress($normalizedAddress),
                'status' => $result['status'] ?? null,
                'code' => $result['code'] ?? null,
                'error_summary' => $this->summarizeError($result['message'] ?? ''),
            ]);

            $this->telegramService->sendBasicReply($chatId, '查询暂时不可用，请稍后重试。', $messageId);
        } catch (\Throwable $e) {
            Log::error('usdt query handler error', [
                'chat_id' => $this->hashIdentifier($chatId),
                'tg_user_id' => $this->hashIdentifier($tgUserId),
                'user_id' => $this->hashIdentifier($boundUserId),
                'address' => $this->maskAddress($normalizedAddress),
                'error_summary' => $this->summarizeError($e->getMessage()),
            ]);

            $this->telegramService->sendBasicReply($chatId, '查询暂时不可用，请稍后重试。', $messageId);
        }
    }

    private function queryUsdtBalance($address, string $network): array
    {
        $config = (array)Config::get('telegram.usdt_query_api', []);
        $apiUrl = trim((string)($config['url'] ?? ''));
        $networkParamName = trim((string)($config['network_param_name'] ?? 'network'));
        $connectTimeout = max(1, (int)($config['connect_timeout'] ?? 3));
        $timeout = max($connectTimeout, (int)($config['timeout'] ?? 5));
        $attempts = max(1, (int)($config['retry_attempts'] ?? 1));
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $apiKeyHeader = trim((string)($config['api_key_header'] ?? 'X-API-Key'));

        if ($apiUrl === '') {
            Log::error('usdt query api not configured', [
                'network' => $network,
                'address' => $this->maskAddress($address),
            ]);
            return ['status' => 'error', 'message' => 'api_not_configured'];
        }

        if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            Log::error('usdt query api url invalid', [
                'network' => $network,
                'address' => $this->maskAddress($address),
            ]);
            return ['status' => 'error', 'message' => 'api_url_invalid'];
        }

        $scheme = strtolower((string)parse_url($apiUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            Log::error('usdt query api scheme invalid', [
                'network' => $network,
                'address' => $this->maskAddress($address),
            ]);
            return ['status' => 'error', 'message' => 'api_scheme_invalid'];
        }

        if (!$this->isSafeOutboundApiUrl($apiUrl)) {
            Log::error('usdt query api url rejected', [
                'network' => $network,
                'address' => $this->maskAddress($address),
            ]);
            return ['status' => 'error', 'message' => 'api_url_rejected'];
        }

        $url = $apiUrl . (str_contains($apiUrl, '?') ? '&' : '?') . 'address=' . urlencode((string)$address);
        if ($networkParamName !== '') {
            $url .= '&' . rawurlencode($networkParamName) . '=' . rawurlencode($network);
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init();

            try {
                if ($ch === false) {
                    throw new \RuntimeException('curl_init_failed');
                }

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                if ($scheme === 'https') {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                }

                $headers = [];
                if ($apiKey !== '' && $apiKeyHeader !== '') {
                    $headers[] = $apiKeyHeader . ': ' . $apiKey;
                }
                if (!empty($headers)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                }

                $response = curl_exec($ch);
                if ($response === false) {
                    $curlErrNo = curl_errno($ch);
                    $summary = in_array($curlErrNo, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT], true)
                        ? 'upstream_timeout'
                        : 'upstream_request_failed';
                    throw new \RuntimeException($summary);
                }

                if ($response === '') {
                    throw new \RuntimeException('upstream_empty_response');
                }

                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode < 200 || $httpCode >= 300) {
                    throw new \RuntimeException('upstream_http_' . $httpCode);
                }

                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('upstream_invalid_json');
                }

                if (!is_array($result)) {
                    throw new \RuntimeException('upstream_invalid_payload');
                }

                Log::debug('usdt query api response summary', [
                    'network' => $network,
                    'address' => $this->maskAddress($address),
                    'status' => $result['status'] ?? null,
                    'code' => $result['code'] ?? null,
                    'message' => $this->summarizeError($result['message'] ?? ''),
                ]);

                return $result;
            } catch (\Throwable $e) {
                Log::warning('usdt query api call attempt failed', [
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'network' => $network,
                    'address' => $this->maskAddress($address),
                    'error_summary' => $this->summarizeError($e->getMessage()),
                ]);

                if ($attempt >= $attempts) {
                    return ['status' => 'error', 'message' => $e->getMessage()];
                }
            } finally {
                if ($ch !== false) {
                    curl_close($ch);
                }
            }
        }

        return ['status' => 'error', 'message' => 'upstream_request_failed'];
    }

    private function formatResponse($data, string $network)
    {
        if (!is_array($data)) {
            Log::error('format usdt response failed because data is not array', [
                'data_type' => gettype($data),
            ]);
            return '查询结果格式错误，请稍后重试';
        }

        $requiredFields = ['USDT_Balance', 'TRX_Balance', 'USDT_to_CNY_Rate', 'TRX_to_CNY_Rate', 'Total_CNY_Balance', 'Account_Details'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                Log::warning('usdt response missing required field', [
                    'missing_field' => $field,
                ]);
                $data[$field] = '未知';
            }
        }

        if (!is_array($data['Account_Details'])) {
            Log::warning('usdt response account details is not array', [
                'type' => gettype($data['Account_Details']),
            ]);
            $data['Account_Details'] = ['address' => '未知', 'create_time' => ''];
        }

        $message = "USDT余额查询结果\n\n";
        $message .= "链类型: {$network}\n";
        $message .= "USDT余额: {$data['USDT_Balance']}\n";
        $message .= "TRX余额: {$data['TRX_Balance']}\n\n";
        $message .= "汇率信息:\n";
        $message .= "1 USDT = {$data['USDT_to_CNY_Rate']} CNY\n";
        $message .= "1 TRX = {$data['TRX_to_CNY_Rate']} CNY\n\n";
        $message .= "总资产价值: {$data['Total_CNY_Balance']} CNY\n\n";

        $address = (string)($data['Account_Details']['address'] ?? '未知');
        $message .= "地址: {$this->maskAddress($address)}\n";

        $createTime = $data['Account_Details']['create_time'] ?? '';
        if (is_numeric($createTime) && (int)$createTime > 0) {
            $timestamp = (int)$createTime > 9999999999 ? (int)(((int)$createTime) / 1000) : (int)$createTime;
            $message .= '创建时间: ' . date('Y-m-d H:i:s', $timestamp) . "\n";
        }

        return $message;
    }

    public function isValidUsdtAddress($address)
    {
        $decision = $this->resolveQueryNetwork((string)$address);
        return ($decision['status'] ?? '') === 'ok';
    }

    public function looksLikeWalletQueryCandidate($address): bool
    {
        $trimmedAddress = trim((string)$address);
        if ($trimmedAddress === '') {
            return false;
        }

        if (str_starts_with($trimmedAddress, 'T')) {
            return true;
        }

        return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', $trimmedAddress);
    }

    public function resolveQueryNetwork(string $address): array
    {
        $originalAddress = (string)$address;
        $trimmedAddress = trim($originalAddress);
        $supportedNetworks = $this->getSupportedNetworks();
        $startsWithT = str_starts_with($trimmedAddress, 'T');
        $isCorrectLength = strlen($trimmedAddress) === 34;
        $matchesAlphabet = (bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $trimmedAddress);
        $passesChecksum = $startsWithT && $isCorrectLength && $matchesAlphabet
            ? $this->passesTronBase58Check($trimmedAddress)
            : false;
        $isEvmAddress = (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', $trimmedAddress);

        Log::info('usdt address validation summary', [
            'original_address' => $this->maskAddress($originalAddress),
            'trimmed_address' => $this->maskAddress($trimmedAddress),
            'has_invisible_chars' => strlen($originalAddress) !== strlen($trimmedAddress),
            'starts_with_t' => $startsWithT,
            'is_correct_length' => $isCorrectLength,
            'matches_base58' => $matchesAlphabet,
            'passes_checksum' => $passesChecksum,
            'is_evm_address' => $isEvmAddress,
            'supported_networks' => $supportedNetworks,
        ]);

        if ($passesChecksum) {
            if (in_array('TRC20', $supportedNetworks, true)) {
                return [
                    'status' => 'ok',
                    'network' => 'TRC20',
                    'user_message' => '',
                ];
            }

            return [
                'status' => 'unsupported',
                'network' => 'TRC20',
                'user_message' => '当前链路未开放该链类型查询，请确认后重试。',
            ];
        }

        if ($isEvmAddress) {
            $evmSupported = array_values(array_intersect($supportedNetworks, ['ERC20', 'BEP20']));
            if (count($evmSupported) === 1) {
                return [
                    'status' => 'ok',
                    'network' => $evmSupported[0],
                    'user_message' => '',
                ];
            }

            if (count($evmSupported) > 1) {
                return [
                    'status' => 'ambiguous',
                    'network' => null,
                    'user_message' => '该地址链类型无法自动判定，请确认链类型后重试。',
                ];
            }

            return [
                'status' => 'unsupported',
                'network' => null,
                'user_message' => '当前仅支持TRC20链地址查询，请确认链类型后重试。',
            ];
        }

        return [
            'status' => 'invalid',
            'network' => null,
            'user_message' => '钱包地址格式不正确，请确认后重试。',
        ];
    }

    public function maskAddress($address)
    {
        $address = trim((string)$address);
        if (strlen($address) <= 10) {
            return $address;
        }

        return substr($address, 0, 6) . '...' . substr($address, -4);
    }

    private function getBalanceRangeForLog($balance)
    {
        if (!is_numeric($balance)) {
            return 'unknown';
        }

        return ((float)$balance) > 0 ? '>0' : '=0';
    }

    private function acquireRateLimit($chatId, $tgUserId, ?int $userId, string $network, string $address): bool
    {
        $ttl = $this->getRateLimitTtl();
        $keys = [
            $this->buildRateLimitKey('tg', (string)$tgUserId),
            $this->buildRateLimitKey('chat', (string)$chatId),
        ];

        if ($userId !== null && $userId > 0) {
            $keys[] = $this->buildRateLimitKey('user', (string)$userId);
        }

        foreach ($keys as $key) {
            try {
                $reserved = Cache::store('redis')->handler()->set($key, (string)time(), ['nx', 'ex' => $ttl]);
                if (!$reserved) {
                    Log::info('usdt query rate limit hit', [
                        'network' => $network,
                        'address' => $this->maskAddress($address),
                        'key_type' => $this->describeRateLimitKey($key),
                    ]);
                    return false;
                }
            } catch (\Throwable $e) {
                Log::error('usdt query rate limit set failed', [
                    'network' => $network,
                    'address' => $this->maskAddress($address),
                    'key_type' => $this->describeRateLimitKey($key),
                    'error_summary' => $this->summarizeError($e->getMessage()),
                ]);
            }
        }

        return true;
    }

    private function buildCacheKey(string $network, string $address): string
    {
        return $this->telegramService->getCachePrefix() . 'usdt_query:cache:' . strtolower($network) . ':' . hash('sha256', $address);
    }

    private function buildRateLimitKey(string $scope, string $value): string
    {
        return $this->telegramService->getCachePrefix() . 'usdt_query:rate:' . $scope . ':' . hash('sha256', $value);
    }

    private function describeRateLimitKey(string $key): string
    {
        if (str_contains($key, ':tg:')) {
            return 'tg_user_id';
        }
        if (str_contains($key, ':chat:')) {
            return 'chat_id';
        }
        if (str_contains($key, ':user:')) {
            return 'user_id';
        }

        return 'unknown';
    }

    private function resolveBoundUserId($tgUserId): ?int
    {
        try {
            $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);
            if (!is_array($bindInfo) || empty($bindInfo['user_id'])) {
                return null;
            }

            return (int)$bindInfo['user_id'];
        } catch (\Throwable $e) {
            Log::warning('usdt query resolve bound user failed', [
                'tg_user_id' => $this->hashIdentifier($tgUserId),
                'error_summary' => $this->summarizeError($e->getMessage()),
            ]);
            return null;
        }
    }

    private function getSupportedNetworks(): array
    {
        $config = (array)Config::get('telegram.usdt_query_api', []);
        $supported = $config['supported_networks'] ?? [];
        if (!is_array($supported) || empty($supported)) {
            $defaultNetwork = strtoupper(trim((string)($config['network'] ?? 'TRC20')));
            return $defaultNetwork === '' ? ['TRC20'] : [$defaultNetwork];
        }

        $normalized = [];
        foreach ($supported as $network) {
            $network = strtoupper(trim((string)$network));
            if ($network !== '') {
                $normalized[] = $network;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function getCacheTtl(): int
    {
        $config = (array)Config::get('telegram.usdt_query_api', []);
        return max(10, min(30, (int)($config['cache_ttl'] ?? 15)));
    }

    private function getRateLimitTtl(): int
    {
        $config = (array)Config::get('telegram.usdt_query_api', []);
        return max(3, min(30, (int)($config['rate_limit_ttl'] ?? 5)));
    }

    private function summarizeError($message): string
    {
        $message = trim((string)$message);
        if ($message === '') {
            return 'unknown';
        }

        $message = preg_replace('/https?:\/\/[^\s]+/i', '[URL_REDACTED]', $message) ?? $message;
        $message = preg_replace('/\b(0x[a-fA-F0-9]{40}|T[1-9A-HJ-NP-Za-km-z]{33})\b/', '[ADDRESS_REDACTED]', $message) ?? $message;
        $message = preg_replace('/([?&](?:token|secret|key)=)[^&\s]+/i', '$1[REDACTED]', $message) ?? $message;

        return mb_substr($message, 0, 80, 'UTF-8');
    }

    private function hashIdentifier($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', (string)$value), 0, 12);
    }

    private function passesTronBase58Check(string $address): bool
    {
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        $decoded = $this->decodeBase58($address);
        if ($decoded === null || strlen($decoded) !== 25) {
            return false;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        if ($payload === false || $checksum === false || strlen($payload) !== 21 || strlen($checksum) !== 4) {
            return false;
        }

        if (ord($payload[0]) !== 0x41) {
            return false;
        }

        $expectedChecksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return hash_equals($expectedChecksum, $checksum);
    }

    private function decodeBase58(string $input): ?string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $bytes = [0];
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $carry = strpos($alphabet, $input[$i]);
            if ($carry === false) {
                return null;
            }

            for ($j = 0, $size = count($bytes); $j < $size; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }

        $leadingZeroCount = 0;
        while ($leadingZeroCount < $length && $input[$leadingZeroCount] === '1') {
            $leadingZeroCount++;
        }
        while ($leadingZeroCount-- > 0) {
            $bytes[] = 0;
        }

        $output = '';
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $output .= chr($bytes[$i]);
        }

        return $output;
    }

    private function isSafeOutboundApiUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''), '[]');
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $user !== '' || $pass !== '') {
            return false;
        }

        $normalizedHost = strtolower($host);
        if ($normalizedHost === 'localhost' || str_ends_with($normalizedHost, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIpAddress($host);
        }

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
