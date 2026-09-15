<?php
declare(strict_types=1);

namespace app\exception;

use app\support\ErrorCode;

/**
 * 认证异常
 *
 * 用于未登录、Token 无效、Token 过期等认证失败场景。
 * HTTP 状态码：401
 * 安全事件：是（记录安全日志）
 */
class AuthenticationException extends BusinessException
{
    public function __construct(
        string $message = '未认证或登录已过期',
        int $errorCode = ErrorCode::UNAUTHENTICATED,
        ?array $context = null
    ) {
        parent::__construct($message, $errorCode, 401, $context, true);
    }
}
