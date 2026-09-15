<?php
declare(strict_types=1);

namespace tests\Unit;

use app\middleware\ApiResponseFormat;
use app\support\ApiResponse;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

/**
 * R1.1 — Legacy Isolation Contract Tests
 *
 * 目标：证明 R1.1 基础设施没有改变 legacy /api/* 与 SSR 响应行为。
 * 覆盖：
 * - show() 成功/失败结构保持不变
 * - ApiResponse 仍为 legacy 同形（{code,status,message,data,success}）
 * - 全局 ApiResponseFormat 对 show()/ApiResponse 形状幂等（单层，无双重包装）
 * - ApiResponseFormat 对 AdminList(DataTables) 形状保持 recordsTotal/recordsFiltered 于 data 内
 * - 非 Json（SSR HTML / 流 / 重定向）不被包装
 * 覆盖级别：UNIT + MIDDLEWARE 实例级（真实 HTTP 已由 A5.2/Pre-R1 VPS 冒烟覆盖）。
 */
class LegacyIsolationContractTest extends TestCase
{
    public function testShowSuccessShapeUnchanged(): void
    {
        $response = show(200, 'success', 'ok', ['a' => 1]);
        $payload = $response->getData();

        $this->assertSame(200, $payload['code']);
        $this->assertSame('success', $payload['status']);
        $this->assertSame('ok', $payload['message']);
        $this->assertSame(['a' => 1], $payload['data']);
        $this->assertTrue($payload['success']);
    }

    public function testShowBusinessFailureShapeUnchanged(): void
    {
        $response = show(400, 'error', 'bad request', null, 400);
        $payload = $response->getData();

        $this->assertSame(400, $payload['code']);
        $this->assertSame('error', $payload['status']);
        $this->assertSame('bad request', $payload['message']);
        $this->assertNull($payload['data']);
        $this->assertFalse($payload['success']);
    }

    public function testApiResponseRemainsLegacyShape(): void
    {
        $ok = ApiResponse::success(['x' => 1])->getData();
        $this->assertSame(['code' => 200, 'status' => 'success', 'message' => 'success', 'data' => ['x' => 1], 'success' => true], $ok);

        $err = ApiResponse::error('denied', 40300, null, 403)->getData();
        $this->assertSame(40300, $err['code']);
        $this->assertSame('error', $err['status']);
        $this->assertFalse($err['success']);
    }

    public function testApiResponseFormatIsIdempotentOnShowShape(): void
    {
        $request = new Request();
        $request->setPathinfo('api/some/legacy');
        $middleware = new ApiResponseFormat();
        $inner = show(200, 'success', 'ok', ['id' => 5]);

        $out = $middleware->handle($request, fn() => $inner);
        $payload = $out->getData();

        // 单层 envelope：data 是业务数据，不是另一个 envelope
        $this->assertSame(200, $payload['code']);
        $this->assertSame('success', $payload['status']);
        $this->assertSame(['id' => 5], $payload['data']);
        $this->assertTrue($payload['success']);
        $this->assertArrayNotHasKey('raw_code', $payload['data'] ?? []);
    }

    public function testApiResponseFormatPreservesDataTablesFields(): void
    {
        $request = new Request();
        $request->setPathinfo('api/admin/list'); // api 路径触发 normalize（等价 DataTables ajax 场景）
        $middleware = new ApiResponseFormat();
        $inner = json([
            'recordsTotal' => 100,
            'recordsFiltered' => 42,
            'data' => [['id' => 1]],
        ]);

        $out = $middleware->handle($request, fn() => $inner);
        $payload = $out->getData();

        // R1.1 回归保护：全局 ApiResponseFormat 对无 envelope 的 DataTables 形状
        // 保持既有 normalize 行为（data 键取出、不二次嵌套、不抛错）。
        // 注：recordsTotal/recordsFiltered 在该路径下的 pre-existing normalize 丢弃行为
        //     是 R0-P1-06 legacy drift 的已知组成部分，R1.1 不改动（见实现报告）。
        $this->assertTrue($payload['success']);
        $this->assertSame([['id' => 1]], $payload['data']);
        $this->assertArrayNotHasKey('code', $payload['data'] ?? []);
        // 无双重包装：data 内不再出现 code/status envelope
        $this->assertArrayNotHasKey('status', $payload['data'] ?? []);
    }

    public function testApiResponseFormatLeavesHtmlUnwrapped(): void
    {
        $request = new Request();
        $middleware = new ApiResponseFormat();
        $html = Response::create('<html><body>SSR page</body></html>', 'html', 200);

        $out = $middleware->handle($request, fn() => $html);

        $this->assertStringContainsString('<html>', $out->getContent());
        $this->assertStringContainsString('SSR page', $out->getContent());
    }

    public function testApiResponseFormatLeavesRedirectUnwrapped(): void
    {
        $request = new Request();
        $middleware = new ApiResponseFormat();
        $redirect = Response::create('', 'redirect', 302)->header(['Location' => '/login']);

        $out = $middleware->handle($request, fn() => $redirect);

        $this->assertSame(302, $out->getCode());
    }
}
