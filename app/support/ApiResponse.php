<?php
declare(strict_types=1);

namespace app\support;

use think\Response;
use think\response\Json;

/**
 * 统一 API Response 工具
 *
 * 输出格式与现有 show() 函数保持完全一致：
 * {
 *   "code": 200,
 *   "status": "success",
 *   "message": "...",
 *   "data": {...},
 *   "success": true
 * }
 *
 * 与现有系统兼容性：
 * - 成功：code=200, status="success", success=true（与现有 show(200, 'success', ...) 一致）
 * - 失败：code=错误码, status="error", success=false（与现有 show(400, 'error', ...) 一致）
 * - 分页：data 包含 items 和 pagination（未来标准，现有分页暂不迁移）
 *
 * 使用场景：
 * - 新 Controller / 新 API 直接使用本工具
 * - 旧 Controller 继续使用 show()，不强制迁移
 * - ExceptionHandle 对 BusinessException 使用本工具输出
 *
 * 注意：本工具不修改任何现有 API 行为，仅提供新的标准输出方式。
 */
class ApiResponse
{
    /**
     * 成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 响应消息
     * @param int $code 业务码（成功固定为 200，与现有系统兼容）
     * @param int $httpStatus HTTP 状态码
     */
    public static function success(
        mixed $data = null,
        string $message = 'success',
        int $code = ErrorCode::SUCCESS,
        int $httpStatus = 200
    ): Json {
        return json([
            'code' => $code,
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'success' => true,
        ], $httpStatus);
    }

    /**
     * 失败响应
     *
     * @param string $message 错误消息
     * @param int $code 业务错误码（参考 ErrorCode）
     * @param mixed $data 附加数据
     * @param int|null $httpStatus HTTP 状态码（null 时自动从错误码推导）
     */
    public static function error(
        string $message = '操作失败',
        int $code = ErrorCode::BAD_REQUEST,
        mixed $data = null,
        ?int $httpStatus = null
    ): Json {
        $http = $httpStatus ?? ErrorCode::toHttpStatus($code);
        return json([
            'code' => $code,
            'status' => 'error',
            'message' => $message,
            'data' => $data,
            'success' => false,
        ], $http);
    }

    /**
     * 分页响应（未来标准）
     *
     * 输出格式：
     * {
     *   "code": 200,
     *   "status": "success",
     *   "message": "success",
     *   "data": {
     *     "items": [...],
     *     "pagination": {
     *       "page": 1,
     *       "page_size": 20,
     *       "total": 100
     *     }
     *   },
     *   "success": true
     * }
     *
     * 注意：现有 DataTables 分页格式不在此统一，Vue3 Admin 迁移时再逐步替换。
     */
    public static function paginate(
        array $items,
        int $page,
        int $pageSize,
        int $total,
        string $message = 'success'
    ): Json {
        return self::success([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => (int)ceil($total / max(1, $pageSize)),
            ],
        ], $message);
    }

    /**
     * 从 BusinessException 构建错误响应
     */
    public static function fromException(\app\contracts\BusinessExceptionInterface $e): Json
    {
        return self::error(
            $e->getMessage() ?: '操作失败',
            $e->getErrorCode(),
            null,
            $e->getHttpStatus()
        );
    }

    /**
     * 构建原始数组（不返回 Json 对象，用于需要数组的场景）
     */
    public static function toArray(
        bool $success,
        string $message,
        int $code,
        mixed $data = null
    ): array {
        return [
            'code' => $code,
            'status' => $success ? 'success' : 'error',
            'message' => $message,
            'data' => $data,
            'success' => $success,
        ];
    }
}
