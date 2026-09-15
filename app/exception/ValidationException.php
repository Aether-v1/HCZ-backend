<?php
declare(strict_types=1);

namespace app\exception;

use app\support\ErrorCode;

/**
 * 参数验证异常
 *
 * 用于请求参数格式、范围、必填项等校验失败。
 * HTTP 状态码：422
 *
 * 与 ThinkPHP 内置 ValidateException 的区别：
 * - 内置 ValidateException 由框架验证器自动抛出，Handler 已有处理
 * - 本异常用于 Service 层手动校验业务前置条件
 * - 两者最终输出格式一致
 */
class ValidationException extends BusinessException
{
    /**
     * 字段级错误详情（可选）
     * 格式：['field_name' => 'error message']
     */
    protected array $fieldErrors;

    public function __construct(
        string $message = '参数验证失败',
        int $errorCode = ErrorCode::VALIDATION_FAILED,
        array $fieldErrors = [],
        ?array $context = null
    ) {
        parent::__construct($message, $errorCode, 422, $context);
        $this->fieldErrors = $fieldErrors;
    }

    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * 便捷构造：单字段错误
     */
    public static function field(string $field, string $message, int $errorCode = ErrorCode::VALIDATION_FAILED): self
    {
        return new self($message, $errorCode, [$field => $message]);
    }
}
