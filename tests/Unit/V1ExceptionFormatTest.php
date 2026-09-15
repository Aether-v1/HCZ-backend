<?php
declare(strict_types=1);

namespace tests\Unit;

use app\ExceptionHandle;
use app\support\RequestId;
use app\support\V1ValidationException;
use PHPUnit\Framework\TestCase;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Request;

/**
 * R1.4 - V1 Exception Format Contract Tests
 *
 * 覆盖：
 * - V1 404 → RESOURCE_NOT_FOUND + request_id
 * - V1 405 → METHOD_NOT_ALLOWED + request_id
 * - V1 422 (ValidateException) → VALIDATION_FAILED + details (field→messages[])
 * - V1 422 (V1ValidationException) → VALIDATION_FAILED + details
 * - V1 500 (RuntimeException) → INTERNAL_ERROR + 脱敏
 * - V1 error envelope 格式: {success:false, error:{code,message}, request_id}
 * - Legacy /api/* 错误格式不变
 * - RequestId 在 V1 error path 可用
 */
class V1ExceptionFormatTest extends TestCase
{
    private ExceptionHandle $handler;

    protected function setUp(): void
    {
        $this->handler = new ExceptionHandle(app());
        RequestId::reset();
    }

    protected function tearDown(): void
    {
        RequestId::reset();
    }

    private function makeV1Request(string $path = 'api/v1/orders'): Request
    {
        $request = new Request();
        $request->setPathinfo($path);
        return $request;
    }

    private function makeLegacyRequest(string $path = 'api/orders'): Request
    {
        $request = new Request();
        $request->setPathinfo($path);
        return $request;
    }

    private function decodeResponse($response): array
    {
        $data = $response->getData();
        if (is_string($data)) {
            return json_decode($data, true);
        }
        return $data;
    }

    // ===== V1 404 =====

    public function testV1404ReturnsResourceNotFound(): void
    {
        $request = $this->makeV1Request('api/v1/not-found');
        $exception = new HttpException(404, '资源不存在');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(404, $response->getCode());
        $this->assertFalse($body['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $body['error']['code']);
        $this->assertSame('资源不存在', $body['error']['message']);
        $this->assertArrayHasKey('request_id', $body);
        $this->assertNotEmpty($body['request_id']);
    }

    // ===== V1 405 =====

    public function testV1405ReturnsMethodNotAllowed(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new HttpException(405, '请求方法不允许');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(405, $response->getCode());
        $this->assertFalse($body['success']);
        $this->assertSame('METHOD_NOT_ALLOWED', $body['error']['code']);
        $this->assertArrayHasKey('request_id', $body);
    }

    // ===== V1 422 ValidateException =====

    public function testV1422ValidateExceptionReturnsValidationFailed(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new ValidateException(['product_id' => '商品ID必填', 'quantity' => '数量必须大于0']);

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($body['success']);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertArrayHasKey('details', $body['error']);
        $this->assertIsArray($body['error']['details']['product_id']);
        $this->assertContains('商品ID必填', $body['error']['details']['product_id']);
        $this->assertIsArray($body['error']['details']['quantity']);
        $this->assertContains('数量必须大于0', $body['error']['details']['quantity']);
        $this->assertArrayHasKey('request_id', $body);
    }

    public function testV1422ValidateExceptionStringError(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new ValidateException('参数验证失败');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(422, $response->getCode());
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertArrayHasKey('details', $body['error']);
        $this->assertArrayHasKey('_global', $body['error']['details']);
    }

    // ===== V1 422 V1ValidationException =====

    public function testV1422V1ValidationExceptionPreservesDetails(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new V1ValidationException(['email' => ['邮箱格式不正确'], 'name' => ['姓名必填']]);

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(422, $response->getCode());
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame(['邮箱格式不正确'], $body['error']['details']['email']);
        $this->assertSame(['姓名必填'], $body['error']['details']['name']);
    }

    // ===== V1 500 =====

    public function testV1500ReturnsInternalErrorSanitized(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new \RuntimeException('数据库连接失败: secret_password=abc123 /etc/passwd');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(500, $response->getCode());
        $this->assertFalse($body['success']);
        $this->assertSame('INTERNAL_ERROR', $body['error']['code']);
        // 脱敏：不得泄露内部 message
        $this->assertStringNotContainsString('secret_password', $body['error']['message']);
        $this->assertStringNotContainsString('/etc/passwd', $body['error']['message']);
        $this->assertArrayHasKey('request_id', $body);
    }

    // ===== V1 envelope format =====

    public function testV1ErrorEnvelopeStructure(): void
    {
        $request = $this->makeV1Request('api/v1/orders');
        $exception = new HttpException(404, 'not found');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        // V1 envelope: {success, error:{code,message}, request_id}
        $this->assertArrayHasKey('success', $body);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('code', $body['error']);
        $this->assertArrayHasKey('message', $body['error']);
        $this->assertArrayHasKey('request_id', $body);

        // 不得是 legacy 格式 {code,status,message,data,success}
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('raw_code', $body);
    }

    // ===== Legacy isolation =====

    public function testLegacyApi404RemainsLegacyFormat(): void
    {
        $request = $this->makeLegacyRequest('api/not-found');
        $exception = new HttpException(404, '资源不存在');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        // Legacy format: {code,status,message,data,success}
        $this->assertSame(404, $body['code']);
        $this->assertSame('error', $body['status']);
        $this->assertArrayHasKey('data', $body);
        $this->assertFalse($body['success']);
        // 不得是 V1 格式
        $this->assertArrayNotHasKey('error', $body);
        $this->assertArrayNotHasKey('request_id', $body);
    }

    public function testLegacyApi422RemainsLegacyFormat(): void
    {
        $request = $this->makeLegacyRequest('api/orders');
        $exception = new ValidateException('参数错误');

        $response = $this->handler->render($request, $exception);
        $body = $this->decodeResponse($response);

        $this->assertSame(422, $body['code']);
        $this->assertSame('error', $body['status']);
        $this->assertArrayNotHasKey('error', $body);
    }

    // ===== RequestId on V1 error paths =====

    public function testRequestIdPresentOnAllV1ErrorPaths(): void
    {
        $paths = [
            ['api/v1/not-found', new HttpException(404, 'not found')],
            ['api/v1/orders', new HttpException(405, 'method not allowed')],
            ['api/v1/orders', new ValidateException('validation failed')],
            ['api/v1/orders', new \RuntimeException('internal error')],
        ];

        foreach ($paths as [$path, $exception]) {
            RequestId::reset();
            $request = $this->makeV1Request($path);
            $response = $this->handler->render($request, $exception);
            $body = $this->decodeResponse($response);

            $this->assertArrayHasKey('request_id', $body, "request_id missing for {$path}");
            $this->assertNotEmpty($body['request_id'], "request_id empty for {$path}");
        }
    }

    public function testV1ErrorResponseHasXRequestIdHeader(): void
    {
        $request = $this->makeV1Request('api/v1/not-found');
        $exception = new HttpException(404, 'not found');

        $response = $this->handler->render($request, $exception);
        $headers = $response->getHeader();

        $this->assertArrayHasKey('X-Request-ID', $headers);
        $this->assertNotEmpty($headers['X-Request-ID']);
    }
}
