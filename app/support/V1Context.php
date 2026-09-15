<?php
declare(strict_types=1);

namespace app\support;

use think\Request;

/**
 * V1 API 请求边界检测
 *
 * R1.4: 统一 /api/v1 路径识别，供 ExceptionHandle / ApiResponseFormat / 未来 middleware 共用。
 *
 * 强制精确边界：
 *   api/v1           = TRUE
 *   api/v1/orders    = TRUE
 *   api/v10          = FALSE
 *   api/v10/orders   = FALSE
 *   api/v1foo        = FALSE
 *   api/orders       = FALSE
 *   api/callback/... = FALSE
 *   epay_notify_url  = FALSE
 *
 * 禁止使用无 boundary 的 str_starts_with($path, 'api/v1')。
 */
final class V1Context
{
    /**
     * 判断当前请求是否属于 /api/v1 命名空间
     */
    public static function isV1Request(Request $request): bool
    {
        $path = $request->pathinfo();
        return self::isV1Path($path);
    }

    /**
     * 纯路径检测（便于测试，不依赖 Request 对象）
     */
    public static function isV1Path(string $path): bool
    {
        return $path === 'api/v1'
            || str_starts_with($path, 'api/v1/');
    }
}
