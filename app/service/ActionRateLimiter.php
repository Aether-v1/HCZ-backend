<?php
declare (strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;
use think\facade\Cache;

/**
 * 通用操作限流器（滑动窗口）
 *
 * 用于注册、提现提交等敏感操作的外围限流。
 * 注意：限流仅为外围防护，不能替代 user 行锁、数据库事务和幂等流水。
 *
 * 缓存优先使用 Redis，失败时降级到文件缓存；缓存不可用时 fail-open（放行）。
 */
class ActionRateLimiter
{
    /**
     * 检查是否允许操作（滑动窗口）
     *
     * @param string $key          限流维度键（如 register:ip:1.2.3.4 / withdrawal:uid:123）
     * @param int    $maxAttempts  窗口内最大允许次数
     * @param int    $windowSeconds 窗口时长（秒）
     * @return bool true=允许, false=被限流
     */
    public static function check(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            return true;
        }

        $cacheKey = 'ratelimit:' . $key;
        $now = time();

        try {
            $timestamps = Cache::store('redis')->get($cacheKey, []);
        } catch (Throwable) {
            try {
                $timestamps = Cache::get($cacheKey, []);
            } catch (Throwable) {
                // 缓存完全不可用时 fail-open，避免影响正常业务
                return true;
            }
        }

        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // 移除窗口外的时间戳
        $cutoff = $now - $windowSeconds;
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn ($ts) => (int)$ts > $cutoff
        ));

        if (count($timestamps) >= $maxAttempts) {
            return false;
        }

        $timestamps[] = $now;

        try {
            Cache::store('redis')->set($cacheKey, $timestamps, $windowSeconds);
        } catch (Throwable) {
            try {
                Cache::set($cacheKey, $timestamps, $windowSeconds);
            } catch (Throwable) {
                // fail-open
            }
        }

        return true;
    }

    /**
     * 断言操作允许，否则抛出 RuntimeException
     *
     * @param string $key
     * @param int    $maxAttempts
     * @param int    $windowSeconds
     * @param string $message       被限流时的错误消息
     * @throws RuntimeException
     */
    public static function assertAllowed(
        string $key,
        int $maxAttempts,
        int $windowSeconds,
        string $message = '操作过于频繁，请稍后再试'
    ): void {
        if (!self::check($key, $maxAttempts, $windowSeconds)) {
            throw new RuntimeException($message);
        }
    }
}
