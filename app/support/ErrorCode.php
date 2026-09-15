<?php
declare(strict_types=1);

namespace app\support;

/**
 * 统一错误码定义
 *
 * 编码规则：
 * - 200: 成功（与现有系统兼容，管理后台严格依赖 code === 200）
 * - 4xxxxx: 客户端错误（参数、认证、授权、资源、业务冲突等）
 * - 5xxxxx: 服务端错误（系统、数据库、第三方等）
 *
 * 与现有系统兼容性：
 * - 现有成功响应使用 code=200，保持不变
 * - 现有错误响应使用 HTTP code（400/401/403/404/422/500），新体系使用 4xxxxx/5xxxxx
 * - ApiResponse::error() 默认输出 code=40000，与现有 show(400, 'error', ...) 行为一致
 * - ExceptionHandle 对新异常类型输出对应的业务错误码，对旧异常保持现有行为
 *
 * 注意：本类定义的是"未来标准"错误码。现有 API 不强制迁移，
 * 逐模块重构时再逐步采用。
 */
class ErrorCode
{
    // ===== 成功 =====
    public const SUCCESS = 200;

    // ===== 客户端错误 4xxxxx =====

    // 400xxx: 通用请求错误
    public const BAD_REQUEST = 40000;
    public const BUSINESS_ERROR = 40000;
    public const INVALID_PARAMETER = 40001;
    public const MISSING_PARAMETER = 40002;
    public const INVALID_FORMAT = 40003;

    // 401xxx: 认证错误
    public const UNAUTHENTICATED = 40100;
    public const TOKEN_INVALID = 40101;
    public const TOKEN_EXPIRED = 40102;
    public const LOGIN_REQUIRED = 40103;

    // 403xxx: 授权错误
    public const FORBIDDEN = 40300;
    public const PERMISSION_DENIED = 40301;
    public const ADMIN_REQUIRED = 40302;
    public const IP_NOT_ALLOWED = 40303;

    // 404xxx: 资源不存在
    public const RESOURCE_NOT_FOUND = 40400;
    public const USER_NOT_FOUND = 40401;
    public const ORDER_NOT_FOUND = 40402;
    public const PRODUCT_NOT_FOUND = 40403;

    // 405xxx: 方法不允许（R1.4 新增，用于 /api/v1 405）
    public const METHOD_NOT_ALLOWED = 40500;

    // 409xxx: 业务冲突
    public const CONFLICT = 40900;
    public const DUPLICATE_OPERATION = 40901;
    public const ORDER_STATUS_CONFLICT = 40902;
    public const ALREADY_PAID = 40903;
    // R1.1 Foundation: 通用状态冲突 / HTTP 幂等冲突（R1.3 落地时使用）
    public const INVALID_STATE = 40904;
    public const IDEMPOTENCY_CONFLICT = 40905;
    public const IDEMPOTENCY_IN_PROGRESS = 40906;

    // 422xxx: 业务验证失败
    public const VALIDATION_FAILED = 42200;
    public const INSUFFICIENT_BALANCE = 42201;
    public const INSUFFICIENT_FROZEN = 42202;
    public const AMOUNT_TOO_SMALL = 42203;
    public const AMOUNT_TOO_LARGE = 42204;
    public const INVALID_AMOUNT = 42205;

    // 429xxx: 限流（R1.4 复用 RATE_LIMITED，不新增 TOO_MANY_REQUESTS）
    public const RATE_LIMITED = 42900;

    // ===== 服务端错误 5xxxxx =====

    // 500xxx: 系统错误
    public const SYSTEM_ERROR = 50000;
    public const INTERNAL_ERROR = 50001;
    public const CONFIG_ERROR = 50002;

    // 502xxx: 第三方服务错误
    public const THIRD_PARTY_ERROR = 50200;
    public const PAYMENT_GATEWAY_ERROR = 50201;
    public const SMS_GATEWAY_ERROR = 50202;
    public const STORAGE_ERROR = 50203;

    // 503xxx: 服务不可用
    public const SERVICE_UNAVAILABLE = 50300;
    public const DATABASE_ERROR = 50301;
    public const CACHE_ERROR = 50302;

