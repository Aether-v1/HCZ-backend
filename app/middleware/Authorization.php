<?php
declare (strict_types = 1);

namespace app\middleware;

use app\exception\AuthorizationException;
use app\service\AuthorizationService;
use Closure;
use think\App;
use think\facade\Log;
use think\Request;
use think\Response;

/**
 * RBAC 授权中间件
 *
 * 职责：
 * - 读取当前登录管理员（从 Session）
 * - 检查是否拥有路由声明的 permission
 * - 有权限 → 放行
 * - 无权限 → 抛出 AuthorizationException（由 ExceptionHandle 统一处理）
 *
 * 重要：
 * - 本中间件不全局启用，需要在路由或 Controller 中显式声明
 * - 没有声明 permission 参数时，直接放行（保持旧 Controller 的 power() 行为不变）
 * - 应在 AdminAuth（认证中间件）之后执行
 * - 不负责 Authentication（登录、Session）→ AdminAuth 负责
 * - 不修改旧 power() 函数，不删除 cz_admin.power 字段
 *
 * 用法示例（路由中显式声明）：
 *   Route::rule('admin/users', 'AdminUser/index')->middleware(Authorization::class, 'admin.user.view');
 *
 * 用法示例（Controller $middleware 数组中带参数）：
 *   protected array $middleware = [
 *       AdminAuth::class,
 *       Authorization::class . ':admin.user.view',
 *   ];
 */
class Authorization
{
    protected App $app;
    protected AuthorizationService $authService;

    public function __construct(App $app, AuthorizationService $authService)
    {
        $this->app = $app;
        $this->authService = $authService;
    }

    /**
     * 处理请求
     *
     * @param Request     $request
     * @param Closure     $next
     * @param string|null $permission 权限 code（如 admin.user.view），为 null 时直接放行
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        // 没有声明权限要求 → 直接放行（兼容旧 Controller）
        if ($permission === null || trim($permission) === '') {
            return $next($request);
        }

        // 获取当前登录管理员ID（从 Session，由 AdminAuth 中间件设置）
        $adminInfo = $request->session('admin');
        $adminId = (int)($adminInfo['id'] ?? 0);

        // 未登录（理论上 AdminAuth 已经处理，这里是双重保险）
        if ($adminId <= 0) {
            Log::warning('Authorization 中间件：未登录访问受限资源', [
                'permission' => $permission,
                'url' => $request->url(),
                'ip' => $request->ip(),
            ]);
            throw new AuthorizationException('未登录，无法访问该资源');
        }

        // 权限检查
        if (!$this->authService->can($adminId, $permission)) {
            Log::warning('Authorization 中间件：权限不足', [
                'admin_id' => $adminId,
                'permission' => $permission,
                'url' => $request->url(),
                'ip' => $request->ip(),
            ]);
            throw new AuthorizationException('权限不足，无法访问该资源');
        }

        return $next($request);
    }
}
