<?php
declare (strict_types=1);

namespace app\controller;
use app\common\SecurityKeyResolver;
use app\controller\indexapi\AgentActions;
use app\controller\indexapi\AccountActions;
use app\controller\indexapi\AuthActions;
use app\controller\indexapi\PointsActions;
use app\controller\indexapi\TransactionActions;
use app\controller\indexapi\MessageActions;
use app\controller\indexapi\SiteActions;
use app\controller\indexapi\OrderActions;
use app\controller\indexapi\FinanceActions;
use app\controller\indexapi\TwoFactorActions;
use app\controller\indexapi\UtilityActions;
use app\service\UserFundLedgerService;
use app\service\UploadService;
use app\service\telegram\OrderTelegramNotifier;
use app\common\service\SubstationPriceService;
use app\common\service\SubstationService;
use app\middleware\UserAuth;
use app\model\User as UserModel;
use app\model\Order;
use app\model\Config as ConfigModel;
use app\model\Recharge;
use app\model\RebateRecord;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\UserBalanceLog;
// 2FA相关引入
use RobThree\Auth\TwoFactorAuth;

use Exception;
use think\App;
use think\exception\HttpResponseException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\Request;
use think\facade\Log;

class IndexApi
{
    use AgentActions;
    use AccountActions;
    use AuthActions;
    use PointsActions;
    use OrderActions;
    use FinanceActions;
    use MessageActions;
    use TransactionActions;
    use TwoFactorActions;
    use UtilityActions;
    use SiteActions;

    /**
     * Request实例
     * @var Request
     */
    protected Request $request;

    /**
     * 应用实例
     * @var App
     */
    protected App $app;
    protected mixed $user_info;
    protected string|array|bool $config = [];
    protected array $middleware = [
        UserAuth::class => [
            'except' => [
                'get_csrf_token',
                'login_post',
                'register_post',
                'epay_notify_url',
                'api_callback_bepusdt',
                'api_site_config',
                'index_api_site_config',
                'api_home_bootstrap',
                'phone_meta',
                'api_product_detail',
                'api_proof_recharge_view',
                'api_proof_trade_view',
            ],
        ],
    ];

    protected string $encryptionKey = '';
    protected bool $encryptionKeyLoaded = false;
    
