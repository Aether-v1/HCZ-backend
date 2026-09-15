<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * R1.1 — ErrorCode V1 Contract Tests
 *
 * 覆盖：
 * - Foundation 必需 public code 集合唯一、非空
 * - numeric → public code 稳定（同值别名收敛到同一 machine code）
 * - public code → numeric 反查的 HTTP status 与原始 numeric 推导一致（不变式）
 * - 关键 HTTP status 映射（401/403/404/409/422/429/500）
 * - 新 Foundation 码 INVALID_STATE / IDEMPOTENCY_CONFLICT 存在且归 409
 */
class ErrorCodeV1ContractTest extends TestCase
{
    public function testFoundationPublicCodesAreUnique(): void
    {
        $codes = ErrorCode::publicCodes();
        $this->assertGreaterThanOrEqual(20, count($codes));
        $this->assertSame(count($codes), count(array_unique($codes)), 'public codes must be unique');
    }

    public function testRequiredFoundationCategoriesExist(): void
    {
        $required = [
            'BAD_REQUEST',
            'VALIDATION_FAILED',
            'AUTH_UNAUTHENTICATED',
            'AUTH_FORBIDDEN',
            'RESOURCE_NOT_FOUND',
            'CONFLICT',
            'INVALID_STATE',
            'IDEMPOTENCY_CONFLICT',
            'RATE_LIMITED',
            'INTERNAL_ERROR',
        ];
        $codes = ErrorCode::publicCodes();
        foreach ($required as $code) {
            $this->assertContains($code, $codes, "missing required public code: {$code}");
        }
    }

    public function testNewFoundationConstantsExist(): void
    {
        $this->assertSame(40904, ErrorCode::INVALID_STATE);
        $this->assertSame(40905, ErrorCode::IDEMPOTENCY_CONFLICT);
    }

    public function testKeyHttpStatusMapping(): void
    {
        $this->assertSame(401, ErrorCode::toHttpStatus(ErrorCode::UNAUTHENTICATED));
        $this->assertSame(403, ErrorCode::toHttpStatus(ErrorCode::FORBIDDEN));
        $this->assertSame(404, ErrorCode::toHttpStatus(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame(409, ErrorCode::toHttpStatus(ErrorCode::CONFLICT));
        $this->assertSame(409, ErrorCode::toHttpStatus(ErrorCode::INVALID_STATE));
        $this->assertSame(409, ErrorCode::toHttpStatus(ErrorCode::IDEMPOTENCY_CONFLICT));
        $this->assertSame(422, ErrorCode::toHttpStatus(ErrorCode::VALIDATION_FAILED));
        $this->assertSame(429, ErrorCode::toHttpStatus(ErrorCode::RATE_LIMITED));
        $this->assertSame(500, ErrorCode::toHttpStatus(ErrorCode::INTERNAL_ERROR));
    }

    public function testPublicCodeStableMapping(): void
    {
        $this->assertSame('AUTH_UNAUTHENTICATED', ErrorCode::toPublicCode(ErrorCode::UNAUTHENTICATED));
        $this->assertSame('AUTH_FORBIDDEN', ErrorCode::toPublicCode(ErrorCode::FORBIDDEN));
        $this->assertSame('RESOURCE_NOT_FOUND', ErrorCode::toPublicCode(ErrorCode::RESOURCE_NOT_FOUND));
        $this->assertSame('VALIDATION_FAILED', ErrorCode::toPublicCode(ErrorCode::VALIDATION_FAILED));
        $this->assertSame('IDEMPOTENCY_CONFLICT', ErrorCode::toPublicCode(ErrorCode::IDEMPOTENCY_CONFLICT));
        $this->assertSame('RATE_LIMITED', ErrorCode::toPublicCode(ErrorCode::RATE_LIMITED));
        $this->assertSame('INTERNAL_ERROR', ErrorCode::toPublicCode(ErrorCode::INTERNAL_ERROR));
    }

    public function testAliasNumericCodesConvergeToSamePublicCode(): void
    {
        $this->assertSame(
            ErrorCode::toPublicCode(ErrorCode::BAD_REQUEST),
            ErrorCode::toPublicCode(ErrorCode::BUSINESS_ERROR)
        );
        $this->assertSame(
            ErrorCode::toPublicCode(ErrorCode::PERMISSION_DENIED),
            ErrorCode::toPublicCode(ErrorCode::FORBIDDEN)
        );
        $this->assertSame(
            ErrorCode::toPublicCode(ErrorCode::SYSTEM_ERROR),
            ErrorCode::toPublicCode(ErrorCode::INTERNAL_ERROR)
        );
    }

    public function testHttpStatusInvariantForAllExplicitPublicCodes(): void
    {
        // 对每个 public code：numericOf(toPublicCode(x)) 的 HTTP 分类必须与 x 的推导一致
        foreach (ErrorCode::publicCodes() as $publicCode) {
            if ($publicCode === 'SUCCESS') {
                continue;
            }
            $numeric = ErrorCode::numericOf($publicCode);
            $this->assertNotSame(ErrorCode::SUCCESS, $numeric, "{$publicCode} must not map to success");
            $this->assertSame(ErrorCode::toPublicCode($numeric), $publicCode, "public code round-trip failed for {$publicCode}");
        }
    }

    public function testNumericCodesInKnownRangeGetHttpCategoryFallback(): void
    {
        // 未显式映射的 4xxxx/5xxxx 数字码仍能得到稳定的类别 public code
        $unknown = 42299; // 未显式映射的 422 区间
        $this->assertSame('VALIDATION_FAILED', ErrorCode::toPublicCode($unknown));
        $this->assertSame(422, ErrorCode::toHttpStatus($unknown));

        $unknownAuth = 40199;
        $this->assertSame('AUTH_UNAUTHENTICATED', ErrorCode::toPublicCode($unknownAuth));
    }

    public function testUnknownPublicCodeFallsBackToInternalError(): void
    {
        $this->assertSame(ErrorCode::INTERNAL_ERROR, ErrorCode::numericOf('NOT_A_REAL_CODE'));
    }
}
