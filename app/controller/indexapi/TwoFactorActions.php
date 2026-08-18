<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\User as UserModel;
use Exception;
use RobThree\Auth\TwoFactorAuth;
use think\facade\Session;

trait TwoFactorActions
{
    protected function handleTwofaInit()
    {
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        if ($user_info && ($user_info->twofa_enabled || !empty($user_info->twofa_secret))) {
            return show(500, 'error', '您已开启2FA认证，无需重复设置');
        }

        try {
            $payload = $this->beginUserTwofaSetup($user_info);
        } catch (\Throwable $e) {
            $this->logApiException('twofa_init', $e);
            return show(500, 'error', '系统繁忙，请稍后再试');
        }

        return show(200, 'success', '2FA提升安全性，建议绑定。手机丢失时，恢复码是唯一找回方式，请妥善保管！', $payload);
    }

    protected function handleTwofaVerify()
    {
        $post_info = $this->request->post();

        $code = trim((string)($post_info['code'] ?? ''));
        if ($code === '') {
            return show(500, 'error', '请输入动态验证码');
        }

        $secret = (string)Session::get('twofa_temp_secret');
        $tempUserId = (int)Session::get('twofa_temp_user_id', 0);
        if ($secret === '' || $tempUserId !== (int)$this->user_info['id']) {
            return show(500, 'error', '请先初始化2FA');
        }

        $twofa = new TwoFactorAuth();
        $valid = $twofa->verifyCode($secret, $code, 2);
        if (!$valid) {
            return show(500, 'error', '请确认APP时间与网络同步，或重新获取动态码');
        }

        $recoveryCodes = Session::get('twofa_temp_recovery_codes');
        if (!is_array($recoveryCodes) || $recoveryCodes === []) {
            return show(500, 'error', '恢复码初始化失败，请重新开始绑定');
        }

        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $user_info->twofa_secret = $this->encryptData($secret);
        $user_info->twofa_recovery_codes = $this->hashRecoveryCodes($recoveryCodes);
        $user_info->twofa_enabled = 1;
        $user_info->save();

        $this->clearPendingTwofaSetup();

        return show(200, 'success', '2FA绑定成功，请立即离线保存恢复码。关闭弹窗后将不会再次显示明文', [
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    protected function handleTwofaDisable()
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();

        if (!$user_info || empty($user_info->twofa_enabled)) {
            return show(500, 'error', '您尚未开启2FA认证');
        }

        $verified = $this->verifyPasswordAndCurrentTwofaCodeForCurrentUser($user_info, $post_info);
        if (empty($verified['ok'])) {
            return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
        }

        $user_info->twofa_secret = null;
        $user_info->twofa_recovery_codes = null;
        $user_info->twofa_enabled = 0;
        $user_info->save();

        $this->clearPendingTwofaSetup();

        return show(200, 'success', '2FA已成功禁用');
    }

    protected function handleTwofaReset()
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();

        if (!$user_info || empty($user_info->twofa_enabled)) {
            return show(500, 'error', '您尚未开启2FA认证');
        }

        $verified = $this->verifyPasswordAndTwofaChallengeForCurrentUser($user_info, $post_info, 'reset');
        if (empty($verified['ok'])) {
            return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
        }

        try {
            $payload = $this->beginUserTwofaSetup($user_info, true);
        } catch (\Throwable $e) {
            $this->logApiException('twofa_reset', $e);
            return show(500, 'error', '系统繁忙，请稍后再试');
        }

        return show(200, 'success', '2FA已重置，请使用新的密钥重新绑定', $payload);
    }

    protected function handleTwofaRegenerateRecoveryCodes()
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();

        if (empty($user_info->twofa_enabled)) {
            return show(500, 'error', '您尚未开启2FA认证');
        }

        $verified = $this->verifyPasswordAndTwofaChallengeForCurrentUser($user_info, $post_info, 'regenerate_recovery_codes');
        if (empty($verified['ok'])) {
            return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
        }

        $recoveryCodes = $this->generateRecoveryCodes(8);
        $user_info->twofa_recovery_codes = $this->hashRecoveryCodes($recoveryCodes);
        $user_info->save();

        return show(200, 'success', '恢复码已重新生成，旧码已失效', [
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    protected function handleTwofaRecover()
    {
        $post_info = $this->request->post();

        $recoveryCode = trim((string)($post_info['recovery_code'] ?? ''));
        if ($recoveryCode === '') {
            return show(500, 'error', '请输入恢复码');
        }

        $isLoginProcess = !empty($post_info['is_login']);

        if ($isLoginProcess) {
            if (empty($post_info['mobile']) || empty($post_info['password'])) {
                return show(500, 'error', '请输入账号密码');
            }

            $user_info = UserModel::where('mobile', $post_info['mobile'])->find();
            if (!$user_info || !password_verify(($post_info['password'] . $user_info->salt), $user_info->password)) {
                return show(500, 'error', '账号密码错误');
            }
        } else {
            $user_info = UserModel::where('id', $this->user_info['id'])->find();
        }

        if (empty($user_info->twofa_enabled) || empty($user_info->twofa_recovery_codes)) {
            return show(500, 'error', '未开启2FA或无可用恢复码');
        }

        $consumeResult = $this->consumeUserRecoveryCode($user_info, $recoveryCode, $isLoginProcess ? 'login' : 'account_recover');
        if (empty($consumeResult['ok'])) {
            return show(500, 'error', (string)($consumeResult['message'] ?? '恢复失败'));
        }

        $message = (string)($consumeResult['message'] ?? '恢复成功');

        if ($isLoginProcess) {
            $ip = $this->request->ip();
            $this->rotateSessionForUserLogin($user_info->getData(), $ip);
            return show(200, 'success', $message);
        }

        return show(200, 'success', $message);
    }

    protected function handleGetUserTwofaStatus()
    {
        try {
            $userId = (int)($this->user_info['id'] ?? 0);
            if ($userId <= 0) {
                return show(403, 'error', '未登录');
            }

            $user = UserModel::where('id', $userId)->find();
            if (!$user) {
                return show(404, 'error', '用户不存在');
            }

            return show(200, 'success', '获取成功', [
                'twofa_enabled' => !empty($user->twofa_enabled) ? 1 : 0,
                'has_recovery_codes' => count($this->getRecoveryCodeHashes((string)$user->twofa_recovery_codes)) > 0 ? 1 : 0,
                'recovery_code_count' => count($this->getRecoveryCodeHashes((string)$user->twofa_recovery_codes)),
            ]);
        } catch (Exception $e) {
            $this->logApiException('twofa_status', $e);
            return show(500, 'error', '系统繁忙，请稍后再试');
        }
    }
}