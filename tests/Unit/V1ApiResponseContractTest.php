<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\ErrorCode;
use app\support\RequestId;
use app\support\V1ApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * R1.1 — V1 API Response Contract Tests
 *
 * 覆盖：success/error envelope、machine code、HTTP status、request_id、
 *       204 空 body、Content-Type、details 可选、INTERNAL_ERROR 脱敏。
 * 覆盖级别：UNIT（类级契约；真实 route 由 R1.4 /api/v1 骨架接入后补 ROUTE 级）。
 */
class V1ApiResponseContractTest extends TestCase
{
    protected function setUp(): void
    {
        RequestId::reset();
        RequestId::set('test-request-001');
    }

    protected function tearDown(): void
    {
        RequestId::reset();
    }

    // ===== Success Contract =====

    public function testSuccessEnvelopeShape(): void
    {
        $response = V1ApiResponse::success(['id' => 1]);
        $payload = $response->getData();

        $this->assertSame(200, $response->getCode());
        $this->assertSame('application/json', strtok((string) $response->getHeader('Content-Type'), ';'));
        $this->assertTrue($payload['success']);
        $this->assertSame(['id' => 1], $payload['data']);
        // 无 meta 时不输出 meta 键
        $this->assertArrayNotHasKey('meta', $payload);
        // 成功响应 body 不带 request_id（通过响应头传递）
        $this->assertArrayNotHasKey('request_id', $payload);
    }

    public function testSuccessWithMeta(): void
    {
        $response = V1ApiResponse::success([], 200, ['page' => 1, 'total' => 10]);
        $payload = $response->getData();

        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']);
        $this->assertSame(['page' => 1, 'total' => 10], $payload['meta']);
    }

    public function testSuccessListDataIsArray(): void
    {
        $payload = V1ApiResponse::success([])->getData();
        $this->assertIsArray($payload['data']);
        $this->assertSame([], $payload['data']);
    }

    public function testCreatedStatus(): void
    {
        $response = V1ApiResponse::success(['id' => 9], 201);
        $this->assertSame(201, $response->getCode());
        $this->assertTrue($response->getData()['success']);
    }

    public function testNoContent204HasEmptyBody(): void
    {
        $response = V1ApiResponse::noContent();
        $this->assertSame(204, $response->getCode());
        $this->assertSame('', $response->getContent());
    }

    // ===== Error Contract =====

    public function testErrorWithNumericCode(): void
    {
        $response = V1ApiResponse::error(ErrorCode::RESOURCE_NOT_FOUND, 'not found');
        $payload = $response->getData();

        $this->assertSame(404, $response->getCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('RESOURCE_NOT_FOUND', $payload['error']['code']);
        $this->assertSame('not found', $payload['error']['message']);
        $this->assertSame('test-request-001', $payload['request_id']);
        $this->assertArrayNotHasKey('details', $payload['error']);
    }

    public function testErrorWithStringCode(): void
    {
        $response = V1ApiResponse::error('AUTH_UNAUTHENTICATED', 'login required');
        $payload = $response->getData();

        $this->assertSame(401, $response->getCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('AUTH_UNAUTHENTICATED', $payload['error']['code']);
    }

    public function testErrorWithDetails(): void
    {
        $response = V1ApiResponse::error('VALIDATION_FAILED', 'bad', 422, ['field' => ['required']]);
        $payload = $response->getData();

        $this->assertSame(422, $response->getCode());
        $this->assertSame('VALIDATION_FAILED', $payload['error']['code']);
        $this->assertSame(['field' => ['required']], $payload['error']['details']);
    }

    public function testErrorHttpStatusOverridesDerived(): void
    {
        // AUTH_FORBIDDEN 默认 403，显式覆盖为 400 时以显式为准
        $response = V1ApiResponse::error('AUTH_FORBIDDEN', 'denied', 400);
        $this->assertSame(400, $response->getCode());
    }

    public function testErrorContentTypeIsJson(): void
    {
        $response = V1ApiResponse::error('RATE_LIMITED', 'slow down');
        $this->assertSame('application/json', strtok((string) $response->getHeader('Content-Type'), ';'));
        $this->assertSame(429, $response->getCode());
    }

    // ===== Internal Error Redaction =====

    public function testInternalErrorRedactsExceptionDetails(): void
    {
        $e = new \RuntimeException('SECRET_SQL_PASSWORD_123');
        $response = V1ApiResponse::internalError($e);
        $body = json_encode($response->getData());

        $this->assertSame(500, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertSame('INTERNAL_ERROR', $response->getData()['error']['code']);
        $this->assertStringNotContainsString('SECRET_SQL_PASSWORD_123', (string) $body);
        $this->assertStringNotContainsString('RuntimeException', (string) $body);
        // request_id 仍可用于故障定位
        $this->assertSame('test-request-001', $response->getData()['request_id']);
    }

    // ===== No Double-Wrapping =====

    public function testErrorBodyNeverNestsV1Envelope(): void
    {
        $response = V1ApiResponse::error('CONFLICT', 'conflict');
        $payload = $response->getData();

        $this->assertArrayNotHasKey('data', $payload);          // 错误体无 data 层
        $this->assertArrayNotHasKey('success', $payload['error']); // error 内不再嵌套 success
        $this->assertSame('CONFLICT', $payload['error']['code']);
    }

    // ===== toArray helper =====

    public function testToArraySuccessAndError(): void
    {
        $ok = V1ApiResponse::toArray(true, ['data' => ['x' => 1]]);
        $this->assertSame(['success' => true, 'data' => ['x' => 1]], $ok);

        $err = V1ApiResponse::toArray(false, ['code' => 'INVALID_STATE', 'message' => 'bad state'], null, 'rid-1');
        $this->assertSame('INVALID_STATE', $err['error']['code']);
        $this->assertSame('rid-1', $err['request_id']);
    }
}
