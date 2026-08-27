<?php
declare (strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * CORS 跨域中间件
 *
 * 处理前后端分离部署下的跨域请求：
 * - 前端域名 hcz.app，后端域名 ops.hcz.app
 * - 前端 Axios 使用 withCredentials=true，必须回显具体 Origin，不能用 *
 * - OPTIONS 预检请求直接返回 204 并携带完整 CORS 头
 */
class CorsMiddleware
{
    /** 允许的前端 Origin 白名单 */
    protected array $allowedOrigins = [
        'https://hcz.app',
        'https://www.hcz.app',
    ];

    /** 允许的 HTTP 方法 */
    protected string $allowMethods = 'GET, POST, PUT, DELETE, OPTIONS';

    /** 允许的请求头（必须包含前端实际发送的 X-CSRF-Token、Cache-Control、Pragma） */
    protected string $allowHeaders = 'Content-Type, X-Requested-With, X-CSRF-Token, Cache-Control, Pragma';

    /** 允许携带凭证 */
    protected string $allowCredentials = 'true';

    /** 预检结果缓存时间（秒） */
    protected string $maxAge = '86400';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = trim((string) $request->header('Origin', ''));

        // OPTIONS 预检请求：直接返回 204，不进入后续业务/CSRF/Auth 中间件
        if (strtoupper($request->method()) === 'OPTIONS') {
            $response = Response::create('', 'html', 204);
            return $this->attachCorsHeaders($response, $origin);
        }

        // 普通请求：先执行后续中间件和业务逻辑，再给响应附加 CORS 头
        $response = $next($request);
        return $this->attachCorsHeaders($response, $origin);
    }

    protected function attachCorsHeaders(Response $response, string $origin): Response
    {
        if (!$this->isAllowedOrigin($origin)) {
            return $response;
        }

        $response->header([
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Credentials' => $this->allowCredentials,
            'Access-Control-Allow-Methods'     => $this->allowMethods,
            'Access-Control-Allow-Headers'     => $this->allowHeaders,
            'Access-Control-Max-Age'           => $this->maxAge,
        ]);

        return $response;
    }

    protected function isAllowedOrigin(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }
}
