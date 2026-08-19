<?php
declare (strict_types=1);

namespace app\middleware;

use Closure;
use think\facade\Session;
use think\Request;
use think\Response;

class CsrfCheck
{
    protected array $protectedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    protected array $exceptPaths = [
        // 用户端认证入口（未登录状态）
        'api/auth/login',
        'api/auth/twofa/recover',
        'api/auth/register',
        'api/auth/captcha',
        'api/auth/captcha/*',
        'api/captcha',
        'api/captcha/*',
        // 支付网关异步回调（第三方服务器调用，无法携带 CSRF Token）
        'api/callback/*',
        'epay_notify_url',
        // 第三方 Webhook
        'robot/webhook',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->shouldValidate($request)) {
            return $next($request);
        }

        $sessionToken = (string)Session::get($this->tokenName(), '');
        $requestToken = trim((string)$request->header('X-CSRF-Token', ''));

        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            return show(403, 'error', 'CSRF令牌验证失败', null, 403);
        }

        return $next($request);
    }

    protected function shouldValidate(Request $request): bool
    {
        $method = strtoupper($request->method());
        if (!in_array($method, $this->protectedMethods, true)) {
            return false;
        }

        $path = trim($request->pathinfo(), '/');
        if ($path === '') {
            return false;
        }

        return !$this->isExceptPath($path);
    }

    protected function isExceptPath(string $path): bool
    {
        foreach ($this->resolveExceptPaths() as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveExceptPaths(): array
    {
        $paths = $this->exceptPaths;
        $configured = (array)config('app.csrf.except', []);

        foreach ($configured as $value) {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $paths[] = (string)$nestedValue;
                }
                continue;
            }

            $paths[] = (string)$value;
        }

        // 动态排除管理后台登录接口（backstage_entrance 为可配置路径）
        // 登录接口为认证入口，登录前可能尚未获取 CSRF Token，排除可避免登录流程被阻断
        $backstageEntrance = trim((string)getConfig('backstage_entrance'), '/');
        if ($backstageEntrance !== '') {
            $paths[] = $backstageEntrance . '/login_check';
        }

        $paths = array_map(static function ($value): string {
            return trim((string)$value, '/');
        }, $paths);

        return array_values(array_unique(array_filter($paths, static function (string $value): bool {
            return $value !== '';
        })));
    }

    protected function pathMatches(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }

        $quoted = preg_quote($pattern, '#');
        $regex = '#^' . str_replace('\\*', '.*', $quoted) . '$#i';

        return (bool)preg_match($regex, $path);
    }

    protected function tokenName(): string
    {
        $csrfConfig = (array)config('app.csrf');

        return (string)($csrfConfig['token_name'] ?? '_csrf_token');
    }
}
