<?php
declare(strict_types=1);

namespace app\service;

use app\model\Admin as AdminModel;
use RobThree\Auth\TwoFactorAuth;
use think\facade\Log;
use think\facade\Session;

/**
 * Auth/Security Domain 唯一编排 Service（B10-32 迁移自 AdminApi，行为等价）。
 *
 * 承载：
 *   - Authentication orchestration（login 2FA gate / recovery 判定）
 *   - 2FA orchestration（setup / enable / disable / regenerate / reset 所需编排）
 *   - Recovery lifecycle（生成 / 哈希 / 读取 / 一次性消费）
 *   - Session lifecycle（login rotation / logout destruction / pending 2FA state）
 *
 * 不承载 Security primitives：
 *   - TOTP / AES 加解密 / key / input validation / sensitive-operation verification
 *     统一委托 AdminSensitiveOperationGuard（唯一 SSOT），不复制其实现。
 *   - 登录限流委托 LoginRateLimiter；操作日志由 Controller 委托 AdminOperationLogService。
 */
class AdminAuthService
{
    /**
     * 2FA 验证调度：TOTP / 恢复码 二选一（迁移自 AdminApi::verifyAdminTwofaOrRecovery）。
     * 仅编排，TOTP 校验委托 Guard，恢复码消费走本 Service 恢复码生命周期。
     */
    public function verifyAdminTwofaOrRecovery(AdminModel $admin, array $postInfo, string $purpose = 'general'): array
    {
        $twofaCode = trim((string)($postInfo['twofa_code'] ?? ''));
        $recoveryCode = strtoupper(trim((string)($postInfo['recovery_code'] ?? '')));
        $verificationCode = trim((string)($postInfo['verification_code'] ?? ''));

        if ($verificationCode !== '') {
            if (preg_match('/^\d{6}$/', $verificationCode)) {
                $twofaCode = $verificationCode;
            } else {
                $recoveryCode = strtoupper($verificationCode);
            }
        }

        if ($twofaCode !== '' && $recoveryCode !== '') {
            return ['ok' => false, 'message' => '请只输入一种验证方式'];
        }

        if ($twofaCode !== '') {
            return $this->verifyAdminTwofaCode($admin, $twofaCode);
        }

        if ($recoveryCode !== '') {
            return $this->consumeAdminRecoveryCode($admin, $recoveryCode, $purpose);
        }

        return ['ok' => false, 'message' => '请输入二步验证码或恢复码'];
    }