    /**
     * 错误码对应的默认 HTTP 状态码映射
     */
    public static function toHttpStatus(int $errorCode): int
    {
        return match (true) {
            $errorCode === self::SUCCESS => 200,
            $errorCode >= 40000 && $errorCode < 40100 => 400,
            $errorCode >= 40100 && $errorCode < 40200 => 401,
            $errorCode >= 40300 && $errorCode < 40400 => 403,
            $errorCode >= 40400 && $errorCode < 40500 => 404,
            $errorCode >= 40500 && $errorCode < 40600 => 405,
            $errorCode >= 40900 && $errorCode < 41000 => 409,
            $errorCode >= 42200 && $errorCode < 42300 => 422,
            $errorCode >= 42900 && $errorCode < 43000 => 429,
            $errorCode >= 50000 && $errorCode < 50100 => 500,
            $errorCode >= 50200 && $errorCode < 50300 => 502,
            $errorCode >= 50300 && $errorCode < 50400 => 503,
            default => 500,
        };
    }

    /**
     * R1.1: 内部数字错误码 → 对外稳定机器可读 public code（字符串）
     *
     * 契约：/api/v1/* 对外 error.code 必须使用本方法输出的稳定字符串码。
     * 前端不得依赖中文 message、数字码或 HTTP status 作为唯一判断。
     *
     * 未显式映射的数字码按 HTTP 分类降级到类别码，保证任意数字码都有稳定 public code。
     */
    public static function toPublicCode(int $errorCode): string
    {
        return match ($errorCode) {
            self::SUCCESS => 'SUCCESS',
            self::BAD_REQUEST, self::BUSINESS_ERROR => 'BAD_REQUEST',
            self::INVALID_PARAMETER => 'INVALID_PARAMETER',
            self::MISSING_PARAMETER => 'MISSING_PARAMETER',
            self::INVALID_FORMAT => 'INVALID_FORMAT',
            self::UNAUTHENTICATED, self::LOGIN_REQUIRED => 'AUTH_UNAUTHENTICATED',
            self::TOKEN_INVALID => 'AUTH_TOKEN_INVALID',
            self::TOKEN_EXPIRED => 'AUTH_TOKEN_EXPIRED',
            self::FORBIDDEN, self::ADMIN_REQUIRED => 'AUTH_FORBIDDEN',
            self::PERMISSION_DENIED => 'AUTH_FORBIDDEN',
            self::IP_NOT_ALLOWED => 'AUTH_FORBIDDEN',
            self::RESOURCE_NOT_FOUND => 'RESOURCE_NOT_FOUND',
            self::USER_NOT_FOUND => 'RESOURCE_NOT_FOUND',
            self::ORDER_NOT_FOUND => 'RESOURCE_NOT_FOUND',
            self::PRODUCT_NOT_FOUND => 'RESOURCE_NOT_FOUND',
            self::METHOD_NOT_ALLOWED => 'METHOD_NOT_ALLOWED',
            self::CONFLICT => 'CONFLICT',
            self::DUPLICATE_OPERATION => 'CONFLICT',
            self::ORDER_STATUS_CONFLICT => 'INVALID_STATE',
            self::ALREADY_PAID => 'CONFLICT',
            self::INVALID_STATE => 'INVALID_STATE',
            self::IDEMPOTENCY_CONFLICT => 'IDEMPOTENCY_CONFLICT',
            self::IDEMPOTENCY_IN_PROGRESS => 'IDEMPOTENCY_IN_PROGRESS',
            self::VALIDATION_FAILED => 'VALIDATION_FAILED',
            self::INSUFFICIENT_BALANCE => 'INSUFFICIENT_BALANCE',
            self::INSUFFICIENT_FROZEN => 'INSUFFICIENT_FROZEN',
            self::AMOUNT_TOO_SMALL => 'INVALID_AMOUNT',
            self::AMOUNT_TOO_LARGE => 'INVALID_AMOUNT',
            self::INVALID_AMOUNT => 'INVALID_AMOUNT',
            self::RATE_LIMITED => 'RATE_LIMITED',
            self::SYSTEM_ERROR, self::INTERNAL_ERROR, self::CONFIG_ERROR => 'INTERNAL_ERROR',
            self::THIRD_PARTY_ERROR, self::PAYMENT_GATEWAY_ERROR, self::SMS_GATEWAY_ERROR, self::STORAGE_ERROR => 'THIRD_PARTY_ERROR',
            self::SERVICE_UNAVAILABLE, self::DATABASE_ERROR, self::CACHE_ERROR => 'SERVICE_UNAVAILABLE',
            default => self::httpCategoryCode($errorCode),
        };
    }

