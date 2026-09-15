<?php
declare(strict_types=1);

namespace app\exception;

use app\contracts\BusinessExceptionInterface;
use app\support\ErrorCode;
use RuntimeException;

/**
 * 基础业务异常
 *
 * 所有业务异常的基类。用于在 Service / Domain 层抛出可预期的业务错误，
 * 由 ExceptionHandle 统一转换为 API Response。
 *
 * 与现有系统的兼容性：
 * - 现有 Controller 使用 show(400, 'error', 'message') 返回错误，行为不变
 * - 新代码可 throw new BusinessException(...)，由 Handler 统一转换为相同格式
 * - 错误码使用 4xxxxx / 5xxxxx，不与现有成功码 200 冲突
 *
 * 示例：
 *   throw new BusinessException('余额不足', ErrorCode::INSUFFICIENT_BALANCE);
 *   throw new BusinessException('订单不存在', ErrorCode::RESOURCE_NOT_FOUND, 404);
 */
class BusinessException extends RuntimeException implements BusinessExceptionInterface
{
    protected int $errorCode;
    protected int $httpStatus;
    protected ?array $context;
    protected bool $securityEvent;

    /**
     * @param string $message 面向用户的错误信息
     * @param int $errorCode 业务错误码（参考 ErrorCode 常量）
     * @param int $httpStatus HTTP 状态码
     * @param array|null $context 附加上下文数据（不会暴露给客户端，仅用于日志）
     * @param bool $securityEvent 是否为安全事件（影响日志分类）
     */
    public function __construct(
        string $message = '业务处理失败',
        int $errorCode = ErrorCode::BUSINESS_ERROR,
        int $httpStatus = 400,
        ?array $context = null,
        bool $securityEvent = false
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->context = $context;
        $this->securityEvent = $securityEvent;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function isSecurityEvent(): bool
    {
        return $this->securityEvent;
    }

    /**
     * 便捷构造：带上下文数据
     */
    public static function withContext(
        string $message,
        int $errorCode,
        array $context,
        int $httpStatus = 400
    ): self {
        return new self($message, $errorCode, $httpStatus, $context);
    }
}
