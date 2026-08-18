<?php
declare (strict_types=1);

namespace app\middleware;
use app\model\User as UserModel;
use app\service\TelegramService;
use Closure;
use think\App;
use think\facade\Session;
use think\facade\Log;
use think\Request;
use think\Response;

class UserAuth
{
    protected Request $request;
    
    /**
     * 应用实例
     * @var App
     */
    protected App $app;

    
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
    }
    
    
    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 方法白名单：这里必须与前端公开接口保持同步，避免登录/注册等被误拦截
        $White_list_Controller = [
            'login',
            'login_post',
            'register',
            'register_post',
            'get_csrf_token',
            'help_center',
            'epay_notify_url',
            'phone_meta',
            'api_auth_login',
            'api_auth_register',
            'twofa_recover',
        ];
        $thisAction = $request->action();
        if (!in_array($thisAction, $White_list_Controller)) {

            // 获取当前用户
            $user_info = Session::get('user');
            
            // 优化1：仅验证用户ID（核心登录状态标识）
            if (empty($user_info['id'])) {
                // 未登录或会话失效，记录日志
                Log::warning('用户未登录或会话失效', [
                    'session_id' => Session::getId(),
                    'current_ip' => $request->ip(),
                    'url' => $request->url()
                ]);

                $path = trim((string)$request->pathinfo(), '/');
                $isApiRequest = str_starts_with($path, 'api/') || $path === 'api';

                if ($isApiRequest || $request->isAjax()) {
                    return show(401, 'error', '未登录', null, 401);
                }
                return redirect('/login');
            }

            // 防禁用/删除后的旧会话继续可用：每次鉴权都回源确认用户仍存在且状态允许使用。
            $currentUser = UserModel::field('id,status')->find((int)$user_info['id']);
            if (!$currentUser || (int)($currentUser['status'] ?? 0) === 0) {
                Log::warning('用户会话因账号状态异常失效', [
                    'session_id' => Session::getId(),
                    'user_id' => (int)($user_info['id'] ?? 0),
                    'current_ip' => $request->ip(),
                    'url' => $request->url(),
                    'user_exists' => $currentUser ? 1 : 0,
                    'status' => (int)($currentUser['status'] ?? 0),
                ]);
                $this->invalidateUserSession();

                $path = trim((string)$request->pathinfo(), '/');
                $isApiRequest = str_starts_with($path, 'api/') || $path === 'api';

                if ($isApiRequest || $request->isAjax()) {
                    return show(401, 'error', '未登录', null, 401);
                }
                return redirect('/login');
            }
            
            // 优化2：IP变化仅记录日志，不强制登出
            if (!empty($user_info['login_ip']) && $user_info['login_ip'] !== $request->ip()) {
                Log::info('用户IP发生变化（正常现象）', [
                    'user_id' => $user_info['id'],
                    'old_ip' => $user_info['login_ip'],
                    'new_ip' => $request->ip(),
                    'url' => $request->url()
                ]);
                
                // 可选：自动更新会话中的IP（保持最新，减少重复日志）
                // $user_info['login_ip'] = $request->ip();
                // Session::set('user', $user_info);
            }
        }

        return $next($request);
    }

    private function invalidateUserSession(): void
    {
        // 防禁用/删除后的旧会话继续可用：清理当前用户会话，同时保留可能存在的后台管理员会话。
        $preserved = [];
        foreach (['admin'] as $key) {
            if (Session::has($key)) {
                $preserved[$key] = Session::get($key);
            }
        }

        Session::delete('user');
        Session::clear();
        Session::destroy();

        foreach ($preserved as $key => $value) {
            Session::set($key, $value);
        }
    }
    
    /**
     * 生成Telegram绑定验证码
     * 供用户在网站端调用，获取绑定验证码
     */
    public function generateTgBindCode()
    {
        // 获取当前登录用户ID
        $user_info = Session::get('user');
        if (empty($user_info['id'])) {
            return show(403, 'error', '未登录，无法生成绑定验证码');
        }
        $userId = $user_info['id'];
        
        try {
            // 实例化Telegram服务
            $telegramService = new TelegramService();
            $result = $telegramService->generateBindCodeForUser((int)$userId);
            
            if (!empty($result['success'])) {
                Log::info('Telegram绑定验证码生成成功', [
                    'user_id' => $userId
                ]);
                return show(
                    200,
                    'success',
                    '请在10分钟内通过Telegram机器人发送此验证码完成绑定',
                    [
                        'code' => (string)((is_array($result['data'] ?? null) ? ($result['data']['bind_code'] ?? '') : '')),
                    ]
                );
            } else {
                Log::warning('Telegram绑定验证码生成失败', ['user_id' => $userId]);
                return show(500, 'error', (string)($result['message'] ?? '生成验证码失败（可能已绑定或用户不存在）'));
            }
        } catch (\Exception $e) {
            Log::error('生成Telegram绑定验证码异常', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return show(500, 'error', '系统异常，请稍后再试');
        }
    }
}
?>
    
