<?php
declare(strict_types=1);

namespace app\exception;

use app\support\ErrorCode;

/**
 * 授权异常
 *
 * 用于已登录但无权限访问资源、越权操作等场景。
 * HTTP 状态码：403
 * 安全事件：是（记录安全日志，可能是 IDOR 攻击）
 */
class AuthorizationException extends BusinessException
{
    public function __construct(
        string $message = '无权限执行此操作',
        int $errorCode = ErrorCode::FORBIDDEN,
        ?array $context = null
    ) {
        parent::__construct($message, $errorCode, 403, $context, true);
    }
}
