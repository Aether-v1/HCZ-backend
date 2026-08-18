<?php

namespace app\listener;

use think\event\LogWrite;

class SanitizeLogPayload
{
    public function handle(LogWrite $event): void
    {
        foreach ($event->log as $level => $messages) {
            foreach ($messages as $index => $message) {
                $event->log[$level][$index] = $this->sanitizeValue($message);
            }
        }
    }

    private function sanitizeValue($value, ?string $key = null)
    {
        if ($this->shouldFilterKey($key)) {
            return '[FILTERED]';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitizeValue($itemValue, is_string($itemKey) ? $itemKey : null);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeStringByKey($value, $key);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->sanitizeStringByKey((string) $value, $key);
        }

        return $value;
    }

    private function sanitizeStringByKey(string $value, ?string $key = null): string
    {
        if ($this->shouldFilterKey($key)) {
            return '[FILTERED]';
        }

        if ($this->shouldMaskPhoneKey($key)) {
            return $this->maskPhone($value);
        }

        if ($this->shouldMaskTokenKey($key)) {
            return $this->maskToken($value);
        }

        if ($this->shouldMaskAddressKey($key)) {
            return $this->maskAddress($value);
        }

        return $this->sanitizeString($value);
    }

    private function sanitizeString(string $value): string
    {
        $value = $this->filterAssignmentValue($value, '(?:password|passwd|pwd|secret|key|api_key|app_key|secret_key|private_key|public_key|client_secret)', true);
        $value = $this->filterAssignmentValue($value, '(?:(?:access|refresh|id)_token|token|authorization)', false);

        $value = preg_replace_callback('/\/bot(\d{6,12}:[A-Za-z0-9_-]{20,})/u', function (array $matches): string {
            return '/bot' . $this->maskToken($matches[1]);
        }, $value) ?? $value;

        $value = preg_replace_callback('/(?i)(Bearer\s+)([A-Za-z0-9\-._~+\/=]+)/u', function (array $matches): string {
            return $matches[1] . $this->maskToken($matches[2]);
        }, $value) ?? $value;

        $value = preg_replace_callback('/(?<!\d)(1\d{2})\d{4}(\d{4})(?!\d)/u', function (array $matches): string {
            return $matches[1] . '****' . $matches[2];
        }, $value) ?? $value;

        $value = preg_replace_callback('/\bT[A-Za-z0-9]{41}\b/u', function (array $matches): string {
            return $this->maskAddress($matches[0]);
        }, $value) ?? $value;

        return $value;
    }

    private function filterAssignmentValue(string $value, string $keyPattern, bool $filter): string
    {
        $pattern = '/(^|[?&\s"\'])((' . $keyPattern . ')(\s*(?:=|:|=>)\s*)("[^"]*"|\'[^\']*\'|[^&\s"\']+))(?=(?:&|\s|"|\'|$))/iu';

        return preg_replace_callback($pattern, function (array $matches) use ($filter): string {
            $replacement = $filter ? '[FILTERED]' : $this->maskToken($this->stripWrappingQuotes($matches[5]));

            return $matches[1] . $matches[3] . $matches[4] . $this->wrapLikeOriginal($matches[5], $replacement);
        }, $value) ?? $value;
    }

    private function normalizeKey(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $key = preg_replace('/([a-z])([A-Z])/', '$1_$2', $key) ?? $key;
        $key = strtolower($key);

        return preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    }

    private function shouldFilterKey(?string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return $normalized !== ''
            && (bool) preg_match('/(?:^|_)(password|passwd|pwd|secret|key)(?:$|_)/', $normalized);
    }

    private function shouldMaskPhoneKey(?string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return $normalized !== ''
            && (bool) preg_match('/(?:^|_)(phone|mobile|telephone|tel)(?:$|_)/', $normalized);
    }

    private function shouldMaskTokenKey(?string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return $normalized !== ''
            && (bool) preg_match('/(?:^|_)(token|authorization)(?:$|_)/', $normalized);
    }

    private function shouldMaskAddressKey(?string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return $normalized !== ''
            && (bool) preg_match('/(?:^|_)(address|wallet)(?:$|_)/', $normalized);
    }

    private function stripWrappingQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' || $first === '\'') && $first === $last) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private function wrapLikeOriginal(string $original, string $replacement): string
    {
        $length = strlen($original);
        if ($length >= 2) {
            $first = $original[0];
            $last = $original[$length - 1];
            if (($first === '"' || $first === '\'') && $first === $last) {
                return $first . $replacement . $last;
            }
        }

        return $replacement;
    }

    private function maskPhone(string $value): string
    {
        return preg_replace('/(?<!\d)(1\d{2})\d{4}(\d{4})(?!\d)/u', '$1****$2', $value) ?? $value;
    }

    private function maskToken(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', max(4, $length));
        }

        return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
    }

    private function maskAddress(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length <= 10) {
            return $value;
        }

        return substr($value, 0, 6) . '...' . substr($value, -4);
    }
}