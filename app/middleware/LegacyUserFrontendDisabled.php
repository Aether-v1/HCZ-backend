<?php
declare (strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class LegacyUserFrontendDisabled
{
    private const LEGACY_CONTROLLERS = ['index', 'indexapi', 'indexlist'];

    private const ALLOWED_PATHS = [
        'api',
        'epay_notify_url',
        'robot/webhook',
    ];

    private const ALLOWED_PREFIXES = [
        'api/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $controller = strtolower((string)$request->controller());
        if (!in_array($controller, self::LEGACY_CONTROLLERS, true)) {
            return $next($request);
        }

        $path = strtolower(trim((string)$request->pathinfo(), '/'));
        if (in_array($path, self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $message = '旧版用户前端已停用';
        if ($request->isAjax() || $request->isJson() || $request->isPost()) {
            return Response::create([
                'code' => 503,
                'status' => 'error',
                'msg' => $message,
                'message' => $message,
            ], 'json', 503);
        }

        return Response::create('<h1 style="font-family: sans-serif; text-align: center; margin-top: 20vh;">旧版用户前端已停用</h1>', 'html', 503);
    }
}