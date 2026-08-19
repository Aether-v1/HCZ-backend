<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\User as UserModel;
use app\service\ActionRateLimiter;
use app\service\LoginRateLimiter;
use Exception;
use RobThree\Auth\TwoFactorAuth;
use think\facade\Log;
use think\facade\Session;

trait AuthActions
{
    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。

/**
 * 获取当前用户2FA状态
 */
// 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
// 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
protected function get_user_2fa_status()
{
    try {
        $user = $this->getCurrentUser(); // 使用之前抽取的获取当前用户方法
        return show(200, 'success', '获取成功', [
            'twofa_enabled' => !empty($user->twofa_enabled) ? 1 : 0
        ]);
    } catch (Exception $e) {
        return show(500, 'error', '获取状态失败：' . $e->getMessage());
    }
}

// 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
// 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
protected function api_account_profile_save()
{
    $post = $this->request->post();
    if (empty($post)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $post = $json;
        }
    }

    $user = UserModel::where('id', $this->user_info['id'] ?? 0)->find();
    if (!$user) {
        return $this->apiError('用户不存在', 404);
    }

    try {
        $fields = [];

        if (array_key_exists('avatar', $post)) {
            $fields['avatar'] = (string)($post['avatar'] ?? '');
        }
        if (array_key_exists('nickname', $post)) {
            $fields['nickname'] = (string)($post['nickname'] ?? '');
        }
        if (array_key_exists('surname', $post)) {
            $fields['surname'] = (string)($post['surname'] ?? '');
        }
        if (array_key_exists('city', $post)) {
            $fields['city'] = (string)($post['city'] ?? '');
        }
        if (array_key_exists('birthday', $post)) {
            $fields['birthday'] = (string)($post['birthday'] ?? '');
        }
        if (array_key_exists('gender', $post)) {
            $fields['gender'] = (int)($post['gender'] ?? 0);
        }

        if (empty($fields)) {
            return $this->apiError('没有可保存的数据', 400);
        }

        foreach ($fields as $key => $value) {
            $user->$key = $value;
        }

        $user->save();

        return $this->apiOk('保存成功', [
            'profile' => [
                'avatar' => (string)($user['avatar'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
                'surname' => (string)($user['surname'] ?? ''),
                'city' => (string)($user['city'] ?? ''),
                'birthday' => (string)($user['birthday'] ?? ''),
                'gender' => (int)($user['gender'] ?? 0),
            ]
        ]);
    } catch (\Throwable $e) {
        \think\facade\Log::error('api_account_profile_save error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'post_keys' => is_array($post) ? array_keys($post) : [],
            'post_count' => is_array($post) ? count($post) : 0,
        ]);
        return $this->apiError('资料保存失败：' . $e->getMessage(), 500);
    }
}

// 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
// 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
protected function api_account_password_save()
{
    $post = $this->request->post();
    if (empty($post)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $post = $json;
        }
    }

    $user = UserModel::where('id', $this->user_info['id'] ?? 0)->find();
    if (!$user) {
        return $this->apiError('用户不存在', 404);
    }

    $oldPassword = trim((string)($post['old_password'] ?? ''));
    $newPassword = trim((string)($post['password_one'] ?? ($post['new_password'] ?? '')));
    $confirmPassword = trim((string)($post['password_two'] ?? ($post['confirm_password'] ?? '')));
    $twofaCode = trim((string)($post['twofa_code'] ?? ''));

    if ($oldPassword === '') {
        return $this->apiError('请输入原登录密码', 400);
    }
    if ($newPassword === '') {
        return $this->apiError('请输入新登录密码', 400);
    }
    if ($confirmPassword === '') {
        return $this->apiError('请输入确认新登录密码', 400);
    }
    if ($newPassword !== $confirmPassword) {
        return $this->apiError('两次密码不相同', 400);
    }

    if (!password_verify($oldPassword . ($user->salt ?? ''), $user->password)) {
        return $this->apiError('原登录密码错误', 400);
    }

