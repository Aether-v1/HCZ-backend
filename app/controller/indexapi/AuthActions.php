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
    protected function legacyGetCsrfToken()
    {
        try {
            // 检查CSRF功能是否启用
            $csrfEnabled = config('app.csrf_enabled');
            if (empty($csrfEnabled)) {
                Log::warning('CSRF令牌获取失败: CSRF功能未启用');
                return show(400, 'error', 'CSRF功能未启用');
            }

            // 检查必要的CSRF配置
            $csrfConfig = config('app.csrf');
            if (empty($csrfConfig) || !isset($csrfConfig['token_name'], $csrfConfig['expire'])) {
                Log::error('CSRF令牌获取失败: CSRF配置不完整', [
                    'csrf_config' => $csrfConfig
                ]);
                return show(500, 'error', 'CSRF配置不完整');
            }

            // 确保会话已启动
            if (empty(Session::getId())) {
                $sessionStarted = Session::start();
                if (!$sessionStarted) {
                    Log::error('CSRF令牌获取失败: 会话启动失败');
                    return show(500, 'error', '会话初始化失败');
                }
            }

            // 生成安全的CSRF令牌，增加异常捕获
            try {
                $csrfToken = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                Log::error('CSRF令牌生成失败: ' . $e->getMessage());
                // 备选方案：如果random_bytes失败，使用openssl_random_pseudo_bytes
                $csrfToken = bin2hex(openssl_random_pseudo_bytes(32));
            }

            // 存储令牌到Session，并设置过期时间
            $storeResult = Session::set(
                $csrfConfig['token_name'],
                $csrfToken,
                $csrfConfig['expire']
            );

            // 关键修改：增加二次验证，解决框架返回值可能的误判
            $storedToken = Session::get($csrfConfig['token_name']);
            $isActuallyStored = ($storedToken === $csrfToken);

            if (!$storeResult && !$isActuallyStored) {
                // 仅当存储失败且二次验证也失败时，才返回错误
                Log::error('CSRF令牌存储失败', [
                    'token_name' => $csrfConfig['token_name'],
                    'expire' => $csrfConfig['expire'],
                    'store_result' => $storeResult,
                    'stored_token' => $storedToken,
                    'expected_token' => $csrfToken
                ]);
                return show(500, 'error', '令牌存储失败');
            } elseif (!$storeResult && $isActuallyStored) {
                // 框架返回失败但实际已存储，记录警告但返回成功
                Log::warning('CSRF令牌存储返回值异常，但实际存储成功', [
                    'store_result' => $storeResult,
                    'session_id' => Session::getId()
                ]);
            }

            return show(200, 'success', '获取CSRF令牌成功', [
                'csrf_token' => $csrfToken,
                'expire' => $csrfConfig['expire'],
                'token_name' => $csrfConfig['token_name']
            ]);
        } catch (Exception $e) {
            Log::error('CSRF令牌获取异常: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return show(500, 'error', '服务器内部错误: ' . $e->getMessage());
        }
    }
    
    /**
     * 初始化用户2FA绑定
     */
    // 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
    // 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
    protected function twofa_init()
    {
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        
        // 检查是否已开启2FA
        if ($user_info->twofa_enabled || !empty($user_info->twofa_secret)) {
            return show(500, 'error', '您已开启2FA认证，无需重复设置');
        }
        
        // 生成随机密钥
        $twofa = new TwoFactorAuth();
        $secret = $twofa->createSecret();
        
        // 生成QR码内容
        $issuer = $this->config['site_name'] ?? 'My Site';
        $label = "{$issuer}:{$user_info->mobile}";
        $qrCodeUrl = $twofa->getQRCodeImageAsDataUri($label, $secret, 300);
        
        // 生成恢复码
        $recoveryCodes = $this->generateRecoveryCodes(8);
        
        // 临时存储密钥和恢复码（会话中）
        Session::set('twofa_temp_secret', $secret);
        Session::set('twofa_temp_recovery_codes', $recoveryCodes);
        
        // 返回密钥和二维码
        return show(200, 'success', '2FA提升安全性，建议绑定。手机丢失时，恢复码是唯一找回方式，请妥善保管！', [
            'secret' => $secret,
            'qr_code' => $qrCodeUrl,
            'recovery_codes' => $recoveryCodes
        ]);
    }
    
    /**
     * 验证并完成用户2FA绑定
     */
    // 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
    // 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
    protected function twofa_verify()
    {
        $post_info = $this->request->post();
        
        if (empty($post_info['code'])) {
            return show(500, 'error', '请输入动态验证码');
        }
        
        // 获取临时存储的密钥
        $secret = Session::get('twofa_temp_secret');
        if (empty($secret)) {
            return show(500, 'error', '请先初始化2FA');
        }
        
        // 验证动态码
        $twofa = new TwoFactorAuth();
        $valid = $twofa->verifyCode($secret, $post_info['code'], 2); // 允许2秒误差
        
        if (!$valid) {
            return show(500, 'error', '请确认APP时间与网络同步，或重新获取动态码');
        }
        
        // 获取恢复码并加密存储
        $recoveryCodes = Session::get('twofa_temp_recovery_codes');
        $encryptedRecoveryCodes = $this->encryptData(implode(',', $recoveryCodes));
        
        // 更新用户信息，保存加密后的密钥
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $user_info->twofa_secret = $this->encryptData($secret);
        $user_info->twofa_recovery_codes = $encryptedRecoveryCodes;
        $user_info->twofa_enabled = 1;
        $user_info->save();
        
        // 清除临时会话数据
        Session::delete('twofa_temp_secret');
        Session::delete('twofa_temp_recovery_codes');
        
        return show(200, 'success', '2FA绑定成功');
    }
    
/**
     * 禁用用户2FA
     */
    // 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
    // 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
    protected function twofa_disable()
    {
        $post_info = $this->request->post();
        
        if (empty($post_info['password'])) {
            return show(500, 'error', '请输入登录密码进行验证');
        }
        
        // 新增：验证2FA验证码
        if (empty($post_info['twofa_code'])) {
            return show(500, 'error', '请输入2FA动态验证码');
        }
        
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        
        // 验证密码
        if (!$user_info || !password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
            return show(500, 'error', '密码验证失败');
        }
        
        // 新增：验证2FA验证码
        try {
            $secret = $this->decryptData($user_info->twofa_secret);
            $twofa = new TwoFactorAuth();
            $valid = $twofa->verifyCode($secret, $post_info['twofa_code'], 2); // 允许2秒误差
            
            if (!$valid) {
                return show(500, 'error', '2FA验证码无效，请确认后重新输入');
            }
        } catch (Exception $e) {
            return show(500, 'error', '2FA验证失败: ' . $e->getMessage());
        }
        
        // 禁用2FA
        $user_info->twofa_secret = null;
        $user_info->twofa_recovery_codes = null;
        $user_info->twofa_enabled = 0;
        $user_info->save();
        
        return show(200, 'success', '2FA已成功禁用');
    }
    
    /**
     * 重新生成备用码
     */
    // 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
    // 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
    protected function twofa_regenerate_recovery_codes()
    {
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        
        if (empty($user_info->twofa_enabled)) {
            return show(500, 'error', '您尚未开启2FA认证');
        }
        
        // 生成新的恢复码
        $recoveryCodes = $this->generateRecoveryCodes(8);
        
        // 加密存储新的恢复码（旧码将失效）
        $user_info->twofa_recovery_codes = $this->encryptData(implode(',', $recoveryCodes));
        $user_info->save();
        
        return show(200, 'success', '恢复码已重新生成，旧码已失效', [
            'recovery_codes' => $recoveryCodes
        ]);
    }
    
    /**
     * 用户使用2FA恢复码登录或恢复
     */
    // 历史遗留 public 实现；当前最终入口由 IndexApi.php 覆盖，保留仅供兼容整理。
    // 注意：不要将此方法视为当前对外接口的真实行为来源，修改本方法不会直接改变当前线上接口行为。
    protected function twofa_recover()
    {
        $post_info = $this->request->post();
        
        if (empty($post_info['recovery_code'])) {
            return show(500, 'error', '请输入恢复码');
        }
        
        // 检查是登录状态下的恢复还是登录过程中的恢复
        $isLoginProcess = !empty($post_info['is_login']);
        
        if ($isLoginProcess) {
            // 登录过程中使用恢复码 - 需要验证用户名密码
            if (empty($post_info['mobile']) || empty($post_info['password'])) {
                return show(500, 'error', '请输入账号密码');
            }
            
            $user_info = UserModel::where('mobile', $post_info['mobile'])->find();
            
            if (!$user_info || !password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
                return show(500, 'error', '账号密码错误');
            }
        } else {
            // 已登录状态下使用恢复码
            $user_info = UserModel::where('id', $this->user_info['id'])->find();
        }
        
        if (empty($user_info->twofa_enabled) || empty($user_info->twofa_recovery_codes)) {
            return show(500, 'error', '未开启2FA或无可用恢复码');
        }
        
        // 解密恢复码
        $recoveryCodesStr = $this->decryptData($user_info->twofa_recovery_codes);
        $recoveryCodes = explode(',', $recoveryCodesStr);
        
        // 查找并验证恢复码
        $codeIndex = array_search(trim($post_info['recovery_code']), $recoveryCodes);
        if ($codeIndex === false) {
            return show(500, 'error', '无效的恢复码');
        }
        
        // 移除使用过的恢复码
        unset($recoveryCodes[$codeIndex]);
        $remainingCodes = array_values($recoveryCodes);
        
        if (count($remainingCodes) == 0) {
            // 如果没有剩余恢复码，禁用2FA
            $user_info->twofa_enabled = 0;
            $user_info->twofa_secret = null;
            $user_info->twofa_recovery_codes = null;
            $message = '恢复码已全部使用，2FA已自动禁用，请重新设置';
        } else {
            // 更新剩余恢复码
            $user_info->twofa_recovery_codes = $this->encryptData(implode(',', $remainingCodes));
            $message = '恢复成功，该恢复码已失效';
        }
        
        $user_info->save();
        
        if ($isLoginProcess) {
            // 处理登录
            $ip = $this->request->ip();
            $this->rotateSessionForUserLogin($user_info->getData(), $ip);
            
            return show(200, 'success', $message);
        }
        
        return show(200, 'success', $message);
    }
    
    // 密码验证
    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyVerifyPassword()
    {
        try {
            // 验证请求方法
            if (!$this->request->isPost()) {
                return show(405, 'error', '不支持的请求方法');
            }

            // 获取密码参数
            $password = trim($this->request->post('password', ''));

            // 密码非空验证
            if (empty($password)) {
                return show(400, 'error', '请输入密码');
            }

            // 检查用户是否已登录
            if (empty($this->user_info) || empty($this->user_info['id'])) {
                return show(401, 'error', '请先登录');
            }

            // 从数据库查询完整用户信息
            $user = UserModel::where('id', $this->user_info['id'])->find();
            if (empty($user)) {
                return show(401, 'error', '用户不存在');
            }

            // 验证密码（使用项目中的加盐方式）
            if (!password_verify(($password . $user->salt), $user->password)) {
                return show(401, 'error', '密码不正确');
            }

            // 验证成功
            return show(200, 'success', '密码验证成功');
        } catch (\Exception $e) {
            return show(500, 'error', '服务器内部错误');
        }
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyLoginPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'login':
                $account = trim((string)($post_info['mobile'] ?? ''));
                if($account === ''){
                    return show(500, 'error', '请输入账号');
                }
                if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $account)) {
                    return show(500, 'error', '账号需为6-32位字母或数字，不能包含特殊符号');
                }
                if(empty($post_info['password'])){
                    return show(500, 'error', '请输入登录密码');
                }
                $user_info = UserModel::where('mobile', '=', $account)->find();
                if ($user_info && password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
                    if($user_info['status'] == 0){
                        return show(500, 'error', '账号已禁封，请联系管理员');
                    }
                    $ip = $this->request->ip();
                    $this->rotateSessionForUserLogin($user_info->getData(), $ip);
                    return show(200, 'success', '登录成功');
                }
                return show(500, 'error', '账号密码错误');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyRegisterPost(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'register':
                $account = trim((string)($post_info['mobile'] ?? ''));
                if($account === ''){
                    return show(500, 'error', '请输入账号');
                }
                if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $account)) {
                    return show(500, 'error', '账号需为6-32位字母或数字，不能包含特殊符号');
                }
                if(empty($post_info['password'])){
                    return show(500, 'error', '请输入密码');
                }
                if(empty($post_info['invite_code'])){
                    return show(500, 'error', '请输入邀请码');
                }
                $tid_1 = UserModel::where('invite_code', $post_info['invite_code'])->find();
                if(empty($tid_1)){
                    return show(500, 'error', '邀请码错误');
                }
                // 关键修改：用$this->调用控制器内的subordinate方法
                $subordinate = $this->subordinate($tid_1);

                $user_info = UserModel::where('mobile', $account)->find();
                if($user_info){
                    return show(500, 'error', '当前账号已存在');
                }
                $salt = randomkeys(4);
                $user_info = UserModel::create([
                    'mobile' => $account,
                    'password' => password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT),
                    'salt' => $salt,
                    'avatar' => $this->config['user_avatar_image'],
                    'nickname' => '用户_'.randomkeys(5, 'en'),
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

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAuthLogin()
    {
        return $this->legacyLoginPost('login');
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAuthRegister()
    {
        return $this->legacyRegisterPost('register');
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAuthLogout()
    {
        $this->destroyUserSession();
        return $this->apiOk('已退出登录');
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAuthCheckPassword()
    {
        return $this->legacyVerifyPassword();
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyLogout()
    {
        $this->destroyUserSession();
        return redirect('/login');
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAccountProfile()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) return $this->apiError('用户不存在', 404);
        return show(200, 'success', '查询成功', [
            'avatar' => (string)($user['avatar'] ?? getConfig('user_avatar_image') ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'surname' => (string)($user['surname'] ?? ''),
            'birthday' => (string)($user['birthday'] ?? ''),
            'gender' => (int)($user['gender'] ?? 0),
            'city' => (string)($user['city'] ?? ''),
            'province' => (string)($user['province'] ?? ''),
            'city_name' => (string)($user['city_name'] ?? ''),
            'district' => (string)($user['district'] ?? ''),
            'mobile' => (string)($user['mobile'] ?? ''),
            'trc20' => (string)($user['trc20'] ?? ''),
        ]);
    }

    // 历史遗留实现：当前最终入口由 IndexApi.php 负责，保留仅供对比与迁移审计。
    protected function legacyApiAccountSettings()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) return $this->apiError('用户不存在', 404);
        return show(200, 'success', '查询成功', [
            'mobile' => (string)($user['mobile'] ?? ''),
            'trc20' => (string)($user['trc20'] ?? ''),
            'twofa_enabled' => !empty($user['twofa_enabled']) ? 1 : 0,
            'tg_chat_id' => (string)($user['tg_chat_id'] ?? ''),
            'tg_username' => (string)($user['tg_username'] ?? ''),
            'tg_bound' => !empty($user['tg_chat_id']) ? 1 : 0,
            'has_wallet_address' => !empty($user['trc20']) ? 1 : 0,
        ]);
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
