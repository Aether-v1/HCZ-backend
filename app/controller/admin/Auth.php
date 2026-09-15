<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\service\AdminAuthService;
use app\service\AdminOperationLogService;
use app\service\LoginRateLimiter;
use think\facade\Log;
use think\facade\Session;

/**
 * Auth/Security Domain Controller（B10-32 迁移自 AdminApi，行为等价）。
 *
 * 仅负责：Request parsing → AdminAuthService（编排）→ HTTP response translation。
 * 不承载：TOTP / AES / recovery 验证 / session 生命周期 / rate limiter / Guard primitive
 * （全部由 AdminAuthService 编排并委托既有 Service SSOT）。
 */
class Auth extends \app\BaseController
{
    protected array $middleware = [AdminAuth::class];

    /**
     * 修改登录方法，添加2FA验证（迁移自 AdminApi::login_check，行为等价）。
     * 顺序冻结：assertNotLimited → AdminModel → password_verify → 2FA gate →
     * session rotation → admin identity → RateLimiter.clear → security log → show()。
     */
    public function login_check()
    {
        $post_info = $this->request->post();
        $account = trim((string)($post_info['account'] ?? ''));
        $password = (string)($post_info['password'] ?? '');

        // 判断是否输入账号密码
        if ($account === '' || $password === '') {
            return show(500, 'error', '账号或密码不得为空');
        }

        $rateLimiter = new LoginRateLimiter();
        try {
            $rateLimiter->assertNotLimited($this->request->ip(), $account);
        } catch (\RuntimeException $e) {
            return show(500, 'error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('admin login rate limit check error: ' . $e->getMessage(), [
                'account' => $account,
                'ip' => $this->request->ip(),
            ]);
            return show(500, 'error', '系统繁忙，请稍后再试');
        }

        $admin_info = AdminModel::where('account', '=', $account)->find();

        // 验证账号密码
        if (!$admin_info || !password_verify(($password . $admin_info->salt), $admin_info->password)) {
            try {
                $rateLimiter->recordFailure($this->request->ip(), $account);
            } catch (\Throwable $e) {
                Log::error('admin login rate limit record error: ' . $e->getMessage(), [
                    'account' => $account,
                    'ip' => $this->request->ip(),
                ]);
                return show(500, 'error', '系统繁忙，请稍后再试');
            }
            Log::warning("登录失败：账号或密码错误，尝试登录的账号为{$account}");
            return show(500, 'error', '请检查您输入的用户名或密码是否正确。');
        }

        $authService = app(AdminAuthService::class);

        // 检查是否启用了2FA
        if ($admin_info->twofa_enabled) {
            $verificationCode = trim((string)($post_info['twofa_code'] ?? ''));
            if ($verificationCode === '') {
                return show(403, 'need_twofa', '请输入二步验证码或恢复码', [
                    'twofa_required' => 1,
                ], 403);
            }

            $twofaResult = $authService->verifyAdminTwofaOrRecovery($admin_info, [
                'verification_code' => $verificationCode,
            ], 'login');
            if (empty($twofaResult['ok'])) {
                $attemptMode = $authService->detectAdminLoginVerificationAttempt($verificationCode);
                try {
                    $rateLimiter->recordFailure($this->request->ip(), $account);
                } catch (\Throwable $e) {
                    Log::error('admin login rate limit record error: ' . $e->getMessage(), [
                        'account' => $account,
                        'ip' => $this->request->ip(),
                    ]);
                    return show(500, 'error', '系统繁忙，请稍后再试');
                }
                if ($attemptMode === 'recovery') {
                    Log::warning("管理员{$account}登录失败：恢复码校验失败", [
                        'account' => $account,
                        'admin_id' => (int)($admin_info['id'] ?? 0),
                        'ip' => (string)$this->request->ip(),
                        'message' => (string)($twofaResult['message'] ?? ''),
                    ]);
                } else {
                    Log::warning("管理员{$account}登录失败：动态码校验失败", [
                        'account' => $account,
                        'admin_id' => (int)($admin_info['id'] ?? 0),
                        'ip' => (string)$this->request->ip(),
                        'message' => (string)($twofaResult['message'] ?? ''),
                    ]);
                }
                return show(500, 'error', (string)($twofaResult['message'] ?? '二步验证码或恢复码不正确'));
            }

            if (($twofaResult['mode'] ?? '') === 'recovery') {
                Log::info("管理员{$account}登录成功：使用恢复码完成校验", [
                    'account' => $account,
                    'admin_id' => (int)($admin_info['id'] ?? 0),
                    'ip' => (string)$this->request->ip(),
                ]);
            }
        }