    if (!empty($user->twofa_enabled)) {
        if ($twofaCode === '') {
            return $this->apiError('请输入2FA动态验证码', 400);
        }
        try {
            $secret = $this->decryptData((string)$user->twofa_secret);
            $twofa = new TwoFactorAuth();
            $valid = $twofa->verifyCode($secret, $twofaCode, 2);
            if (!$valid) {
                return $this->apiError('2FA验证码无效，请确认后重新输入', 400);
            }
        } catch (\Throwable $e) {
            Log::error('api_account_password_save 2fa error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->apiError('2FA验证失败：' . $e->getMessage(), 500);
        }
    }

    try {
        $salt = $this->generateSalt();
        $user->password = password_hash($newPassword . $salt, PASSWORD_BCRYPT);
        $user->salt = $salt;
        $user->save();

        $this->destroyUserSession();

        return $this->apiOk('修改成功', [
            'need_relogin' => 1,
        ]);
    } catch (\Throwable $e) {
        Log::error('api_account_password_save error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return $this->apiError('修改失败：' . $e->getMessage(), 500);
    }
}

    protected function handleVerifyPassword()
    {
        try {
            if (!$this->request->isPost()) {
                return show(405, 'error', '不支持的请求方法');
            }

            $password = trim($this->request->post('password', ''));
            if (empty($password)) {
                return show(400, 'error', '请输入密码');
            }

            if (empty($this->user_info) || empty($this->user_info['id'])) {
                return show(401, 'error', '请先登录');
            }

            $user = UserModel::where('id', $this->user_info['id'])->find();
            if (empty($user)) {
                return show(401, 'error', '用户不存在');
            }

            if (!password_verify(($password . $user->salt), $user->password)) {
                return show(401, 'error', '密码不正确');
            }

            return show(200, 'success', '密码验证成功');
        } catch (\Exception $e) {
            return show(500, 'error', '服务器内部错误');
        }
    }

    protected function handleLoginPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'login':
                $account = trim((string)($post_info['mobile'] ?? ''));
                if ($account === '') {
                    return show(500, 'error', '请输入账号');
                }
                if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $account)) {
                    return show(500, 'error', '账号需为6-32位字母或数字，不能包含特殊符号');
                }
                if (empty($post_info['password'])) {
                    return show(500, 'error', '请输入登录密码');
                }
                $rateLimiter = new LoginRateLimiter();
                try {
                    $rateLimiter->assertNotLimited($this->request->ip(), $account);
                } catch (\RuntimeException $e) {
                    return show(500, 'error', $e->getMessage());
                } catch (\Throwable $e) {
                    Log::error('user login rate limit check error: ' . $e->getMessage(), [
                        'account' => $account,
                        'ip' => $this->request->ip(),
                    ]);
                    return show(500, 'error', '系统繁忙，请稍后再试');
                }
                $user_info = UserModel::where('mobile', '=', $account)->find();
                if ($user_info && password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
                    if ($user_info['status'] == 0) {
                        return show(500, 'error', '账号已禁封，请联系管理员');
                    }

                    if (!empty($user_info->twofa_enabled)) {
                        $twofaResult = $this->verifyUserTwofaCode($user_info, trim((string)($post_info['twofa_code'] ?? '')));
                        if (empty($twofaResult['ok'])) {
                            return show(500, 'error', (string)($twofaResult['message'] ?? '2FA验证失败'), [
                                'twofa_required' => 1,
                                'recovery_supported' => 1,
                            ]);
                        }
                    }

                    $ip = $this->request->ip();
                    try {
                        $rateLimiter->clear($ip, $account);
                    } catch (\Throwable $e) {
                        Log::warning('user login rate limit clear error: ' . $e->getMessage(), [
                            'account' => $account,
                            'ip' => $ip,
                        ]);
                    }
                    $this->rotateSessionForUserLogin($user_info->getData(), $ip);
                    return show(200, 'success', '登录成功');
                }
                try {
                    $rateLimiter->recordFailure($this->request->ip(), $account);
                } catch (\Throwable $e) {
                    Log::error('user login rate limit record error: ' . $e->getMessage(), [
                        'account' => $account,
                        'ip' => $this->request->ip(),
                    ]);
                    return show(500, 'error', '系统繁忙，请稍后再试');
                }
                return show(500, 'error', '账号密码错误');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleRegisterPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'register':
                // SEC-004: 注册限流 — IP 维度，10 分钟内最多 5 次
                // 仅为外围防护，不替代数据库唯一约束和业务校验
                $registerIp = (string)($this->request->ip() ?: '0.0.0.0');
                if (!ActionRateLimiter::check('register:ip:' . $registerIp, 5, 600)) {
                    return show(429, 'error', '注册操作过于频繁，请10分钟后再试', [], 429);
                }

                $account = trim((string)($post_info['mobile'] ?? ''));
                if ($account === '') {
                    return show(500, 'error', '请输入账号');
                }
                if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $account)) {
                    return show(500, 'error', '账号需为6-32位字母或数字，不能包含特殊符号');
                }
                if (empty($post_info['password'])) {
                    return show(500, 'error', '请输入密码');
                }
                if (empty($post_info['invite_code'])) {
                    return show(500, 'error', '请输入邀请码');
                }
                $tid_1 = UserModel::where('invite_code', $post_info['invite_code'])->find();
                if (empty($tid_1)) {
                    return show(500, 'error', '邀请码错误');
                }
                $subordinate = $this->subordinate($tid_1);

                $user_info = UserModel::where('mobile', $account)->find();
                if ($user_info) {
                    return show(500, 'error', '当前账号已存在');
                }
                $salt = randomkeys(4);
                UserModel::create([
                    'mobile' => $account,
                    'password' => password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT),
                    'salt' => $salt,
                    'avatar' => $this->config['user_avatar_image'],
                    'nickname' => '用户_' . randomkeys(5, 'en'),
                    'invite_code' => randomkeys(8),
                    'tid_1' => $subordinate['tid_1'],
                    'tid_2' => $subordinate['tid_2'],
                    'tid_3' => $subordinate['tid_3'],
                    'tid_4' => $subordinate['tid_4'],
                    'tid_5' => $subordinate['tid_5'],
                    'tid_6' => $subordinate['tid_6'],
                    'tid_7' => $subordinate['tid_7'],
                    'tid_8' => $subordinate['tid_8'],
                    'tid_9' => $subordinate['tid_9'],
                    'tid_10' => $subordinate['tid_10'],
                ]);
                return show(200, 'success', '注册成功');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleLogout()
    {
        $this->destroyUserSession();
        return redirect('/login');
    }
}
