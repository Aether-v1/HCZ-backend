<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\BankCard;
use app\model\User as UserModel;
use app\service\TelegramService as UserTelegramService;
use RobThree\Auth\TwoFactorAuth;
use think\facade\Log;
use think\facade\Session;

trait AccountActions
{
    protected function handleBankCardPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        $bankCardId = (int)($post_info['bank_card_id'] ?? ($post_info['id'] ?? 0));
        $BankCard_info = $bankCardId > 0 ? BankCard::where('uid', $user_info['id'])->find($bankCardId) : null;
        switch ($action) {
            case 'submit':
                if (empty($post_info['name'])) {
                    return show(500, 'error', '请输入姓名');
                }
                if (empty($post_info['mobile'])) {
                    return show(500, 'error', '请输入预留手机号');
                }
                $wxAccount = trim($post_info['wx_account'] ?? '');
                $zfbAccount = trim($post_info['zfb_account'] ?? '');
                if (empty($wxAccount) && empty($zfbAccount)) {
                    return show(500, 'error', '请至少填写一种支付方式（微信或支付宝）');
                }

                $credentialError = $this->verifySensitiveActionCredential($user_info, $post_info);
                if ($credentialError) {
                    return $credentialError;
                }

                if ($BankCard_info) {
                    $BankCard_info->name = $this->normalizeProfileText($post_info['name'] ?? '', 100);
                    $BankCard_info->mobile = $this->normalizeProfileText($post_info['mobile'] ?? '', 50);
                    $BankCard_info->wx_account = $this->normalizeProfileText($wxAccount, 100);
                    $BankCard_info->zfb_account = $this->normalizeProfileText($zfbAccount, 100);
                    $BankCard_info->save();
                    return show(200, 'success', '保存成功');
                }

                $BankCard_count = BankCard::where('uid', $user_info['id'])->count();
                $default_selection = empty($BankCard_count) ? 1 : 0;

                BankCard::create([
                    'uid' => $user_info['id'],
                    'name' => $this->normalizeProfileText($post_info['name'] ?? '', 100),
                    'mobile' => $this->normalizeProfileText($post_info['mobile'] ?? '', 50),
                    'wx_account' => $this->normalizeProfileText($wxAccount, 100),
                    'zfb_account' => $this->normalizeProfileText($zfbAccount, 100),
                    'default_selection' => $default_selection,
                ]);
                return show(200, 'success', '保存成功');

            case 'default_selection':
                if (!$BankCard_info) {
                    return show(500, 'error', '银行卡不存在');
                }
                $data = BankCard::where('uid', $user_info['id'])->select();
                foreach ($data as $vo) {
                    $BankCard = BankCard::find($vo['id']);
                    $BankCard->default_selection = 0;
                    $BankCard->save();
                }
                $BankCard_info->default_selection = 1;
                $BankCard_info->save();
                return show(200, 'success', '切换成功');

            case 'del':
                if (!$BankCard_info) {
                    return show(500, 'error', '银行卡不存在');
                }
                if ($BankCard_info->delete() === false) {
                    return show(500, 'error', '删除失败');
                }
                return show(200, 'success', '删除成功');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleAccountSettingsPost(string $action)
    {
        $post_info = $this->request->post();
        $user_info = UserModel::where('id', $this->user_info['id'])->find();
        switch ($action) {
            case 'information':
                $user_info->avatar = $post_info['avatar'];
                $user_info->nickname = $this->normalizeProfileText($post_info['nickname'] ?? '');
                $user_info->surname = $this->normalizeProfileText($post_info['surname'] ?? '');
                $user_info->city = $post_info['city'];
                $user_info->birthday = $post_info['birthday'];
                $user_info->gender = $post_info['gender'];
                $user_info->save();
                return show(200, 'success', '保存成功');

            case 'password':
                if (empty($post_info['old_password'])) {
                    return show(500, 'error', '请输入原登录密码');
                }
                if (empty($post_info['password_one'])) {
                    return show(500, 'error', '请输入新登录密码');
                }
                if (empty($post_info['password_two'])) {
                    return show(500, 'error', '请输入确认新登录密码');
                }
                if ($post_info['password_one'] != $post_info['password_two']) {
                    return show(500, 'error', '两次密码不相同');
                }

                $oldPassword = trim($post_info['old_password']);
                $newPassword = trim($post_info['password_one']);

                if ($user_info && password_verify(($oldPassword . $user_info->salt), $user_info->password)) {
                    if (!empty($user_info->twofa_enabled)) {
                        if (empty($post_info['twofa_code'])) {
                            return show(500, 'error', '请输入2FA动态验证码');
                        }
                        try {
                            $secret = $this->decryptData($user_info->twofa_secret);
                            $twofa = new \RobThree\Auth\TwoFactorAuth();
                            $valid = $twofa->verifyCode($secret, $post_info['twofa_code'], 2);
                            if (!$valid) {
                                return show(500, 'error', '2FA验证码无效，请确认后重新输入');
                            }
                        } catch (\Exception $e) {
                            return show(500, 'error', '2FA验证失败: ' . $e->getMessage());
                        }
                    }

                    $salt = $this->generateSalt();
                    $user_info->password = password_hash(($newPassword . $salt), PASSWORD_BCRYPT);
                    $user_info->salt = $salt;
                    $user_info->save();

                    $this->destroyUserSession();
                    return show(200, 'success', '修改成功');
                }
                return show(500, 'error', '原登录密码错误');

            case 'wallet_address':
                try {
                    $lastSubmitTime = Session::get('last_wallet_submit_time');
                    if ($lastSubmitTime && time() - $lastSubmitTime < 300) {
                        return show(500, 'error', '操作过于频繁，请5分钟后再试');
                    }

                    if (empty($post_info['address'])) {
                        return show(500, 'error', '请输入提币地址');
                    }

                    if (!preg_match('/^T[a-zA-Z0-9]{33}$/', $post_info['address'])) {
                        return show(500, 'error', '请输入有效的TRC20地址');
                    }

                    if (empty($user_info)) {
                        Log::warning('钱包地址修改失败：用户不存在', [
                            'user_id' => $this->user_info['id'] ?? '未知',
                            'ip' => $this->request->ip(),
                        ]);
                        return show(500, 'error', '用户信息不存在');
                    }

                    $credentialError = $this->verifySensitiveActionCredential($user_info, $post_info);
                    if ($credentialError) {
                        Log::warning('钱包地址修改失败：身份验证未通过', [
                            'user_id' => $user_info->id,
                            'ip' => $this->request->ip(),
                            'twofa_enabled' => !empty($user_info->twofa_enabled) ? 1 : 0,
                        ]);
                        return $credentialError;
                    }

                    if ($user_info->trc20 === $post_info['address']) {
                        return show(200, 'success', '地址未变更，无需保存');
                    }

                    $user_info->trc20 = $post_info['address'];
                    $saveResult = $user_info->save();

                    if (!$saveResult) {
                        throw new \Exception('数据库保存失败');
                    }

                    Log::info('钱包地址修改成功', [
                        'user_id' => $user_info->id,
                        'username' => $user_info->username ?? '未知',
                        'ip' => $this->request->ip(),
                        'operate_time' => date('Y-m-d H:i:s'),
                    ]);

                    Session::set('last_wallet_submit_time', time());

                    return show(200, 'success', '钱包地址修改成功');
                } catch (\Exception $e) {
                    Log::error('钱包地址修改异常', [
                        'user_id' => $this->user_info['id'] ?? '未知',
                        'error_message' => $e->getMessage(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                        'ip' => $this->request->ip(),
                    ]);
                    return show(500, 'error', '操作失败：' . $e->getMessage());
                }

            case 'generate_tg_bind_code':
                return show(410, 'error', '旧TG绑定接口已下线，请使用 /api/account/telegram-binding-code');

            default:
                return show(500, 'error', '请求出错');
        }
    }

    protected function handleApiUserBootstrap()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) {
            return show(500, 'error', '用户不存在');
        }

        $telegramService = new UserTelegramService();
        $telegramBinding = $telegramService->getBindingStatusForUser((int)$user['id']);
        $telegramBindingData = (array)($telegramBinding['data'] ?? []);

        $inviteInfo = $this->directBuildInviteInfo($user);
        $configMap = $this->buildSiteConfigMap();
        $walletAddress = $this->directBuildWalletAddressPayload($user);
        $bankCard = $this->directBuildBankCardPayload(BankCard::where('uid', $user['id'])->order('default_selection', 'desc')->order('id', 'desc')->find());
        $financeSummary = $this->directBuildFinanceSummaryPayload($user);

        return show(200, 'success', '查询成功', [
            'profile' => [
                'id' => (int)($user['id'] ?? 0),
                'username' => (string)($user['username'] ?? $user['mobile'] ?? ''),
                'avatar' => (string)($user['avatar'] ?? getConfig('user_avatar_image') ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
                'surname' => (string)($user['surname'] ?? ''),
                'birthday' => (string)($user['birthday'] ?? ''),
                'gender' => (int)($user['gender'] ?? 0),
                'city' => (string)($user['city'] ?? ''),
                'province' => (string)($user['province'] ?? ''),
                'city_name' => (string)($user['city_name'] ?? ''),
                'district' => (string)($user['district'] ?? ''),
                'trc20' => (string)($user['trc20'] ?? ''),
                'mobile' => (string)($user['mobile'] ?? ''),
            ],
            'accountSummary' => [
                'balance' => $this->directMoney($user['balance'] ?? 0),
                'available' => $this->directMoney($user['balance'] ?? 0),
                'frozen_amount' => $this->directMoney($user['frozen_amount'] ?? 0),
                'commission' => $this->directMoney($user['agent_wallet'] ?? 0),
                'points' => (int)($user['points'] ?? 0),
            ],
            'financeSummary' => $financeSummary,
            'walletAddress' => $walletAddress,
            'bankCard' => $bankCard,
            'accountBindings' => [
                'wallet_address' => $walletAddress,
                'bank_card' => $bankCard,
                'has_wallet_address' => (int)($walletAddress['is_bound'] ?? 0),
                'has_bank_card' => (int)($bankCard['is_bound'] ?? 0),
                'telegram' => $telegramBindingData,
                'has_telegram' => (int)($telegramBindingData['is_bound'] ?? 0),
            ],
            'inviteInfo' => [
                'invite_code' => (string)($inviteInfo['invite_code'] ?? ''),
                'invite_link' => (string)($inviteInfo['invite_link'] ?? ''),
                'invite_url' => (string)($inviteInfo['invite_link'] ?? ''),
                'invite_count' => (int)($inviteInfo['invite_count'] ?? 0),
                'poster' => (string)($inviteInfo['poster'] ?? ''),
                'qr_code' => (string)($inviteInfo['qr_code'] ?? ''),
            ],
            'siteInfo' => [
                'site_name' => (string)($configMap['website_name'] ?? config('site.web_site_title') ?? ''),
                'notice' => (string)($configMap['notice'] ?? $configMap['foreground_notice'] ?? ''),
            ],
        ]);
    }

    protected function handleApiAccountProfile()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) {
            return $this->apiError('用户不存在', 404);
        }
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

