<?php
declare(strict_types=1);

namespace tests\Unit;

use app\service\ActionRateLimiter;
use PHPUnit\Framework\TestCase;
use think\facade\Cache;

/**
 * SEC-004: ActionRateLimiter 单元测试
 *
 * 测试滑动窗口限流逻辑，不依赖真实 Redis（使用文件缓存或 mock）。
 */
class ActionRateLimiterTest extends TestCase
{
    private string $testKey = 'test:unit:ratelimit';
    private string $cacheKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        // ActionRateLimiter 内部使用 'ratelimit:' . $key 作为缓存键
        $this->cacheKey = 'ratelimit:' . $this->testKey;
        $this->cleanupKey($this->cacheKey);
    }

    protected function tearDown(): void
    {
        $this->cleanupKey($this->cacheKey);
        parent::tearDown();
    }

    private function cleanupKey(string $key): void
    {
        try {
            Cache::store('redis')->delete($key);
        } catch (\Throwable) {
        }
        try {
            Cache::delete($key);
        } catch (\Throwable) {
        }
    }

    public function testAllowsWithinLimit(): void
    {
        // 窗口 60 秒，最多 3 次
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(
                ActionRateLimiter::check($this->testKey, 3, 60),
                "第 {$i} 次请求应被允许"
            );
        }
    }

    public function testBlocksWhenExceedingLimit(): void
    {
        // 窗口 60 秒，最多 2 次
        $this->assertTrue(ActionRateLimiter::check($this->testKey, 2, 60));
        $this->assertTrue(ActionRateLimiter::check($this->testKey, 2, 60));
        // 第 3 次应被拒绝
        $this->assertFalse(
            ActionRateLimiter::check($this->testKey, 2, 60),
            '超过限制后应被拒绝'
        );
    }

    public function testAssertAllowedThrowsWhenLimited(): void
    {
        // 先耗尽限额
        ActionRateLimiter::check($this->testKey, 1, 60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('操作过于频繁');
        ActionRateLimiter::assertAllowed($this->testKey, 1, 60, '操作过于频繁');
    }

    public function testAssertAllowedPassesWhenNotLimited(): void
    {
        // 不应抛出异常
        ActionRateLimiter::assertAllowed($this->testKey, 5, 60, '操作过于频繁');
        $this->assertTrue(true, '未超限时空转通过');
    }

    public function testZeroMaxAttemptsAlwaysAllowed(): void
    {
        // maxAttempts <= 0 时直接放行（配置无效时 fail-open）
        $this->assertTrue(ActionRateLimiter::check($this->testKey, 0, 60));
        $this->assertTrue(ActionRateLimiter::check($this->testKey, -1, 60));
    }

    public function testZeroWindowAlwaysAllowed(): void
    {
        // windowSeconds <= 0 时直接放行
        $this->assertTrue(ActionRateLimiter::check($this->testKey, 5, 0));
        $this->assertTrue(ActionRateLimiter::check($this->testKey, 5, -1));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $keyA = $this->testKey . ':A';
        $keyB = $this->testKey . ':B';

        // 耗尽 A 的限额
        ActionRateLimiter::check($keyA, 1, 60);
        $this->assertFalse(ActionRateLimiter::check($keyA, 1, 60), 'A 应被限流');

        // B 不受影响
        $this->assertTrue(ActionRateLimiter::check($keyB, 1, 60), 'B 不应受 A 影响');

        // 清理
        $this->cleanupKey('ratelimit:' . $keyA);
        $this->cleanupKey('ratelimit:' . $keyB);
    }
}
