<?php
declare (strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;
use think\facade\Cache;
use think\facade\Session;

class LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW = 300;
    private const LOCK_SECONDS = 1800;

    public function assertNotLimited(string $ip, string $username): void
    {
        $key = $this->buildKey($ip, $username);
        $state = $this->getState($key);
        $now = time();

        if ($this->isExpired($state, $now)) {
            $this->clearByKey($key);
            return;
        }

        $lockedUntil = (int)($state['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            throw new RuntimeException('尝试过多，请稍后再试');
        }

        $firstFailedAt = (int)($state['first_failed_at'] ?? 0);
        $count = (int)($state['count'] ?? 0);
        if ($count >= self::MAX_FAILURES && $firstFailedAt > 0 && ($firstFailedAt + self::FAILURE_WINDOW) > $now) {
            $state['locked_until'] = $now + self::LOCK_SECONDS;
            $this->storeState($key, $state, self::LOCK_SECONDS);
            throw new RuntimeException('尝试过多，请稍后再试');
        }
    }

    public function recordFailure(string $ip, string $username): void
    {
        $key = $this->buildKey($ip, $username);
        $state = $this->getState($key);
        $now = time();
        $lockedUntil = (int)($state['locked_until'] ?? 0);

        if ($lockedUntil > $now) {
            return;
        }

        $firstFailedAt = (int)($state['first_failed_at'] ?? 0);
        if ($firstFailedAt <= 0 || ($firstFailedAt + self::FAILURE_WINDOW) <= $now) {
            $state = [
                'count' => 0,
                'first_failed_at' => $now,
                'locked_until' => 0,
            ];
            $firstFailedAt = $now;
        }

        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $state['first_failed_at'] = $firstFailedAt;
        $state['locked_until'] = (int)($state['locked_until'] ?? 0);

        $ttl = max(1, ($firstFailedAt + self::FAILURE_WINDOW) - $now);
        $this->storeState($key, $state, $ttl);
    }

    public function clear(string $ip, string $username): void
    {
        $this->clearByKey($this->buildKey($ip, $username));
    }

    private function buildKey(string $ip, string $username): string
    {
        return 'login_fail:' . $ip . ':' . trim($username);
    }

    private function isExpired(array $state, int $now): bool
    {
        $firstFailedAt = (int)($state['first_failed_at'] ?? 0);
        $lockedUntil = (int)($state['locked_until'] ?? 0);

        return $firstFailedAt > 0
            && ($firstFailedAt + self::FAILURE_WINDOW) <= $now
            && $lockedUntil <= $now;
    }

    private function getState(string $key): array
    {
        try {
            $state = Cache::store('redis')->get($key, []);
        } catch (Throwable) {
            $state = Session::get($key, []);
        }

        return is_array($state) ? $state : [];
    }

    private function storeState(string $key, array $state, int $ttl): void
    {
        try {
            Cache::store('redis')->set($key, $state, $ttl);
        } catch (Throwable) {
        }

        Session::set($key, $state);
    }

    private function clearByKey(string $key): void
    {
        try {
            Cache::store('redis')->delete($key);
        } catch (Throwable) {
        }

        Session::delete($key);
    }
}