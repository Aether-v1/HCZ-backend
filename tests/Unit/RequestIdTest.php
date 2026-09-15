<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * Batch 1: Request ID 工具单元测试
 *
 * 覆盖：
 * - 生成唯一 ID
 * - current() 惰性生成
 * - set() 覆盖
 * - reset() 重置
 * - context() 输出
 * - 格式规范
 */
class RequestIdTest extends TestCase
{
    protected function setUp(): void
    {
        RequestId::reset();
    }

    protected function tearDown(): void
    {
        RequestId::reset();
    }

    public function testGenerateReturnsString(): void
    {
        $id = RequestId::generate();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testGenerateFormat(): void
    {
        $id = RequestId::generate();
        // 格式：hcz_{yyyyMMddHHmmss}_{16位随机十六进制}
        $this->assertStringStartsWith('hcz_', $id);
        $parts = explode('_', $id);
        $this->assertCount(3, $parts);
        $this->assertSame('hcz', $parts[0]);
        $this->assertSame(14, strlen($parts[1]), '时间戳部分应为 14 位');
        $this->assertSame(16, strlen($parts[2]), '随机部分应为 16 位十六进制');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $parts[2]);
    }

    public function testGenerateUniqueness(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = RequestId::generate();
        }
        $this->assertCount(100, array_unique($ids), '100 次生成应全部唯一');
    }

    public function testCurrentLazyGenerates(): void
    {
        // 重置后 current() 应自动生成
        $id = RequestId::current();
        $this->assertIsString($id);
        $this->assertStringStartsWith('hcz_', $id);
    }

    public function testCurrentReturnsSameId(): void
    {
        $id1 = RequestId::current();
        $id2 = RequestId::current();
        $this->assertSame($id1, $id2, '同一请求内 current() 应返回相同 ID');
    }

    public function testSetOverridesCurrent(): void
    {
        RequestId::set('custom-request-id-123');
        $this->assertSame('custom-request-id-123', RequestId::current());
    }

    public function testResetClearsCurrent(): void
    {
        RequestId::set('test-id');
        $this->assertSame('test-id', RequestId::current());

        RequestId::reset();
        $newId = RequestId::current();
        $this->assertNotSame('test-id', $newId);
        $this->assertStringStartsWith('hcz_', $newId);
    }

    public function testContextReturnsArrayWithRequestId(): void
    {
        RequestId::set('context-test-id');
        $context = RequestId::context();
        $this->assertIsArray($context);
        $this->assertArrayHasKey('request_id', $context);
        $this->assertSame('context-test-id', $context['request_id']);
    }

    public function testContextGeneratesIfNotSet(): void
    {
        $context = RequestId::context();
        $this->assertArrayHasKey('request_id', $context);
        $this->assertStringStartsWith('hcz_', $context['request_id']);
    }
}
