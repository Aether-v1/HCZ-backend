<?php
declare(strict_types=1);

namespace tests\Unit;

use app\middleware\ApiResponseFormat;
use app\support\V1ApiResponse;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

/**
 * R1.4 - ApiResponseFormat V1 Isolation Contract Tests
 *
 * 覆盖：
 * - V1 success Json → 不被 legacy normalize（保持 {success,data}）
 * - V1 error Json → 不被 legacy normalize（保持 {success,error,request_id}）
 * - Legacy /api/* Json → 仍被 normalize 为 {code,status,message,data,success,raw_code}
 * - DataTables response → 保持 recordsTotal/recordsFiltered 在 data 内
 * - 204 Response → 不被 normalize（body 为空）
 * - Redirect Response → 不被 normalize
 * - /api/v10 → 走 legacy logic（不是 V1）
 * - /api/v1foo → 走 legacy logic（不是 V1）
 */
class V1ApiResponseFormatIsolationTest extends TestCase
{
    private ApiResponseFormat $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ApiResponseFormat();
    }

    private function makeRequest(string $path): Request
    {
        $request = new Request();
        $request->setPathinfo($path);
        return $request;
    }

    private function runMiddleware(Request $request, Response $response): Response
    {
        $next = function () use ($response) {
            return $response;
        };
        return $this->middleware->handle($request, $next);
    }

    // ===== V1 success isolation =====

    public function testV1SuccessJsonNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/orders');
        $v1Response = V1ApiResponse::success(['items' => [1, 2, 3]], 200, ['total' => 3]);

        $result = $this->runMiddleware($request, $v1Response);
        $body = $result->getData();

        // V1 envelope 保持不变
        $this->assertTrue($body['success']);
        $this->assertSame(['items' => [1, 2, 3]], $body['data']);
        $this->assertSame(['total' => 3], $body['meta']);
        // 不得被改写成 legacy 格式
        $this->assertArrayNotHasKey('code', $body);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('raw_code', $body);
    }

    public function testV1SuccessWithoutMetaNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/orders');
        $v1Response = V1ApiResponse::success(['id' => 1]);

        $result = $this->runMiddleware($request, $v1Response);
        $body = $result->getData();

        $this->assertTrue($body['success']);
        $this->assertSame(['id' => 1], $body['data']);
        $this->assertArrayNotHasKey('meta', $body);
        $this->assertArrayNotHasKey('code', $body);
    }

    // ===== V1 error isolation =====

    public function testV1ErrorJsonNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/orders');
        $v1Response = V1ApiResponse::error('RESOURCE_NOT_FOUND', '订单不存在', 404);

        $result = $this->runMiddleware($request, $v1Response);
        $body = $result->getData();

        // V1 error envelope 保持不变
        $this->assertFalse($body['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $body['error']['code']);
        $this->assertSame('订单不存在', $body['error']['message']);
        $this->assertArrayHasKey('request_id', $body);
        // 不得被改写成 legacy 格式
        $this->assertArrayNotHasKey('code', $body);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('raw_code', $body);
    }

    // ===== Legacy API still normalized =====

    public function testLegacyApiJsonStillNormalized(): void
    {
        $request = $this->makeRequest('api/orders');
        $legacyResponse = json(['id' => 1, 'name' => 'test']);

        $result = $this->runMiddleware($request, $legacyResponse);
        $body = $result->getData();

        // Legacy format: {code,status,message,data,success,raw_code}
        $this->assertSame(200, $body['code']);
        $this->assertSame('success', $body['status']);
        $this->assertSame('ok', $body['message']);
        $this->assertSame(['id' => 1, 'name' => 'test'], $body['data']);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('raw_code', $body);
    }

    public function testLegacyApiErrorJsonStillNormalized(): void
    {
        $request = $this->makeRequest('api/orders');
        $legacyResponse = json(['code' => 404, 'msg' => 'not found'], 404);

        $result = $this->runMiddleware($request, $legacyResponse);
        $body = $result->getData();

        $this->assertSame(404, $body['code']);
        $this->assertSame('error', $body['status']);
        $this->assertSame('not found', $body['message']);
        $this->assertFalse($body['success']);
    }

    // ===== DataTables preservation =====

    public function testDataTablesResponsePreservesRecordsTotal(): void
    {
        $request = $this->makeRequest('admin/user_list');
        $dataTablesResponse = json([
            'draw' => 1,
            'recordsTotal' => 100,
            'recordsFiltered' => 50,
            'data' => [['id' => 1]],
        ]);

        $result = $this->runMiddleware($request, $dataTablesResponse);
        $body = $result->getData();

        // admin path 不被 ApiResponseFormat normalize（非 api/ 前缀、非 ajax/json）
        // DataTables 原始结构保持不变
        $this->assertSame(100, $body['recordsTotal']);
        $this->assertSame(50, $body['recordsFiltered']);
        $this->assertSame([['id' => 1]], $body['data']);
    }

    // ===== 204 isolation =====

    public function test204ResponseNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/orders/1');
        $noContentResponse = V1ApiResponse::noContent();

        $result = $this->runMiddleware($request, $noContentResponse);

        // 204 不被 normalize，body 为空
        $this->assertSame(204, $result->getCode());
        $this->assertEmpty($result->getContent());
    }

    public function testLegacy204ResponseNotNormalized(): void
    {
        $request = $this->makeRequest('api/orders/1');
        $noContentResponse = Response::create('', 'html', 204);

        $result = $this->runMiddleware($request, $noContentResponse);

        $this->assertSame(204, $result->getCode());
        $this->assertEmpty($result->getContent());
    }

    // ===== Redirect isolation =====

    public function testRedirectResponseNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/old-path');
        $redirectResponse = redirect('/api/v1/new-path');

        $result = $this->runMiddleware($request, $redirectResponse);

        // Redirect 不被 normalize
        $this->assertSame(302, $result->getCode());
    }

    // ===== V1 boundary: /api/v10 and /api/v1foo must go legacy =====

    public function testApiV10GoesLegacyLogic(): void
    {
        $request = $this->makeRequest('api/v10/orders');
        $response = json(['id' => 1]);

        $result = $this->runMiddleware($request, $response);
        $body = $result->getData();

        // /api/v10 不是 V1，走 legacy normalize
        $this->assertArrayHasKey('code', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame('success', $body['status']);
    }

    public function testApiV1fooGoesLegacyLogic(): void
    {
        $request = $this->makeRequest('api/v1foo/bar');
        $response = json(['id' => 1]);

        $result = $this->runMiddleware($request, $response);
        $body = $result->getData();

        // /api/v1foo 不是 V1，走 legacy normalize
        $this->assertArrayHasKey('code', $body);
        $this->assertSame('success', $body['status']);
    }

    public function testApiV1ExactGoesV1(): void
    {
        $request = $this->makeRequest('api/v1');
        $v1Response = V1ApiResponse::success(['ok' => true]);

        $result = $this->runMiddleware($request, $v1Response);
        $body = $result->getData();

        // /api/v1 精确匹配是 V1
        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('code', $body);
    }

    // ===== Non-Json response not normalized =====

    public function testHtmlResponseNotNormalized(): void
    {
        $request = $this->makeRequest('api/v1/page');
        $htmlResponse = Response::create('<html>test</html>', 'html');

        $result = $this->runMiddleware($request, $htmlResponse);

        // HTML 不被 normalize
        $this->assertSame('<html>test</html>', $result->getContent());
    }

    public function testPlainTextResponseNotNormalized(): void
    {
        $request = $this->makeRequest('api/callback/bepusdt');
        $textResponse = Response::create('success', 'html');

        $result = $this->runMiddleware($request, $textResponse);

        // 支付回调纯文本不被 normalize
        $this->assertSame('success', $result->getContent());
    }
}