    protected function handleApiAccountSettings()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) {
            return $this->apiError('用户不存在', 404);
        }

        $telegramService = new UserTelegramService();
        $telegramBinding = $telegramService->getBindingStatusForUser((int)$user['id']);
        $telegramBindingData = (array)($telegramBinding['data'] ?? []);

        return show(200, 'success', '查询成功', [
            'mobile' => (string)($user['mobile'] ?? ''),
            'trc20' => (string)($user['trc20'] ?? ''),
            'twofa_enabled' => !empty($user['twofa_enabled']) ? 1 : 0,
            'tg_chat_id' => (string)($telegramBindingData['tg_chat_id'] ?? ''),
            'tg_username' => (string)($telegramBindingData['tg_username'] ?? ''),
            'tg_bound' => (int)($telegramBindingData['is_bound'] ?? 0),
            'telegram_binding' => $telegramBindingData,
            'has_wallet_address' => !empty($user['trc20']) ? 1 : 0,
        ]);
    }

    protected function handleApiAccountTelegramBindingStatus()
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        if ($userId <= 0) {
            return show(403, 'error', '未登录');
        }

        $telegramService = new UserTelegramService();
        $result = $telegramService->getBindingStatusForUser($userId);
        if (empty($result['success'])) {
            return show(500, 'error', (string)($result['message'] ?? '查询失败'));
        }

        return show(200, 'success', '查询成功', (array)($result['data'] ?? []));
    }

    protected function handleApiAccountTelegramBindingCode()
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        if ($userId <= 0) {
            return show(403, 'error', '未登录');
        }

        $telegramService = new UserTelegramService();
        $result = $telegramService->generateBindCodeForUser($userId);
        if (empty($result['success'])) {
            return show(500, 'error', (string)($result['message'] ?? '生成失败'));
        }

        return show(200, 'success', '绑定码生成成功', (array)($result['data'] ?? []));
    }

    protected function handleApiAccountTelegramUnbind()
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        if ($userId <= 0) {
            return show(403, 'error', '未登录');
        }

        $telegramService = new UserTelegramService();
        $result = $telegramService->unbindByUserId($userId);
        if (empty($result['success'])) {
            return show(500, 'error', (string)($result['message'] ?? '解绑失败'));
        }

        $statusResult = $telegramService->getBindingStatusForUser($userId);
        return show(200, 'success', (string)($result['message'] ?? '解绑成功'), (array)($statusResult['data'] ?? []));
    }

    protected function handleApiAccountProfileSave()
    {
        $post = $this->readRequestPayload();
        $user = UserModel::where('id', $this->user_info['id'] ?? 0)->find();
        if (!$user) {
            return $this->apiError('用户不存在', 404);
        }

        try {
            $fields = [];
            $locationKeys = ['province', 'city_name', 'district'];
            $requestedLocationKeys = array_values(array_filter($locationKeys, static fn ($key) => array_key_exists($key, $post)));
            if (!empty($requestedLocationKeys)) {
                $tableFields = $user->getConnection()->getTableFields($user->getTable());
                $fieldMap = is_array($tableFields) ? array_fill_keys($tableFields, true) : [];
                $missingLocationFields = array_values(array_filter($requestedLocationKeys, static fn ($key) => !isset($fieldMap[$key])));
                if (!empty($missingLocationFields)) {
                    return $this->apiError('当前数据库缺少字段：' . implode('、', $missingLocationFields), 500);
                }
            }

            if (array_key_exists('avatar', $post)) {
                $fields['avatar'] = $this->normalizeProfileUrl($post['avatar'] ?? '');
            }
            if (array_key_exists('nickname', $post)) {
                $fields['nickname'] = $this->normalizeProfileText($post['nickname'] ?? '');
            }
            if (array_key_exists('surname', $post)) {
                $fields['surname'] = $this->normalizeProfileText($post['surname'] ?? '');
            }
            if (array_key_exists('city', $post)) {
                $fields['city'] = (string)($post['city'] ?? '');
            }
            if (array_key_exists('province', $post)) {
                $fields['province'] = (string)($post['province'] ?? '');
            }
            if (array_key_exists('city_name', $post)) {
                $fields['city_name'] = (string)($post['city_name'] ?? '');
            }
            if (array_key_exists('district', $post)) {
                $fields['district'] = (string)($post['district'] ?? '');
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
                    'province' => (string)($user['province'] ?? ''),
                    'city_name' => (string)($user['city_name'] ?? ''),
                    'district' => (string)($user['district'] ?? ''),
                    'birthday' => (string)($user['birthday'] ?? ''),
                    'gender' => (int)($user['gender'] ?? 0),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('api_account_profile_save error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'post_keys' => is_array($post) ? array_keys($post) : [],
                'post_count' => is_array($post) ? count($post) : 0,
            ]);
            return $this->apiError('资料保存失败：' . $e->getMessage(), 500);
        }
    }

    protected function handleApiAccountPasswordSave()
    {
        $post = $this->readRequestPayload();
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

    protected function handleApiAccountWalletAddressSave()
    {
        return $this->apiFromLegacyResult($this->handleAccountSettingsPost('wallet_address'), '钱包地址修改成功');
    }

    protected function handleApiAccountBankCardSave()
    {
        $post = $this->request->post();
        $bankCardId = (string)($post['bank_card_id'] ?? $post['id'] ?? '');
        if ($bankCardId !== '') {
            $this->request->withPost(array_merge($post, ['bank_card_id' => $bankCardId]));
        }
        $result = $this->handleBankCardPost('submit');
        if (($post['default_selection'] ?? '') === '1' || (int)($post['default_selection'] ?? 0) === 1) {
            $card = BankCard::where('uid', $this->user_info['id'])->order('id', 'desc')->find();
            if ($card) {
                $this->request->withPost(['bank_card_id' => $card['id']]);
                $this->handleBankCardPost('default_selection');
            }
        }
        return $this->apiFromLegacyResult($result, '保存成功');
    }

    protected function handleApiAccountBankCardDelete()
    {
        $post = $this->request->post();
        $id = (string)($post['bank_card_id'] ?? $post['id'] ?? '');
        if ($id === '') {
            return $this->apiError('缺少收款信息ID', 400);
        }
        $this->request->withPost(['id' => $id, 'bank_card_id' => $id]);
        return $this->apiFromLegacyResult($this->handleBankCardPost('del'), '删除成功');
    }

    protected function handleApiAccountBankCardDefault()
    {
        $post = $this->request->post();
        $id = (string)($post['bank_card_id'] ?? $post['id'] ?? '');
        if ($id === '') {
            return $this->apiError('缺少收款信息ID', 400);
        }
        $this->request->withPost(['bank_card_id' => $id]);
        return $this->apiFromLegacyResult($this->handleBankCardPost('default_selection'), '切换成功');
    }

    protected function handleApiAccountWalletAddress()
    {
        $user = UserModel::where('id', $this->user_info['id'])->find();
        if (!$user) {
            return show(500, 'error', '用户不存在');
        }

        $walletAddress = $this->directBuildWalletAddressPayload($user);
        return show(200, 'success', '查询成功', array_merge($walletAddress, [
            'balance' => $this->directMoney($user['balance'] ?? 0),
            'frozen_amount' => $this->directMoney($user['frozen_amount'] ?? 0),
            'summary' => [
                'balance' => $this->directMoney($user['balance'] ?? 0),
                'frozen_amount' => $this->directMoney($user['frozen_amount'] ?? 0),
            ],
        ]));
    }

    protected function handleApiAccountBankCard()
    {
        $card = BankCard::where('uid', $this->user_info['id'])
            ->order('default_selection', 'desc')
            ->order('id', 'desc')
            ->find();

        $payload = $this->directBuildBankCardPayload($card);
        $message = !empty($payload['is_bound']) ? '查询成功' : '暂无收款信息';
        return show(200, 'success', $message, $payload);
    }
}