    /**
     * 恢复码一次性消费（迁移自 AdminApi::consumeAdminRecoveryCode，行为等价）。
     * read → password_verify → unset consumed → save；成功后立即持久化，不可重放。
     * 恢复码并发窗口 = EXISTING / LOW / DEFERRED（本批次不新增锁/事务/schema 变更）。
     */
    public function consumeAdminRecoveryCode(AdminModel $admin, string $recoveryCode, string $purpose = 'general'): array
    {
        $recoveryCode = strtoupper(trim($recoveryCode));
        if ($recoveryCode === '') {
            return ['ok' => false, 'message' => '请输入恢复码'];
        }

        $hashes = $this->getAdminRecoveryCodeHashes((string)($admin->twofa_recovery_codes ?? ''));
        if ($hashes === []) {
            return ['ok' => false, 'message' => '当前账号没有可用恢复码'];
        }

        $matchedIndex = null;
        foreach ($hashes as $index => $hash) {
            if (password_verify($recoveryCode, (string)$hash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            return ['ok' => false, 'message' => '恢复码无效'];
        }

        unset($hashes[$matchedIndex]);
        $remainingHashes = array_values($hashes);
        $admin->twofa_recovery_codes = (string)json_encode($remainingHashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $admin->save();

        Log::info('admin 2fa recovery code consumed', [
            'admin_id' => (int)($admin->id ?? 0),
            'purpose' => $purpose,
            'remaining_count' => count($remainingHashes),
        ]);

        return [
            'ok' => true,
            'message' => '恢复码验证成功，该恢复码已失效',
            'mode' => 'recovery',
            'remaining_count' => count($remainingHashes),
        ];
    }

    /**
     * 恢复码生成（迁移自 AdminApi::generateAdminRecoveryCodes，行为等价）。
     * random_int 生成，A-Z0-9，默认 8×8；仅返回明文供一次性展示，持久化走 hash。
     */
    public function generateAdminRecoveryCodes(int $count = 8, int $length = 8): array
    {
        $codes = [];
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxIndex = strlen($chars) - 1;

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $length; $j++) {
                $code .= $chars[random_int(0, $maxIndex)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * 恢复码哈希（迁移自 AdminApi::hashAdminRecoveryCodes，行为等价）。
     * 仅保存 bcrypt hash（PASSWORD_BCRYPT），不保存明文。
     */
    public function hashAdminRecoveryCodes(array $recoveryCodes): string
    {
        $hashes = [];
        foreach ($recoveryCodes as $code) {
            $hashes[] = password_hash((string)$code, PASSWORD_BCRYPT);
        }

        return (string)json_encode($hashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 恢复码哈希读取与兼容规范化（迁移自 AdminApi::getAdminRecoveryCodeHashes，行为等价）。
     */
    public function getAdminRecoveryCodeHashes(string $stored): array
    {
        $stored = trim($stored);
        if ($stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        $hashes = [];
        foreach ($decoded as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $normalizedValue = strtoupper(trim($value));
            $hashes[] = password_get_info($value)['algo'] === 0
                ? password_hash($normalizedValue, PASSWORD_BCRYPT)
                : $value;
        }

        return $hashes;
    }

    /**
     * 2FA 绑定初始化（迁移自 AdminApi::beginAdminTwofaSetup，行为等价）。
     * secret 生成 / QR 由 TwoFactorAuth 提供；加密 key 委托 Guard::requireEncryptionKey；
     * pending 状态写入现有 Session keys。
     */
    public function beginAdminTwofaSetup(AdminModel $admin, bool $allowReset = false): array
    {
        $this->requireEncryptionKey();

        if (!$allowReset && ($admin->twofa_enabled || !empty($admin->twofa_secret))) {
            throw new \RuntimeException('您已开启2FA认证，无需重复设置');
        }

        $twofa = new TwoFactorAuth();
        $secret = $twofa->createSecret();
        $config = (array)getConfig();
        $issuer = trim((string)($config['name'] ?? $config['site_name'] ?? 'Admin Panel'));
        if ($issuer === '') {
            $issuer = 'Admin Panel';
        }

        $account = trim((string)($admin->account ?? 'admin_' . (int)($admin->id ?? 0)));
        $label = $issuer . ':' . $account;
        $qrCodeUrl = $twofa->getQRCodeImageAsDataUri($label, $secret, 300);
        $recoveryCodes = $this->generateAdminRecoveryCodes(8);

        $this->clearPendingAdminTwofaSetup();
        Session::set('admin_twofa_temp_secret', $secret);
        Session::set('admin_twofa_temp_recovery_codes', $recoveryCodes);
        Session::set('admin_twofa_temp_admin_id', (int)$admin->id);

        return [
            'secret' => $secret,
            'qr_code' => $qrCodeUrl,
        ];
    }

    /**
     * pending 2FA 状态清理（迁移自 AdminApi::clearPendingAdminTwofaSetup，行为等价）。
     * key 名冻结：admin_twofa_temp_secret / admin_twofa_temp_recovery_codes / admin_twofa_temp_admin_id。
     */
    public function clearPendingAdminTwofaSetup(): void
    {
        Session::delete('admin_twofa_temp_secret');
        Session::delete('admin_twofa_temp_recovery_codes');
        Session::delete('admin_twofa_temp_admin_id');
    }

    /**
     * 登录验证码类型判定（迁移自 AdminApi::detectAdminLoginVerificationAttempt，行为等价）。
     */
    public function detectAdminLoginVerificationAttempt(string $verificationCode): string
    {
        $verificationCode = trim($verificationCode);
        if ($verificationCode === '') {
            return 'missing';
        }

        return preg_match('/^\d{6}$/', $verificationCode) ? 'twofa' : 'recovery';
    }

    /**
     * 登录 Session 轮换 + 管理员身份建立（迁移自 AdminApi::rotateSessionForAdminLogin，行为等价）。
     * 顺序冻结：delete admin → clear pending → regenerate(true)（防 fixation）→ set admin。
     */
    public function rotateSessionForAdminLogin(array $adminData): void
    {
        Session::delete('admin');
        $this->clearPendingAdminTwofaSetup();
        Session::regenerate(true);
        Session::set('admin', $adminData);
    }

    /**
     * 登出 Session 销毁（迁移自 AdminApi::destroyAdminSession，行为等价）。
     * 保留 user identity → 删 admin identity → clear/destroy → 恢复 user identity。
     */
    public function destroyAdminSession(): void
    {
        $preserved = [];
        foreach (['user'] as $key) {
            if (Session::has($key)) {
                $preserved[$key] = Session::get($key);
            }
        }

        Session::delete('admin');
        $this->clearPendingAdminTwofaSetup();
        Session::clear();
        Session::destroy();

        foreach ($preserved as $key => $value) {
            Session::set($key, $value);
        }
    }

    /**
     * 敏感数据加密（迁移自 AdminApi::encryptAdminData，行为等价）。
     * 仅委托 Guard::requireEncryptionKey() 获取 key，openssl AES-256-CBC 为 PHP 标准库调用
     * （与 Guard::decryptAdminData 对称），不复制 Guard 实现。
     */
    public function encryptAdminData(string $data): string
    {
        $key = $this->requireEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new \Exception('敏感数据加密失败');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * 2FA 验证码输入校验（委托 Guard，唯一 SSOT）。
     */
    public function validateAdminTwofaCodeInput(string $code): ?string
    {
        return $this->guard()->validateAdminTwofaCodeInput($code);
    }

    /**
     * 2FA TOTP 校验（委托 Guard::verifyAdminTwofaCode，唯一 SSOT）。
     */
    public function verifyAdminTwofaCode(AdminModel $admin, string $code, int $window = 2): array
    {
        return $this->guard()->verifyAdminTwofaCode($admin, $code, $window);
    }

    /**
     * 使用临时 secret 校验 TOTP（enable 阶段，迁移自 AdminApi 内 new TwoFactorAuth()->verifyCode）。
     * 2FA 原语由 TwoFactorAuth 库承载，本方法仅为编排封装。
     */
    public function verifyTwofaCodeWithSecret(string $secret, string $code, int $window = 2): bool
    {
        $twofa = new TwoFactorAuth();

        return $twofa->verifyCode($secret, $code, $window);
    }

    protected function requireEncryptionKey(): string
    {
        return $this->guard()->requireEncryptionKey();
    }

    protected function guard(): AdminSensitiveOperationGuard
    {
        return app(AdminSensitiveOperationGuard::class);
    }
}