    /**
     * R1.1: 按 HTTP 分类给出兜底 public code（未显式映射的数字码）
     */
    private static function httpCategoryCode(int $errorCode): string
    {
        return match (true) {
            $errorCode >= 40000 && $errorCode < 40100 => 'BAD_REQUEST',
            $errorCode >= 40100 && $errorCode < 40200 => 'AUTH_UNAUTHENTICATED',
            $errorCode >= 40300 && $errorCode < 40400 => 'AUTH_FORBIDDEN',
            $errorCode >= 40400 && $errorCode < 40500 => 'RESOURCE_NOT_FOUND',
            $errorCode >= 40500 && $errorCode < 40600 => 'METHOD_NOT_ALLOWED',
            $errorCode >= 40900 && $errorCode < 41000 => 'CONFLICT',
            $errorCode >= 42200 && $errorCode < 42300 => 'VALIDATION_FAILED',
            $errorCode >= 42900 && $errorCode < 43000 => 'RATE_LIMITED',
            default => 'INTERNAL_ERROR',
        };
    }

    /**
     * R1.1: public code → 内部数字错误码（未知 public code 回落 INTERNAL_ERROR）
     */
    public static function numericOf(string $publicCode): int
    {
        $map = [
            'SUCCESS' => self::SUCCESS,
            'BAD_REQUEST' => self::BAD_REQUEST,
            'INVALID_PARAMETER' => self::INVALID_PARAMETER,
            'MISSING_PARAMETER' => self::MISSING_PARAMETER,
            'INVALID_FORMAT' => self::INVALID_FORMAT,
            'AUTH_UNAUTHENTICATED' => self::UNAUTHENTICATED,
            'AUTH_TOKEN_INVALID' => self::TOKEN_INVALID,
            'AUTH_TOKEN_EXPIRED' => self::TOKEN_EXPIRED,
            'AUTH_FORBIDDEN' => self::FORBIDDEN,
            'RESOURCE_NOT_FOUND' => self::RESOURCE_NOT_FOUND,
            'METHOD_NOT_ALLOWED' => self::METHOD_NOT_ALLOWED,
            'CONFLICT' => self::CONFLICT,
            'INVALID_STATE' => self::INVALID_STATE,
            'IDEMPOTENCY_CONFLICT' => self::IDEMPOTENCY_CONFLICT,
            'IDEMPOTENCY_IN_PROGRESS' => self::IDEMPOTENCY_IN_PROGRESS,
            'VALIDATION_FAILED' => self::VALIDATION_FAILED,
            'INSUFFICIENT_BALANCE' => self::INSUFFICIENT_BALANCE,
            'INSUFFICIENT_FROZEN' => self::INSUFFICIENT_FROZEN,
            'INVALID_AMOUNT' => self::INVALID_AMOUNT,
            'RATE_LIMITED' => self::RATE_LIMITED,
            'THIRD_PARTY_ERROR' => self::THIRD_PARTY_ERROR,
            'SERVICE_UNAVAILABLE' => self::SERVICE_UNAVAILABLE,
            'INTERNAL_ERROR' => self::INTERNAL_ERROR,
        ];

        return $map[$publicCode] ?? self::INTERNAL_ERROR;
    }

    /**
     * R1.1: 枚举全部 public code（用于契约测试唯一性校验）
     *
     * @return array<string>
     */
    public static function publicCodes(): array
    {
        return [
            'SUCCESS',
            'BAD_REQUEST',
            'INVALID_PARAMETER',
            'MISSING_PARAMETER',
            'INVALID_FORMAT',
            'AUTH_UNAUTHENTICATED',
            'AUTH_TOKEN_INVALID',
            'AUTH_TOKEN_EXPIRED',
            'AUTH_FORBIDDEN',
            'RESOURCE_NOT_FOUND',
            'METHOD_NOT_ALLOWED',
            'CONFLICT',
            'INVALID_STATE',
            'IDEMPOTENCY_CONFLICT',
            'IDEMPOTENCY_IN_PROGRESS',
            'VALIDATION_FAILED',
            'INSUFFICIENT_BALANCE',
            'INSUFFICIENT_FROZEN',
            'INVALID_AMOUNT',
            'RATE_LIMITED',
            'THIRD_PARTY_ERROR',
            'SERVICE_UNAVAILABLE',
            'INTERNAL_ERROR',
        ];
    }
}
