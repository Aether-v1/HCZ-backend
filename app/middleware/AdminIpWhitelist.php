<?php
declare (strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class AdminIpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('app.admin_ip_whitelist', []);
        $allowedIps = is_array($allowedIps) ? array_filter(array_map('trim', $allowedIps)) : [];
        $currentIp = $this->resolveClientIp($request);

        if (!in_array($currentIp, $allowedIps, true)) {
            if ($request->isAjax() || $request->isJson() || $request->isPost()) {
                return show(403, 'error', '403 Forbidden', null, 403);
            }

            return Response::create('403 Forbidden', 'html', 403);
        }

        return $next($request);
    }

    private function resolveClientIp(Request $request): string
    {
        $remoteAddr = trim((string) $request->server('REMOTE_ADDR', ''));
        $trustedProxies = config('app.admin_trusted_proxy_ips', []);
        $trustedHeaders = config('app.admin_real_ip_headers', []);

        $trustedProxies = is_array($trustedProxies) ? array_filter(array_map('trim', $trustedProxies)) : [];
        $trustedHeaders = is_array($trustedHeaders) ? array_filter(array_map('trim', $trustedHeaders)) : [];

        if ($remoteAddr !== '' && $this->ipMatchesAny($remoteAddr, $trustedProxies)) {
            foreach ($trustedHeaders as $header) {
                $clientIp = $this->extractForwardedIp($request, $header);
                if ($clientIp !== '') {
                    return $clientIp;
                }
            }
        }

        if ($remoteAddr !== '') {
            return $remoteAddr;
        }

        return (string) $request->ip();
    }

    private function extractForwardedIp(Request $request, string $header): string
    {
        $serverKey = strtoupper(str_replace('-', '_', $header));
        if (strpos($serverKey, 'HTTP_') !== 0) {
            $serverKey = 'HTTP_' . $serverKey;
        }

        $rawValue = trim((string) $request->server($serverKey, ''));
        if ($rawValue === '') {
            return '';
        }

        foreach (explode(',', $rawValue) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    private function ipMatchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipMatchesRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesRange(string $ip, string $range): bool
    {
        if ($range === '') {
            return false;
        }

        if (strpos($range, '/') === false) {
            return strcasecmp($ip, $range) === 0;
        }

        [$subnet, $prefixLength] = array_pad(explode('/', $range, 2), 2, null);
        if ($subnet === null || $prefixLength === null || !ctype_digit($prefixLength)) {
            return false;
        }

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int) $prefixLength;
        $byteLength = strlen($ipBinary);
        $maxPrefix = $byteLength * 8;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}