        try {
            $rateLimiter->clear($this->request->ip(), $account);
        } catch (\Throwable $e) {
            Log::warning('admin login rate limit clear error: ' . $e->getMessage(), [
                'account' => $account,
                'ip' => $this->request->ip(),
            ]);
        }

        // 登录成功，记录日志
        $loginVerificationSummary = '，未启用2FA';
        if (!empty($admin_info->twofa_enabled)) {
            $loginVerificationSummary = (($twofaResult['mode'] ?? '') === 'recovery')
                ? '，已通过恢复码完成二步验证'
                : '，已通过动态码完成二步验证';
        }

        Log::info("管理员{$account}登录成功" . ($admin_info->twofa_enabled ? '（已完成二步验证）' : ''));
        $authService->rotateSessionForAdminLogin($admin_info->getData());
        app(AdminOperationLogService::class)->record('管理员登录成功', '管理员管理', '管理员账号：' . $account . $loginVerificationSummary, [
            'admin' => is_array($this->request->session('admin')) ? $this->request->session('admin') : [],
            'target_id' => (int)($admin_info['id'] ?? 0),
            'target_type' => 'admin',
        ]);
        return show(200, 'success', '登录成功', getConfig('backstage_entrance'));
    }

    // 后台管理员退出登录（迁移自 AdminApi::logout，行为等价）
    public function logout()
    {
        // 防被动登出：后台退出仅允许 POST，阻断第三方页面通过 GET 链接或图片直接触发退出。
        if (!$this->request->isPost()) {
            return show(405, 'error', '不支持的请求方法', null, 405);
        }

        $adminSession = $this->request->session('admin');
        $account = $adminSession['account'] ?? '未知管理员';
        Log::info("管理员{$account}退出登录");
        app(AdminAuthService::class)->destroyAdminSession();
        return redirect((string)url(getConfig('backstage_entrance').'/login'));
    }

    /**
     * 双因素认证相关操作（迁移自 AdminApi::twofa_post，行为等价）。
     * disable / regenerate_recovery_codes / reset 均先 verify（verifyAdminTwofaOrRecovery），
     * 不绕过 verification gate。
     */
    public function twofa_post(string $action)
    {
        $post_info = $this->request->post();
        $adminSession = $this->request->session('admin');
        $currentAdminId = (int)($adminSession['id'] ?? 0);

        try {
            if (!$this->directRequestPathMatches('twofa_post/' . $action)) {
                return show(403, 'error', '2FA请求路径错误');
            }

            if (!$this->directValidateRequiredCsrfToken()) {
                Log::warning('admin twofa invalid csrf blocked', [
                    'admin_id' => $currentAdminId,
                    'action' => $action,
                    'ip' => (string)$this->request->ip(),
                    'path' => $this->directCurrentRequestPath(),
                ]);
                return show(403, 'error', '2FA请求校验失败');
            }

            if ($currentAdminId <= 0) {
                return show(403, 'error', '管理员未登录');
            }

            $id = (int)($post_info['id'] ?? $currentAdminId);
            if ($id !== $currentAdminId) {
                return show(403, 'error', '只能操作当前登录管理员的2FA设置');
            }

            $model = AdminModel::find($id);
            if (!$model) {
                return show(500, 'error', '管理员不存在');
            }

            $account = (string)($model->account ?? '');
            $logPrefix = "管理员[{$account}]";
            $authService = app(AdminAuthService::class);
            $operationLog = app(AdminOperationLogService::class);

            switch ($action) {
                case 'generate':
                    $payload = $authService->beginAdminTwofaSetup($model);

                    Log::info("{$logPrefix}初始化2FA绑定");
                    return show(200, 'success', '请使用身份验证器扫描二维码并输入当前验证码完成绑定', $payload);

                case 'enable':
                    $code = trim((string)($post_info['code'] ?? $post_info['twofa_code'] ?? ''));
                    $inputError = $authService->validateAdminTwofaCodeInput($code);
                    if ($inputError !== null) {
                        return show(500, 'error', $inputError);
                    }

                    $secret = (string)Session::get('admin_twofa_temp_secret', '');
                    $tempAdminId = (int)Session::get('admin_twofa_temp_admin_id', 0);
                    if ($secret === '' || $tempAdminId !== (int)$model->id) {
                        return show(500, 'error', '请先开始2FA绑定');
                    }

                    if (!$authService->verifyTwofaCodeWithSecret($secret, $code, 2)) {
                        Log::warning("{$logPrefix}2FA启用失败：验证码错误");
                        return show(500, 'error', '请确认APP时间同步后再重试');
                    }

                    $recoveryCodes = Session::get('admin_twofa_temp_recovery_codes');
                    if (!is_array($recoveryCodes) || $recoveryCodes === []) {
                        return show(500, 'error', '恢复码初始化失败，请重新开始绑定');
                    }

                    $model->twofa_secret = $authService->encryptAdminData($secret);
                    $model->twofa_recovery_codes = $authService->hashAdminRecoveryCodes($recoveryCodes);
                    $model->twofa_enabled = 1;
                    $model->save();

                    $authService->clearPendingAdminTwofaSetup();

                    $operationLog->record('启用管理员2FA', '管理员管理', '管理员账号：' . $account . '，已启用2FA认证', [
                        'admin' => is_array($adminSession) ? $adminSession : [],
                        'target_id' => (int)($model->id ?? 0),
                        'target_type' => 'admin',
                    ]);

                    Log::info("{$logPrefix}成功启用2FA");
                    return show(200, 'success', '2FA已成功启用，请立即离线保存恢复码', [
                        'recovery_codes' => $recoveryCodes,
                    ]);

                case 'disable':
                    if (empty($model->twofa_enabled)) {
                        return show(500, 'error', '您尚未开启2FA认证');
                    }

                    $verified = $authService->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'disable');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $model->twofa_enabled = 0;
                    $model->twofa_secret = null;
                    $model->twofa_recovery_codes = null;
                    $model->save();

                    $authService->clearPendingAdminTwofaSetup();

                    $operationLog->record('禁用管理员2FA', '管理员管理', '管理员账号：' . $account . '，已禁用2FA认证', [
                        'admin' => is_array($adminSession) ? $adminSession : [],
                        'target_id' => (int)($model->id ?? 0),
                        'target_type' => 'admin',
                    ]);

                    Log::info("{$logPrefix}成功禁用2FA");
                    return show(200, 'success', '2FA已成功禁用');

                case 'verify':
                    $verified = $authService->verifyAdminTwofaCode($model, trim((string)($post_info['code'] ?? $post_info['twofa_code'] ?? '')));
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证码不正确'));
                    }

                    return show(200, 'success', '验证码验证成功');

                case 'recover':
                    $verified = $authService->consumeAdminRecoveryCode($model, (string)($post_info['recovery_code'] ?? ''), 'recover');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '恢复失败'));
                    }

                    Log::info("{$logPrefix}使用恢复码成功");
                    return show(200, 'success', (string)($verified['message'] ?? '恢复码验证成功'), [
                        'remaining_count' => (int)($verified['remaining_count'] ?? 0),
                    ]);

                case 'regenerate_recovery_codes':
                    if (!$model->twofa_enabled) {
                        return show(500, 'error', '未启用2FA');
                    }

                    $verified = $authService->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'regenerate_recovery_codes');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $recoveryCodes = $authService->generateAdminRecoveryCodes(8);
                    $model->twofa_recovery_codes = $authService->hashAdminRecoveryCodes($recoveryCodes);
                    $model->save();

                    $operationLog->record('重置管理员2FA恢复码', '管理员管理', '管理员账号：' . $account . '，已重新生成2FA恢复码', [
                        'admin' => is_array($adminSession) ? $adminSession : [],
                        'target_id' => (int)($model->id ?? 0),
                        'target_type' => 'admin',
                    ]);

                    Log::info("{$logPrefix}重新生成2FA恢复码");
                    return show(200, 'success', '恢复码已重新生成', [
                        'recovery_codes' => $recoveryCodes,
                        'message' => '请保存新的恢复码，旧的恢复码已失效'
                    ]);

                case 'reset':
                    if (!$model->twofa_enabled) {
                        return show(500, 'error', '您尚未开启2FA认证');
                    }

                    $verified = $authService->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'reset');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $payload = $authService->beginAdminTwofaSetup($model, true);

                    Log::info("{$logPrefix}重置2FA绑定");
                    return show(200, 'success', '2FA已重置，请使用新的密钥重新绑定', $payload);

                default:
                    return show(500, 'error', '无效的操作');
            }
        } catch (\Throwable $e) {
            Log::error("2FA操作失败：" . $e->getMessage());
            return show(500, 'error', '操作失败：' . $e->getMessage());
        }
    }
}
