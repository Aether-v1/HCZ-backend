<?php
declare(strict_types=1);

namespace app\support;

use think\Response;
use think\response\Json;

/**
 * V1 API Response — R1.1 Foundation（未来 /api/v1/* 唯一响应契约）
 *
 * 与 legacy show()/ApiResponse（{code,status,message,data,success}）完全分离，
 * 仅在 /api/v1/* 路由域使用（R1.4 挂载路由时接入，本轮提供工具与契约测试）。
 *
 * 成功：
 *   {"success": true, "data": {}}            （无 meta 时不输出 meta）
 *   {"success": true, "data": {}, "meta": {}}（分页/额外元信息时）
 *
 * 失败：
 *   {"success": false, "error": {"code": "RESOURCE_NOT_FOUND", "message": "..."}}
 *   （可选 details：{"field": ["..."]}；可选 request_id，默认从 RequestId::current() 取）
 *
 * 契约要点：
 * - error.code 必须为稳定机器码（ErrorCode::toPublicCode 输出），禁止依赖中文 message。
 * - HTTP status 由 code 自动推导（ErrorCode::toHttpStatus），也可显式覆盖。
 * - 204 No Content：body 必须为空（noContent()）。
 * - internalError()：对外只输出 INTERNAL_ERROR 通用信息 + request_id，异常明细只进日志。
 * - 本类不包装任何已有 Json Response，不存在二次嵌套。
 */
class V1ApiResponse
{
    /**
     * 成功响应
     *
     * @param mixed $data 响应数据（无内容传 null）
     * @param int $httpStatus 200/201/204 等
     * @param array $meta 额外元信息（分页等），为空时不输出 meta 字段
     */
    public static function success(mixed $data = null, int $httpStatus = 200, array $meta = []): Json
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return json($payload, $httpStatus);
    }

    /**
     * 失败响应
     *
     * @param int|string $code 数字错误码（ErrorCode 常量）或 public code 字符串
     * @param string $message 人类可读信息（机器判断依赖 code，不依赖 message）
     * @param int|null $httpStatus HTTP 状态码；null 时由 code 自动推导
     * @param mixed $details 可选校验/明细信息
     * @param string|null $requestId 请求 ID；null 时默认使用 RequestId::current()
     */
    public static function error(
        int|string $code,
        string $message,
        ?int $httpStatus = null,
        mixed $details = null,
        ?string $requestId = null
    ): Json {
        $numeric = is_int($code) ? $code : ErrorCode::numericOf($code);
        $publicCode = is_string($code) ? $code : ErrorCode::toPublicCode($code);
        $http = $httpStatus ?? ErrorCode::toHttpStatus($numeric);

        $error = ['code' => $publicCode, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        $payload = ['success' => false, 'error' => $error];
        $payload['request_id'] = $requestId ?? RequestId::current();

        return json($payload, $http);
    }

    /**
     * 无内容成功（204）：body 必须为空
     */
    public static function noContent(int $httpStatus = 204): Response
    {
        return Response::create('', 'html', $httpStatus);
    }

    /**
     * 未预期异常：对外只暴露 INTERNAL_ERROR 通用信息 + request_id。
     * 异常明细（message/trace）由调用方负责写入服务器日志，禁止进入响应体。
     */
    public static function internalError(\Throwable $e, ?string $requestId = null): Json
    {
        // 注意：$e 仅用于类型约束与日志侧，响应体不包含 $e->getMessage()。
        unset($e);

        return self::error(ErrorCode::INTERNAL_ERROR, 'Internal server error', 500, null, $requestId);
    }

    /**
     * 输出原始数组（供契约测试断言，不直接用于响应）
     */
    public static function toArray(bool $success, array $errorOrData, ?array $meta = null, ?string $requestId = null): array
    {
        if ($success) {
            $payload = ['success' => true, 'data' => $errorOrData['data'] ?? null];
            if ($meta !== null && $meta !== []) {
                $payload['meta'] = $meta;
            }
            return $payload;
        }

        $error = ['code' => $errorOrData['code'], 'message' => $errorOrData['message']];
        if (isset($errorOrData['details'])) {
            $error['details'] = $errorOrData['details'];
        }
        $payload = ['success' => false, 'error' => $error];
        $payload['request_id'] = $requestId ?? RequestId::current();
        return $payload;
    }
}
