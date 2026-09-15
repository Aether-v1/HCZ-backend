<?php
declare(strict_types=1);

namespace app\contracts;

/**
 * 业务异常接口
 *
 * 所有自定义业务异常必须实现此接口，
 * 以便 ExceptionHandle 统一识别和处理。
 *
 * 设计原则：
 * - code: 业务错误码（4xxxxx / 5xxxxx），区别于 HTTP 状态码
 * - message: 面向用户的错误信息
 * - httpStatus: 对应的 HTTP 状态码
 * - data: 附加上下文数据（可选）
 */
interface BusinessExceptionInterface
{
    /**
     * 获取业务错误码
     */
    public function getErrorCode(): int;

    /**
     * 获取 HTTP 状态码
     */
    public function getHttpStatus(): int;

    /**
     * 获取附加数据
     */
    public function getContext(): ?array;

    /**
     * 是否应记录为安全日志（如认证失败、越权等）
     */
    public function isSecurityEvent(): bool;
}
