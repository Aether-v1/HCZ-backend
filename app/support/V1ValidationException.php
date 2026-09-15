<?php
declare(strict_types=1);

namespace app\support;

use RuntimeException;

/**
 * V1 输入校验失败异常。
 *
 * 仅承载结构化错误详情（field => messages[]），不渲染 HTTP。
 * HTTP 映射（422 VALIDATION_FAILED + V1ApiResponse envelope）由 R1.4 ExceptionHandle 负责。
 */
class V1ValidationException extends RuntimeException
{
    /** @var array<string, array<int, string>> field => messages */
    public readonly array $details;

    /**
     * @param array<string, array<int, string>|string> $details 字段错误；string 值会被包成数组
     */
    public function __construct(array $details, string $message = 'Request validation failed', int $code = 42200)
    {
        $normalized = [];
        foreach ($details as $field => $msgs) {
            if (is_array($msgs)) {
                $normalized[$field] = array_values(array_map('strval', $msgs));
            } else {
                $normalized[$field] = [(string) $msgs];
            }
        }
        $this->details = $normalized;
        parent::__construct($message, $code);
    }
}
