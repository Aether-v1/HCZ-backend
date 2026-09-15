<?php
declare (strict_types = 1);

namespace app\service;

use app\common\SecurityKeyResolver;
use app\model\Admin as AdminModel;
use think\facade\Log;
use think\Request;
use RobThree\Auth\TwoFactorAuth;
use Exception;

/**
 * HCZ B08: 管理员敏感操作二次验证 Guard（TwoFactor 邻接，C1 已授权行为等价搬移）。
 *
 * 安全边界（代码强制，非 DocBlock）：
 *  - 身份来源：构造注入 Request，当前管理员一律取自 Session('admin')；
 *    入口 verifySensitiveOperation() 不接受任何 admin_id / AdminModel 参数 → 无法验证任意管理员、无法伪造身份、无法绕过当前 session。
 *  - 本 Guard 不承担 AdminAuth / RBAC / CSRF（均在中间件与控制器层）；仅复核当前操作者身份。
 *  - 不依赖 AdminApi / 任何 Controller（依赖仅：Request / SecurityKeyResolver / AdminModel / TwoFactorAuth）。
 *
 * 与原 AdminApi 私有原语行为完全等价（window=2、错误文案、异常语义、日志、返回结构）：
 *  - verifySensitiveOperation   ← AdminApi::directValidateSensitiveOperation
 *  - verifyAdminTwofaCode       ← AdminApi::verifyAdminTwofaCode
 *  - decryptAdminData           ← AdminApi::decryptAdminData
 *  - requireEncryptionKey       ← AdminApi::requireEncryptionKey
 *  - validateAdminTwofaCodeInput← AdminApi::validateAdminTwofaCodeInput
 */
class AdminSensitiveOperationGuard
{
    protected Request $request;
    protected string $encryptionKey = '';
    protected bool $encryptionKeyLoaded = false;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?: app()->request;
    }

    /**
     * 敏感操作二次验证（OLD AdminApi::directValidateSensitiveOperation 行为等价）。
     * 管理员身份由当前 Session 强制确定，不接受外部传入任意 admin_id / AdminModel。
     *
     * @param array  $postInfo 请求参数（含 twofa_code / verify_code / admin_password）
     * @param string $scene    敏感场景标识（用于日志）
     * @return array ['ok'=>bool, 'message'=>string, 'mode'=>'twofa'|'password']
     */
    public function verifySensitiveOperation(array $postInfo, string $scene): array
    {
        $adminIdentity = (array)$this->request->session('admin', []);
        $adminId = (int)($adminIdentity['id'] ?? 0);
        $admin = $adminId > 0 ? AdminModel::find($adminId) : null;
        if (!$admin) {
            return ['ok' => false, 'message' => '管理员未登录'];
        }

        if (!empty($admin->twofa_enabled)) {
            $twofaCode = trim((string)($postInfo['twofa_code'] ?? $postInfo['verify_code'] ?? ''));
            $verified = $this->verifyAdminTwofaCode($admin, $twofaCode);
            if (empty($verified['ok'])) {
                Log::warning('admin sensitive action missing 2fa', ['scene' => $scene, 'admin_id' => $adminId, 'ip' => (string)$this->request->ip()]);
                return ['ok' => false, 'message' => (string)($verified['message'] ?? '二步验证码不正确')];
            }

            return ['ok' => true, 'mode' => 'twofa'];
        }

        $adminPassword = trim((string)($postInfo['admin_password'] ?? ''));
        if ($adminPassword === '') {
            Log::warning('admin sensitive action missing password', ['scene' => $scene, 'admin_id' => $adminId, 'ip' => (string)$this->request->ip()]);
            return ['ok' => false, 'message' => '请输入当前管理员密码'];
        }

        if (!password_verify($adminPassword . (string)($admin->salt ?? ''), (string)($admin->password ?? ''))) {
            Log::warning('admin sensitive action invalid password', ['scene' => $scene, 'admin_id' => $adminId, 'ip' => (string)$this->request->ip()]);
            return ['ok' => false, 'message' => '当前管理员密码错误'];
        }

        return ['ok' => true, 'mode' => 'password'];
    }

    /**
     * 校验 6 位 2FA 验证码（OLD AdminApi::verifyAdminTwofaCode 行为等价）。
     * @param AdminModel $admin  目标管理员（由调用方从已认证身份取得；本方法仅做校验，不自行取身份）
     * @param string     $code   用户输入的验证码
     * @param int        $window 时间窗口（默认 2，与 OLD 一致）
     * @return array
     */
    public function verifyAdminTwofaCode(AdminModel $admin, string $code, int $window = 2): array
    {
        $inputError = $this->validateAdminTwofaCodeInput($code);
        if ($inputError !== null) {
            return ['ok' => false, 'message' => $inputError];
        }

        if (empty($admin->twofa_enabled) || empty($admin->twofa_secret)) {
            return ['ok' => false, 'message' => '当前管理员未正确配置2FA'];
        }

        try {
            $secret = $this->decryptAdminData((string)$admin->twofa_secret);
            if ($secret === '') {
                return ['ok' => false, 'message' => '2FA密钥异常，请重新绑定'];
            }

            $twofa = new TwoFactorAuth();
            if (!$twofa->verifyCode($secret, $code, $window)) {
                return ['ok' => false, 'message' => '二步验证码不正确'];
            }
        } catch (\Throwable $e) {
            Log::warning('admin 2fa verify failed: ' . $e->getMessage(), [
                'admin_id' => (int)($admin->id ?? 0),
                'line' => $e->getLine(),
            ]);
            return ['ok' => false, 'message' => '2FA验证失败，请稍后重试'];
        }

        return ['ok' => true, 'message' => 'ok', 'mode' => 'twofa'];
    }

    /**
     * 解密管理员 2FA 敏感数据（OLD AdminApi::decryptAdminData 行为等价）。
     */
    public function decryptAdminData(string $data): string
    {
        $key = $this->requireEncryptionKey();
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new Exception('敏感数据格式无效');
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($decoded) <= $ivLength) {
            throw new Exception('敏感数据格式无效');
        }

        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);

        if (!is_string($decrypted)) {
            throw new Exception('敏感数据解密失败');
        }

        return $decrypted;
    }

    /**
     * 校验 2FA 验证码输入格式（OLD AdminApi::validateAdminTwofaCodeInput 行为等价）。
     */
    public function validateAdminTwofaCodeInput(string $code): ?string
    {
        if ($code === '') {
            return '请输入二步验证码';
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return '请输入6位二步验证码';
        }

        return null;
    }

    /**
     * 获取数据加密密钥（OLD AdminApi::requireEncryptionKey 行为等价，含进程内缓存）。
     */
    public function requireEncryptionKey(): string
    {
        if ($this->encryptionKeyLoaded) {
            return $this->encryptionKey;
        }

        $this->encryptionKey = SecurityKeyResolver::resolveDataEncryptionKey();
        $this->encryptionKeyLoaded = true;

        return $this->encryptionKey;
    }
}
