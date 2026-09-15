<?php
declare(strict_types=1);

namespace app\exception;

use app\support\ErrorCode;

/**
 * 资源不存在异常
 *
 * 用于查询的用户、订单、商品等资源不存在。
 * HTTP 状态码：404
 */
class ResourceNotFoundException extends BusinessException
{
    public function __construct(
        string $message = '资源不存在',
        int $errorCode = ErrorCode::RESOURCE_NOT_FOUND,
        ?array $context = null
    ) {
        parent::__construct($message, $errorCode, 404, $context);
    }

    /**
     * 便捷构造：指定资源类型
     */
    public static function of(string $resourceType, $id = null): self
    {
        $message = $id !== null
            ? sprintf('%s不存在: %s', $resourceType, (string)$id)
            : sprintf('%s不存在', $resourceType);
        return new self($message, ErrorCode::RESOURCE_NOT_FOUND, ['resource_type' => $resourceType, 'id' => $id]);
    }
}
