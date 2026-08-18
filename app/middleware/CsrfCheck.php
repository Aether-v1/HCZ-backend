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
        'api/auth/login',
        'api/auth/twofa/recover',
        'api/auth/register',
        'api/auth/captcha',
        'api/auth/captcha/*',
        'api/captcha',
        'api/captcha/*',
        'api/callback/*',
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
        if ($path === '' || !str_starts_with($path, 'api/')) {
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
