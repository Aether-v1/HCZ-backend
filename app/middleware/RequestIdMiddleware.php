<?php
declare(strict_types=1);

namespace app\middleware;

use app\support\RequestId;
use Closure;
use think\Request;
use think\Response;

/**
 * Request ID 中间件
 *
 * 为每个请求生成/传递唯一 ID，贯穿整个调用链。
 *
 * 行为：
 * 1. 优先读取请求头 X-Request-ID（客户端传入，便于前端追踪）
 * 2. 未传入时自动生成
 * 3. 存入 RequestId 工具，供日志和业务代码使用
 * 4. 响应头添加 X-Request-ID
 *
 * 兼容性：
 * - 不修改请求体、响应体
 * - 不修改任何业务逻辑
 * - 仅添加请求/响应头和日志上下文
 * - 可选择性注册到路由，不影响未注册的路由
 */
class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 优先使用客户端传入的 X-Request-ID（R1.1: 必须通过长度/字符校验，非法则重新生成）
        $clientRequestId = trim((string)$request->header('X-Request-ID', ''));
        $sanitized = $clientRequestId !== '' ? RequestId::sanitize($clientRequestId) : null;
        if ($sanitized !== null) {
            RequestId::set($sanitized);
        } else {
            RequestId::current(); // 未传/非法 → 自动生成
        }

        /** @var Response $response */
        $response = $next($request);

        // 响应头添加 X-Request-ID
        return $response->header(['X-Request-ID' => RequestId::current()]);
    }
}