    // 私钥存储属性
    protected ?string $privateKey = null;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录用户信息写入至私有属性
        $this->user_info = $this->request->session('user');
        $this->config = getConfig();
    }

    private function csrfTokenName(): string
    {
        $csrfConfig = (array)config('app.csrf');

        return (string)($csrfConfig['token_name'] ?? '_csrf_token');
    }

    private function generateCsrfToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            Log::warning('csrf token fallback used: ' . $e->getMessage());
        }

        return bin2hex(openssl_random_pseudo_bytes(32));
    }

    private function issueCsrfToken(bool $force = false): string
    {
        $tokenName = $this->csrfTokenName();
        $token = (string)Session::get($tokenName, '');

        if ($force || $token === '') {
            $token = $this->generateCsrfToken();
            Session::set($tokenName, $token);
        }

        return $token;
    }

    private function rotateSessionForUserLogin(array $userData, string $ip): void
    {
        $userData = $this->sanitizeUserSessionData($userData);
        $userData['login_ip'] = $ip;

        Session::delete('user');
        Session::delete('mobile');
        Session::delete('remember_password');
        Session::delete('twofa_temp_secret');
        Session::delete('twofa_temp_recovery_codes');
        Session::delete('twofa_temp_user_id');
        Session::regenerate(true);

        Session::set('user', $userData);
        Session::set('user.login_ip', $ip);
        $this->issueCsrfToken(true);
    }

    private function destroyUserSession(): void
    {
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

    private function sanitizeUserSessionData(array $userData): array
    {
        foreach (['password', 'salt', 'token', 'twofa_secret', 'twofa_recovery_codes'] as $sensitiveKey) {
            unset($userData[$sensitiveKey]);
        }

        return $userData;
    }

    private function clearPendingTwofaSetup(): void
    {
        Session::delete('twofa_temp_secret');
        Session::delete('twofa_temp_recovery_codes');
        Session::delete('twofa_temp_user_id');
    }

    private function getGatewayTradeId(array $payload): string
    {
        return (string)($payload['trade_id'] ?? ($payload['id'] ?? ''));
    }

    private function hashGatewayToken($token): string
    {
        $token = trim((string)$token);
        if ($token === '') {
            return '';
        }

        return substr(hash('sha256', $token), 0, 12);
    }

    private function maskGatewayTxid($txid): string
    {
        $txid = trim((string)$txid);
        $length = strlen($txid);
        if ($txid === '') {
            return '';
        }

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        if ($length <= 12) {
            return substr($txid, 0, 3) . str_repeat('*', $length - 6) . substr($txid, -3);
        }

        return substr($txid, 0, 6) . str_repeat('*', $length - 12) . substr($txid, -6);
    }

    protected function logApiException(string $scene, \Throwable $e, array $context = []): void
    {
        $userId = (int)($this->user_info['id'] ?? 0);
        $admin = $this->request->session('admin');
        $adminId = is_array($admin) ? (int)($admin['id'] ?? 0) : 0;

        $logContext = array_merge([
            'scene' => $scene,
            'message' => $this->sanitizeExceptionLogValue($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $this->request->url(),
            'user_id' => $userId > 0 ? $userId : null,
            'admin_id' => $adminId > 0 ? $adminId : null,
        ], $this->sanitizeExceptionLogContext($context));

        Log::error('api exception', $logContext);
    }

    protected function sanitizeExceptionLogContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $sanitized[$key] = $this->sanitizeExceptionLogEntry((string)$key, $value);
        }

        return $sanitized;
    }

    protected function sanitizeExceptionLogEntry(string $key, $value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $childLabel = is_int($childKey) ? $key . '_' . $childKey : (string)$childKey;
                $sanitized[$childKey] = $this->sanitizeExceptionLogEntry($childLabel, $childValue);
            }

            return $sanitized;
        }

        if (!is_scalar($value) && $value !== null) {
            return '[' . gettype($value) . ']';
        }

        return $this->sanitizeExceptionLogValue($value, $key);
    }

    protected function sanitizeExceptionLogValue($value, string $key = ''): string
    {
        $string = trim((string)$value);
        if ($string === '') {
            return $string;
        }

        $lowerKey = strtolower($key);
        if ($lowerKey !== '' && preg_match('/token|secret|key|sign/', $lowerKey)) {
            return '[masked]';
        }

        if (preg_match('/^1\d{10}$/', $string)) {
            return substr($string, 0, 3) . '****' . substr($string, -4);
        }

        if (preg_match('/^(T|0x)[A-Za-z0-9]{12,}$/', $string)) {
            return substr($string, 0, 6) . '...' . substr($string, -4);
        }

        return $string;
    }

    private function buildGatewayNotifyPayload(array $payload): string
    {
        return json_encode([
            'order_id' => (string)($payload['order_id'] ?? ''),
            'status' => (string)($payload['status'] ?? ''),
            'amount' => $payload['amount'] ?? null,
            'actual_amount' => $payload['actual_amount'] ?? null,
            'trade_id' => $this->getGatewayTradeId($payload),
            'txid' => $this->maskGatewayTxid($payload['block_transaction_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function beginUserTwofaSetup(UserModel $user, bool $allowReset = false): array
    {
        $this->requireEncryptionKey();

        if (!$allowReset && ($user->twofa_enabled || !empty($user->twofa_secret))) {
            throw new \RuntimeException('您已开启2FA认证，无需重复设置');
        }

        $twofa = new TwoFactorAuth();
        $secret = $twofa->createSecret();
        $issuer = $this->config['site_name'] ?? 'My Site';
        $label = "{$issuer}:{$user->mobile}";
        $qrCodeUrl = $twofa->getQRCodeImageAsDataUri($label, $secret, 300);
        $recoveryCodes = $this->generateRecoveryCodes(8);

        $this->clearPendingTwofaSetup();
        Session::set('twofa_temp_secret', $secret);
        Session::set('twofa_temp_recovery_codes', $recoveryCodes);
        Session::set('twofa_temp_user_id', (int)$user->id);

        return [
            'secret' => $secret,
            'qr_code' => $qrCodeUrl,
        ];
    }

    private function validateTwofaCodeInput(string $code): ?string
    {
        if ($code === '') {
            return '请输入2FA动态验证码';
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return '请输入6位2FA动态验证码';
        }

        return null;
    }

    private function userTwofaAttemptKey(UserModel $user): string
    {
        return '2fa_attempt:' . (int)($user->id ?? 0) . ':' . (string)$this->request->ip();
    }

    private function getUserTwofaAttemptState(UserModel $user): array
    {
        $key = $this->userTwofaAttemptKey($user);

        try {
            $state = Cache::store('redis')->get($key, []);
        } catch (\Throwable) {
            $state = Cache::get($key, []);
        }

        return is_array($state) ? $state : [];
    }

    private function storeUserTwofaAttemptState(UserModel $user, array $state, int $ttl): void
    {
        $key = $this->userTwofaAttemptKey($user);

        try {
            Cache::store('redis')->set($key, $state, $ttl);
        } catch (\Throwable) {
        }

        Cache::set($key, $state, $ttl);
    }

    private function clearUserTwofaAttemptState(UserModel $user): void
    {
        $key = $this->userTwofaAttemptKey($user);

        try {
            Cache::store('redis')->delete($key);
        } catch (\Throwable) {
        }

        Cache::delete($key);
    }

    private function abortUserTwofaRateLimit(): void
    {
        // 防 2FA/恢复码暴力破解：命中限速后直接返回 429，避免继续进入原校验流程。
        throw new HttpResponseException(show(429, 'error', '尝试过多，请5分钟后再试', [], 429));
    }

    private function assertUserTwofaAttemptAllowed(UserModel $user): void
    {
        // 防 2FA/恢复码暴力破解：用户 + IP 维度校验 5 分钟锁定状态。
        $state = $this->getUserTwofaAttemptState($user);
        $now = time();
        $lockedUntil = (int)($state['locked_until'] ?? 0);
        $firstFailedAt = (int)($state['first_failed_at'] ?? 0);
        $count = (int)($state['count'] ?? 0);

        if ($firstFailedAt > 0 && ($firstFailedAt + 300) <= $now && $lockedUntil <= $now) {
            $this->clearUserTwofaAttemptState($user);
            return;
        }

        if ($lockedUntil > $now) {
            $this->abortUserTwofaRateLimit();
        }

        if ($count >= 5 && $firstFailedAt > 0 && ($firstFailedAt + 300) > $now) {
            $state['locked_until'] = $now + 300;
            $this->storeUserTwofaAttemptState($user, $state, 300);
            $this->abortUserTwofaRateLimit();
        }
    }

    private function recordUserTwofaAttemptFailure(UserModel $user): void
    {
        // 防 2FA/恢复码暴力破解：每次校验失败累计次数，第 5 次失败锁定 5 分钟。
        $state = $this->getUserTwofaAttemptState($user);
        $now = time();
        $firstFailedAt = (int)($state['first_failed_at'] ?? 0);

        if ($firstFailedAt <= 0 || ($firstFailedAt + 300) <= $now) {
            $state = [
                'count' => 0,
                'first_failed_at' => $now,
                'locked_until' => 0,
            ];
            $firstFailedAt = $now;
        }

        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $state['first_failed_at'] = $firstFailedAt;
        $state['locked_until'] = (int)($state['locked_until'] ?? 0);
        if ((int)$state['count'] >= 5) {
            $state['locked_until'] = $now + 300;
            $this->storeUserTwofaAttemptState($user, $state, 300);
            $this->abortUserTwofaRateLimit();
        }

        $ttl = max(1, ($firstFailedAt + 300) - $now);
        $this->storeUserTwofaAttemptState($user, $state, $ttl);
    }

    private function verifyUserTwofaCode(UserModel $user, string $code, int $window = 2): array
    {
        $this->assertUserTwofaAttemptAllowed($user);
        $inputError = $this->validateTwofaCodeInput($code);
        if ($inputError !== null) {
            return ['ok' => false, 'message' => $inputError];
        }

        if (empty($user->twofa_enabled) || empty($user->twofa_secret)) {
            return ['ok' => false, 'message' => '当前账号未正确配置2FA，请联系管理员或使用恢复码'];
        }

        try {
            $secret = $this->decryptData((string)$user->twofa_secret);
            if ($secret === '') {
                return ['ok' => false, 'message' => '2FA密钥异常，请使用恢复码或重新绑定'];
            }

            $twofa = new TwoFactorAuth();
            if (!$twofa->verifyCode($secret, $code, $window)) {
                $this->recordUserTwofaAttemptFailure($user);
                return ['ok' => false, 'message' => '2FA验证码无效，请确认后重新输入'];
            }
        } catch (\Throwable $e) {
            Log::warning('user 2fa verify failed: ' . $e->getMessage(), [
                'user_id' => (int)($user->id ?? 0),
                'line' => $e->getLine(),
            ]);
            return ['ok' => false, 'message' => '2FA验证失败，请稍后重试'];
        }

        $this->clearUserTwofaAttemptState($user);

        return ['ok' => true, 'message' => 'ok'];
    }

    private function verifySensitiveActionCredential(UserModel $user, array $postInfo): ?\think\response\Json
    {
        if (!empty($user->twofa_enabled)) {
            $twofaCode = trim((string)($postInfo['twofa_code'] ?? ''));
            $twofaResult = $this->verifyUserTwofaCode($user, $twofaCode);
            if (empty($twofaResult['ok'])) {
                return show(500, 'error', (string)($twofaResult['message'] ?? '2FA验证失败'));
            }

            return null;
        }

        $password = trim((string)($postInfo['password'] ?? ''));
        if ($password === '') {
            return show(500, 'error', '请输入登录密码');
        }

        if (!password_verify($password . (string)($user->salt ?? ''), (string)($user->password ?? ''))) {
            return show(500, 'error', '登录密码错误');
        }

        return null;
    }

    private function hashRecoveryCodes(array $recoveryCodes): string
    {
        $hashes = [];
        foreach ($recoveryCodes as $code) {
            $hashes[] = password_hash((string)$code, PASSWORD_BCRYPT);
        }

        return (string)json_encode($hashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeLegacyRecoveryCodes(string $stored): array
    {
        $candidates = [];

        $decrypted = $this->decryptDataSafely($stored);
        if ($decrypted !== '') {
            $candidates[] = $decrypted;
        }

        $candidates[] = $stored;

        foreach ($candidates as $candidate) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string)$candidate)), static function ($code) {
                return $code !== '';
            }));

            if ($codes !== []) {
                return $codes;
            }
        }

        return [];
    }

    private function getRecoveryCodeHashes(string $stored): array
    {
        $stored = trim($stored);
        if ($stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            $hashes = [];
            foreach ($decoded as $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }

                $hashes[] = password_get_info($value)['algo'] === 0
                    ? password_hash($value, PASSWORD_BCRYPT)
                    : $value;
            }

            return $hashes;
        }

        $legacyCodes = $this->decodeLegacyRecoveryCodes($stored);
        if ($legacyCodes === []) {
            return [];
        }

        return array_map(static function ($code) {
            return password_hash((string)$code, PASSWORD_BCRYPT);
        }, $legacyCodes);
    }

    private function consumeUserRecoveryCode(UserModel $user, string $recoveryCode, string $purpose = 'general'): array
    {
        $this->assertUserTwofaAttemptAllowed($user);
        $recoveryCode = strtoupper(trim($recoveryCode));
        if ($recoveryCode === '') {
            return ['ok' => false, 'message' => '请输入恢复码'];
        }

        $hashes = $this->getRecoveryCodeHashes((string)$user->twofa_recovery_codes);
        if ($hashes === []) {
            return ['ok' => false, 'message' => '未开启2FA或无可用恢复码'];
        }

        $matchedIndex = null;
        foreach ($hashes as $index => $hash) {
            if (password_verify($recoveryCode, (string)$hash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            $this->recordUserTwofaAttemptFailure($user);
            return ['ok' => false, 'message' => '无效的恢复码'];
        }

        unset($hashes[$matchedIndex]);
        $remainingHashes = array_values($hashes);

        $user->twofa_recovery_codes = (string)json_encode($remainingHashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message = '恢复码验证成功，该恢复码已失效';

        $user->save();

        Log::info('user 2fa recovery code consumed', [
            'user_id' => (int)($user->id ?? 0),
            'purpose' => $purpose,
            'remaining_count' => count($remainingHashes),
        ]);

        $this->clearUserTwofaAttemptState($user);

        return [
            'ok' => true,
            'message' => $message,
            'remaining_count' => count($remainingHashes),
        ];
    }

    private function verifyTwofaOrRecoveryForCurrentUser(UserModel $user, array $postInfo, string $purpose = 'general'): array
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
            $verified = $this->verifyUserTwofaCode($user, $twofaCode);
            if (!empty($verified['ok'])) {
                $verified['method'] = 'totp';
            }
            return $verified;
        }

        if ($recoveryCode !== '') {
            $verified = $this->consumeUserRecoveryCode($user, $recoveryCode, $purpose);
            if (!empty($verified['ok'])) {
                $verified['method'] = 'recovery_code';
            }
            return $verified;
        }

        return ['ok' => false, 'message' => '请输入当前有效的2FA动态验证码或恢复码'];
    }

    private function verifyPasswordAndTwofaChallengeForCurrentUser(UserModel $user, array $postInfo, string $purpose = 'general'): array
    {
        $password = trim((string)($postInfo['password'] ?? ''));
        if ($password === '') {
            return ['ok' => false, 'message' => '请输入当前登录密码'];
        }

        if (!password_verify(($password . $user->salt), $user->password)) {
            return ['ok' => false, 'message' => '登录密码验证失败'];
        }

        $verified = $this->verifyTwofaOrRecoveryForCurrentUser($user, $postInfo, $purpose);
        if (empty($verified['ok'])) {
            return $verified;
        }

        $verified['password_verified'] = true;
        return $verified;
    }

    private function verifyPasswordAndCurrentTwofaCodeForCurrentUser(UserModel $user, array $postInfo): array
    {
        $password = trim((string)($postInfo['password'] ?? ''));
        if ($password === '') {
            return ['ok' => false, 'message' => '请输入当前登录密码'];
        }

        if (!password_verify(($password . $user->salt), $user->password)) {
            return ['ok' => false, 'message' => '登录密码验证失败'];
        }

        $twofaCode = trim((string)($postInfo['twofa_code'] ?? ''));
        $verificationCode = trim((string)($postInfo['verification_code'] ?? ''));
        if ($twofaCode === '' && $verificationCode !== '') {
            $twofaCode = $verificationCode;
        }

        $verified = $this->verifyUserTwofaCode($user, $twofaCode);
        if (empty($verified['ok'])) {
            return $verified;
        }

        $verified['password_verified'] = true;
        $verified['method'] = 'totp';
        return $verified;
    }

    private function normalizeProfileText($value, int $maxLength = 255): string
    {
        $text = strip_tags((string)$value);
        $text = trim($text);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function normalizeProfileUrl($value, int $maxLength = 1000): string
    {
        $url = sanitizeImageUrl($value, '');

        if ($url === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($url, 0, $maxLength);
        }

        return substr($url, 0, $maxLength);
    }
    
    private function directLockUser(int $uid)
    {
        if ($uid <= 0) {
            return null;
        }

        return UserModel::where('id', $uid)->lock(true)->find();
    }

    /**
     * 获取CSRF令牌
     */
    public function get_csrf_token()
    {
        try {
            $csrfEnabled = config('app.csrf_enabled');
            if (empty($csrfEnabled)) {
                Log::warning('CSRF令牌获取失败: CSRF功能未启用');
                return show(400, 'error', 'CSRF功能未启用');
            }

            $csrfConfig = config('app.csrf');
            if (empty($csrfConfig) || !isset($csrfConfig['token_name'], $csrfConfig['expire'])) {
                Log::error('CSRF令牌获取失败: CSRF配置不完整', [
                    'csrf_config' => $csrfConfig
                ]);
                return show(500, 'error', 'CSRF配置不完整');
            }

            if (empty(Session::getId())) {
                $sessionStarted = Session::start();
                if (!$sessionStarted) {
                    Log::error('CSRF令牌获取失败: 会话启动失败');
                    return show(500, 'error', '会话初始化失败');
                }
            }

            $csrfToken = $this->issueCsrfToken();

            return show(200, 'success', '获取CSRF令牌成功', [
                'csrf_token' => $csrfToken,
                'expire' => $csrfConfig['expire'],
                'token_name' => $csrfConfig['token_name']
            ]);
        } catch (Exception $e) {
            $this->logApiException('get_csrf_token', $e);
            return show(500, 'error', '系统繁忙，请稍后再试');
        }
    }

    public function api_auth_csrf()
    {
        return $this->get_csrf_token();
    }

    public function api_auth_twofa_recover()
    {
        return $this->handleTwofaRecover();
    }
    
    /**
     * 初始化用户2FA绑定
     */
    public function twofa_init()
    {
        return $this->handleTwofaInit();
    }
    
    /**
     * 验证并完成用户2FA绑定
     */
    public function twofa_verify()
    {
        return $this->handleTwofaVerify();
    }
    
/**
     * 禁用用户2FA
     */
    public function twofa_disable()
    {
        return $this->handleTwofaDisable();
    }

    public function twofa_reset()
    {
        return $this->handleTwofaReset();
    }
    
    /**
     * 重新生成备用码
     */
    public function twofa_regenerate_recovery_codes()
    {
        return $this->handleTwofaRegenerateRecoveryCodes();
    }
    
    /**
     * 用户使用2FA恢复码登录或恢复
     */
    public function twofa_recover()
    {
        return $this->handleTwofaRecover();
    }
    
    /**
     * 生成恢复码
     */
    protected function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }
        return $codes;
    }
    
    // 密码验证
    public function verify_password()
    {
        return $this->handleVerifyPassword();
    }

    /**
     * 加密数据
     */
    protected function encryptData(string $data): string
    {
        $key = $this->requireEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new Exception('敏感数据加密失败');
        }
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * 解密数据
     */
    protected function decryptData(string $data): string
    {
        $key = $this->requireEncryptionKey();
        $data = base64_decode($data, true);
        if ($data === false) {
            throw new Exception('敏感数据格式无效');
        }
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) <= $ivLength) {
            throw new Exception('敏感数据格式无效');
        }
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
        if (!is_string($decrypted)) {
            throw new Exception('敏感数据解密失败');
        }

        return $decrypted;
    }

    protected function decryptDataSafely(string $data): string
    {
        if (trim($data) === '') {
            return '';
        }

        try {
            $decrypted = $this->decryptData($data);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($decrypted) ? $decrypted : '';
    }

    private function requireEncryptionKey(): string
    {
        if ($this->encryptionKeyLoaded) {
            return $this->encryptionKey;
        }

        $this->encryptionKey = SecurityKeyResolver::resolveDataEncryptionKey();
        $this->encryptionKeyLoaded = true;

        return $this->encryptionKey;
    }

    private function currentUserId(): int
    {
        return (int)($this->user_info['id'] ?? 0);
    }

    private function currentAdminInfo(): array
    {
        $admin = $this->request->session('admin');
        return is_array($admin) ? $admin : [];
    }

    private function currentAdminId(): int
    {
        return (int)($this->currentAdminInfo()['id'] ?? 0);
    }

    private function proofPrivateRoot(): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'private';
    }

    private function buildProofStorageDirectory(string $scene): string
    {
        return 'proofs/' . trim($scene, '/') . '/' . date('Ym');
    }

    private function buildProofBasename(string $orderNumber): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($orderNumber)) ?? '';
        $prefix = trim($prefix, '._-');
        if ($prefix === '') {
            $prefix = 'proof';
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $fallback = openssl_random_pseudo_bytes(6);
            if ($fallback === false) {
                throw new Exception('凭证文件名生成失败');
            }
            $suffix = bin2hex($fallback);
        }

        return strtolower($prefix . '_' . $suffix);
    }

    private function normalizeStoredProofReference(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            try {
                $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
                if ($path !== '') {
                    $value = $path;
                }
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $value;
    }

    private function isPrivateProofPath(string $value): bool
    {
        return $this->normalizePrivateProofRelativePath($value) !== '';
    }

    private function normalizePrivateProofRelativePath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($normalized === '') {
            return '';
        }

        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return '';
            }

            $segments[] = $segment;
        }

        if ($segments === [] || $segments[0] !== 'proofs') {
            return '';
        }

        return implode('/', $segments);
    }

    private function isLegacyPublicProofPath(string $value): bool
    {
        $normalized = '/' . ltrim($this->normalizeStoredProofReference($value), '/');
        return (bool)preg_match('#^/(storage|upload|uploads)/#i', $normalized);
    }

    private function isLikelyBase64ImagePayload(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'data:image/')) {
            return true;
        }

        return strlen($value) > 256 && (bool)preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $value);
    }

    private function detectImageExtensionFromFile(string $absolutePath): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $absolutePath);
                finfo_close($finfo);
            }
        }

        if ($mime === '') {
            $imageInfo = @getimagesize($absolutePath);
            $mime = (string)($imageInfo['mime'] ?? '');
        }

        return match (strtolower($mime)) {
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
    }

    private function detectImageMimeFromFile(string $absolutePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $absolutePath);
                finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }

        $imageInfo = @getimagesize($absolutePath);
        return (string)($imageInfo['mime'] ?? 'application/octet-stream');
    }

    private function proofAbsolutePath(string $relativePath): string
    {
        $normalizedRelativePath = $this->normalizePrivateProofRelativePath($relativePath);
        $rootPath = rtrim($this->proofPrivateRoot(), DIRECTORY_SEPARATOR);

        if ($normalizedRelativePath === '') {
            return $rootPath . DIRECTORY_SEPARATOR . '__invalid__';
        }

        return $rootPath
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelativePath);
    }

    private function pathIsWithinRoot(string $path, string $root): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }

        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    private function publicAssetAbsolutePath(string $path): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    }

    private function deletePrivateProofIfNeeded(string $path): void
    {
        if (!$this->isPrivateProofPath($path)) {
            return;
        }

        $absolutePath = $this->proofAbsolutePath(ltrim($this->normalizeStoredProofReference($path), '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function importLegacyPublicProof(string $storedPath, string $scene, string $orderNumber): string
    {
        $normalized = '/' . ltrim($this->normalizeStoredProofReference($storedPath), '/');
        $sourcePath = $this->publicAssetAbsolutePath($normalized);
        if (!is_file($sourcePath)) {
            return '';
        }

        $extension = $this->detectImageExtensionFromFile($sourcePath);
        if ($extension === '') {
            return '';
        }

        $directory = $this->buildProofStorageDirectory($scene);
        $relativePath = $directory . '/' . $this->buildProofBasename($orderNumber) . '.' . $extension;
        $targetPath = $this->proofAbsolutePath($relativePath);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return '';
        }

        $moved = @rename($sourcePath, $targetPath);
        if (!$moved) {
            $moved = @copy($sourcePath, $targetPath);
            if ($moved) {
                @unlink($sourcePath);
            }
        }
        if (!$moved) {
            return '';
        }

        @chmod($targetPath, 0644);
        return $relativePath;
    }

    private function persistPrivateProofUpload(object|string $source, string $scene, string $orderNumber, string $existingPath = ''): string
    {
        $storedPath = '';
        if (is_string($source)) {
            $normalized = $this->normalizeStoredProofReference($source);
            if ($normalized === '') {
                throw new \RuntimeException('请选择凭证图片');
            }

            if ($this->isPrivateProofPath($normalized)) {
                $storedPath = ltrim($normalized, '/');
            } elseif ($this->isLegacyPublicProofPath($normalized)) {
                $storedPath = $this->importLegacyPublicProof($normalized, $scene, $orderNumber);
                if ($storedPath === '') {
                    throw new \RuntimeException('历史凭证迁移失败，请重新上传');
                }
            } elseif ($this->isLikelyBase64ImagePayload($source)) {
                $uploader = new UploadService();
                $stored = $uploader->storePrivateImageUpload($source, [
                    'directory' => $this->buildProofStorageDirectory($scene),
                    'basename' => $this->buildProofBasename($orderNumber),
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    'empty_message' => '请选择凭证图片',
                    'storage_root' => $this->proofPrivateRoot(),
                ]);
                $storedPath = (string)($stored['relative_path'] ?? '');
            } else {
                throw new \RuntimeException('凭证图片格式错误');
            }
        } else {
            $uploader = new UploadService();
            $stored = $uploader->storePrivateImageUpload($source, [
                'directory' => $this->buildProofStorageDirectory($scene),
                'basename' => $this->buildProofBasename($orderNumber),
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'empty_message' => '请选择凭证图片',
                'storage_root' => $this->proofPrivateRoot(),
            ]);
            $storedPath = (string)($stored['relative_path'] ?? '');
        }

        if ($storedPath === '') {
            throw new \RuntimeException('凭证图片保存失败');
        }

        $existingNormalized = ltrim($this->normalizeStoredProofReference($existingPath), '/');
        if ($existingNormalized !== '' && $existingNormalized !== $storedPath) {
            $this->deletePrivateProofIfNeeded($existingNormalized);
        }

        return $storedPath;
    }

    private function extractProofUploadSource(array $fieldNames): object|string|null
    {
        $fileBag = (array)$this->request->file();
        foreach ($fieldNames as $fieldName) {
            if (isset($fileBag[$fieldName]) && is_object($fileBag[$fieldName])) {
                return $fileBag[$fieldName];
            }
        }
        foreach ($fileBag as $file) {
            if (is_object($file)) {
                return $file;
            }
        }

        foreach (array_merge($fieldNames, ['result', 'base64']) as $fieldName) {
            $value = trim((string)$this->request->post($fieldName, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function requestHasProofUploadInput(array $fieldNames): bool
    {
        $source = $this->extractProofUploadSource($fieldNames);
        if ($source === null) {
            return false;
        }

        if (is_object($source)) {
            return true;
        }

        $normalized = $this->normalizeStoredProofReference($source);
        if ($normalized === '') {
            return false;
        }

        return $this->isPrivateProofPath($normalized)
            || $this->isLegacyPublicProofPath($normalized)
            || $this->isLikelyBase64ImagePayload($source);
    }

    private function buildRechargeProofViewUrl(string $orderNumber, string $storedPath = ''): string
    {
        if (trim($storedPath) === '' || trim($orderNumber) === '') {
            return '';
        }

        return '/api/proof/recharge/' . rawurlencode($orderNumber) . '/view';
    }

    private function buildTradeProofViewUrl(string $orderNumber, string $storedPath = ''): string
    {
        if (trim($storedPath) === '' || trim($orderNumber) === '') {
            return '';
        }

        return '/api/proof/trade/' . rawurlencode($orderNumber) . '/view';
    }

    private function proofDeniedResponse()
    {
        return response('Forbidden', 403, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function proofNotFoundResponse()
    {
        return response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function proofFileResponse(string $absolutePath, string $relativePath)
    {
        $normalizedRelativePath = $this->normalizePrivateProofRelativePath($relativePath);
        $rootPath = realpath($this->proofPrivateRoot());
        $realPath = is_file($absolutePath) ? realpath($absolutePath) : false;

        if ($normalizedRelativePath === '' || $rootPath === false || $realPath === false || !$this->pathIsWithinRoot($realPath, $rootPath) || !is_file($realPath)) {
            return $this->proofNotFoundResponse();
        }

        $mime = $this->detectImageMimeFromFile($realPath);
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($realPath) . '"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $serverSoftware = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (str_contains($serverSoftware, 'nginx')) {
            $headers['X-Accel-Redirect'] = '/_protected/' . $normalizedRelativePath;
            return response('', 200, $headers);
        }

        $content = @file_get_contents($realPath);
        if ($content === false) {
            return $this->proofNotFoundResponse();
        }

        return response($content, 200, $headers);
    }

    private function rechargeProofStoredPath(Recharge $recharge): string
    {
        $storedPath = (string)($recharge['image'] ?? '');
        $normalized = $this->normalizeStoredProofReference($storedPath);
        if ($normalized === '') {
            return '';
        }

        if ($this->isPrivateProofPath($normalized)) {
            return ltrim($normalized, '/');
        }

        if ($this->isLegacyPublicProofPath($normalized)) {
            $migratedPath = $this->importLegacyPublicProof($normalized, 'recharge', (string)($recharge['order_number'] ?? ''));
            if ($migratedPath !== '') {
                $recharge->image = $migratedPath;
                $recharge->save();
                return $migratedPath;
            }
        }

        return '';
    }

    private function tradeProofStoredPath(TransactionOrder $order): string
    {
        $storedPath = (string)($order['voucher_image'] ?? '');
        $normalized = $this->normalizeStoredProofReference($storedPath);
        if ($normalized === '') {
            return '';
        }

        if ($this->isPrivateProofPath($normalized)) {
            return ltrim($normalized, '/');
        }

        if ($this->isLegacyPublicProofPath($normalized)) {
            $migratedPath = $this->importLegacyPublicProof($normalized, 'trade', (string)($order['order_number'] ?? ''));
            if ($migratedPath !== '') {
                $order->voucher_image = $migratedPath;
                $order->save();
                return $migratedPath;
            }
        }

        return '';
    }

    private function canViewRechargeProof(Recharge $recharge): bool
    {
        if ($this->currentAdminId() > 0) {
            return true;
        }

        return $this->currentUserId() > 0 && (int)($recharge['uid'] ?? 0) === $this->currentUserId();
    }

    private function canViewTradeProof(TransactionOrder $order): bool
    {
        if ($this->currentAdminId() > 0) {
            return true;
        }

        $uid = $this->currentUserId();
        if ($uid <= 0) {
            return false;
        }

        return $uid === (int)($order['uid'] ?? 0) || $uid === (int)($order['sell_uid'] ?? 0);
    }

    public function transaction_trading_details_post(string $action)
    {
        return $this->handleTransactionTradingDetailsPost($action);
    }

    public function transaction_buy_post(string $action)
    {
        return $this->handleTransactionBuyPost($action);
    }

    public function transaction_my_sale_post(string $action)
    {
        return $this->handleTransactionMySalePost($action);
    }

    public function transaction_sale_edit_post(string $action)
    {
        return $this->handleTransactionSaleEditPost($action);
    }

    public function batch_post(string $action)
    {
        return $this->handleBatchPost($action);
    }

public function bank_card_post(string $action)
{
    return $this->handleBankCardPost($action);
}

        
    public function payment_voucher(string $action){
        return $this->handlePaymentVoucher($action);
    }

                 
    public function epay_notify_url()
    {
        return $this->handleEpayNotifyUrl();
    }

public function account_settings_post(string $action)
{
    return $this->handleAccountSettingsPost($action);
}


    public function product_post(string $action)
    {
        return $this->handleProductPost($action);
    }



    public function query_business_page_post(string $action)
    {
        return $this->handleQueryBusinessPagePost($action);
    }
    

    // 图片上传
    public function upload_post()
    {
        return $this->handleUploadPost();
    }

    public function api_finance_orders()
    {
        return $this->handleApiFinanceOrders();
    }

    public function api_finance_recharge()
    {
        return $this->handleApiFinanceRecharge();
    }

    public function api_finance_recharge_detail()
    {
        return $this->handleApiFinanceRechargeDetail();
    }

    public function api_finance_recharge_submit()
    {
        return $this->handleApiFinanceRechargeSubmit();
    }

    /**
     * 生成安全的4位盐值（纯字母数字）
     */
    protected function generateSalt(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle($chars), 0, 4);
    }


    public function login_post(string $action)
    {
        return $this->handleLoginPost($action);
    }


    public function register_post(string $action)
    {
        return $this->handleRegisterPost($action);
    }

    private function subordinate($user)
    {
        // 初始化1-10级上级为0（默认值）
        $result = [
            'tid_1' => 0, 'tid_2' => 0, 'tid_3' => 0, 'tid_4' => 0, 'tid_5' => 0,
            'tid_6' => 0, 'tid_7' => 0, 'tid_8' => 0, 'tid_9' => 0, 'tid_10' => 0
        ];

        // 空值保护：如果传入的用户为空，直接返回默认值
        if (empty($user) || empty($user->id)) {
            return $result;
        }

        // 利用用户表已冗余存储的 tid_1~tid_10 上级链字段，逐层平移：
        //   新用户 tid_1  = 传入用户.id
        //   新用户 tid_2  = 传入用户.tid_1
        //   ...
        //   新用户 tid_10 = 传入用户.tid_9
        // 当某层上级为空时终止，后续层级保持默认值 0（与原循环查库终止行为一致）。
        // 本方法不再执行任何数据库查询。
        $ancestorChain = [
            $user->id,
            $user->tid_1,
            $user->tid_2,
            $user->tid_3,
            $user->tid_4,
            $user->tid_5,
            $user->tid_6,
            $user->tid_7,
            $user->tid_8,
            $user->tid_9,
        ];

        for ($i = 0; $i < 10; $i++) {
            if (empty($ancestorChain[$i])) {
                break;
            }
            $result['tid_' . ($i + 1)] = $ancestorChain[$i];
        }

        return $result;
    }

    public function points_info()
    {
        return $this->handlePointsInfo();
    }

    public function api_points_info()
    {
        return $this->handlePointsInfo();
    }
    
    /**
     * 处理签到请求
     */
    public function points_checkin()
    {
        return $this->handlePointsCheckin();
    }

    public function api_points_checkin()
    {
        return $this->handlePointsCheckin();
    }
    
    /**
     * 获取积分记录
     */
public function points_records()
{
    return $this->handlePointsRecords();
}

public function api_points_records()
{
    return $this->handlePointsRecords();
}

public function api_points_tasks()
{
    return $this->handlePointsTasks();
}

public function api_points_task_claim()
{
    return $this->handlePointsTaskClaim();
}

public function api_points_exchange_items()
{
    return $this->handlePointsExchangeItems();
}

public function api_points_exchange_submit()
{
    return $this->handlePointsExchangeSubmit();
}
    
/**
 * 获取当前用户2FA状态
 */
public function get_user_2fa_status()
{
    return $this->handleGetUserTwofaStatus();
}

public function api_account_twofa_status()
{
    return $this->handleGetUserTwofaStatus();
}

public function api_account_twofa_init()
{
    return $this->handleTwofaInit();
}

public function api_account_twofa_verify()
{
    return $this->handleTwofaVerify();
}

public function api_account_twofa_disable()
{
    return $this->handleTwofaDisable();
}

public function api_account_twofa_reset()
{
    return $this->handleTwofaReset();
}

public function api_account_twofa_recover()
{
    return $this->handleTwofaRecover();
}

public function api_account_twofa_recovery_codes_regenerate()
{
    return $this->handleTwofaRegenerateRecoveryCodes();
}


    public function api_auth_login()
    {
        return $this->handleLoginPost('login');
    }

    public function api_auth_register()
    {
        return $this->handleRegisterPost('register');
    }

    public function api_auth_logout()
    {
        $this->destroyUserSession();
        return $this->apiOk('已退出登录');
    }

    public function api_auth_check_password()
    {
        return $this->handleVerifyPassword();
    }

    public function api_product_confirm_recharge()
    {
        return $this->handleProductPost('confirm_recharge');
    }

    public function api_product_confirm_payment()
    {
        return $this->handleProductPost('confirm_payment');
    }

    public function api_upload_image()
    {
        return $this->apiFromLegacyResult($this->handleUploadPost(), '上传成功');
    }

    public function phone_meta()
    {
        return $this->handlePhoneMeta();
    }

    public function api_phone_meta()
    {
        return $this->apiFromLegacyResult($this->handlePhoneMeta(), '查询成功');
    }

    public function api_product_discount()
    {
        return $this->apiFromLegacyResult($this->handleProductPost('discount'), '查询成功');
    }

    public function api_product_query_submit()
    {
        return $this->apiFromLegacyResult($this->handleQueryBusinessPagePost('confirm_submit'), '查询成功');
    }

    public function api_product_query_payment()
    {
        return $this->apiFromLegacyResult($this->handleQueryBusinessPagePost('confirm_payment'), '支付成功');
    }

    public function api_transaction_sale_submit()
    {
        return $this->handleTransactionSaleEditPost('submit');
    }

    public function api_transaction_sale_status()
    {
        return $this->handleTransactionMySalePost('status_operate');
    }

    public function footer_post(string $action)
    {
        return $this->handleFooterPost($action);
    }

    public function api_product_detail($id)
    {
        return $this->handleApiProductDetail($id);
    }

    public function api_home_bootstrap()
    {
        return $this->handleApiHomeBootstrap();
    }

    public function agency_center_post(string $action)
    {
        return $this->handleAgencyCenterPost($action);
    }

    public function api_invite_info()
    {
        return $this->handleApiInviteInfo();
    }

    public function api_agent_summary()
    {
        return $this->handleApiAgentSummary();
    }

    public function api_agent_users()
    {
        return $this->handleApiAgentUsers();
    }

    public function api_agent_activate()
    {
        return $this->handleApiAgentActivate();
    }

    public function api_agent_wallet_transfer()
    {
        return $this->handleApiAgentWalletTransfer();
    }

    public function api_user_messages()
    {
        return $this->handleApiUserMessages();
    }

    public function api_user_message_detail()
    {
        return $this->handleApiUserMessageDetail();
    }

    public function api_user_message_read()
    {
        return $this->handleApiUserMessageRead();
    }

    public function api_user_message_read_all()
    {
        return $this->handleApiUserMessageReadAll();
    }

    public function api_user_message_unread_count()
    {
        return $this->handleApiUserMessageUnreadCount();
    }

    public function api_user_message_delete()
    {
        return $this->handleApiUserMessageDelete();
    }

    public function order_query()
    {
        return $this->handleOrderQuery();
    }

    public function out_order_post(string $action)
    {
        return $this->handleOutOrderPost($action);
    }

    public function order_post(string $action)
    {
        return $this->handleOrderPost($action);
    }

    public function api_order_query()
    {
        return $this->handleApiOrderQuery();
    }

    public function api_order_delete()
    {
        return $this->handleApiOrderDelete();
    }

    public function api_order_list()
    {
        return $this->handleApiOrderList();
    }

    public function api_order_detail(string $order_number)
    {
        return $this->handleApiOrderDetail($order_number);
    }

    public function api_order_cancel()
    {
        return $this->handleApiOrderCancel();
    }

    public function api_order_confirm_receipt()
    {
        return $this->handleApiOrderConfirmReceipt();
    }

    public function wallet_details_post(string $action)
    {
        return $this->handleWalletDetailsPost($action);
    }

    public function api_finance_wallet_details()
    {
        return $this->handleApiFinanceWalletDetails();
    }

    public function api_finance_detail_summary()
    {
        return $this->handleApiFinanceDetailSummary();
    }

    public function api_finance_detail_records()
    {
        return $this->handleApiFinanceDetailRecords();
    }

    public function api_finance_summary()
    {
        return $this->handleApiFinanceSummary();
    }

    public function api_finance_withdrawal($unused = null)
    {
        return $this->handleApiFinanceWithdrawal($unused);
    }

    public function api_finance_withdrawal_preview()
    {
        return $this->handleApiFinanceWithdrawalPreview();
    }

    public function api_finance_withdrawal_submit()
    {
        return $this->handleApiFinanceWithdrawalSubmit();
    }

    public function api_finance_withdrawal_detail()
    {
        return $this->handleApiFinanceWithdrawalDetail();
    }


    // 退出登录
    public function logout()
    {
        return $this->handleLogout();
    }

    // 多模块共用 helper，暂留主控制器

private function apiOk(string $message = 'ok', mixed $data = null, int $code = 200)
{
    return show($code, 'success', $message, $data, 200);
}

private function apiError(string $message = '请求失败', int $code = 400, mixed $data = null)
{
    return show($code, 'error', $message, $data, 200);
}

private function readRequestPayload(): array
{
    $payload = $this->request->post();
    if (!empty($payload)) {
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

private function apiFromLegacyResult(mixed $result, string $successMessage = '操作成功')
{
    if ($result instanceof \think\response\Json) {
        return $result;
    }

    if (is_array($result)) {
        $status = (string)($result['status'] ?? '');
        $code = isset($result['code']) ? (int)$result['code'] : null;
        $message = (string)($result['message'] ?? $result['msg'] ?? $successMessage);
        $data = $result['data'] ?? null;

        $ok = $status === 'success' || $code === 0 || $code === 200 || $code === 1;

        return $ok
            ? $this->apiOk($message, $data, 200)
            : $this->apiError($message, $code && $code >= 400 ? $code : 400, $data);
    }

    if (is_string($result)) {
        return $this->apiOk($successMessage, ['result' => $result], 200);
    }

    return $this->apiOk($successMessage, $result, 200);
}

    private function directMoney($value, int $scale = 2): string
    {
        return number_format((float)($value ?? 0), $scale, '.', '');
    }

    private function directRechargeStatusText(int $status): string
    {
        $map = [
            0 => '待汇款提交',
            1 => '已提交',
            2 => '订单取消',
            3 => '订单完成',
        ];
        return $map[$status] ?? '未知状态';
    }

    private function directWithdrawalStatusText(int $status): string
    {
        $map = [
            0 => '提现处理中',
            1 => '提现成功',
            2 => '提现失败',
            3 => '提现已取消',
        ];
        return $map[$status] ?? '未知状态';
    }

    private function directPayTypeText($payType, $epayType = ''): string
    {
        $payType = (string)($payType ?? '');
        $epayType = (string)($epayType ?? '');
        if ($payType === '1') return 'U支付';
        if ($payType === '2') {
            if ($epayType === '1') return '支付宝';
            if ($epayType === '2') return '微信支付';
            return '易支付';
        }
        return '未知方式';
    }

    private function directQrUrl(string $text = ''): string
    {
        if ($text === '') return '';
        return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($text);
    }

    private function directPublicOrigin(): string
    {
        $proto = (string)($this->request->header('x-forwarded-proto') ?: $this->request->scheme());
        $host = (string)($this->request->header('x-forwarded-host') ?: $this->request->host());
        if ($host === '') {
            return '';
        }
        return rtrim($proto . '://' . $host, '/');
    }

    private function directMaskMobile(string $mobile = ''): string
    {
        $mobile = trim($mobile);
        if (strlen($mobile) !== 11) return $mobile;
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }

    private function directBuildInviteInfo($user): array
    {
        $inviteCode = (string)($user['invite_code'] ?? '');
        $origin = $this->directPublicOrigin();
        $inviteLink = ($origin !== '' && $inviteCode !== '') ? ($origin . '/register?code=' . urlencode($inviteCode)) : '';
        return [
            'invite_code' => $inviteCode,
            'invite_link' => $inviteLink,
            'share_link' => $inviteLink,
            'qr_code' => $this->directQrUrl($inviteLink),
        ];
    }

    private function directAgentListByLevel(int $uid, int $level = 1): array
    {
        $level = max(1, min(10, $level));
        $tidField = 'tid_' . $level;
        $subUsers = UserModel::where($tidField, $uid)->where('status', 1)->order('id', 'desc')->select();
        $list = [];
        foreach ($subUsers as $user) {
            $nickname = (string)($user['nickname'] ?: $user['surname'] ?: ('用户' . substr((string)($user['mobile'] ?? ''), -4)));
            $totalRebate = (float)RebateRecord::where([
                'tid' => $uid,
                'uid' => $user['id'],
                'level' => $level,
            ])->sum('amount');
            $latestRebateTime = (string)(RebateRecord::where([
                'tid' => $uid,
                'uid' => $user['id'],
                'level' => $level,
            ])->order('create_time', 'desc')->value('create_time') ?? '');

            $list[] = [
                'user_id' => (int)$user['id'],
                'nickname' => $nickname,
                'name' => $nickname,
                'user_name' => $nickname,
                'mobile' => $this->directMaskMobile((string)($user['mobile'] ?? '')),
                'avatar' => (string)($user['avatar'] ?? ''),
                'user_avatar' => (string)($user['avatar'] ?? ''),
                'join_time' => (string)($user['create_time'] ?? ''),
                'create_time' => (string)($user['create_time'] ?? ''),
                'latest_rebate_time' => $latestRebateTime,
                'total_rebate' => $this->directMoney($totalRebate),
                'rebate_level' => $level,
                'parent_id' => $uid,
            ];
        }
        return $list;
    }


    private function directBuildWalletAddressPayload($user): array
    {
        $address = (string)($user['trc20'] ?? '');
        return [
            'address' => $address,
            'wallet_address' => $address,
            'wallet_type' => 'TRC20',
            'network' => 'TRC20',
            'trc20' => $address,
            'realname' => (string)($user['surname'] ?? $user['nickname'] ?? ''),
            'status' => $address !== '' ? 1 : 0,
            'is_bound' => $address !== '' ? 1 : 0,
        ];
    }

    private function directBuildBankCardPayload($card = null): array
    {
        if (!$card) {
            return [
                'id' => 0,
                'name' => '',
                'bank_name' => '',
                'card_number' => '',
                'bank_account' => '',
                'realname' => '',
                'branch_name' => '',
                'mobile' => '',
                'wx_account' => '',
                'zfb_account' => '',
                'default_selection' => 0,
                'status' => 0,
                'is_bound' => 0,
            ];
        }

        $bankName = (string)($card['name'] ?? '');
        $bankAccount = (string)($card['bank_account'] ?? $card['card_number'] ?? '');
        $realname = (string)($card['realname'] ?? $card['surname'] ?? '');

        return [
            'id' => (int)($card['id'] ?? 0),
            'name' => $bankName,
            'bank_name' => $bankName,
            'card_number' => $bankAccount,
            'bank_account' => $bankAccount,
            'realname' => $realname,
            'branch_name' => (string)($card['branch_name'] ?? ''),
            'mobile' => (string)($card['mobile'] ?? ''),
            'wx_account' => (string)($card['wx_account'] ?? ''),
            'zfb_account' => (string)($card['zfb_account'] ?? ''),
            'default_selection' => (int)($card['default_selection'] ?? 0),
            'status' => 1,
            'is_bound' => 1,
        ];
    }

    private function directWriteBalanceLog(array $data): void
    {
        $scene = (string)($data['scene'] ?? '');
        $orderNumber = (string)($data['order_number'] ?? '');
        if ($scene === '' || $orderNumber === '') {
            throw new Exception('余额流水参数异常');
        }
        if (UserBalanceLog::where('scene', $scene)->where('order_number', $orderNumber)->find()) {
            return;
        }
        UserBalanceLog::create([
            'uid' => (int)($data['uid'] ?? 0),
            'scene' => $scene,
            'change_type' => 1,
            'currency' => 'USDT',
            'amount' => round((float)($data['amount'] ?? 0), 2),
            'balance_before' => round((float)($data['balance_before'] ?? 0), 2),
            'balance_after' => round((float)($data['balance_after'] ?? 0), 2),
            'biz_type' => 'cz_order',
            'biz_id' => (int)($data['biz_id'] ?? 0),
            'order_number' => $orderNumber,
            'remark' => (string)($data['remark'] ?? ''),
            'operator_id' => (int)($data['operator_id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function directCancelOrderRefund(int $uid, int $orderId = 0, string $orderNumber = ''): array
    {
        $orderSnapshot = [];
        Db::startTrans();
        try {
            $query = Order::scopeUserVisible(Order::where('uid', $uid))->lock(true);
            if ($orderId > 0) {
                $order = $query->where('id', $orderId)->find();
            } else {
                $order = $query->where('order_number', $orderNumber)->find();
            }
            if (!$order) {
                throw new Exception('订单不存在');
            }
            if ((int)$order['status'] !== 0) {
                throw new Exception('当前订单不可取消');
            }
            $user = $this->directLockUser($uid);
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $refundAmount = order_refundable_usdt($order);
            if ($refundAmount > 0) {
                $balanceBefore = round((float)($user['balance'] ?? 0), 2);
                $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
                    $user,
                    UserFundLedgerService::WALLET_FROZEN,
                    UserFundLedgerService::WALLET_BALANCE,
                    $refundAmount,
                    [
                        'biz_type' => 'product_order',
                        'biz_id' => (int)$order['id'],
                        'biz_no' => (string)$order['order_number'],
                        'order_number' => (string)$order['order_number'],
                        'out_change_type' => 'product_order_cancel_refund',
                        'in_change_type' => 'product_order_cancel_refund',
                        'operator_type' => 'user',
                        'operator_id' => $uid,
                        'status' => 'done',
                        'request_no' => 'product_order_cancel_refund:' . (string)$order['order_number'],
                        'remark' => 'product order cancel refund',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'directCancelOrderRefund',
                            'refund_scene' => 'order_cancel_refund',
                            'order_status_before' => (int)($order['status'] ?? 0),
                        ],
                    ]
                );
                $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                $balanceAfter = array_key_exists('balance', $walletSnapshot)
                    ? round((float)$walletSnapshot['balance'], 2)
                    : round((float)($user['balance'] ?? ($balanceBefore + $refundAmount)), 2);
                $this->directWriteBalanceLog([
                    'uid' => $uid,
                    'scene' => 'order_cancel_refund',
                    'amount' => $refundAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'biz_id' => (int)$order['id'],
                    'order_number' => (string)$order['order_number'],
                    'remark' => '订单取消退款',
                    'operator_id' => 0,
                ]);
            }
            $order->status = 3;
            $order->save();
            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        try {
            (new OrderTelegramNotifier())->notifyProductOrderCancelled($orderSnapshot, '用户取消');
        } catch (\Throwable $notifyException) {
            Log::error('product order notify failed', [
                'order_id' => (int)($orderSnapshot['id'] ?? 0),
                'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                'uid' => (int)($orderSnapshot['uid'] ?? 0),
                'action' => 'product_order_cancelled_notify',
                'error_message' => $notifyException->getMessage(),
            ]);
        }

        return [
            'order' => $order,
            'refund_amount' => $refundAmount,
        ];
    }

    private function directSumOrderActualPayUsdt($query): float
    {
        $amount = 0.00;
        foreach ($query->select() as $order) {
            $amount = round($amount + order_actual_pay_usdt($order), 2);
        }
        return round($amount, 2);
    }

    private function directSumRefundLogAmount(int $uid, ?array $between = null): float
    {
        $query = \app\model\UserFundLog::where('uid', $uid)
            ->where('wallet_type', 'balance')
            ->whereIn('change_type', [
                'product_order_cancel_refund',
                'product_order_partial_refund',
            ]);
        if ($between) {
            $query->whereTime('create_time', 'between', $between);
        }
        return round((float)$query->sum('amount'), 2);
    }
    private function directBuildFinanceSummaryPayload($user): array
    {
        $rate = $this->directMoney(getConfig('rate') ?? 0, 2);
        $withdrawalFee = $this->directMoney(getConfig('withdrawal_fee') ?? 0);
        $miniRechargeAmount = $this->directMoney(getConfig('mini_recharge_amount') ?? 0);
        $miniWithdrawalAmount = $this->directMoney(getConfig('mini_withdrawal_amount') ?? 0);
        $paymentAddress = (string)(getConfig('payment_address') ?? '');
        $epayAlipayEnabled = (string)(getConfig('epay_alipay_enabled') ?? '1') !== '0' ? '1' : '0';
        $epayWechatEnabled = (string)(getConfig('epay_wechat_enabled') ?? '1') !== '0' ? '1' : '0';

        return [
            'balance' => $this->directMoney($user['balance'] ?? 0),
            'available' => $this->directMoney($user['balance'] ?? 0),
            'frozen_amount' => $this->directMoney($user['frozen_amount'] ?? 0),
            'trc20' => (string)($user['trc20'] ?? ''),
            'rate' => $rate,
            'withdrawal_fee' => $withdrawalFee,
            'min_recharge' => $miniRechargeAmount,
            'min_withdrawal' => $miniWithdrawalAmount,
            'mini_recharge_amount' => $miniRechargeAmount,
            'mini_withdrawal_amount' => $miniWithdrawalAmount,
            'payment_address' => $paymentAddress,
            'payment_qr_url' => $this->directQrUrl($paymentAddress),
            'epay_alipay_enabled' => $epayAlipayEnabled,
            'epay_wechat_enabled' => $epayWechatEnabled,
            'config' => [
                'rate' => $rate,
                'withdrawal_fee' => $withdrawalFee,
                'mini_recharge_amount' => $miniRechargeAmount,
                'mini_withdrawal_amount' => $miniWithdrawalAmount,
                'payment_address' => $paymentAddress,
                'payment_qr_url' => $this->directQrUrl($paymentAddress),
                'epay_alipay_enabled' => $epayAlipayEnabled,
                'epay_wechat_enabled' => $epayWechatEnabled,
            ],
        ];
    }

    private function directOrderStatusText($order): string
    {
        $status = (int)($order['status'] ?? 0);
        $confirmStatus = (int)($order['confirm_status'] ?? 0);
        if ($status === 3) return '已取消';
        if ($confirmStatus === 3) return '未收到';
        if ($status === 2 && $confirmStatus === 1) return '待确认';
        if ($status === 2) return '已完成';
        if ($status === 1) return '充值中';
        return '待充值';
    }

public function api_region_tree()
{
    $tree = include app()->getRootPath() . 'app/common/legacy_region_tree.php';
    return $this->apiOk('查询成功', $tree);
}

    public function api_user_bootstrap()
    {
        return $this->handleApiUserBootstrap();
    }

        public function api_account_profile()
    {
        return $this->handleApiAccountProfile();
    }

    public function api_account_settings()
    {
        return $this->handleApiAccountSettings();
    }

    public function api_account_telegram_binding_status()
    {
        return $this->handleApiAccountTelegramBindingStatus();
    }

    public function api_account_telegram_binding_code()
    {
        return $this->handleApiAccountTelegramBindingCode();
    }

    public function api_account_telegram_unbind()
    {
        return $this->handleApiAccountTelegramUnbind();
    }

public function api_account_profile_save()
{
    return $this->handleApiAccountProfileSave();
}

public function api_account_password_save()
{
    return $this->handleApiAccountPasswordSave();
}

    public function api_account_wallet_address_save()
    {
        return $this->handleApiAccountWalletAddressSave();
    }

    public function api_account_bank_card_save()
    {
        return $this->handleApiAccountBankCardSave();
    }

    public function api_account_bank_card_delete()
    {
        return $this->handleApiAccountBankCardDelete();
    }

    public function api_account_bank_card_default()
    {
        return $this->handleApiAccountBankCardDefault();
    }

    public function api_account_wallet_address()
    {
        return $this->handleApiAccountWalletAddress();
    }

    public function api_account_bank_card()
    {
        return $this->handleApiAccountBankCard();
    }

    public function api_callback_bepusdt()
    {
        return (new Notify($this->app))->api_callback_bepusdt();
    }

    private function siteConfigKeys(): array
    {
        return [
            'name', 'notice', 'payment_address', 'rate',
            'agent_jieshao', 'agent_money',
            'a_recommend_id', 'a_recommend_image',
            'b_recommend_id', 'b_recommend_image',
            'contact_service_url', 'contact_service_image',
            'chatwoot_enabled', 'chatwoot_base_url', 'chatwoot_token',
            'mini_recharge_amount', 'mini_withdrawal_amount',
            'withdrawal_fee', 'transaction_fees',
            'transaction_mini_quantity', 'user_avatar_image',
            'platform_account_uid',
        ];
    }

    private function buildSiteConfigMap(): array
    {
        $keys = $this->siteConfigKeys();
        $rows = ConfigModel::whereIn('k', $keys)->select();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['k']] = $row['v'];
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $map)) {
                $map[$key] = (string)(getConfig($key) ?? '');
            }
        }
        return $map;
    }

    private function normalizeSiteProduct($product, string $overrideImage = '', ?int $substationId = null, bool $summaryOnly = false): array
    {
        if (!$product) {
            return [];
        }
        $image = $overrideImage !== '' ? $overrideImage : ($product['image'] ?? '');
        $resolvedSubstationId = $substationId;
        if ($resolvedSubstationId === null) {
            $substationContext = SubstationService::resolveByRequest($this->request);
            $resolvedSubstationId = (int)($substationContext['substation_id'] ?? 0);
        }

        $priceTiers = [];
        $displayTier = [];
        try {
            $priceTiers = SubstationPriceService::listEffectiveTiersForProduct((int)($product['id'] ?? 0), (int)$resolvedSubstationId);
            $displayTier = $priceTiers[0] ?? [];
        } catch (\Throwable $e) {
            $priceTiers = [];
            $displayTier = [];
        }

        $basePayload = [
            'id' => (int)($product['id'] ?? 0),
            'name' => (string)($product['name'] ?? ''),
            'home_name' => (string)($product['home_name'] ?? $product['name'] ?? ''),
            'describe' => SubstationPriceService::resolveProductDescribe((int)($product['id'] ?? 0), (int)$resolvedSubstationId, (string)($product['describe'] ?? '')),
            'image' => (string)$image,
            'display_discount' => (float)($displayTier['final_discount'] ?? 0),
            'display_price_cny' => (float)($displayTier['final_price'] ?? 0),
            'display_price_usdt' => (float)($displayTier['final_price_usdt'] ?? 0),
            'batch_status' => (int)($product['batch_status'] ?? 0),
            'product_type' => (int)($product['product_type'] ?? 0),
            'type' => (int)($product['type'] ?? 1),
            'status' => (int)($product['status'] ?? 1),
            'sort' => (int)($product['sort'] ?? 0),
        ];

        if ($summaryOnly) {
            return $basePayload;
        }

        return array_merge($basePayload, [
            'mini_recharge_amount' => (float)($product['mini_recharge_amount'] ?? 0),
            'par_value' => $product['par_value'] ?? [],
            'price_tiers' => $priceTiers,
            'platform_display_price_cny' => (float)($displayTier['platform_settlement_price'] ?? 0),
            'platform_display_price_usdt' => (float)($displayTier['platform_price_usdt'] ?? 0),
            'order_fields' => $product['order_info'] ?? [],
            'tutorial_content' => (string)($product['tutorial_content'] ?? ''),
        ]);
    }

    public function index_api_site_config()
    {
        return $this->handleApiSiteConfig();
    }

    public function api_site_config()
    {
        return $this->handleApiSiteConfig();
    }

    private function parseBankCardInfo($bankCardInfo): array
    {
        $info = is_array($bankCardInfo) ? $bankCardInfo : [];
        $bankName = (string)($info['bank_name'] ?? $info['bank'] ?? '');
        $bankAccount = (string)($info['bank_account'] ?? $info['card_number'] ?? $info['bank_card'] ?? '');
        $accountName = (string)($info['name'] ?? $info['account_name'] ?? $info['real_name'] ?? '');
        $wechatAccount = (string)($info['wx_account'] ?? $info['wechat'] ?? '');
        $alipayAccount = (string)($info['zfb_account'] ?? $info['alipay'] ?? '');
        $walletAddress = (string)($info['trc20'] ?? $info['wallet_address'] ?? '');
        $paymentMethod = (string)($info['payment_method'] ?? '');
        if ($paymentMethod === '') {
            if ($bankAccount !== '' || $bankName !== '') {
                $paymentMethod = '银行卡';
            } elseif ($wechatAccount !== '') {
                $paymentMethod = '微信';
            } elseif ($alipayAccount !== '') {
                $paymentMethod = '支付宝';
            } elseif ($walletAddress !== '') {
                $paymentMethod = 'TRC20';
            }
        }

        return [
            'payment_method' => $paymentMethod,
            'bank_name' => $bankName,
            'bank_account' => $bankAccount,
            'account_name' => $accountName,
            'wechat_account' => $wechatAccount,
            'alipay_account' => $alipayAccount,
            'wallet_address' => $walletAddress,
            'wx_account' => $wechatAccount,
            'zfb_account' => $alipayAccount,
            'trc20' => $walletAddress,
            'mobile' => (string)($info['mobile'] ?? ''),
            'raw' => $info,
        ];
    }

    private function transactionStatusMeta($order): array
    {
        return TransactionOrder::buildStatusMeta($order);
    }

    public function api_transaction_order_detail(string $order_number)
    {
        return $this->handleApiTransactionOrderDetail($order_number);
    }

public function api_transaction_order_release()
{
    return $this->handleApiTransactionOrderRelease();
}


public function api_transaction_market()
{
    return $this->handleApiTransactionMarket();
}

public function api_transaction_orders()
{
    return $this->handleApiTransactionOrders();
}

public function api_transaction_my_sale()
{
    return $this->handleApiTransactionMySale();
}

    public function api_transaction_buy()
    {
        return $this->handleApiTransactionBuy();
    }

    public function api_transaction_order_proof_image()
    {
        return $this->handleApiTransactionOrderProofImage();
    }

    public function api_transaction_order_cancel()
    {
        return $this->handleApiTransactionOrderCancel();
    }

    public function api_transaction_order_proof_submit()
    {
        return $this->handleApiTransactionOrderProofSubmit();
    }

    public function api_proof_recharge_view(string $order_number)
    {
        $orderNumber = trim((string)$order_number);
        if ($orderNumber === '') {
            return $this->proofNotFoundResponse();
        }

        $recharge = Recharge::where('order_number', $orderNumber)->find();
        if (!$recharge) {
            return $this->proofNotFoundResponse();
        }
        if (!$this->canViewRechargeProof($recharge)) {
            return $this->proofDeniedResponse();
        }

        $storedPath = $this->rechargeProofStoredPath($recharge);
        if ($storedPath === '') {
            return $this->proofNotFoundResponse();
        }

        return $this->proofFileResponse($this->proofAbsolutePath($storedPath), $storedPath);
    }

    public function api_proof_trade_view(string $order_number)
    {
        $orderNumber = trim((string)$order_number);
        if ($orderNumber === '') {
            return $this->proofNotFoundResponse();
        }

        $order = TransactionOrder::where('order_number', $orderNumber)->find();
        if (!$order) {
            return $this->proofNotFoundResponse();
        }
        if (!$this->canViewTradeProof($order)) {
            return $this->proofDeniedResponse();
        }

        $storedPath = $this->tradeProofStoredPath($order);
        if ($storedPath === '') {
            return $this->proofNotFoundResponse();
        }

        return $this->proofFileResponse($this->proofAbsolutePath($storedPath), $storedPath);
    }

}
