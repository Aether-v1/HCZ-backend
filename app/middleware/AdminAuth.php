<?php
declare (strict_types=1);

namespace app\middleware;

use app\model\Admin as AdminModel;
use Closure;
use think\facade\Session;
use think\facade\Log;
use think\Request;
use think\Response;

class AdminAuth
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 方法白名单
        $White_list_Controller = ['login', 'login_check', 'password'];
        $thisAction = $request->action();

        if (!in_array($thisAction, $White_list_Controller)) {
            // 获取当前用户
            $admin_info = Session::get('admin');
            
            // 优化验证逻辑：只检查用户ID是否存在，弱化IP验证
            if (empty($admin_info['id'])) {
                // 记录日志便于排查（可选）
                Log::warning('管理员未登录或会话失效', [
                    'session_id' => Session::getId(),
                    'current_ip' => $request->ip(),
                    'url' => $request->url()
                ]);
                
                if ($request->isAjax()) {
                    return show(403, 'error', '未登录，禁止请求');
                }
                return redirect((string)url(getConfig('backstage_entrance').'/login'));
            }

            // 防禁用/删除后的旧会话继续可用：每次鉴权都回源确认管理员仍存在且状态允许使用。
            $currentAdmin = AdminModel::find((int)$admin_info['id']);
            $adminData = $currentAdmin ? $currentAdmin->getData() : [];
            if (!$currentAdmin || (array_key_exists('status', $adminData) && (int)$adminData['status'] === 0)) {
                Log::warning('管理员会话因账号状态异常失效', [
                    'session_id' => Session::getId(),
                    'admin_id' => (int)($admin_info['id'] ?? 0),
                    'current_ip' => $request->ip(),
                    'url' => $request->url(),
                    'admin_exists' => $currentAdmin ? 1 : 0,
                    'status' => (int)($adminData['status'] ?? 0),
                ]);
                $this->invalidateAdminSession();

                if ($request->isAjax()) {
                    return show(403, 'error', '未登录，禁止请求');
                }
                return redirect((string)url(getConfig('backstage_entrance').'/login'));
            }
            
            // 可选：仅记录IP变化（不强制登出）
            if (!empty($admin_info['login_ip']) && $admin_info['login_ip'] !== $request->ip()) {
                Log::info('管理员IP发生变化（正常现象，非安全问题）', [
                    'admin_id' => $admin_info['id'],
                    'old_ip' => $admin_info['login_ip'],
                    'new_ip' => $request->ip()
                ]);
                // 可选：更新会话中的IP为新IP
                // $admin_info['login_ip'] = $request->ip();
                // Session::set('admin', $admin_info);
            }
        }

        return $next($request);
    }

    private function invalidateAdminSession(): void
    {
        // 防禁用/删除后的旧会话继续可用：清理当前管理员会话，同时保留可能存在的前台用户会话。
        $preserved = [];
        foreach (['user'] as $key) {
            if (Session::has($key)) {
                $preserved[$key] = Session::get($key);
            }
        }

        Session::delete('admin');
        Session::clear();
        Session::destroy();

        foreach ($preserved as $key => $value) {
            Session::set($key, $value);
        }
    }
}
    