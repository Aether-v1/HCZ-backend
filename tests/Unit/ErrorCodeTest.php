<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Batch 1: 错误码定义单元测试
 *
 * 覆盖：
 * - 错误码常量值
 * - 错误码到 HTTP 状态码映射
 * - 与现有成功码 200 的兼容性
 */
class ErrorCodeTest extends TestCase
{
    public function testSuccessCodeIs200(): void
    {
        $this->assertSame(200, ErrorCode::SUCCESS);
    }

    public function testBadRequestRange(): void
    {
        $this->assertSame(40000, ErrorCode::BAD_REQUEST);
        $this->assertSame(40001, ErrorCode::INVALID_PARAMETER);
        $this->assertSame(40002, ErrorCode::MISSING_PARAMETER);
        $this->assertSame(40003, ErrorCode::INVALID_FORMAT);
    }

    public function testAuthenticationRange(): void
    {
        $this->assertSame(40100, ErrorCode::UNAUTHENTICATED);
        $this->assertSame(40101, ErrorCode::TOKEN_INVALID);
        $this->assertSame(40102, ErrorCode::TOKEN_EXPIRED);
        $this->assertSame(40103, ErrorCode::LOGIN_REQUIRED);
    }

    public function testAuthorizationRange(): void
    {
        $this->assertSame(40300, ErrorCode::FORBIDDEN);
        $this->assertSame(40301, ErrorCode::PERMISSION_DENIED);
        $this->assertSame(40302, ErrorCode::ADMIN_REQUIRED);
        $this->assertSame(40303, ErrorCode::IP_NOT_ALLOWED);
    }

    public function testNotFoundRange(): void
    {
        $this->assertSame(40400, ErrorCode::RESOURCE_NOT_FOUND);
        $this->assertSame(40401, ErrorCode::USER_NOT_FOUND);
        $this->assertSame(40402, ErrorCode::ORDER_NOT_FOUND);
        $this->assertSame(40403, ErrorCode::PRODUCT_NOT_FOUND);
    }

    public function testConflictRange(): void
    {
        $this->assertSame(40900, ErrorCode::CONFLICT);
        $this->assertSame(40901, ErrorCode::DUPLICATE_OPERATION);
        $this->assertSame(40902, ErrorCode::ORDER_STATUS_CONFLICT);
        $this->assertSame(40903, ErrorCode::ALREADY_PAID);
    }

    public function testValidationFailedRange(): void
    {
        $this->assertSame(42200, ErrorCode::VALIDATION_FAILED);
        $this->assertSame(42201, ErrorCode::INSUFFICIENT_BALANCE);
        $this->assertSame(42202, ErrorCode::INSUFFICIENT_FROZEN);
        $this->assertSame(42203, ErrorCode::AMOUNT_TOO_SMALL);
        $this->assertSame(42204, ErrorCode::AMOUNT_TOO_LARGE);
        $this->assertSame(42205, ErrorCode::INVALID_AMOUNT);
    }

    public function testRateLimited(): void
    {
        $this->assertSame(42900, ErrorCode::RATE_LIMITED);
    }

    public function testSystemErrorRange(): void
    {
        $this->assertSame(50000, ErrorCode::SYSTEM_ERROR);
        $this->assertSame(50001, ErrorCode::INTERNAL_ERROR);
        $this->assertSame(50002, ErrorCode::CONFIG_ERROR);
    }

    public function testThirdPartyErrorRange(): void
    {
        $this->assertSame(50200, ErrorCode::THIRD_PARTY_ERROR);
        $this->assertSame(50201, ErrorCode::PAYMENT_GATEWAY_ERROR);
        $this->assertSame(50202, ErrorCode::SMS_GATEWAY_ERROR);
        $this->assertSame(50203, ErrorCode::STORAGE_ERROR);
    }

    public function testServiceUnavailableRange(): void
    {
        $this->assertSame(50300, ErrorCode::SERVICE_UNAVAILABLE);
        $this->assertSame(50301, ErrorCode::DATABASE_ERROR);
        $this->assertSame(50302, ErrorCode::CACHE_ERROR);
    }

    public function testToHttpStatusMapping(): void
    {
        $this->assertSame(200, ErrorCode::toHttpStatus(ErrorCode::SUCCESS));
        $this->assertSame(400, ErrorCode::toHttpStatus(ErrorCode::BAD_REQUEST));
        $this->assertSame(400, ErrorCode::toHttpStatus(ErrorCode::INVALID_PARAMETER));
        $this->assertSame(401, ErrorCode::toHttpStatus(ErrorCode::UNAUTHENTICATED));
        $this->assertSame(401, ErrorCode::toHttpStatus(ErrorCode::TOKEN_EXPIRED));
        $this->assertSame(403, ErrorCode::toHttpStatus(ErrorCode::FORBIDDEN));
        $this->assertSame(403, ErrorCode::toHttpStatus(ErrorCode::IP_NOT_ALLOWED));
        $this->assertSame(404, ErrorCode::toHttpStatus(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(404, ErrorCode::toHttpStatus(ErrorCode::ORDER_NOT_FOUND));
        $this->assertSame(409, ErrorCode::toHttpStatus(ErrorCode::CONFLICT));
        $this->assertSame(409, ErrorCode::toHttpStatus(ErrorCode::DUPLICATE_OPERATION));
        $this->assertSame(422, ErrorCode::toHttpStatus(ErrorCode::VALIDATION_FAILED));
        $this->assertSame(422, ErrorCode::toHttpStatus(ErrorCode::INSUFFICIENT_BALANCE));
        $this->assertSame(429, ErrorCode::toHttpStatus(ErrorCode::RATE_LIMITED));
        $this->assertSame(500, ErrorCode::toHttpStatus(ErrorCode::SYSTEM_ERROR));
        $this->assertSame(502, ErrorCode::toHttpStatus(ErrorCode::PAYMENT_GATEWAY_ERROR));
        $this->assertSame(503, ErrorCode::toHttpStatus(ErrorCode::DATABASE_ERROR));
    }

    public function testToHttpStatusDefaultForUnknown(): void
    {
        $this->assertSame(500, ErrorCode::toHttpStatus(99999));
        $this->assertSame(500, ErrorCode::toHttpStatus(0));
    }

    public function testErrorCodesDoNotConflictWithSuccess200(): void
    {
        // 所有错误码都不应等于 200（成功码）
        $reflection = new \ReflectionClass(ErrorCode::class);
        $constants = $reflection->getConstants();
        foreach ($constants as $name => $value) {
            if ($name !== 'SUCCESS') {
                $this->assertNotSame(200, $value, "错误码 {$name} 不应等于成功码 200");
            }
        }
    }
}
