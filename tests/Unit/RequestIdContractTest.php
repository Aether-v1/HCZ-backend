<?php
declare(strict_types=1);

namespace tests\Unit;

use app\middleware\RequestIdMiddleware;
use app\support\RequestId;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

/**
 * R1.1 — Request-ID Contract Tests
 *
 * 覆盖：
 * - generate() 格式与安全随机源
 * - isValid() / sanitize() 长度 + 字符白名单（拒绝空白/控制字符/超长/非 ASCII）
 * - RequestIdMiddleware：合法传入保留、非法传入重新生成、响应头始终存在
 * 覆盖级别：UNIT（工具 + middleware 实例级；真实 HTTP 头由 R1.4 骨架接入后补 ROUTE 级）。
 */
class RequestIdContractTest extends TestCase
{
    protected function setUp(): void
    {
        RequestId::reset();
    }

    protected function tearDown(): void
    {
        RequestId::reset();
    }

    public function testGenerateFormat(): void
    {
        $id = RequestId::generate();
        $this->assertMatchesRegularExpression('/^hcz_\d{14}_[0-9a-f]{16}$/', $id);
        $this->assertLessThanOrEqual(64, strlen($id));
    }

    public function testGenerateUniqueAcrossCalls(): void
    {
        $this->assertNotSame(RequestId::generate(), RequestId::generate());
    }

    public function testIsValidAcceptsCommonFormats(): void
    {
        $this->assertTrue(RequestId::isValid('abc-123_x:Y'));
        $this->assertTrue(RequestId::isValid('hcz_20260910120000_0123456789abcdef'));
        $this->assertTrue(RequestId::isValid(str_repeat('a', 128)));
    }

    public function testIsValidRejectsBadInput(): void
    {
        $this->assertFalse(RequestId::isValid(''));
        $this->assertFalse(RequestId::isValid('has space'));
        $this->assertFalse(RequestId::isValid("line\nbreak"));
        $this->assertFalse(RequestId::isValid("ctrl\x07char"));
        $this->assertFalse(RequestId::isValid(str_repeat('a', 129)));   // 超长
        $this->assertFalse(RequestId::isValid('中文-id'));                // 非 ASCII
        $this->assertFalse(RequestId::isValid('quote"inject'));          // 引号
        $this->assertFalse(RequestId::isValid('slash/inject'));          // 斜杠
    }

    public function testSanitizeTrimsAndReturnsValidId(): void
    {
        $this->assertSame('valid-id-1', RequestId::sanitize('  valid-id-1  '));
        $this->assertNull(RequestId::sanitize('bad id'));
        $this->assertNull(RequestId::sanitize(str_repeat('x', 129)));
    }

    public function testMiddlewarePreservesValidIncomingId(): void
    {
        $request = new Request();
        $request->withHeader(['X-Request-ID' => 'client-trace-42']);
        $middleware = new RequestIdMiddleware();

        $response = $middleware->handle($request, fn() => Response::create('{}', 'json', 200));

        $this->assertSame('client-trace-42', RequestId::current());
        $this->assertSame('client-trace-42', $response->getHeader('X-Request-ID'));
    }

    public function testMiddlewareReplacesInvalidIncomingIdWithGenerated(): void
    {
        $request = new Request();
        $request->withHeader(['X-Request-ID' => "evil\n\rx"]); // 控制字符
        $middleware = new RequestIdMiddleware();

        $response = $middleware->handle($request, fn() => Response::create('{}', 'json', 200));

        $this->assertNotSame("evil\n\rx", RequestId::current());
        $this->assertMatchesRegularExpression('/^hcz_/', RequestId::current());
        $this->assertSame(RequestId::current(), $response->getHeader('X-Request-ID'));
    }

    public function testMiddlewareGeneratesWhenAbsent(): void
    {
        $request = new Request();
        $middleware = new RequestIdMiddleware();

        $response = $middleware->handle($request, fn() => Response::create('{}', 'json', 200));

        $this->assertMatchesRegularExpression('/^hcz_/', RequestId::current());
        $this->assertSame(RequestId::current(), $response->getHeader('X-Request-ID'));
    }
}
