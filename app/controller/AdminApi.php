<?php
declare (strict_types=1);

namespace app\controller;
use app\common\SecurityKeyResolver;
use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\model\User as UserModel;
use app\model\Cache as CacheModel;
use app\model\Config as ConfigModel;
use app\model\Order;
use app\model\Product;
use app\model\Slide;
use app\model\Recharge;
use app\model\Withdrawal;
use app\model\TransactionOrder;
use app\model\TransactionProduct;
use app\model\RebateRecord;
use app\model\BankCard;
use app\model\Substation;
use app\model\UserMessage;
use app\model\UserBalanceLog;
use app\model\PointsRecord;
use app\service\AdminOperationLogService;
use app\service\LoginRateLimiter;
use app\service\ProductOrderService;
use app\service\UploadService;
use app\service\UserFundLedgerService;
use app\service\UserMessageService;
use app\service\telegram\OrderTelegramNotifier;
use app\common\service\SubstationSettlementService;
use app\common\service\SubstationPriceService;

// 引入2FA相关类
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Alignment\LabelAlignmentCenter;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use PragmaRX\Google2FA\Google2FA;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

use Exception;
use JsonException;
use RobThree\Auth\TwoFactorAuth;
use think\App;
use think\db\exception\DbException;
use think\exception\ValidateException;
use think\facade\Session;
use think\facade\Validate;
use think\Request;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Log; // 引入日志类
use Yurun\Util\HttpRequest;
use yzh52521\filesystem\facade\Filesystem;

class AdminApi
{
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

    protected mixed $admin_info;
    protected string|array|bool $config = [];
    protected array $middleware = [AdminAuth::class];
    protected AdminOperationLogService $adminOperationLogService;
    protected string $encryptionKey = '';
    protected bool $encryptionKeyLoaded = false;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 将当前登录管理员信息写入至私有属性
        $this->admin_info = $this->request->session('admin');
        $this->config = getConfig();
        $this->adminOperationLogService = new AdminOperationLogService($this->request);
    }

    private function directWriteAdminOperationLog(string $action, string $module, string $content, array $options = []): void
    {
        $this->adminOperationLogService->record($action, $module, $content, array_merge([
            'admin' => is_array($this->admin_info) ? $this->admin_info : [],
        ], $options));
    }

    private function directConfigFieldLabels(): array
    {
        return [
            'name' => '网站名称',
            'backstage_entrance' => '后台入口',
            'rate' => '最新费率',
            'mailing_address' => 'TG客服',
            'notice' => '首页公告',
            'a_recommend_id' => '首页推荐一产品',
            'b_recommend_id' => '首页推荐二产品',
            'a_recommend_image' => '首页推荐一图片',
            'b_recommend_image' => '首页推荐二图片',
            'contact_service_url' => '在线客服地址',
            'contact_service_image' => '在线客服图片',
            'chatwoot_enabled' => 'Chatwoot启用状态',
            'chatwoot_base_url' => 'Chatwoot客服域名',
            'chatwoot_token' => 'Chatwoot网站令牌',
            'transaction_mini_quantity' => '最低挂单数量',
            'transaction_fees' => '交易手续费',
            'platform_account_uid' => '平台账户UID(手续费记账)',
            'user_avatar_image' => '默认注册头像',
            'agent_money' => '代理开通价格',
            'agent_jieshao' => '代理开通介绍',
            'substation_open_price' => '分站开通价格',
            'substation_open_intro' => '分站开通介绍',
            'substation_base_domain' => '分站基础域名',
            'agreement' => '用户协议',
            'privacy_policy' => '隐私政策',
            'mini_recharge_amount' => '最低充值金额',
            'mini_withdrawal_amount' => '最低提现金额',
            'withdrawal_fee' => '提现手续费',
            'payment_address' => '收款地址',
            'bepusdt_base_url' => 'Bepusdt接口地址',
            'bepusdt_api_token' => 'Bepusdt秘钥',
            'epay_url' => '易支付接口地址',
            'epay_id' => '易支付ID',
            'epay_key' => '易支付秘钥',
            'epay_alipay_enabled' => '支付宝充值开关',
            'epay_wechat_enabled' => '微信支付充值开关',
            'telegram_bot_status' => 'Telegram机器人状态',
            'telegram_bot_token' => 'Telegram机器人Token',
            'telegram_bot_username' => 'Telegram机器人用户名',
            'telegram_webhook_url' => 'Telegram Webhook地址',
            'telegram_welcome_message' => 'Telegram欢迎语',
            'substation_blocked_subdomains' => '分站保留前缀',
        ];
    }

    private function directBuildChangedFields(array $before, array $after, array $keys): array
    {
        $changed = [];
        foreach ($keys as $key) {
            $oldValue = (string)($before[$key] ?? '');
            $newValue = (string)($after[$key] ?? '');
            if ($oldValue === $newValue) {
                continue;
            }
            $changed[$key] = [
                'before' => $before[$key] ?? '',
                'after' => $after[$key] ?? '',
            ];
        }
        return $changed;
    }

    private function safeExcel($value): string
    {
        // 防 Excel 公式注入：对导出单元格前缀为 = + - @ 的文本加单引号。
        $value = (string)$value;
        if (preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    private function cleanupExpiredExportFiles(): void
    {
        // 防导出文件泄露：清理 runtime/export 中超过 10 分钟的临时导出文件。
        $directory = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($directory)) {
            return;
        }

        $expireBefore = time() - 600;
        foreach ((array)glob($directory . DIRECTORY_SEPARATOR . 'export_*.xls') as $filePath) {
            if (!is_string($filePath) || !is_file($filePath)) {
                continue;
            }

            $modifiedAt = @filemtime($filePath);
            if ($modifiedAt !== false && $modifiedAt <= $expireBefore) {
                @unlink($filePath);
            }
        }
    }

    private function createPrivateExportDownload(Spreadsheet $spreadsheet, string $scene = 'order_export'): string
    {
        // 防导出文件泄露：导出文件落到 runtime/export 私有目录，并通过一次性下载接口访问。
        $this->cleanupExpiredExportFiles();
        $directory = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $adminId = (int)($this->admin_info['id'] ?? 0);
        $timestamp = date('YmdHis');
        $random = bin2hex(random_bytes(4));
        $downloadName = 'export_' . $adminId . '_' . $timestamp . '_' . $random . '.xls';
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $downloadName;

        $writer = new Xls($spreadsheet);
        $writer->save($absolutePath);

        $token = bin2hex(random_bytes(16));
        $cacheKey = 'admin_export_download:' . $token;
        Cache::set($cacheKey, [
            'admin_id' => $adminId,
            'path' => $absolutePath,
            'name' => $downloadName,
            'scene' => $scene,
        ], 600);

        Log::info('admin export file created', [
            'admin_id' => $adminId,
            'scene' => $scene,
            'path' => $absolutePath,
        ]);

        return '/' . trim((string)getConfig('backstage_entrance'), '/') . '/export_download?token=' . rawurlencode($token);
    }

    public function export_download()
    {
        // 防导出文件泄露：下载只允许通过后台私有接口读取 runtime/export 中的临时文件。
        $this->cleanupExpiredExportFiles();
        $token = trim((string)$this->request->get('token', ''));
        if ($token === '') {
            return show(500, 'error', '导出文件不存在');
        }

        $cacheKey = 'admin_export_download:' . $token;
        $record = Cache::get($cacheKey);
        if (!is_array($record) || empty($record['path']) || empty($record['name'])) {
            return show(500, 'error', '导出文件不存在或已过期');
        }

        if ((int)($record['admin_id'] ?? 0) !== (int)($this->admin_info['id'] ?? 0)) {
            return show(500, 'error', '无权下载该导出文件');
        }

        $absolutePath = (string)$record['path'];
        if (!is_file($absolutePath)) {
            Cache::delete($cacheKey);
            return show(500, 'error', '导出文件不存在或已过期');
        }

        return download($absolutePath, (string)$record['name']);
    }

    private function directAllowedConfigKeys(): array
    {
        return array_keys($this->directConfigFieldLabels());
    }
    
    private function directTextareaConfigKeys(): array
    {
        return ['notice', 'agent_jieshao', 'substation_open_intro', 'agreement', 'privacy_policy', 'telegram_welcome_message'];
    }
    
    private function directUrlConfigKeys(): array
    {
        return ['contact_service_url', 'chatwoot_base_url', 'epay_url', 'bepusdt_base_url', 'telegram_webhook_url'];
    }
    
    private function directDangerousConfigFragments(): array
    {
        return ['<script', 'onerror=', 'onload=', 'javascript:', '<iframe', '<object', '<embed', '<svg', 'data:text/html'];
    }
    
    private function directContainsDangerousConfigFragment(string $value): bool
    {
        $normalized = strtolower($value);
        foreach ($this->directDangerousConfigFragments() as $fragment) {
            if ($fragment !== '' && str_contains($normalized, $fragment)) {
                return true;
            }
        }
        return false;
    }
    
    private function directValidateSafeConfigUrl(string $value): bool
    {
        if ($value === '' || $this->directContainsDangerousConfigFragment($value)) {
            return false;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        $host = (string)parse_url($value, PHP_URL_HOST);
        $user = (string)parse_url($value, PHP_URL_USER);
        $pass = (string)parse_url($value, PHP_URL_PASS);
        return in_array($scheme, ['http', 'https'], true) && $host !== '' && $user === '' && $pass === '';
    }
    
    private function directNormalizeDecimalString(string $value, int $scale = 2): string
    {
        $normalized = number_format((float)$value, max(0, $scale), '.', '');
        $normalized = rtrim(rtrim($normalized, '0'), '.');
        return $normalized === '' ? '0' : $normalized;
    }

    private function directCsrfTokenName(): string
    {
        $csrfConfig = (array)config('app.csrf');

        return (string)($csrfConfig['token_name'] ?? '_csrf_token');
    }

    private function directAllowedConfigMetaKeys(): array
    {
        return array_values(array_unique([
            '__token__',
            $this->directCsrfTokenName(),
        ]));
    }

    private function directAllowedSensitiveAuthKeys(): array
    {
        return ['admin_password', 'twofa_code', 'verify_code'];
    }

    private function directCurrentRequestPath(): string
    {
        return trim(str_replace('\\', '/', (string)$this->request->pathinfo()), '/');
    }

    private function directRequestPathMatches(string $relativePath): bool
    {
        $currentPath = strtolower($this->directCurrentRequestPath());
        $relativePath = strtolower(trim(str_replace('\\', '/', $relativePath), '/'));
        if ($relativePath === '') {
            return false;
        }

        return $currentPath === $relativePath
            || str_ends_with($currentPath, '/' . $relativePath)
            || str_contains($currentPath, '/' . $relativePath . '/');
    }

    private function directIsAllowedConfigReferer(string $referer): bool
    {
        if ($referer === '') {
            return false;
        }

        $refererHost = (string)parse_url($referer, PHP_URL_HOST);
        $requestHost = (string)$this->request->host();
        if ($refererHost !== '' && $requestHost !== '' && strcasecmp($refererHost, $requestHost) !== 0) {
            return false;
        }

        $refererPath = (string)parse_url($referer, PHP_URL_PATH);
        $refererPath = trim(str_replace('\\', '/', $refererPath), '/');
        foreach (['setting', 'substation_apply', 'substation_profile_audit'] as $allowedPath) {
            $allowedPath = strtolower(trim($allowedPath, '/'));
            $normalizedRefererPath = strtolower($refererPath);
            if (
                $normalizedRefererPath === $allowedPath
                || str_ends_with($normalizedRefererPath, '/' . $allowedPath)
                || str_contains($normalizedRefererPath, '/' . $allowedPath . '/')
            ) {
                return true;
            }
        }

        return false;
    }

    private function directValidateOptionalCsrfToken(): bool
    {
        $tokenName = $this->directCsrfTokenName();
        $requestToken = trim((string)$this->request->post($tokenName, $this->request->post('__token__', '')));
        if ($requestToken === '') {
            $requestToken = trim((string)$this->request->header('X-CSRF-Token', ''));
        }
        if ($requestToken === '') {
            return true;
        }

        $sessionToken = (string)Session::get($tokenName, '');

        return $sessionToken !== '' && hash_equals($sessionToken, $requestToken);
    }

    private function directValidateRequiredCsrfToken(): bool
    {
        $tokenName = $this->directCsrfTokenName();
        $requestToken = trim((string)$this->request->post($tokenName, $this->request->post('__token__', '')));
        if ($requestToken === '') {
            $requestToken = trim((string)$this->request->header('X-CSRF-Token', ''));
        }
        if ($requestToken === '') {
            return false;
        }

        $sessionToken = (string)Session::get($tokenName, '');

        return $sessionToken !== '' && hash_equals($sessionToken, $requestToken);
    }

    private function clearPendingAdminTwofaSetup(): void
    {
        Session::delete('admin_twofa_temp_secret');
        Session::delete('admin_twofa_temp_recovery_codes');
        Session::delete('admin_twofa_temp_admin_id');
    }

    private function generateAdminRecoveryCodes(int $count = 8, int $length = 8): array
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

    private function hashAdminRecoveryCodes(array $recoveryCodes): string
    {
        $hashes = [];
        foreach ($recoveryCodes as $code) {
            $hashes[] = password_hash((string)$code, PASSWORD_BCRYPT);
        }

        return (string)json_encode($hashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getAdminRecoveryCodeHashes(string $stored): array
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

    private function consumeAdminRecoveryCode(AdminModel $admin, string $recoveryCode, string $purpose = 'general'): array
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

    private function encryptAdminData(string $data): string
    {
        $key = $this->requireEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new Exception('敏感数据加密失败');
        }

        return base64_encode($iv . $encrypted);
    }

    private function decryptAdminData(string $data): string
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

    private function validateAdminTwofaCodeInput(string $code): ?string
    {
        if ($code === '') {
            return '请输入二步验证码';
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return '请输入6位二步验证码';
        }

        return null;
    }

    private function verifyAdminTwofaCode(AdminModel $admin, string $code, int $window = 2): array
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

    private function verifyAdminTwofaOrRecovery(AdminModel $admin, array $postInfo, string $purpose = 'general'): array
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

    private function detectAdminLoginVerificationAttempt(string $verificationCode): string
    {
        $verificationCode = trim($verificationCode);
        if ($verificationCode === '') {
            return 'missing';
        }

        return preg_match('/^\d{6}$/', $verificationCode) ? 'twofa' : 'recovery';
    }

    private function beginAdminTwofaSetup(AdminModel $admin, bool $allowReset = false): array
    {
        $this->requireEncryptionKey();

        if (!$allowReset && ($admin->twofa_enabled || !empty($admin->twofa_secret))) {
            throw new \RuntimeException('您已开启2FA认证，无需重复设置');
        }

        $twofa = new TwoFactorAuth();
        $secret = $twofa->createSecret();
        $issuer = trim((string)($this->config['name'] ?? $this->config['site_name'] ?? 'Admin Panel'));
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

    private function requireEncryptionKey(): string
    {
        if ($this->encryptionKeyLoaded) {
            return $this->encryptionKey;
        }

        $this->encryptionKey = SecurityKeyResolver::resolveDataEncryptionKey();
        $this->encryptionKeyLoaded = true;

        return $this->encryptionKey;
    }

    private function directValidateSensitiveOperation(array $postInfo, string $scene): array
    {
        $adminId = (int)($this->admin_info['id'] ?? 0);
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

    private function directHasAdminPermission(string $permission): bool
    {
        $adminId = (int)($this->admin_info['id'] ?? 0);
        if ($adminId === 1) {
            return true;
        }

        if ($adminId <= 0) {
            return false;
        }

        return power((string)($this->admin_info['power'] ?? ''), $permission) != 2;
    }

    private function directDenyAdminPermission(string $permission)
    {
        Log::warning('admin permission denied', [
            'admin_id' => (int)($this->admin_info['id'] ?? 0),
            'permission' => $permission,
            'ip' => (string)$this->request->ip(),
            'path' => $this->directCurrentRequestPath(),
        ]);

        return show(403, 'error', '权限不足');
    }

    private function directGetAllowedAdminPowerList(): array
    {
        return [
            "用户列表", "支付管理", "充值业务 - 产品列表", "查询业务 - 产品列表",
            "充值业务 - 订单列表", "查询业务 - 订单列表", "交易挂单数据", "交易订单数据",
            "充值订单记录", "提现订单记录", "返佣记录", "首页轮播图",
            "积分管理", "管理员列表", "操作记录", "系统设置管理"
        ];
    }

    private function directValidateAdminPowerValue(string $power): array
    {
        $allowed = $this->directGetAllowedAdminPowerList();
        if (trim($power) === '') {
            return ['ok' => true, 'message' => '', 'cleaned' => ''];
        }
        $items = preg_split('/[,，]/', $power);
        $items = array_map('trim', $items);
        $items = array_filter($items, function ($v) { return $v !== ''; });
        $items = array_values($items);
        $invalid = array_diff($items, $allowed);
        if (!empty($invalid)) {
            return ['ok' => false, 'message' => '包含非法权限项：' . implode('、', $invalid), 'cleaned' => ''];
        }
        return ['ok' => true, 'message' => '', 'cleaned' => implode(',', $items)];
    }

    private function directIsCurrentAdminSuperAdmin(): bool
    {
        return (int)($this->admin_info['id'] ?? 0) === 1;
    }

    private function directMaskSensitiveLogValue(string $key, mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '[complex data]';
        }

        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return '';
        }

        if (!in_array($key, ['bepusdt_api_token', 'epay_key', 'telegram_bot_token', 'chatwoot_token', 'payment_address'], true)) {
            return $stringValue;
        }

        $length = mb_strlen($stringValue, 'UTF-8');
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return mb_substr($stringValue, 0, 4, 'UTF-8')
            . str_repeat('*', max(4, $length - 8))
            . mb_substr($stringValue, $length - 4, 4, 'UTF-8');
    }

    private function directBuildSecurityLogPayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            $sanitized[(string)$key] = $this->directMaskSensitiveLogValue((string)$key, $value);
        }

        return $sanitized;
    }

    private function directLogConfigWriteAttempt(string $message, array $payload, string $level = 'info'): void
    {
        $context = [
            'admin_id' => (int)($this->admin_info['id'] ?? 0),
            'ip' => (string)$this->request->ip(),
            'path' => $this->directCurrentRequestPath(),
            'referer' => (string)$this->request->header('referer', ''),
            'post_keys' => array_keys($payload),
            'post_data' => $this->directBuildSecurityLogPayload($payload),
        ];

        if ($level === 'warning') {
            Log::warning($message, $context);
            return;
        }

        Log::info($message, $context);
    }

    private function directOrderStatusText(int $status): string
    {
        return [
            0 => '待充值',
            1 => '处理中',
            2 => '已完成',
            3 => '已取消',
        ][$status] ?? '未知状态';
    }

    private function directOrderConfirmStatusText(int $status): string
    {
        return [
            0 => '未完成',
            1 => '待确认',
            2 => '已确认',
            3 => '未收到',
        ][$status] ?? '未知状态';
    }

    private function rotateSessionForAdminLogin(array $adminData): void
    {
        Session::delete('admin');
        $this->clearPendingAdminTwofaSetup();
        Session::regenerate(true);
        Session::set('admin', $adminData);
    }

    private function destroyAdminSession(): void
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

    private function directLockUser(int $uid)
    {
        if ($uid <= 0) {
            return null;
        }
        return UserModel::where('id', $uid)->lock(true)->find();
    }

    private function directAdminAdjustBalanceWithLedger($user, float $amount, int $bizId, string $bizNo, string $changeType, string $remark): array
    {
        $delta = $changeType === 'admin_balance_subtract' ? -1 * round($amount, 2) : round($amount, 2);

        return (new UserFundLedgerService())->changeLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_BALANCE,
            $delta,
            [
                'biz_type' => 'admin_balance_adjust',
                'biz_id' => $bizId,
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'change_type' => $changeType,
                'operator_type' => 'admin',
                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                'status' => 'done',
                'request_no' => 'admin_balance_adjust:' . $bizNo,
                'remark' => $remark,
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_user_post_balance',
                    'adjust_mode' => $changeType === 'admin_balance_subtract' ? 'subtract' : 'add',
                ],
            ]
        );
    }
    

    private function directRefundProductOrderCancelToBalance($user, $order, float $refundUsdt): array
    {
        $balanceBefore = round((float)($user['balance'] ?? 0), 2);
        $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            UserFundLedgerService::WALLET_BALANCE,
            round($refundUsdt, 2),
            [
                'biz_type' => 'product_order',
                'biz_id' => (int)($order['id'] ?? 0),
                'biz_no' => (string)($order['order_number'] ?? ''),
                'order_number' => (string)($order['order_number'] ?? ''),
                'out_change_type' => 'product_order_cancel_refund',
                'in_change_type' => 'product_order_cancel_refund',
                'operator_type' => 'admin',
                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                'status' => 'done',
                'request_no' => 'product_order_cancel_refund:' . (string)($order['order_number'] ?? ''),
                'remark' => 'product order cancel refund',
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_product_order_cancel_refund',
                    'refund_scene' => 'order_cancel_refund',
                    'order_status_before' => (int)($order['status'] ?? 0),
                    'confirm_status_before' => (int)($order['confirm_status'] ?? 0),
                ],
            ]
        );
        $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
        $balanceAfter = array_key_exists('balance', $walletSnapshot)
            ? round((float)($walletSnapshot['balance'] ?? 0), 2)
            : round((float)($user['balance'] ?? ($balanceBefore + $refundUsdt)), 2);

        $this->directWriteBalanceLog([
            'uid' => (int)($order['uid'] ?? 0),
            'scene' => 'order_cancel_refund',
            'amount' => $refundUsdt,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'biz_id' => (int)($order['id'] ?? 0),
            'order_number' => (string)($order['order_number'] ?? ''),
            'remark' => '订单取消退款',
            'operator_id' => (int)($this->admin_info['id'] ?? 0),
        ]);

        return [
            'ledger_result' => $ledgerResult,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
        ];
    }

    private function directMatchSettlementDiscount(int $productId, float $amountReceived): array
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception('商品不存在');
        }
        $discountList = $product['discount'] ?? [];
        if (!is_array($discountList) || empty($discountList)) {
            throw new Exception('商品折扣配置异常');
        }
        usort($discountList, function ($a, $b) {
            return (float)($a['mini_amount'] ?? 0) <=> (float)($b['mini_amount'] ?? 0);
        });
        $minimumTier = $discountList[0];
        $fallbackTier = $minimumTier;
        $matchedTier = null;
        foreach ($discountList as $item) {
            $miniAmount = round((float)($item['mini_amount'] ?? 0), 2);
            $maxiAmount = round((float)($item['maxi_amount'] ?? 0), 2);
            if ($amountReceived >= $miniAmount) {
                $fallbackTier = $item;
            }
            if ($amountReceived >= $miniAmount && $amountReceived <= $maxiAmount) {
                $matchedTier = $item;
                break;
            }
        }
        if ($matchedTier === null) {
            $matchedTier = $amountReceived < (float)($minimumTier['mini_amount'] ?? 0) ? $minimumTier : $fallbackTier;
        }
        return [
            'mini_amount' => round((float)($matchedTier['mini_amount'] ?? 0), 2),
            'discount' => round((float)($matchedTier['discount'] ?? 0), 2),
        ];
    }

    private function directBuildOrderSettlement($order, float $rate): array
    {
        $amountMoney = round((float)($order['amount_money'] ?? 0), 2);
        $amountReceived = round((float)($order['amount_received'] ?? 0), 2);
        $originalPayCny = order_original_pay_cny($order);
        $operatorId = (int)($this->admin_info['id'] ?? 0);
        $settlement = [
            'settlement_match_amount' => null,
            'settlement_match_discount' => null,
            'settlement_final_cny_amount' => $originalPayCny,
            'settlement_refund_cny_amount' => 0.00,
            'settlement_refund_rate' => null,
            'settlement_refund_usdt_amount' => 0.00,
            'settlement_refund_time' => null,
            'settlement_operator_id' => $operatorId,
        ];
        if ($amountReceived <= 0) {
            return $settlement;
        }
        if ($amountReceived > $amountMoney) {
            throw new Exception('实际到账金额不能大于订单充值金额');
        }
        if (abs($amountReceived - $amountMoney) < 0.000001) {
            return $settlement;
        }

        $matched = $this->directMatchSettlementDiscount((int)($order['product_id'] ?? 0), $amountReceived);
        $effectiveDiscount = (float)($matched['discount'] ?? 0);
        $finalPayCny = round($amountReceived * ($effectiveDiscount / 10), 2);

        $substationId = (int)($order['substation_id'] ?? 0);
        if ($substationId > 0) {
            $priceInfo = SubstationPriceService::resolveDiscountPreview((int)($order['product_id'] ?? 0), $amountReceived, $substationId);
            $effectiveDiscount = round((float)($priceInfo['discount'] ?? $effectiveDiscount), 2);
            $finalPayCny = round((float)($priceInfo['paymentAmount'] ?? $finalPayCny), 2);
            $matched['mini_amount'] = round((float)($amountReceived), 2);
        }

        $refundCny = round($originalPayCny - $finalPayCny, 2);
        if ($refundCny < 0) {
            throw new Exception('应退人民币金额不能小于0');
        }
        $refundUsdt = round($refundCny / $rate, 2);
        $settlement['settlement_match_amount'] = $matched['mini_amount'];
        $settlement['settlement_match_discount'] = $effectiveDiscount;
        $settlement['settlement_final_cny_amount'] = $finalPayCny;
        $settlement['settlement_refund_cny_amount'] = $refundCny;
        $settlement['settlement_refund_rate'] = round($rate, 6);
        $settlement['settlement_refund_usdt_amount'] = $refundUsdt;
        $settlement['settlement_refund_time'] = $refundUsdt > 0 ? date('Y-m-d H:i:s') : null;
        return $settlement;
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

    private function directCompleteRechargeOrder(int $orderId): void
    {
        $order = null;
        $orderSnapshot = [];
        $originalStatus = 0;
        $originalConfirmStatus = 0;
        $refundUsdt = 0.0;
        Db::startTrans();
        try {
            $order = Order::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new Exception('订单不存在');
            }
            $originalStatus = (int)($order['status'] ?? 0);
            $originalConfirmStatus = (int)($order['confirm_status'] ?? 0);
            if (!in_array((int)$order['status'], [0, 1], true)) {
                throw new Exception('审核异常');
            }
            $user = $this->directLockUser((int)($order['uid'] ?? 0));
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $rate = (float)(getConfig('rate') ?? 0);
            if ($rate <= 0) {
                throw new Exception('后台汇率未配置');
            }
            $settlement = $this->directBuildOrderSettlement($order, $rate);
            $refundUsdt = round((float)($settlement['settlement_refund_usdt_amount'] ?? 0), 2);
            if ($refundUsdt > 0) {
                $balanceBefore = round((float)($user['balance'] ?? 0), 2);
                $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
                    $user,
                    UserFundLedgerService::WALLET_FROZEN,
                    UserFundLedgerService::WALLET_BALANCE,
                    $refundUsdt,
                    [
                        'biz_type' => 'product_order',
                        'biz_id' => (int)$order['id'],
                        'biz_no' => (string)$order['order_number'],
                        'order_number' => (string)$order['order_number'],
                        // Settlement refund should use a distinct ledger scene from cancel refund.
                        'out_change_type' => 'product_order_partial_refund',
                        'in_change_type' => 'product_order_partial_refund',
                        'operator_type' => 'admin',
                        'operator_id' => (int)($this->admin_info['id'] ?? 0),
                        'status' => 'done',
                        'request_no' => 'refund:product_order_partial:' . (string)$order['order_number'],
                        'remark' => 'product order partial refund',
                        'idempotent' => true,
                        'extra' => [
                            'source' => 'directCompleteRechargeOrder',
                            'refund_scene' => 'order_complete_partial_refund',
                            'settlement_match_amount' => round((float)($settlement['settlement_match_amount'] ?? 0), 2),
                            'settlement_final_cny_amount' => round((float)($settlement['settlement_final_cny_amount'] ?? 0), 2),
                            'settlement_refund_usdt_amount' => $refundUsdt,
                        ],
                    ]
                );
                $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                $balanceAfter = array_key_exists('balance', $walletSnapshot)
                    ? round((float)$walletSnapshot['balance'], 2)
                    : round((float)($user['balance'] ?? ($balanceBefore + $refundUsdt)), 2);
                $this->directWriteBalanceLog([
                    'uid' => (int)$order['uid'],
                    'scene' => 'order_complete_partial_refund',
                    'amount' => $refundUsdt,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'biz_id' => (int)$order['id'],
                    'order_number' => (string)$order['order_number'],
                    'remark' => '订单完成部分退款',
                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                ]);
            }
            $order->settlement_match_amount = $settlement['settlement_match_amount'];
            $order->settlement_match_discount = $settlement['settlement_match_discount'];
            $order->settlement_final_cny_amount = $settlement['settlement_final_cny_amount'];
            $order->settlement_refund_cny_amount = $settlement['settlement_refund_cny_amount'];
            $order->settlement_refund_rate = $settlement['settlement_refund_rate'];
            $order->settlement_refund_usdt_amount = $settlement['settlement_refund_usdt_amount'];
            $order->settlement_refund_time = $settlement['settlement_refund_time'];
            $order->settlement_operator_id = $settlement['settlement_operator_id'];
            $order->operator_id = (int)($this->admin_info['id'] ?? 0);
            $order->status = 2;
            $order->confirm_status = 1;
            $order->complete_time = date('Y-m-d H:i:s');
            $order->save();
            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        if ($order) {
            try {
                (new OrderTelegramNotifier())->notifyProductOrderCompleted($orderSnapshot);
            } catch (\Throwable $notifyException) {
                Log::error('product order notify failed', [
                    'order_id' => (int)($orderSnapshot['id'] ?? 0),
                    'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                    'uid' => (int)($orderSnapshot['uid'] ?? 0),
                    'action' => 'product_order_completed_notify',
                    'error_message' => $notifyException->getMessage(),
                ]);
            }
            rebate((string)($order['order_number'] ?? ''));
            SubstationSettlementService::settleCompletedOrder((int)$order['id'], (int)($this->admin_info['id'] ?? 0));
            $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order['order_number'] ?? '') . '，UID：' . (int)($order['uid'] ?? 0) . '，订单状态：' . $this->directOrderStatusText($originalStatus) . ' -> ' . $this->directOrderStatusText((int)($order['status'] ?? 0)) . '，确认状态：' . $this->directOrderConfirmStatusText($originalConfirmStatus) . ' -> ' . $this->directOrderConfirmStatusText((int)($order['confirm_status'] ?? 0)) . '，实际到账：' . (string)($order['amount_received'] ?? '未设置') . '，退款：' . number_format($refundUsdt, 2) . ' USDT', [
                'target_id' => (int)($order['id'] ?? 0),
                'target_type' => 'order',
            ]);
        }
    }

    private function directRefundRemainingUsdt(int $orderId): void
    {
        $orderSnapshot = [];
        $orderNumber = '';
        $uid = 0;
        $refundUsdt = 0.0;
        Db::startTrans();
        try {
            $order = Order::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new Exception('订单不存在');
            }
            $orderNumber = (string)($order['order_number'] ?? '');
            $uid = (int)($order['uid'] ?? 0);
            $status = (int)($order['status'] ?? 0);
            $confirmStatus = (int)($order['confirm_status'] ?? 0);
            if (!(in_array($status, [0, 1], true) || ($status === 2 && $confirmStatus === 3))) {
                throw new Exception('审核异常');
            }
            $user = $this->directLockUser((int)($order['uid'] ?? 0));
            if (!$user) {
                throw new Exception('用户不存在');
            }
            $refundUsdt = order_refundable_usdt($order);
            if ($refundUsdt > 0) {
                $this->directRefundProductOrderCancelToBalance($user, $order, $refundUsdt);
            }
            $order->status = 3;
            $order->operator_id = (int)($this->admin_info['id'] ?? 0);
            $order->save();
            $orderSnapshot = $order->toArray();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        try {
            (new OrderTelegramNotifier())->notifyProductOrderCancelled($orderSnapshot, '后台退款取消');
        } catch (\Throwable $notifyException) {
            Log::error('product order notify failed', [
                'order_id' => (int)($orderSnapshot['id'] ?? 0),
                'order_no' => (string)($orderSnapshot['order_number'] ?? ''),
                'uid' => (int)($orderSnapshot['uid'] ?? 0),
                'action' => 'product_order_cancelled_notify',
                'error_message' => $notifyException->getMessage(),
            ]);
        }
        $this->directWriteAdminOperationLog('手动退款', '订单管理', '订单号：' . $orderNumber . '，UID：' . $uid . '，退款金额：' . number_format($refundUsdt, 2) . ' USDT', [
            'target_id' => $orderId,
            'target_type' => 'order',
        ]);
    }

    private function handleTransactionProductOperate(array $post_info)
    {
        // 预读（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
        $preRead = TransactionProduct::find($post_info['id']);
        if(!$preRead || !in_array((int)$preRead['status'], [1, 2], true)){
            return show(500, 'error', '操作异常');
        }
        try {
            Db::startTrans();
            // 统一锁顺序：先锁 seller，再锁 product
            // 避免与 releaseBySeller(order→seller→product) 形成 product↔seller 循环等待死锁
            $seller = $this->directLockUser((int)$preRead['uid']);
            if (!$seller) {
                throw new Exception('用户不存在');
            }
            $TransactionProduct_info = TransactionProduct::where('id', $post_info['id'])->lock(true)->find();
            if (!$TransactionProduct_info || !in_array((int)$TransactionProduct_info['status'], [1, 2], true)) {
                Db::rollback();
                return show(500, 'error', '操作异常');
            }

            // 关闭挂单(status=3)前必须检查活跃订单：存在待汇款(0)或已汇款(1)订单时禁止关闭
            if ((int)$post_info['status'] === 3) {
                $activeCommitted = (float)Db::name('transaction_order')
                    ->where('pid', (int)$TransactionProduct_info['id'])
                    ->whereIn('status', [0, 1])
                    ->lock(true)
                    ->sum('pay_amount');
                if ($activeCommitted > 0.005) {
                    Db::rollback();
                    return show(500, 'error', '该挂单存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法关闭，请先处理订单');
                }
            }

            $TransactionProduct_info->status = $post_info['status'];
            $TransactionProduct_info->save();

            if($post_info['status'] == 3){
                $refundAmount = (float)($TransactionProduct_info['sell_account'] ?? 0);
                if ($refundAmount > 0) {
                    $this->releaseTransactionListingByAdmin($seller, $TransactionProduct_info, $refundAmount, 0.0);
                }
            }
                Db::commit();
                return show(200, 'success', '操作成功');
            } catch (\Throwable $e) {
                Db::rollback();
                Log::error('admin transaction_product_post operate error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                return show(500, 'error', '操作异常');
            }
    }

    private function transactionListingBizNo($listing): string
    {
        return 'listing:' . (int)($listing['id'] ?? 0);
    }

    private function releaseTransactionListingByAdmin($user, $listing, float $amount, ?float $targetSellAccount = 0.0): array
    {
        $bizNo = $this->transactionListingBizNo($listing);

        return (new UserFundLedgerService())->transferLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_FROZEN,
            UserFundLedgerService::WALLET_BALANCE,
            round($amount, 2),
            [
                'biz_type' => 'transaction_listing',
                'biz_id' => (int)($listing['id'] ?? 0),
                'biz_no' => $bizNo,
                'order_number' => $bizNo,
                'out_change_type' => 'transaction_listing_release',
                'in_change_type' => 'transaction_listing_release',
                'operator_type' => 'admin',
                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                'status' => 'done',
                'request_no' => 'transaction_listing_release:' . $bizNo . ':target:' . number_format((float)$targetSellAccount, 2, '.', ''),
                'remark' => 'transaction listing release',
                'idempotent' => true,
                'extra' => [
                    'source' => 'admin_transaction_listing_release',
                    'target_sell_account' => $targetSellAccount,
                ],
            ]
        );
    }
    private function handleTransactionProductDelete(array $post_info)
    {
        // 预读（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
        $preRead = TransactionProduct::find($post_info['id']);
        if (!$preRead) {
            return show(404, 'error', '挂单不存在');
        }
        try {
            Db::startTrans();
            // 统一锁顺序：先锁 seller，再锁 product
            $seller = $this->directLockUser((int)$preRead['uid']);
            if (!$seller) {
                throw new Exception('卖家用户不存在');
            }
            $TransactionProduct_info = TransactionProduct::where('id', $post_info['id'])->lock(true)->find();
            if (!$TransactionProduct_info) {
                Db::rollback();
                return show(404, 'error', '挂单不存在');
            }

            // 检查活跃订单：存在待汇款(0)或已汇款(1)订单时禁止删除
            $activeCommitted = (float)Db::name('transaction_order')
                ->where('pid', (int)$TransactionProduct_info['id'])
                ->whereIn('status', [0, 1])
                ->lock(true)
                ->sum('pay_amount');
            if ($activeCommitted > 0.005) {
                Db::rollback();
                return show(500, 'error', '该挂单存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法删除');
            }

            // 退还剩余冻结资金（seller 已锁定）
            $refundAmount = (float)($TransactionProduct_info['sell_account'] ?? 0);
            if ($refundAmount > 0.005) {
                $this->releaseTransactionListingByAdmin($seller, $TransactionProduct_info, $refundAmount, 0.0);
            }

            TransactionProduct::destroy($post_info['id']);
            Db::commit();
            return show(200, 'success', '删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin transaction_product_post del error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
            return show(500, 'error', '删除失败');
        }
    }

    private function handleTransactionProductDeleteBatch(array $post_info)
    {
        try {
            Db::startTrans();
            $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
            if (empty($ids)) {
                Db::rollback();
                return show(500, 'error', '请选择要删除的挂单');
            }

            // 预读所有挂单（不加锁）获取卖家 uid，用于统一锁顺序 seller → product
            $preReads = TransactionProduct::where('id', 'in', array_map('intval', $ids))->select();
            if (count($preReads) !== count($ids)) {
                throw new Exception('部分挂单不存在');
            }

            foreach ($preReads as $preRead) {
                // 统一锁顺序：先锁 seller，再锁 product
                $seller = $this->directLockUser((int)$preRead['uid']);
                if (!$seller) {
                    throw new Exception('挂单 #' . (int)$preRead['id'] . ' 卖家用户不存在');
                }
                $product = TransactionProduct::where('id', (int)$preRead['id'])->lock(true)->find();
                if (!$product) {
                    throw new Exception('挂单不存在: ' . (int)$preRead['id']);
                }

                // 检查活跃订单
                $activeCommitted = (float)Db::name('transaction_order')
                    ->where('pid', (int)$product['id'])
                    ->whereIn('status', [0, 1])
                    ->lock(true)
                    ->sum('pay_amount');
                if ($activeCommitted > 0.005) {
                    throw new Exception('挂单 #' . (int)$product['id'] . ' 存在进行中的交易订单（占用 ' . $activeCommitted . ' USDT），无法删除');
                }

                // 退还剩余冻结资金（seller 已锁定）
                $refundAmount = (float)($product['sell_account'] ?? 0);
                if ($refundAmount > 0.005) {
                    $this->releaseTransactionListingByAdmin($seller, $product, $refundAmount, 0.0);
                }

                TransactionProduct::destroy((int)$product['id']);
            }

            Db::commit();
            return show(200, 'success', '批量删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin transaction_product_post batch_del error: ' . $e->getMessage(), ['ids' => $post_info['ids'] ?? []]);
            return show(500, 'error', $e->getMessage() ?: '批量删除失败');
        }
    }

    private function handleBalance(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post balance invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '余额请求校验失败');
        }
        $configFieldKeys = array_values(array_intersect(array_keys((array)$post_info), $this->directAllowedConfigKeys()));
        if (!empty($configFieldKeys)) {
            Log::warning('admin user_post balance polluted payload blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
                'blocked_keys' => $configFieldKeys,
            ]);
            return show(403, 'error', '余额请求包含非法配置字段');
        }
        if (!$this->directRequestPathMatches('user_post/balance')) {
            Log::warning('admin user_post balance invalid path blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }
        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'user_balance');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }
        $post_info = array_intersect_key((array)$post_info, array_flip(['uid', 'balance_cz', 'add_minus']));
        try {
            Db::startTrans();
            $user_info = $this->directLockUser((int)$post_info['uid']);
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $amount = (float)($post_info['balance_cz'] ?? 0);
            if ($amount <= 0) {
                Db::rollback();
                return show(500, 'error', '金额有误');
            }
            if ($post_info['add_minus'] === 'add') {
                $beforeBalance = (float)($user_info['balance'] ?? 0);
                $bizNo = date("Ymd") . randomkeys(6, 'number');
                $recharge = Recharge::create([
                    'uid' => $user_info['id'],
                    'amount' => $amount,
                    'wallet_address' => '后台充值加款',
                    'status' => 3,
                    'order_number' => $bizNo,
                ]);
                if (!$recharge) {
                    throw new Exception('调账记录创建失败');
                }
                $ledgerResult = $this->directAdminAdjustBalanceWithLedger(
                    $user_info,
                    $amount,
                    (int)($recharge['id'] ?? 0),
                    $bizNo,
                    'admin_balance_add',
                    '后台人工加款'
                );
                $balanceAfter = round((float)($ledgerResult['after_amount'] ?? ($beforeBalance + $amount)), 2);
                Db::commit();
                $this->directWriteAdminOperationLog('修改用户余额', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，变更：增加 ' . number_format($amount, 2) . ' USDT，余额：' . number_format($beforeBalance, 2) . ' -> ' . number_format($balanceAfter, 2), [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]);
                return show(200, 'success', '加款成功');
            }else if ($post_info['add_minus'] === 'minus') {
                $beforeBalance = (float)($user_info['balance'] ?? 0);
                $bizNo = date("Ymd") . randomkeys(6, 'number');
                $recharge = Recharge::create([
                    'uid' => $user_info['id'],
                    'amount' => $amount,
                    'wallet_address' => '后台充值扣款',
                    'status' => 3,
                    'operate_type' => 1,
                    'order_number' => $bizNo,
                ]);
                if (!$recharge) {
                    throw new Exception('调账记录创建失败');
                }
                $ledgerResult = $this->directAdminAdjustBalanceWithLedger(
                    $user_info,
                    $amount,
                    (int)($recharge['id'] ?? 0),
                    $bizNo,
                    'admin_balance_subtract',
                    '后台人工扣款'
                );
                $balanceAfter = round((float)($ledgerResult['after_amount'] ?? ($beforeBalance - $amount)), 2);
                Db::commit();
                $this->directWriteAdminOperationLog('修改用户余额', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，变更：减少 ' . number_format($amount, 2) . ' USDT，余额：' . number_format($beforeBalance, 2) . ' -> ' . number_format($balanceAfter, 2), [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]);
                return show(200, 'success', '扣款成功');
            }
            Db::rollback();
            return show(500, 'error', '操作类型错误');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post balance error: ' . $e->getMessage(), ['uid' => (int)($post_info['uid'] ?? 0)]);
            return show(500, 'error', '操作失败');
        }
    }

    private function handleUserPassword(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post password invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '密码重置请求校验失败');
        }
        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'user_password_reset');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        $uid = (int)($post_info['uid'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }
        $newPassword = (string)($post_info['password'] ?? '');
        if ($newPassword === '') {
            return show(500, 'error', '新密码不能为空');
        }

        try {
            Db::startTrans();
            $user_info = $this->directLockUser($uid);
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $salt = randomkeys(4);
            $user_info->password = password_hash(($newPassword . $salt), PASSWORD_BCRYPT);
            $user_info->salt = $salt;
            $user_info->save();
            Db::commit();

            $this->directWriteAdminOperationLog('重置用户密码', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，已由管理员重置登录密码', [
                'target_id' => (int)$user_info['id'],
                'target_type' => 'user',
            ]);

            return show(200, 'success', '修改成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post password error: ' . $e->getMessage(), [
                'uid' => $uid,
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
            ]);
            return show(500, 'error', '修改失败');
        }
    }

    private function handleUserStatusSwitch(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post status_switch invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '状态切换请求校验失败');
        }

        $uid = (int)($post_info['uid'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        try {
            Db::startTrans();
            $res = $this->directLockUser($uid);
            if (!$res) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $oldStatus = (int)$res['status'];
            $newStatus = ($oldStatus === 0) ? 1 : 0;
            $res->status = $newStatus;
            $res->save();
            Db::commit();

            $this->directWriteAdminOperationLog('切换用户状态', '用户管理', '用户UID：' . (int)$res['id'] . '，账号：' . (string)($res['mobile'] ?? '') . '，状态：' . ($oldStatus === 0 ? '禁用' : '启用') . ' -> ' . ($newStatus === 0 ? '禁用' : '启用'), [
                'target_id' => (int)$res['id'],
                'target_type' => 'user',
            ]);

            return show(200, 'success', '状态更新成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post status_switch error: ' . $e->getMessage(), [
                'uid' => $uid,
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
            ]);
            return show(500, 'error', '状态更新失败');
        }
    }

    private function handleUserTwofaUnbind(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post twofa_unbind invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '解绑请求校验失败');
        }
        if (!$this->directRequestPathMatches('user_post/twofa_unbind')) {
            Log::warning('admin user_post twofa_unbind invalid path blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }

        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'user_twofa_unbind');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        try {
            Db::startTrans();
            $user_info = $this->directLockUser((int)($post_info['uid'] ?? 0));
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }

            if (empty($user_info['twofa_enabled']) && empty($user_info['twofa_secret']) && empty($user_info['twofa_recovery_codes'])) {
                Db::rollback();
                return show(500, 'error', '该用户未启用2FA');
            }

            $user_info->twofa_enabled = 0;
            $user_info->twofa_secret = null;
            $user_info->twofa_recovery_codes = null;
            $user_info->save();

            Db::commit();

            $this->directWriteAdminOperationLog(
                '解绑用户2FA',
                '用户管理',
                '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，已由管理员强制清空2FA绑定',
                [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]
            );

            return show(200, 'success', '用户2FA解绑成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post twofa_unbind error: ' . $e->getMessage(), [
                'uid' => (int)($post_info['uid'] ?? 0),
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
            ]);
            return show(500, 'error', '解绑失败');
        }
    }

    private function handleUserRights(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post rights invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '权益请求校验失败');
        }

        if (!$this->directRequestPathMatches('user_post/rights')) {
            Log::warning('admin user_post rights invalid path blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }

        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'user_rights');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        $uid = (int)($post_info['uid'] ?? 0);
        $rightsAction = trim((string)($post_info['rights_action'] ?? ''));
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        $allowedActions = ['vip_open', 'vip_close', 'svip_open', 'svip_close'];
        if (!in_array($rightsAction, $allowedActions, true)) {
            return show(500, 'error', '权益操作类型错误');
        }

        try {
            $message = Db::transaction(function () use ($uid, $rightsAction) {
                $user = $this->directLockUser($uid);
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                if ($rightsAction === 'vip_open') {
                    $user->agent_status = 1;
                    $user->save();
                    return 'VIP 已开通';
                }

                if ($rightsAction === 'vip_close') {
                    $user->agent_status = 0;
                    $user->save();
                    return 'VIP 已关闭';
                }

                $substation = Substation::where('uid', $uid)->lock(true)->find();
                if (!$substation) {
                    $substation = Substation::create([
                        'uid' => $uid,
                        'status' => 0,
                        'wallet_balance' => 0,
                        'wallet_total_income' => 0,
                        'wallet_total_transferred' => 0,
                        'income_balance' => 0,
                        'create_time' => date('Y-m-d H:i:s'),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                    $substation = Substation::where('id', (int)$substation['id'])->lock(true)->find();
                }

                if ($rightsAction === 'svip_open') {
                    $substation->status = 2;
                    if (trim((string)($substation['open_time'] ?? '')) === '') {
                        $substation->open_time = date('Y-m-d H:i:s');
                    }
                    $substation->reject_reason = null;
                    $substation->update_time = date('Y-m-d H:i:s');
                    $substation->save();

                    // 业务规则：SVIP 包含 VIP。
                    $user->agent_status = 1;
                    $user->save();
                    return 'SVIP 已开通（已同步开通 VIP）';
                }

                $substation->status = 0;
                $substation->reject_reason = null;
                $substation->update_time = date('Y-m-d H:i:s');
                $substation->save();
                return 'SVIP 已关闭';
            });

            return show(200, 'success', $message);
        } catch (\Throwable $e) {
            Log::error('admin user_post rights error: ' . $e->getMessage(), [
                'uid' => $uid,
                'rights_action' => $rightsAction,
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
            ]);
            return show(500, 'error', $e->getMessage() ?: '权益操作失败');
        }
    }

    private function directCheckUserPendingBusiness(int $uid): array
    {
        if ($uid <= 0) {
            return ['ok' => false, 'message' => '用户参数错误'];
        }
        // 充值订单：status=0 待支付（链上/网关）、status=1 待审核（手动充值）
        $pendingRecharge = Recharge::where('uid', $uid)->whereIn('status', [0, 1])->count();
        if ($pendingRecharge > 0) {
            return ['ok' => false, 'message' => '存在待处理充值订单（' . $pendingRecharge . '笔）'];
        }
        // 提现订单：status=0 待审核
        $pendingWithdrawal = Withdrawal::where('uid', $uid)->where('status', 0)->count();
        if ($pendingWithdrawal > 0) {
            return ['ok' => false, 'message' => '存在待审核提现订单（' . $pendingWithdrawal . '笔）'];
        }
        // C2C 订单：作为买家 status IN (0,1)
        $pendingBuyOrder = TransactionOrder::where('uid', $uid)->whereIn('status', [0, 1])->count();
        if ($pendingBuyOrder > 0) {
            return ['ok' => false, 'message' => '存在进行中的C2C买入订单（' . $pendingBuyOrder . '笔）'];
        }
        // C2C 订单：作为卖家 status IN (0,1)
        $pendingSellOrder = TransactionOrder::where('sell_uid', $uid)->whereIn('status', [0, 1])->count();
        if ($pendingSellOrder > 0) {
            return ['ok' => false, 'message' => '存在进行中的C2C卖出订单（' . $pendingSellOrder . '笔）'];
        }
        // 交易挂单：status IN (1,2) 且有剩余可售量（冻结资金未释放）
        $pendingListing = TransactionProduct::where('uid', $uid)->whereIn('status', [1, 2])->where('sell_account', '>', 0)->count();
        if ($pendingListing > 0) {
            return ['ok' => false, 'message' => '存在进行中的交易挂单（' . $pendingListing . '个）'];
        }
        // 钱包余额检查：余额/冻结/代理钱包有余额时禁止删除
        $user = UserModel::where('id', $uid)->field('id,balance,frozen_amount,agent_wallet,mobile')->find();
        if ($user) {
            $balance = round((float)($user['balance'] ?? 0), 2);
            $frozen = round((float)($user['frozen_amount'] ?? 0), 2);
            $agentWallet = round((float)($user['agent_wallet'] ?? 0), 2);
            if ($balance > 0.005 || $frozen > 0.005 || $agentWallet > 0.005) {
                return ['ok' => false, 'message' => '用户钱包存在余额（余额:' . $balance . ' 冻结:' . $frozen . ' 代理钱包:' . $agentWallet . '）'];
            }
        }
        return ['ok' => true, 'message' => ''];
    }

    private function handleUserDeleteBatch(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post dels invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'ids' => $post_info['ids'] ?? [],
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '删除请求校验失败');
        }

        $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
        if (empty($ids)) {
            return show(500, 'error', '请选择要删除的用户');
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (empty($ids)) {
            return show(500, 'error', '用户参数错误');
        }

        // 先全部检查通过，再执行删除（避免删了一半才失败）
        $failedChecks = [];
        foreach ($ids as $uid) {
            $pendingCheck = $this->directCheckUserPendingBusiness($uid);
            if (empty($pendingCheck['ok'])) {
                $failedChecks[] = 'UID#' . $uid . '：' . $pendingCheck['message'];
            }
        }
        if (!empty($failedChecks)) {
            return show(500, 'error', '以下用户无法删除：' . implode('；', $failedChecks));
        }

        // 全部通过后执行删除
        $deletedCount = 0;
        foreach ($ids as $uid) {
            $user = UserModel::where('id', $uid)->field('id,mobile')->find();
            if ($user) {
                UserModel::destroy($uid);
                $deletedCount++;
                $this->directWriteAdminOperationLog('删除用户', '用户管理', '用户UID：' . $uid . '，账号：' . (string)($user['mobile'] ?? ''), [
                    'target_id' => $uid,
                    'target_type' => 'user',
                ]);
            }
        }

        return show(200, 'success', '删除成功（' . $deletedCount . '个）');
    }

    private function handleUserDelete(array $post_info)
    {
        if (!$this->directHasAdminPermission('用户列表')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post del invalid csrf blocked', [
                'admin_id' => (int)($this->admin_info['id'] ?? 0),
                'uid' => (int)($post_info['id'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '删除请求校验失败');
        }

        $uid = (int)($post_info['id'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        $pendingCheck = $this->directCheckUserPendingBusiness($uid);
        if (empty($pendingCheck['ok'])) {
            return show(500, 'error', $pendingCheck['message'] . '，无法删除');
        }

        $user = UserModel::where('id', $uid)->field('id,mobile')->find();
        if (!$user) {
            return show(500, 'error', '用户不存在');
        }

        UserModel::destroy($uid);

        $this->directWriteAdminOperationLog('删除用户', '用户管理', '用户UID：' . $uid . '，账号：' . (string)($user['mobile'] ?? ''), [
            'target_id' => $uid,
            'target_type' => 'user',
        ]);

        return show(200, 'success', '删除成功');
    }

    private function handleSetting(array $rawPostData)
    {
        if (!$this->directHasAdminPermission('系统设置管理')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $allowedConfigKeys = $this->directAllowedConfigKeys();
        $allowedMetaKeys = $this->directAllowedConfigMetaKeys();
        if (!$this->directRequestPathMatches('setting_post/setting')) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid path blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求路径错误');
        }
        $referer = trim((string)$this->request->header('referer', ''));
        if ($referer !== '' && !$this->directIsAllowedConfigReferer($referer)) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid source blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求来源错误');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            $this->directLogConfigWriteAttempt('admin setting_post invalid csrf blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求校验失败');
        }
        $sensitiveValidation = $this->directValidateSensitiveOperation((array)$rawPostData, 'system_setting');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }
        $unknownKeys = array_values(array_diff(array_keys($rawPostData), array_merge($allowedConfigKeys, $allowedMetaKeys, $this->directAllowedSensitiveAuthKeys())));
        if (!empty($unknownKeys)) {
            $this->directLogConfigWriteAttempt('admin setting_post mixed payload blocked', $rawPostData, 'warning');
            return show(403, 'error', '配置请求包含非法字段：' . implode(',', $unknownKeys));
        }

        $postData = array_intersect_key($rawPostData, array_flip($allowedConfigKeys));
        if (empty($postData)) {
            return show(500, 'error', '未提交有效配置项');
        }
        foreach ($postData as $k => $v) {
            if (is_string($v)) {
                $postData[$k] = trim($v);
            }
        }
        $fieldLabels = $this->directConfigFieldLabels();
        foreach ($postData as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (in_array($key, $this->directTextareaConfigKeys(), true) && $this->directContainsDangerousConfigFragment($value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '包含危险内容，请移除脚本或事件代码');
            }
            if (in_array($key, $this->directUrlConfigKeys(), true) && !$this->directValidateSafeConfigUrl($value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '仅支持安全的 HTTP/HTTPS 地址');
            }
        }
        $lengthRules = [
            'notice' => 1000,
            'agent_jieshao' => 5000,
            'substation_open_intro' => 5000,
            'agreement' => 20000,
            'privacy_policy' => 20000,
            'telegram_welcome_message' => 1000,
            'payment_address' => 255,
            'contact_service_url' => 500,
            'chatwoot_base_url' => 500,
            'chatwoot_token' => 255,
            'epay_url' => 500,
            'bepusdt_base_url' => 500,
            'telegram_webhook_url' => 500,
        ];
        foreach ($lengthRules as $key => $maxLength) {
            if (!array_key_exists($key, $postData)) {
                continue;
            }
            if (mb_strlen((string)$postData[$key], 'UTF-8') > $maxLength) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '长度不能超过' . $maxLength . '个字符');
            }
        }
        $numericRules = [
            'rate' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'mini_recharge_amount' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'mini_withdrawal_amount' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'withdrawal_fee' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'substation_open_price' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'agent_money' => ['pattern' => '/^\d+(?:\.\d{1,2})?$/', 'scale' => 2, 'min' => 0],
            'transaction_mini_quantity' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'transaction_fees' => ['pattern' => '/^\d+(?:\.\d{1,6})?$/', 'scale' => 6, 'min' => 0],
            'platform_account_uid' => ['pattern' => '/^\d+$/', 'scale' => 0, 'min' => 0],
        ];
        foreach ($numericRules as $key => $rule) {
            if (!array_key_exists($key, $postData)) {
                continue;
            }
            $value = (string)$postData[$key];
            if ($value === '' || !preg_match((string)$rule['pattern'], $value)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '格式不正确');
            }
            if ((float)$value < (float)($rule['min'] ?? 0)) {
                return show(500, 'error', ($fieldLabels[$key] ?? $key) . '不能小于0');
            }
            $postData[$key] = $this->directNormalizeDecimalString($value, (int)($rule['scale'] ?? 2));
        }
        foreach (['chatwoot_enabled', 'epay_alipay_enabled', 'epay_wechat_enabled'] as $switchKey) {
            if (array_key_exists($switchKey, $postData) && !in_array((string)$postData[$switchKey], ['0', '1'], true)) {
                return show(500, 'error', ($fieldLabels[$switchKey] ?? $switchKey) . '格式不正确');
            }
        }
        if (array_key_exists('payment_address', $postData) && preg_match('/[\r\n\x00-\x1F\x7F]/', (string)$postData['payment_address'])) {
            return show(500, 'error', '收款地址包含非法字符');
        }
        $beforeConfig = is_array($this->config) ? $this->config : [];
        foreach ($postData as $k => $v) {
            $configModel = ConfigModel::where('k', $k)->find();
            if ($configModel) {
                $configModel->v = $v;
                $configModel->save();
                continue;
            }

            ConfigModel::create([
                'k' => $k,
                'v' => $v,
            ]);
        }
        CacheModel::destroy('config');
        $this->directLogConfigWriteAttempt('admin setting_post config updated', $postData);

        $walletKeys = ['payment_address'];
        $paymentKeys = ['mini_recharge_amount', 'mini_withdrawal_amount', 'withdrawal_fee', 'bepusdt_base_url', 'bepusdt_api_token', 'epay_url', 'epay_id', 'epay_key', 'epay_alipay_enabled', 'epay_wechat_enabled'];
        $allKeys = array_keys($postData);
        $systemKeys = array_values(array_diff($allKeys, $walletKeys, $paymentKeys));
        $labels = $fieldLabels;

        $systemChanged = $this->directBuildChangedFields($beforeConfig, $postData, $systemKeys);
        $paymentChanged = $this->directBuildChangedFields($beforeConfig, $postData, $paymentKeys);
        $walletChanged = $this->directBuildChangedFields($beforeConfig, $postData, $walletKeys);

        if (!empty($systemChanged)) {
            $this->directWriteAdminOperationLog('修改系统配置', '系统配置', $this->adminOperationLogService->summarizeChanges($systemChanged, $labels));
        }
        if (!empty($paymentChanged)) {
            $this->directWriteAdminOperationLog('修改支付配置', '系统配置', $this->adminOperationLogService->summarizeChanges($paymentChanged, $labels));
        }
        if (!empty($walletChanged)) {
            $this->directWriteAdminOperationLog('修改钱包地址', '系统配置', $this->adminOperationLogService->summarizeChanges($walletChanged, $labels));
        }

        return show(200, 'success', '修改成功');
    }

    private function handleSettingUpload()
    {
        $fileBag = (array)$this->request->file();
        $keyname = array_key_first($fileBag);
        $file = $keyname !== null ? ($fileBag[$keyname] ?? null) : null;
        if (!is_object($file)) {
            return show(404, 'error', '请选择图片');
        }

        try {
            $stored = (new UploadService())->storeImageUpload($file, [
                'directory' => 'storage/images',
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif'],
            ]);
            return json([
                'default' => $stored['public_path'],
                'data' => $stored['public_path'],
            ]);
        } catch (Exception $e) {
            return show(404, 'error', $e->getMessage());
        }
    }
    

    public function transaction_product_post(string $action)
    {
        // P2-004 P1-003: C2C 挂单操作/删除权限检查（覆盖 operate/del/dels）
        if (!$this->directHasAdminPermission('交易挂单数据')) {
            return $this->directDenyAdminPermission('交易挂单数据');
        }
        $post_info = $this->request->post();
        switch ($action) {
            case 'operate':
                return $this->handleTransactionProductOperate($post_info);

            case 'del':
                return $this->handleTransactionProductDelete($post_info);

            case 'dels':
                return $this->handleTransactionProductDeleteBatch($post_info);

            default:
                return show(500, 'error', '你不对劲');
        }
    }

public function order_post(string $action)
{
    $post_info = $this->request->post();

    // P2-004 P1-001: 按订单实际 type 动态权限检查（防止低权限管理员混入不同 type 订单）
    $orderIds = [];
    if (!empty($post_info['ids'])) {
        $idsRaw = is_array($post_info['ids']) ? $post_info['ids'] : explode(',', (string)$post_info['ids']);
        $orderIds = array_filter(array_unique(array_map('intval', $idsRaw)));
    } elseif (!empty($post_info['id'])) {
        $orderIds = [(int)$post_info['id']];
    }
    if (!empty($orderIds)) {
        $ordersForPerm = Order::where('id', 'in', $orderIds)->field('id,type')->select();
        foreach ($ordersForPerm as $orderForPerm) {
            $otype = (int)($orderForPerm['type'] ?? 0);
            $perm = $otype === 1 ? '充值业务 - 订单列表' : ($otype === 2 ? '查询业务 - 订单列表' : '');
            if ($perm === '' || !$this->directHasAdminPermission($perm)) {
                return $this->directDenyAdminPermission($perm ?: '订单管理');
            }
        }
    }

    switch ($action) {
        case 'audit_s':
            if ((int)$post_info['status'] === 2 || (int)$post_info['status'] === 3) {
                $data = Order::where('id', 'in', $post_info['ids'])->select();
                $failedIds = [];
                foreach ($data as $vo) {
                    try {
                        if ((int)$post_info['status'] === 2) {
                            $this->directCompleteRechargeOrder((int)$vo['id']);
                        } else {
                            $this->directRefundRemainingUsdt((int)$vo['id']);
                        }
                    } catch (\Throwable $e) {
                        $failedIds[] = (int)$vo['id'];
                    }
                }
                if (!empty($failedIds)) {
                    return show(500, 'error', '部分订单失败：' . implode(',', $failedIds));
                }
                $this->directWriteAdminOperationLog('审核订单', '订单管理', '批量审核订单成功，状态：' . $this->directOrderStatusText((int)$post_info['status']) . '，订单ID：' . trim((string)($post_info['ids'] ?? ''), ','), [
                    'target_type' => 'order',
                ]);
                return show(200, 'success', '处理成功');
            }
            $data = Order::where('id', 'in', $post_info['ids'])->select();
            $failedIds = [];
            foreach ($data as $vo) {
                $vo->status = $post_info['status'];
                if($vo['status'] == 2){
                    $vo->confirm_status = 1;
                    $vo->complete_time = date("Y-m-d H:i:s");
                    // 返佣操作
                    rebate($vo['order_number']);
                }
                $vo->save();
                if ((int)$post_info['status'] === 1) {
                    try {
                        (new OrderTelegramNotifier())->notifyProductOrderProcessing($vo->toArray());
                    } catch (\Throwable $notifyException) {
                        Log::error('product order notify failed', [
                            'order_id' => (int)($vo['id'] ?? 0),
                            'order_no' => (string)($vo['order_number'] ?? ''),
                            'uid' => (int)($vo['uid'] ?? 0),
                            'action' => 'product_order_processing_notify',
                            'error_message' => $notifyException->getMessage(),
                        ]);
                    }
                }
            }
            $this->directWriteAdminOperationLog('审核订单', '订单管理', '批量更新订单状态成功，状态：' . $this->directOrderStatusText((int)$post_info['status']) . '，订单ID：' . trim((string)($post_info['ids'] ?? ''), ','), [
                'target_type' => 'order',
            ]);
            return show(200, 'success', '处理成功');
            
        case 'audit_dz':
            // 验证必要参数
            if (empty($post_info['ids']) && empty($post_info['id'])) {
                return show(500, 'error', '请提供订单ID');
            }
            
            // 验证金额参数
            if (!isset($post_info['dz_number']) || !is_numeric($post_info['dz_number']) || $post_info['dz_number'] < 0) {
                return show(500, 'error', '请输入有效的到账金额');
            }
            
            // 处理单个或批量订单
            $ids = $post_info['ids'] ?? $post_info['id'];
            $idArray = is_array($ids) ? $ids : explode(',', $ids);
            $idArray = array_filter(array_unique($idArray, SORT_NUMERIC), 'is_numeric');
            
            if (empty($idArray)) {
                return show(500, 'error', '订单ID格式不正确');
            }
            
            try {
                // 开启数据库事务
                Db::startTrans();
                
                // 批量更新订单
                $orderCount = 0;
                $failedIds = [];
                
                foreach ($idArray as $id) {
                    $order_info = Order::find($id);
                    if ($order_info) {
                        // 记录原始值用于日志
                        $oldValue = $order_info->amount_received;
                        
                        // 更新到账金额
                        $order_info->amount_received = $post_info['dz_number'];
                        // 增加最后更新时间和操作人记录
                        $order_info->update_time = date("Y-m-d H:i:s");
                        $order_info->operator_id = $this->admin_info['id'] ?? 0;
                        
                        // 检查保存是否成功
                        if ($order_info->save() !== false) {
                            $orderCount++;
                            // 记录操作日志
                            trace("订单ID:{$id} 到账金额从 {$oldValue} 更新为 {$post_info['dz_number']}", 'info');
                        } else {
                            $failedIds[] = $id;
                            trace("订单ID:{$id} 更新到账金额失败", 'error');
                        }
                    } else {
                        $failedIds[] = $id;
                    }
                }
                
                // 提交事务
                Db::commit();
                
                // 清除相关缓存（如果有缓存机制）
                if (class_exists('Cache')) {
                    Cache::rm('order_list_' . implode('_', $idArray));
                    Cache::rm('order_stats');
                }
                
                if ($orderCount > 0) {
                    $message = "成功更新{$orderCount}个订单的到账金额";
                    if (!empty($failedIds)) {
                        $message .= "，以下订单更新失败：" . implode(',', $failedIds);
                    }
                    $this->directWriteAdminOperationLog('手动补单', '订单管理', '批量设置实际到账金额：' . (string)$post_info['dz_number'] . '，成功订单数：' . $orderCount . '，订单ID：' . implode(',', $idArray), [
                        'target_type' => 'order',
                    ]);
                    return show(200, 'success', $message);
                } else {
                    return show(500, 'error', '未找到可更新的订单或更新失败');
                }
            } catch (Exception $e) {
                // 回滚事务
                Db::rollback();
                trace("更新到账金额异常：" . $e->getMessage(), 'error');
                return show(500, 'error', '操作失败：' . $e->getMessage());
            }

        case 'audit':
            if($post_info['type'] == 'status'){
                if((int)$post_info['status'] === 2){
                    try {
                        $this->directCompleteRechargeOrder((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                if((int)$post_info['status'] === 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                $order_info = Order::find($post_info['id']);

                if($order_info['status'] == 0 || $order_info['status'] == 1){
                    $oldStatus = (int)($order_info['status'] ?? 0);
                    $oldConfirmStatus = (int)($order_info['confirm_status'] ?? 0);
                    $order_info->status = $post_info['status'];
                    if($order_info['status'] == 2){
                        $order_info->confirm_status = 1;
                        $order_info->complete_time = date("Y-m-d H:i:s");
                        // 返佣操作
                        rebate($order_info['order_number']);
                    }
                    $order_info->save();
                    $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order_info['order_number'] ?? '') . '，状态：' . $this->directOrderStatusText($oldStatus) . ' -> ' . $this->directOrderStatusText((int)$order_info['status']) . '，确认状态：' . $this->directOrderConfirmStatusText($oldConfirmStatus) . ' -> ' . $this->directOrderConfirmStatusText((int)($order_info['confirm_status'] ?? 0)), [
                        'target_id' => (int)($order_info['id'] ?? 0),
                        'target_type' => 'order',
                    ]);
                    if ((int)$post_info['status'] === 1) {
                        try {
                            (new OrderTelegramNotifier())->notifyProductOrderProcessing($order_info->toArray());
                        } catch (\Throwable $notifyException) {
                            Log::error('product order notify failed', [
                                'order_id' => (int)($order_info['id'] ?? 0),
                                'order_no' => (string)($order_info['order_number'] ?? ''),
                                'uid' => (int)($order_info['uid'] ?? 0),
                                'action' => 'product_order_processing_notify',
                                'error_message' => $notifyException->getMessage(),
                            ]);
                        }
                    }
                    return show(200, 'success', '处理成功');
                }
                return show(500, 'error', '审核异常');        
            }else{
                $order_info = Order::find($post_info['id']);
                if($order_info['status'] == 2 && $order_info['confirm_status'] == 3){
                    if($post_info['status'] == 2){
                        try {
                            $updatedOrderSnapshot = (new ProductOrderService())->confirmReceipt(
                                (int)$post_info['id'],
                                2,
                                [
                                    'source' => 'admin_audit_confirm',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                ]
                            );
                            $this->directWriteAdminOperationLog('瀹℃牳璁㈠崟', '璁㈠崟绠＄悊', '璁㈠崟鍙凤細' . (string)($updatedOrderSnapshot['order_number'] ?? '') . '锛岀‘璁ょ姸鎬侊細' . $this->directOrderConfirmStatusText(3) . ' -> ' . $this->directOrderConfirmStatusText(2), [
                                'target_id' => (int)($updatedOrderSnapshot['id'] ?? 0),
                                'target_type' => 'order',
                            ]);
                            return show(200, 'success', '澶勭悊鎴愬姛');
                        } catch (\Throwable $e) {
                            return show(500, 'error', $e->getMessage());
                        }
                    }
                    if($post_info['status'] == 3){
                        try {
                            $this->directRefundRemainingUsdt((int)$post_info['id']);
                            return show(200, 'success', '处理成功');
                        } catch (\Throwable $e) {
                            return show(500, 'error', $e->getMessage());
                        }
                    }
                    if($post_info['status'] == 2){
                        $order_info->confirm_status = 2;
                        $order_info->save();
                    }

                    if($post_info['status'] == 3){
                        $order_info->status = 3;
                        $order_info->save();
                    }

                    return show(200, 'success', '处理成功');
                }
                return show(500, 'error', '审核异常');      
            }

        case 'query':
            $order_info = Order::find($post_info['id']);
            if($order_info['status'] == 0 || $order_info['status'] == 1){
                if((int)$post_info['status'] === 2){
                    try {
                        $this->directCompleteRechargeOrder((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                if((int)$post_info['status'] === 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$post_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                }
                $order_info->status = $post_info['status'];
                if($order_info['status'] == 2){
                    $order_info->confirm_status = 1;
                    $order_info->complete_time = date("Y-m-d H:i:s");
                    
                    // 返佣操作
                    rebate($order_info['order_number']);
                }
                $order_info->save();
                if ((int)$post_info['status'] === 1) {
                    try {
                        (new OrderTelegramNotifier())->notifyProductOrderProcessing($order_info->toArray());
                    } catch (\Throwable $notifyException) {
                        Log::error('product order notify failed', [
                            'order_id' => (int)($order_info['id'] ?? 0),
                            'order_no' => (string)($order_info['order_number'] ?? ''),
                            'uid' => (int)($order_info['uid'] ?? 0),
                            'action' => 'product_order_processing_notify',
                            'error_message' => $notifyException->getMessage(),
                        ]);
                    }
                }
                if($post_info['status'] == 3){
                    try {
                        $this->directRefundRemainingUsdt((int)$order_info['id']);
                        return show(200, 'success', '处理成功');
                    } catch (\Throwable $e) {
                        Log::error('admin order_post audit refund error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                        return show(500, 'error', '处理失败：' . $e->getMessage());
                    }
                }
                $this->directWriteAdminOperationLog('审核订单', '订单管理', '订单号：' . (string)($order_info['order_number'] ?? '') . '，查询订单状态更新为：' . $this->directOrderStatusText((int)$order_info['status']), [
                    'target_id' => (int)($order_info['id'] ?? 0),
                    'target_type' => 'order',
                ]);
                return show(200, 'success', '处理成功');
            }
            return show(500, 'error', '审核异常');

        case 'example_a':
            if(empty($post_info['ids'])){
                if($post_info['product']){
                    $par[] = ['product_id', '=', substr($post_info['product'], 8)];
                }
                $par[] = ['type', '=', 1];
                $data = Order::where($par)->select();
            }else{
                $data = Order::where('id', 'in', $post_info['ids'])->where('type', 1)->select();
            }
            $customFieldNames = [
                'order_number' => '订单号',
                'product_info' => '产品信息',
                'order_info' => '充值信息',
                'amount_money' => '充值金额',
                'discount_amount' => '折扣金额',
                'discount' => '折扣比例',
                'rate' => '当前费率',
                'cny_amount' => '支付金额',
                'status' => '订单状态',
                'confirm_status' => '确认状态',	
                'create_time' => '创建时间',
            ];
            // 创建PHPExcel对象
            $spreadsheet = new Spreadsheet();
            // 设置自定义字段名为第一行
            $spreadsheet->getActiveSheet()->fromArray(array_map([$this, 'safeExcel'], $customFieldNames), NULL, 'A1');
            // 填充数据
            $rowData = [];
            foreach ($data as $row) {
                $order_info = Order::where('id', $row['id'])->find();
                $order_info->export_status = 1;
                $order_info->save();
                
                if($order_info['status'] == 0){
                    $status = '待充值';
                }if($order_info['status'] == 1){
                    $status = '充值中';
                }if($order_info['status'] == 2){
                    $status = '已完成';
                }if($order_info['status'] == 3){
                    $status = '已取消';
                }
                if($order_info['confirm_status'] == 0){
                    $confirm_status = '未完成';
                }if($order_info['confirm_status'] == 1){
                    $confirm_status = '待确认';
                }if($order_info['confirm_status'] == 2){
                    $confirm_status = '已确认';
                }if($order_info['confirm_status'] == 3){
                    $confirm_status = '未收到';
                }

                $info = '';
                $orderDetails = is_array($order_info['order_info']) ? $order_info['order_info'] : [];
                foreach ($orderDetails as $item) {
                    if (!preg_match('/\[(.*?)\](.*)/', $item, $matches) || count($matches) < 3) {
                        continue;
                    }

                    $fieldValue = trim((string)$matches[2]);
                    $result = checkIfImageExists($fieldValue);
                    if ($result == 1) {
                        $info .= $matches[1] . '：' . url('/')->domain(true) . $fieldValue . '    ';
                    } else {
                        $info .= $matches[1] . '：' . $fieldValue . '    ';
                    }

                    if (phone_info($fieldValue)) {
                        $info .= '运营商：' . phone_info($fieldValue) . '    ';
                        $info .= '话费余额：' . $row['phone_yue_a'] . '    ';
                    }
                }

                $rowData[] = [
                    'order_number' => $this->safeExcel($order_info['order_number']),
                    'product_info' => $this->safeExcel($order_info['product_info']['name']),
                    'order_info' => $this->safeExcel($info),
                    'amount_money' => $this->safeExcel($order_info['amount_money']),
                    'discount_amount' => $this->safeExcel($order_info['discount_amount']),
                    'discount' => $this->safeExcel($order_info['discount']),
                    'rate' => $this->safeExcel($order_info['rate']),
                    'cny_amount' => $this->safeExcel($order_info['cny_amount']),
                    'status' => $this->safeExcel($status),
                    'confirm_status' => $this->safeExcel($confirm_status),	
                    'create_time' => $this->safeExcel($order_info['create_time']),
                ];
            }
            $spreadsheet->getActiveSheet()->fromArray($rowData, NULL, 'A2');
            $downloadUrl = $this->createPrivateExportDownload($spreadsheet, 'order_example_a');
            return show(200, 'success', '执行成功', $downloadUrl);
                
        case 'example_b':
            if(empty($post_info['ids'])){
                if($post_info['product']){
                    $par[] = ['product_id', '=', substr($post_info['product'], 8)];
                }
                $par[] = ['type', '=', 2];
                $data = Order::where($par)->select();
            }else{
                $data = Order::where('id', 'in', $post_info['ids'])->where('type', 2)->select();
            }
            $customFieldNames = [
                'order_number' => '订单号',
                'product_info' => '产品信息',
                'order_info' => '充值信息',
                'rate' => '当前费率',
                'cny_amount' => '支付金额',
                'status' => '订单状态',
                'confirm_status' => '确认状态',	
                'create_time' => '创建时间',
            ];
            // 创建PHPExcel对象
            $spreadsheet = new Spreadsheet();
            // 设置自定义字段名为第一行
            $spreadsheet->getActiveSheet()->fromArray(array_map([$this, 'safeExcel'], $customFieldNames), NULL, 'A1');
            // 填充数据
            $rowData = [];
            foreach ($data as $row) {
                $order_info = Order::where('id', $row['id'])->find();
                $order_info->export_status = 1;
                $order_info->save();
                if($order_info['status'] == 0){
                    $status = '待充值';
                }if($order_info['status'] == 1){
                    $status = '充值中';
                }if($order_info['status'] == 2){
                    $status = '已完成';
                }if($order_info['status'] == 3){
                    $status = '已取消';
                }
                if($order_info['confirm_status'] == 0){
                    $confirm_status = '未完成';
                }if($order_info['confirm_status'] == 1){
                    $confirm_status = '待确认';
                }if($order_info['confirm_status'] == 2){
                    $confirm_status = '已确认';
                }if($order_info['confirm_status'] == 3){
                    $confirm_status = '未收到';
                }

                $info = '';
                $orderDetails = is_array($order_info['order_info']) ? $order_info['order_info'] : [];
                foreach ($orderDetails as $item) {
                    if (!preg_match('/\[(.*?)\](.*)/', $item, $matches) || count($matches) < 3) {
                        continue;
                    }

                    $fieldValue = trim((string)$matches[2]);
                    $result = checkIfImageExists($fieldValue);
                    if ($result == 1) {
                        $info .= $matches[1] . '：' . url('/')->domain(true) . $fieldValue . '    ';
                    } else {
                        $info .= $matches[1] . '：' . $fieldValue . '    ';
                    }

                    if (getTelecomOperator($fieldValue) != '未知') {
                        $info .= '运营商：' . getTelecomOperator($fieldValue) . '    ';
                        $info .= '话费余额：' . $row['phone_yue_a'] . '    ';
                    }
                }

                $rowData[] = [
                    'order_number' => $this->safeExcel($order_info['order_number']),
                    'product_info' => $this->safeExcel($order_info['product_info']['name']),
                    'order_info' => $this->safeExcel($info),
                    'rate' => $this->safeExcel($order_info['rate']),
                    'cny_amount' => $this->safeExcel($order_info['cny_amount']),
                    'status' => $this->safeExcel($status),
                    'confirm_status' => $this->safeExcel($confirm_status),	
                    'create_time' => $this->safeExcel($order_info['create_time']),
                ];
            }
            $spreadsheet->getActiveSheet()->fromArray($rowData, NULL, 'A2');
            $downloadUrl = $this->createPrivateExportDownload($spreadsheet, 'order_example_b');

            return show(200, 'success', '执行成功', $downloadUrl);

        case 'set_amount_received':
            // 验证必要参数
            if (empty($post_info['id']) || !isset($post_info['amount_received'])) {
                return show(500, 'error', '请提供订单ID和实际到账金额');
            }
            
            // 验证金额参数
            if (!is_numeric($post_info['amount_received']) || $post_info['amount_received'] < 0) {
                return show(500, 'error', '请输入有效的实际到账金额');
            }
            
            try {
                $order_info = Order::find($post_info['id']);
                if (!$order_info) {
                    return show(500, 'error', '订单不存在');
                }
                
                // 更新实际到账金额
                $order_info->amount_received = $post_info['amount_received'];
                $order_info->update_time = date("Y-m-d H:i:s");
                $order_info->operator_id = $this->admin_info['id'] ?? 0;
                $order_info->save();
                
                // 记录操作日志
                trace("订单ID:{$post_info['id']} 实际到账金额更新为 {$post_info['amount_received']}", 'info');
                
                return show(200, 'success', '实际到账金额设置成功');
            } catch (Exception $e) {
                trace("设置实际到账金额异常：" . $e->getMessage(), 'error');
                return show(500, 'error', '操作失败：' . $e->getMessage());
            }

        case 'batch_set_amount_received':
            // 验证必要参数
            if (empty($post_info['ids']) || !isset($post_info['amount_received'])) {
                return show(500, 'error', '请提供订单ID和实际到账金额');
            }
            
            // 验证金额参数
            if (!is_numeric($post_info['amount_received']) || $post_info['amount_received'] < 0) {
                return show(500, 'error', '请输入有效的实际到账金额');
            }
            
            // 处理订单ID
            $idArray = explode(',', $post_info['ids']);
            $idArray = array_filter(array_unique($idArray, SORT_NUMERIC), 'is_numeric');
            
            if (empty($idArray)) {
                return show(500, 'error', '订单ID格式不正确');
            }
            
            try {
                // 开启数据库事务
                Db::startTrans();
                
                // 批量更新订单
                $orderCount = 0;
                $failedIds = [];
                
                foreach ($idArray as $id) {
                    $order_info = Order::find($id);
                    if ($order_info) {
                        // 更新实际到账金额
                        $order_info->amount_received = $post_info['amount_received'];
                        $order_info->update_time = date("Y-m-d H:i:s");
                        $order_info->operator_id = $this->admin_info['id'] ?? 0;
                        
                        // 检查保存是否成功
                        if ($order_info->save() !== false) {
                            $orderCount++;
                            // 记录操作日志
                            trace("订单ID:{$id} 实际到账金额更新为 {$post_info['amount_received']}", 'info');
                        } else {
                            $failedIds[] = $id;
                            trace("订单ID:{$id} 更新实际到账金额失败", 'error');
                        }
                    } else {
                        $failedIds[] = $id;
                    }
                }
                
                // 提交事务
                Db::commit();
                
                if ($orderCount > 0) {
                    $message = "成功更新{$orderCount}个订单的实际到账金额";
                    if (!empty($failedIds)) {
                        $message .= "，以下订单更新失败：" . implode(',', $failedIds);
                    }
                    $this->directWriteAdminOperationLog('手动补单', '订单管理', '批量设置实际到账金额：' . (string)$post_info['amount_received'] . '，成功订单数：' . $orderCount . '，订单ID：' . implode(',', $idArray), [
                        'target_type' => 'order',
                    ]);
                    return show(200, 'success', $message);
                } else {
                    return show(500, 'error', '未找到可更新的订单或更新失败');
                }
            } catch (Exception $e) {
                // 回滚事务
                Db::rollback();
                trace("批量更新实际到账金额异常：" . $e->getMessage(), 'error');
                return show(500, 'error', '操作失败：' . $e->getMessage());
            }

        case 'picture_upload':
            try {
                $stored = (new UploadService())->storeImageUpload(
                    (string)$this->request->post('result'),
                    [
                        'directory' => 'storage/picture',
                        'allowed_mimes' => ['image/jpeg', 'image/png'],
                        'empty_message' => '图片上传错误',
                    ]
                );

                $order_info = Order::find($post_info['order_id']);
                $order_info->results = $stored['public_path'];
                $order_info->save();
                return show(200, 'success', '上传保存成功');
            } catch (Exception $e) {
                return show(500, 'error', $e->getMessage());
            }
        case 'del':
            Order::destroy($post_info['id']);
            return show(200, 'success', '删除成功');
            
        case 'dels':
            
            $data = Order::where('id', 'in', $post_info['ids'])->select();
            foreach($data as $key => $vo) {
                Order::destroy($vo['id']);
            }
            return show(200, 'success', '删除成功');
            
        default:
            return show(500, 'error', '你不对劲');
    }
}

    public function withdrawal_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'audit':
                // P2-004 P1-002: 提现审核权限检查
                if (!$this->directHasAdminPermission('提现订单记录')) {
                    return $this->directDenyAdminPermission('提现订单记录');
                }
                if (!$this->directValidateRequiredCsrfToken()) {
                    return show(403, 'error', '提现请求校验失败');
                }
                $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'withdrawal_audit');
                if (empty($sensitiveValidation['ok'])) {
                    return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
                }
                $auditStatus = (int)($post_info['status'] ?? 0);
                if (!in_array($auditStatus, [1, 2], true)) {
                    return show(500, 'error', '瀹℃牳寮傚父');
                }
                try {
                    Db::startTrans();
                    $withdrawal_info = Withdrawal::where('id', $post_info['id'])->lock(true)->find();
                    if($withdrawal_info && (int)$withdrawal_info['status'] == 0){
                        $amount = round((float)($withdrawal_info['amount'] ?? 0), 2);
                        if ($amount <= 0) {
                            throw new Exception('提现金额异常');
                        }

                        if($auditStatus === 1){
                            $user_info = $this->directLockUser((int)$withdrawal_info['uid']);
                            if (!$user_info) {
                                throw new Exception('User not found');
                            }
                            // 手续费校验：fee 必须 >= 0 且 <= amount
                            $withdrawalFee = round((float)($withdrawal_info['withdrawal_fee'] ?? 0), 2);
                            if ($withdrawalFee < -0.005) {
                                throw new Exception('提现手续费异常：负数');
                            }
                            if ($withdrawalFee > $amount + 0.005) {
                                throw new Exception('提现手续费异常：超过提现金额');
                            }
                            (new UserFundLedgerService())->changeLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_FROZEN,
                                -1 * $amount,
                                [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'change_type' => 'withdraw_deduct',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_deduct:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal approved deduct frozen amount',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'audit_status' => 1,
                                    ],
                                ]
                            );
                            // 平台手续费记账（纯流水，与审核同一事务，幂等 request_no）
                            if ($withdrawalFee > 0.005) {
                                (new UserFundLedgerService())->recordPlatformIncome($withdrawalFee, [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'change_type' => 'withdrawal_fee_income',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_fee:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal platform fee',
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'withdraw_amount' => $amount,
                                        'withdrawal_fee' => $withdrawalFee,
                                        'actual_payout' => round($amount - $withdrawalFee, 2),
                                    ],
                                ]);
                            }
                        }

                        $withdrawal_info->status = $auditStatus;
                        $withdrawal_info->save();

                        if($auditStatus === 2){
                            $user_info = $this->directLockUser((int)$withdrawal_info['uid']);
                            if (!$user_info) {
                                throw new Exception('用户不存在');
                            }
                            $balanceBefore = round((float)($user_info['balance'] ?? 0), 2);
                            $ledgerResult = (new UserFundLedgerService())->transferLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_FROZEN,
                                UserFundLedgerService::WALLET_BALANCE,
                                $amount,
                                [
                                    'biz_type' => 'withdrawal',
                                    'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                    'biz_no' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                    'out_change_type' => 'withdraw_reject_refund',
                                    'in_change_type' => 'withdraw_reject_refund',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'withdraw_reject_refund:' . (string)($withdrawal_info['order_number'] ?? ''),
                                    'remark' => 'withdrawal reject refund',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'withdrawal_post_audit',
                                        'audit_status' => 2,
                                    ],
                                ]
                            );
                            $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                            $balanceAfter = array_key_exists('balance', $walletSnapshot)
                                ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                                : round((float)($user_info['balance'] ?? ($balanceBefore + $amount)), 2);
                            $this->directWriteBalanceLog([
                                'uid' => (int)($user_info['id'] ?? 0),
                                'scene' => 'withdrawal_reject_refund',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'biz_id' => (int)($withdrawal_info['id'] ?? 0),
                                'order_number' => (string)($withdrawal_info['order_number'] ?? ''),
                                'remark' => '后台审核拒绝提现，余额退回',
                                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                            ]);
                        }
                        $withdrawalSnapshot = $withdrawal_info->toArray();
                        Db::commit();
                        if ($auditStatus === 1) {
                            try {
                                (new OrderTelegramNotifier())->notifyWithdrawalSucceeded($withdrawalSnapshot);
                            } catch (\Throwable $notifyException) {
                                Log::error('withdrawal notify failed', [
                                    'withdrawal_id' => (int)($withdrawalSnapshot['id'] ?? 0),
                                    'order_no' => (string)($withdrawalSnapshot['order_number'] ?? ''),
                                    'uid' => (int)($withdrawalSnapshot['uid'] ?? 0),
                                    'action' => 'withdrawal_succeeded_notify',
                                    'error_message' => $notifyException->getMessage(),
                                ]);
                            }
                        }
                        $this->directWriteAdminOperationLog('审核订单', '财务管理', '提现单号：' . (string)($withdrawal_info['order_number'] ?? '') . '，状态更新为：' . ($auditStatus === 1 ? '提现成功' : '提现失败'), [
                            'target_id' => (int)($withdrawal_info['id'] ?? 0),
                            'target_type' => 'withdrawal',
                        ]);
                        return show(200, 'success', '审核成功');
                    }
                    Db::rollback();
                    return show(500, 'error', '审核异常');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post audit error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                    return show(500, 'error', '审核异常');
                }

            case 'del':
                try {
                    Db::startTrans();
                    $withdrawal_info = Withdrawal::where('id', $post_info['id'])->lock(true)->find();
                    if (!$withdrawal_info) {
                        Db::rollback();
                        return show(404, 'error', '提现记录不存在');
                    }
                    // status=0 待审核提现禁止删除：冻结资金尚未处理，删除会导致资金丢失
                    if ((int)$withdrawal_info['status'] === 0) {
                        Db::rollback();
                        return show(500, 'error', '待审核提现不可删除，请先审核拒绝后再删除');
                    }
                    // status=1(已通过) / status=2(已拒绝) 资金已处理完毕，允许删除
                    Withdrawal::destroy((int)$withdrawal_info['id']);
                    Db::commit();
                    return show(200, 'success', '删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post del error: ' . $e->getMessage(), ['id' => ($post_info['id'] ?? 0)]);
                    return show(500, 'error', '删除失败');
                }

            case 'dels':
                try {
                    Db::startTrans();
                    $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
                    if (empty($ids)) {
                        Db::rollback();
                        return show(500, 'error', '请选择要删除的提现记录');
                    }
                    foreach ($ids as $id) {
                        $withdrawal_info = Withdrawal::where('id', (int)$id)->lock(true)->find();
                        if (!$withdrawal_info) {
                            throw new Exception('提现记录不存在: ' . (int)$id);
                        }
                        // 任何一条 status=0 则整批回滚，一条都不删
                        if ((int)$withdrawal_info['status'] === 0) {
                            throw new Exception('提现单号 ' . (string)($withdrawal_info['order_number'] ?? '') . ' 待审核，不可删除');
                        }
                        Withdrawal::destroy((int)$withdrawal_info['id']);
                    }
                    Db::commit();
                    return show(200, 'success', '批量删除成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin withdrawal_post dels error: ' . $e->getMessage(), ['ids' => ($post_info['ids'] ?? [])]);
                    return show(500, 'error', $e->getMessage() ?: '批量删除失败');
                }
            default:
                return show(500, 'error', '你不对劲');
        }
    }

    public function recharge_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'audit':
                // P2-005 P1-006: 充值审核权限检查
                if (!$this->directHasAdminPermission('充值订单记录')) {
                    return $this->directDenyAdminPermission('充值订单记录');
                }
                if (!$this->directValidateRequiredCsrfToken()) {
                    return show(403, 'error', '充值请求校验失败');
                }
                $sensitiveValidation = $this->directValidateSensitiveOperation((array)$post_info, 'recharge_audit');
                if (empty($sensitiveValidation['ok'])) {
                    return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
                }
                $auditStatus = (int)($post_info['status'] ?? 0);
                if (!in_array($auditStatus, [1, 2], true)) {
                    return show(500, 'error', '审核异常');
                }
                try {
                    Db::startTrans();
                    $recharge_info = Recharge::where('id', $post_info['id'])->lock(true)->find();
                    if($recharge_info && (int)$recharge_info['status'] == 1){
                        $amount = round((float)($recharge_info['amount'] ?? 0), 2);
                        if ($amount <= 0) {
                            throw new Exception('充值金额异常');
                        }

                        if($auditStatus === 1){
                            $status = 3;
                        }elseif($auditStatus === 2){
                            $status = 2;
                        } else {
                            $status = (int)$recharge_info['status'];
                        }
                        $recharge_info->status = $status;
                        $recharge_info->save();

                        if($auditStatus === 1){
                            $user_info = $this->directLockUser((int)$recharge_info['uid']);
                            if (!$user_info) {
                                throw new Exception('用户不存在');
                            }
                            $balanceBefore = (float)($user_info['balance'] ?? 0);
                            $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
                                $user_info,
                                UserFundLedgerService::WALLET_BALANCE,
                                $amount,
                                [
                                    'biz_type' => 'recharge',
                                    'biz_id' => (int)($recharge_info['id'] ?? 0),
                                    'biz_no' => (string)($recharge_info['order_number'] ?? ''),
                                    'order_number' => (string)($recharge_info['order_number'] ?? ''),
                                    'change_type' => 'recharge_manual_paid',
                                    'operator_type' => 'admin',
                                    'operator_id' => (int)($this->admin_info['id'] ?? 0),
                                    'status' => 'done',
                                    'request_no' => 'recharge_manual_paid:' . (string)($recharge_info['order_number'] ?? ''),
                                    'remark' => '后台审核通过充值到账',
                                    'idempotent' => true,
                                    'extra' => [
                                        'source' => 'admin_recharge_audit_paid',
                                        'audit_status' => 1,
                                    ],
                                ]
                            );
                            $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
                            $balanceAfter = array_key_exists('balance', $walletSnapshot)
                                ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                                : round((float)($user_info['balance'] ?? ($balanceBefore + $amount)), 2);
                            $this->directWriteBalanceLog([
                                'uid' => (int)($user_info['id'] ?? 0),
                                'scene' => 'recharge_manual_audit_paid',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'biz_id' => (int)($recharge_info['id'] ?? 0),
                                'order_number' => (string)($recharge_info['order_number'] ?? ''),
                                'remark' => '后台审核通过充值到账',
                                'operator_id' => (int)($this->admin_info['id'] ?? 0),
                            ]);
                        }
                        $rechargeSnapshot = $recharge_info->toArray();
                        Db::commit();
                        if ($auditStatus === 1) {
                            try {
                                (new OrderTelegramNotifier())->notifyWalletRechargePaid($rechargeSnapshot);
                            } catch (\Throwable $notifyException) {
                                Log::error('wallet recharge notify failed', [
                                    'recharge_id' => (int)($rechargeSnapshot['id'] ?? 0),
                                    'order_no' => (string)($rechargeSnapshot['order_number'] ?? ''),
                                    'uid' => (int)($rechargeSnapshot['uid'] ?? 0),
                                    'action' => 'wallet_recharge_paid_notify',
                                    'error_message' => $notifyException->getMessage(),
                                ]);
                            }
                        }
                        $this->directWriteAdminOperationLog('审核订单', '财务管理', '充值单号：' . (string)($recharge_info['order_number'] ?? '') . '，状态更新为：' . ($auditStatus === 1 ? '充值成功' : '充值失败'), [
                            'target_id' => (int)($recharge_info['id'] ?? 0),
                            'target_type' => 'recharge',
                        ]);
                        return show(200, 'success', '审核成功');
                    }
                    Db::rollback();
                    return show(500, 'error', '审核异常');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('admin recharge_post audit error: ' . $e->getMessage(), ['id' => (int)($post_info['id'] ?? 0)]);
                    return show(500, 'error', '审核异常');
                }

            case 'del':
                Recharge::destroy($post_info['id']);
                return show(200, 'success', '删除成功');
                
                
            case 'dels':
                $data = Recharge::where('id', 'in', $post_info['ids'])->select();
                foreach($data as $key => $vo) {
                    Recharge::destroy($vo['id']);
                }
                return show(200, 'success', '删除成功');
                
            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    
    

    public function bank_card_post(string $action)
    {
        // P2-004 P2-001: 银行卡管理权限检查
        if (!$this->directHasAdminPermission('支付管理')) {
            return $this->directDenyAdminPermission('支付管理');
        }
        $post_info = $this->request->post();
        switch ($action) {
            case 'dels':
                $data = BankCard::where('id', 'in', $post_info['ids'])->select();
                $deletedIds = [];
                foreach($data as $key => $vo) {
                    BankCard::destroy($vo['id']);
                    $deletedIds[] = (int)$vo['id'];
                }
                $this->directWriteAdminOperationLog('删除银行卡', '支付管理', '批量删除用户银行卡，数量：' . count($deletedIds) . '，ID：' . implode(',', $deletedIds), [
                    'target_type' => 'bank_card',
                ]);
                return show(200, 'success', '删除成功');
                
            default:
                return show(500, 'error', '你不对劲');
        }
    }

    public function points_post(string $action)
    {
        if (!$this->directHasAdminPermission('积分管理')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }

        $post = $this->request->post();
        switch ($action) {
            case 'config_save':
                return show(400, 'error', '积分配置已下线');

            case 'tasks_save':
                return show(400, 'error', '任务设置已下线');

            case 'exchange_save':
                $notice = trim((string)($post['points_exchange_notice'] ?? ''));
                $decoded = [];

                if (array_key_exists('points_exchange_items', $post)) {
                    $rawItems = trim((string)($post['points_exchange_items'] ?? '[]'));
                    if ($rawItems === '') {
                        $rawItems = '[]';
                    }

                    try {
                        $decoded = json_decode($rawItems, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        return show(400, 'error', '兑换项配置不是有效的 JSON');
                    }

                    if (!is_array($decoded)) {
                        return show(400, 'error', '兑换项配置格式错误，必须是数组');
                    }
                } else {
                    $ids = is_array($post['exchange_id'] ?? null) ? $post['exchange_id'] : [];
                    $types = is_array($post['exchange_type'] ?? null) ? $post['exchange_type'] : [];
                    $titles = is_array($post['exchange_title'] ?? null) ? $post['exchange_title'] : [];
                    $pointsList = is_array($post['exchange_points'] ?? null) ? $post['exchange_points'] : [];
                    $stockList = is_array($post['exchange_stock'] ?? null) ? $post['exchange_stock'] : [];
                    $couponAmounts = is_array($post['exchange_coupon_amount'] ?? null) ? $post['exchange_coupon_amount'] : [];
                    $skuList = is_array($post['exchange_sku'] ?? null) ? $post['exchange_sku'] : [];
                    $descriptionList = is_array($post['exchange_description'] ?? null) ? $post['exchange_description'] : [];
                    $imageList = is_array($post['exchange_image'] ?? null) ? $post['exchange_image'] : [];
                    $enabledList = is_array($post['exchange_enabled'] ?? null) ? $post['exchange_enabled'] : [];
                    $rowCount = max(
                        count($ids),
                        count($types),
                        count($titles),
                        count($pointsList),
                        count($stockList),
                        count($couponAmounts),
                        count($skuList),
                        count($descriptionList),
                        count($imageList),
                        count($enabledList)
                    );

                    for ($index = 0; $index < $rowCount; $index++) {
                        $decoded[] = [
                            'id' => $ids[$index] ?? '',
                            'type' => $types[$index] ?? 'coupon',
                            'title' => $titles[$index] ?? '',
                            'points' => $pointsList[$index] ?? 0,
                            'stock' => $stockList[$index] ?? 0,
                            'coupon_amount' => $couponAmounts[$index] ?? 0,
                            'sku' => $skuList[$index] ?? '',
                            'description' => $descriptionList[$index] ?? '',
                            'image' => $imageList[$index] ?? '',
                            'enabled' => $enabledList[$index] ?? 0,
                        ];
                    }
                }

                $normalizedItems = [];
                foreach ($decoded as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $type = strtolower(trim((string)($item['type'] ?? 'coupon')));
                    if (!in_array($type, ['coupon', 'physical'], true)) {
                        $type = 'coupon';
                    }

                    $title = trim((string)($item['title'] ?? ''));
                    if ($title === '') {
                        $title = ($type === 'physical' ? '实物商品' : '优惠券') . '#' . ((int)$index + 1);
                    }

                    $normalizedItems[] = [
                        'id' => trim((string)($item['id'] ?? ('item_' . ((int)$index + 1)))),
                        'type' => $type,
                        'title' => $title,
                        'points' => max(1, (int)($item['points'] ?? 1)),
                        'stock' => max(0, (int)($item['stock'] ?? 0)),
                        'coupon_amount' => max(0, (int)($item['coupon_amount'] ?? 0)),
                        'sku' => trim((string)($item['sku'] ?? '')),
                        'description' => trim((string)($item['description'] ?? '')),
                        'image' => trim((string)($item['image'] ?? '')),
                        'enabled' => !empty($item['enabled']) ? 1 : 0,
                    ];
                }

                $this->directSaveConfigValue('points_exchange_items', json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->directSaveConfigValue('points_exchange_notice', $notice);
                $this->directWriteAdminOperationLog('兑换设置', '积分管理', '更新积分兑换配置');
                return show(200, 'success', '保存成功');

            default:
                return show(500, 'error', '未知操作');
        }
    }

    public function points_exchange_orders_json()
    {
        if (!$this->directHasAdminPermission('积分管理')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        $page    = max(1, (int)$this->request->get('page', 1));
        $limit   = max(1, min(100, (int)$this->request->get('limit', 20)));
        $status  = $this->request->get('status', '');
        $keyword = trim((string)$this->request->get('keyword', ''));

        $table = 'points_exchange_order';
        // 自动建表（如果还没有兑换申请）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_points_exchange_order` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `item_id` varchar(64) NOT NULL DEFAULT '',
            `item_type` varchar(16) NOT NULL DEFAULT 'coupon',
            `item_title` varchar(128) NOT NULL DEFAULT '',
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已发放 2=已拒绝',
            `remark` varchar(256) NOT NULL DEFAULT '',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`),
            KEY `idx_item_status` (`item_id`,`status`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $query = Db::name($table)->field('id,uid,item_id,item_type,item_title,points,status,remark,create_time');

        if ($status !== '' && in_array($status, ['0', '1', '2'], true)) {
            $query->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $matchedUids = UserModel::whereLike('mobile|nickname', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $matchedUids) {
                $q->whereLike('item_title', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $q->whereOr('uid', (int)$keyword);
                }
                if (!empty($matchedUids)) {
                    $q->whereOr('uid', 'in', $matchedUids);
                }
            });
        }

        $total  = (int)(clone $query)->count();
        $list   = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $uids   = array_values(array_unique(array_map(static fn ($r) => (int)($r['uid'] ?? 0), $list)));
        $userMap = [];
        if (!empty($uids)) {
            foreach (UserModel::whereIn('id', $uids)->field('id,mobile,nickname')->select()->toArray() as $u) {
                $userMap[(int)$u['id']] = $u;
            }
        }
        foreach ($list as &$row) {
            $u = $userMap[(int)($row['uid'] ?? 0)] ?? [];
            $row['mobile']   = $u['mobile'] ?? '';
            $row['nickname'] = $u['nickname'] ?? '';
        }
        unset($row);

        return show(200, 'success', '查询成功', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    public function points_exchange_order_post(string $action)
    {
        if (!$this->directHasAdminPermission('积分管理')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }

        $post = $this->request->post();
        $id   = max(1, (int)($post['id'] ?? 0));

        $order = Db::name('points_exchange_order')->where('id', $id)->find();
        if (!$order) {
            return show(404, 'error', '兑换订单不存在');
        }

        $now = date('Y-m-d H:i:s');

        switch ($action) {
            case 'fulfill':
                if ((int)$order['status'] !== 0) {
                    return show(400, 'error', '该订单已处理');
                }
                Db::name('points_exchange_order')->where('id', $id)->update([
                    'status'      => 1,
                    'remark'      => trim((string)($post['remark'] ?? '')),
                    'update_time' => $now,
                ]);
                $this->directWriteAdminOperationLog('兑换发放', '积分管理', '发放兑换订单 #' . $id);
                return show(200, 'success', '已标记为已发放');

            case 'reject':
                if ((int)$order['status'] !== 0) {
                    return show(400, 'error', '该订单已处理');
                }
                $remark = trim((string)($post['remark'] ?? ''));

                Db::startTrans();
                try {
                    Db::name('points_exchange_order')->where('id', $id)->update([
                        'status'      => 2,
                        'remark'      => $remark,
                        'update_time' => $now,
                    ]);

                    // 退还积分
                    $refundPoints = max(0, (int)$order['points']);
                    if ($refundPoints > 0) {
                        UserModel::where('id', (int)$order['uid'])->update([
                            'points_balance' => Db::raw('points_balance + ' . $refundPoints),
                            'month_used'     => Db::raw('GREATEST(0, month_used - ' . $refundPoints . ')'),
                            'update_time'    => $now,
                        ]);

                        Db::name('points_record')->insert([
                            'uid'         => (int)$order['uid'],
                            'points'      => $refundPoints,
                            'reason'      => '兑换拒绝退还：' . (string)$order['item_title'],
                            'type'        => 'earned',
                            'create_time' => $now,
                        ]);
                    }

                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('兑换拒绝失败: ' . $e->getMessage());
                    return show(500, 'error', '操作失败');
                }

                $this->directWriteAdminOperationLog('兑换拒绝', '积分管理', '拒绝兑换订单 #' . $id . ' 并退还积分');
                return show(200, 'success', '已拒绝并退还积分');

            default:
                return show(500, 'error', '未知操作');
        }
    }

    public function points_records_json()
    {
        if (!$this->directHasAdminPermission('积分管理')) {
            return $this->directDenyAdminPermission('积分管理');
        }

        $page = max(1, (int)$this->request->get('page', 1));
        $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
        $keyword = trim((string)$this->request->get('keyword', ''));
        $type = trim((string)$this->request->get('type', ''));

        $query = PointsRecord::field('id,uid,points,reason,type,create_time');

        if ($type !== '' && in_array($type, ['earned', 'used'], true)) {
            $query->where('type', $type);
        }
        if ($keyword !== '') {
            $matchedUserIds = UserModel::whereLike('mobile|nickname', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $matchedUserIds) {
                $q->whereLike('reason', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $q->whereOr('uid', (int)$keyword);
                }
                if (!empty($matchedUserIds)) {
                    $q->whereOr('uid', 'in', $matchedUserIds);
                }
            });
        }

        $totalQuery = clone $query;
        $total = (int)$totalQuery->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $uids = array_values(array_unique(array_filter(array_map(static fn ($row) => (int)($row['uid'] ?? 0), $list))));
        $userMap = [];
        if (!empty($uids)) {
            $users = UserModel::whereIn('id', $uids)->field('id,mobile,nickname')->select()->toArray();
            foreach ($users as $user) {
                $userMap[(int)$user['id']] = $user;
            }
        }
        foreach ($list as &$row) {
            $user = $userMap[(int)($row['uid'] ?? 0)] ?? [];
            $row['mobile'] = $user['mobile'] ?? '';
            $row['nickname'] = $user['nickname'] ?? '';
        }
        unset($row);

        return show(200, 'success', '查询成功', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    private function directSaveConfigValue(string $key, string $value): void
    {
        $config = ConfigModel::where('k', $key)->find();
        if ($config) {
            $config->v = $value;
            $config->save();
            CacheModel::destroy('config');
            return;
        }

        ConfigModel::create(['k' => $key, 'v' => $value]);
        CacheModel::destroy('config');
    }

    public function transaction_order_post(string $action)
    {
        $post_info = $this->request->post();

        if (!$this->directHasAdminPermission('交易订单数据')) {
            return $this->directDenyAdminPermission('交易订单数据');
        }

        switch ($action) {
            case 'del':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $order = TransactionOrder::find($id);
                if (!$order) {
                    return show(500, 'error', '订单不存在');
                }
                $orderNumber = (string)($order['order_number'] ?? '');
                $orderStatus = (int)($order['status'] ?? 0);
                $buyerUid = (int)($order['uid'] ?? 0);
                $sellerUid = (int)($order['seller_uid'] ?? 0);
                TransactionOrder::destroy($id);
                $this->directWriteAdminOperationLog('删除交易订单', '交易订单', '订单ID：' . $id . '，订单号：' . $orderNumber . '，买家UID：' . $buyerUid . '，卖家UID：' . $sellerUid . '，删除前状态：' . $orderStatus);
                return show(200, 'success', '删除成功');

            case 'dels':
                $idsRaw = $post_info['ids'] ?? '';
                if (is_array($idsRaw)) {
                    $ids = array_filter(array_map('intval', $idsRaw), static fn($v) => $v > 0);
                } else {
                    $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)), static fn($v) => $v > 0);
                }
                if (empty($ids)) {
                    return show(500, 'error', '参数错误');
                }
                $orders = TransactionOrder::where('id', 'in', $ids)->select();
                $deletedCount = 0;
                $orderNumbers = [];
                foreach ($orders as $vo) {
                    $orderNumbers[] = (string)($vo['order_number'] ?? '');
                    TransactionOrder::destroy($vo['id']);
                    $deletedCount++;
                }
                $this->directWriteAdminOperationLog('批量删除交易订单', '交易订单', '删除数量：' . $deletedCount . '，订单ID：' . implode(',', $ids) . '，订单号：' . implode(',', $orderNumbers));
                return show(200, 'success', '删除成功');

            default:
                return show(500, 'error', '你不对劲');
        }
    }
    

    public function rebate_record_post(string $action)
    {
        $post_info = $this->request->post();

        if (!$this->directHasAdminPermission('返佣记录')) {
            return $this->directDenyAdminPermission('返佣记录');
        }

        switch ($action) {
            case 'del':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $record = RebateRecord::find($id);
                if (!$record) {
                    return show(500, 'error', '记录不存在');
                }
                $uid = (int)($record['uid'] ?? 0);
                $amount = (string)($record['amount'] ?? '');
                $orderNumber = (string)($record['order_number'] ?? '');
                RebateRecord::destroy($id);
                $this->directWriteAdminOperationLog('删除返佣记录', '返佣记录', '记录ID：' . $id . '，用户UID：' . $uid . '，关联订单号：' . $orderNumber . '，金额：' . $amount);
                return show(200, 'success', '删除成功');

            case 'dels':
                $idsRaw = $post_info['ids'] ?? '';
                if (is_array($idsRaw)) {
                    $ids = array_filter(array_map('intval', $idsRaw), static fn($v) => $v > 0);
                } else {
                    $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)), static fn($v) => $v > 0);
                }
                if (empty($ids)) {
                    return show(500, 'error', '参数错误');
                }
                $records = RebateRecord::where('id', 'in', $ids)->select();
                $deletedCount = 0;
                foreach ($records as $vo) {
                    RebateRecord::destroy($vo['id']);
                    $deletedCount++;
                }
                $this->directWriteAdminOperationLog('批量删除返佣记录', '返佣记录', '删除数量：' . $deletedCount . '，记录ID：' . implode(',', $ids));
                return show(200, 'success', '删除成功');

            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    

    public function slide_post(string $action)
    {
        $post_info = $this->request->post();

        if (!$this->directHasAdminPermission('首页轮播图')) {
            return $this->directDenyAdminPermission('首页轮播图');
        }

        switch ($action) {
            case 'submit':
                if(empty($post_info['name'])){
                    return show(500, 'error', '请输入轮播图名称');
                }
                if(empty($post_info['image'])){
                    return show(500, 'error', '请上传轮播图图片');
                }
                $slideId = (int)($post_info['id'] ?? 0);
                if ($slideId > 0) {
                    $slide = Slide::find($slideId);
                    if ($slide) {
                        $oldName = (string)$slide['name'];
                        $slide->name = $post_info['name'];
                        $slide->image = $post_info['image'];
                        $slide->save();
                        $this->directWriteAdminOperationLog('修改轮播图', '首页轮播图', '轮播图ID：' . $slideId . '，名称：' . $oldName . ' -> ' . $post_info['name']);
                        return show(200, 'success', '修改成功');
                    }
                }
                Slide::create([
                    'name' => $post_info['name'],
                    'image' => $post_info['image'],
                ]);
                $this->directWriteAdminOperationLog('添加轮播图', '首页轮播图', '轮播图名称：' . $post_info['name']);
                return show(200, 'success', '添加成功');

            case 'del':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $slide = Slide::find($id);
                if (!$slide) {
                    return show(500, 'error', '轮播图不存在');
                }
                $slideName = (string)$slide['name'];
                Slide::destroy($id);
                $this->directWriteAdminOperationLog('删除轮播图', '首页轮播图', '轮播图ID：' . $id . '，名称：' . $slideName);
                return show(200, 'success', '删除成功');

            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    public function product_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'add_modify':
                $productType = (int)($post_info['type'] ?? 0);
                $productPermission = $productType === 1 ? '充值业务 - 产品列表' : ($productType === 2 ? '查询业务 - 产品列表' : '');
                if ($productPermission === '' || !$this->directHasAdminPermission($productPermission)) {
                    return $this->directDenyAdminPermission($productPermission ?: '产品管理');
                }
                if(empty($post_info['name'])){
                    return show(500, 'error', '请输入产品名称');
                }
                if(empty($post_info['describe'])){
                    return show(500, 'error', '请输入产品描述');
                }
                if($post_info['type'] == 1){
                    if(empty($post_info['image'])){
                        return show(500, 'error', '请上传产品图标');
                    }
                    if(empty($post_info['mini_recharge_amount'])){
                        return show(500, 'error', '请输入最低充值金额');
                    }
                }else{
                    if(empty($post_info['quiry_price'])){
                        return show(500, 'error', '请输入查询价格');
                    }
                }

                Db::startTrans();
                try {
                    $Product_info = Product::find($post_info['id']??'');
                    if($Product_info){
                        $Product_info->name = $post_info['name'];
                        $Product_info->home_name = $post_info['name'];
                        $Product_info->describe = $post_info['describe'];
                        $Product_info->tutorial_content = $post_info['tutorial_content']??null;
                        $Product_info->image = $post_info['image']??null;
                        $Product_info->mini_recharge_amount = $post_info['mini_recharge_amount']??null;
                        $Product_info->kickback_rtion_1 = $post_info['kickback_rtion_1']??null;
                        $Product_info->kickback_rtion_2 = $post_info['kickback_rtion_2']??null;
                        $Product_info->kickback_rtion_3 = $post_info['kickback_rtion_3']??null;
                        $Product_info->kickback_rtion_4 = $post_info['kickback_rtion_4']??null;
                        $Product_info->kickback_rtion_5 = $post_info['kickback_rtion_5']??null;
                        $Product_info->kickback_rtion_6 = $post_info['kickback_rtion_6']??null;
                        $Product_info->kickback_rtion_7 = $post_info['kickback_rtion_7']??null;
                        $Product_info->kickback_rtion_8 = $post_info['kickback_rtion_8']??null;
                        $Product_info->kickback_rtion_9 = $post_info['kickback_rtion_9']??null;
                        $Product_info->kickback_rtion_10 = $post_info['kickback_rtion_10']??null;

                        $Product_info->order_info = $post_info['order_info']??null;
                        $Product_info->par_value = $post_info['par_value']??null;
                        $Product_info->discount = $post_info['discount']??null;
                        $Product_info->quiry_price = $post_info['quiry_price']??null;
                        $Product_info->batch_status = $post_info['batch_status']??null;
                        $Product_info->product_type = $post_info['product_type']??null;
                        $saveRes = $Product_info->save();
                        if ($saveRes === false) {
                            throw new \Exception('商品保存失败');
                        }

                        Db::name('substation_product_tier_price')->where('product_id', (int)$Product_info->id)->delete();

                        Db::commit();
                        return show(200, 'success', '修改成功');
                    }

                    Product::create([
                        'type' => $post_info['type'],
                        'name' => $post_info['name'],
                        'describe' => $post_info['describe'],
                        'tutorial_content' => $post_info['tutorial_content']??null,
                        'image' => $post_info['image']??null,
                        'mini_recharge_amount' => $post_info['mini_recharge_amount']??null,
                        'kickback_rtion_1' => $post_info['kickback_rtion_1']??null,
                        'kickback_rtion_2' => $post_info['kickback_rtion_2']??null,
                        'kickback_rtion_3' => $post_info['kickback_rtion_3']??null,
                        'kickback_rtion_4' => $post_info['kickback_rtion_4']??null,
                        'kickback_rtion_5' => $post_info['kickback_rtion_5']??null,
                        'kickback_rtion_6' => $post_info['kickback_rtion_6']??null,
                        'kickback_rtion_7' => $post_info['kickback_rtion_7']??null,
                        'kickback_rtion_8' => $post_info['kickback_rtion_8']??null,
                        'kickback_rtion_9' => $post_info['kickback_rtion_9']??null,
                        'kickback_rtion_10' => $post_info['kickback_rtion_10']??null,

                        'order_info' => $post_info['order_info']??null,
                        'par_value' => $post_info['par_value']??null,
                        'discount' => $post_info['discount']??null,
                        'quiry_price' => $post_info['quiry_price']??null,
                        'batch_status' => $post_info['batch_status']??null,
                        'product_type' => $post_info['product_type'] ?? null,
                    ]);

                    Db::commit();
                    return show(200, 'success', '添加成功');
                } catch (\Throwable $e) {
                    Db::rollback();
                    Log::error('product_post add_modify error: ' . $e->getMessage(), [
                        'id' => (int)($post_info['id'] ?? 0),
                        'action' => 'add_modify',
                    ]);
                    return show(500, 'error', !empty($post_info['id']) ? '修改失败' : '添加失败');
                }

            case 'info':
                $res = Product::find($post_info['id']);
                
                if($res['type'] == 1){
                    $order_info_html = '';
                    foreach($res['order_info'] as $key => $vo_a) {
                        $selected_1 = $selected_2 = $selected_3 = $selected_4 = '';
                        if($vo_a['type'] == 1){
                            $selected_1 = 'selected';
                        }elseif($vo_a['type'] == 2){
                            $selected_2 = 'selected';
                        }elseif($vo_a['type'] == 3){
                            $selected_3 = 'selected';
                        }elseif($vo_a['type'] == 4){
                            $selected_4 = 'selected';
                        }

                        $order_info_html .= '
                        <div data-repeater-item order_info_html>
                            <div class="form-group row mb-3">
                                <div class="col-md-10">
                                    <div class="input-group">
                                        <span class="input-group-text">类型：</span>
                                        <select class="form-select" id="type" data-placeholder="请选择类型">
                                            <option value="1" '.$selected_1.'>输入框</option>
                                            <option value="2" '.$selected_2.'>地区选择（市）</option>
                                            <option value="3" '.$selected_3.'>地区选择（区）</option>
                                            <option value="4" '.$selected_4.'>图片上传</option>
                                        </select>
                                        <span class="input-group-text">排序：</span>
                                        <input type="text" class="form-control" id="sort" placeholder="请输入排序" value="'.$vo_a['sort'].'"/>
                                    </div>
                                    <div class="input-group">
                                        <span class="input-group-text">名称：</span>
                                        <input type="text" class="form-control" id="name" placeholder="请输入名称" value="'.$vo_a['name'].'"/>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <a href="javascript:;" data-repeater-delete class="btn btn-light-danger">
                                        <i class="ki-duotone ki-trash fs-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                                    </a>
                                </div>
                            </div>
                        </div>';
                    }
                    $res['order_info_html'] = $order_info_html;

                    $par_value_html = '';
                    foreach($res['par_value'] as $key => $vo_b) {
                        $name = $vo_b['name']??'';
                        $par_value_html .= '
                        <div data-repeater-item>
                            <div class="form-group row mb-3">
                                <div class="col-md-10">
                                    <div class="input-group">
                                        <span class="input-group-text">名称：</span>
                                        <input type="text" class="form-control" id="name" placeholder="请输入名称" value="'.$name.'"/>
                                        <span class="input-group-text">面值：</span>
                                        <input type="text" class="form-control" id="value" placeholder="请输入面值" value="'.$vo_b['value'].'"/>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <a href="javascript:;" data-repeater-delete class="btn btn-light-danger">
                                        <i class="ki-duotone ki-trash fs-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                                    </a>
                                </div>
                            </div>
                        </div>';
                    }
                    $res['par_value_html'] = $par_value_html;
                    
                    $discount_html = '';
                    foreach($res['discount'] as $key => $vo_c) {
                        $discount_html .= '
                        <div data-repeater-item>
                            <div class="form-group row mb-3">
                                <div class="col-md-10">
                                    <div class="input-group">
                                        <input type="text" id="mini_amount" class="form-control form-control-" placeholder="请输入金额" value="'.$vo_c['mini_amount'].'">
                                        <span class="input-group-text">~</span>
                                        <input type="text" id="maxi_amount" class="form-control form-control-" placeholder="请输入金额" value="'.$vo_c['maxi_amount'].'">
                                        <span class="input-group-text">折扣</span>
                                        <input type="text" id="discounts" class="form-control form-control-" placeholder="请输入折扣" value="'.$vo_c['discount'].'">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <a href="javascript:;" data-repeater-delete class="btn btn-light-danger">
                                        <i class="ki-duotone ki-trash fs-5"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                                    </a>
                                </div>
                            </div>
                        </div>';
                    }
                    $res['discount_html'] = $discount_html;
                }
                return show(200, 'success', '获取信息成功', (array)$res->getData());
                

            case 'status_switch':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $res = Product::find($id);
                if (!$res) {
                    return show(500, 'error', '产品不存在');
                }
                $prodType = (int)$res['type'];
                $prodPerm = $prodType === 1 ? '充值业务 - 产品列表' : ($prodType === 2 ? '查询业务 - 产品列表' : '');
                if ($prodPerm === '' || !$this->directHasAdminPermission($prodPerm)) {
                    return $this->directDenyAdminPermission($prodPerm ?: '产品管理');
                }
                $oldStatus = (int)$res['status'];
                $newStatus = $oldStatus === 0 ? 1 : 0;
                $res->status = $newStatus;
                $res->save();
                $this->directWriteAdminOperationLog('切换产品状态', '产品管理', '产品ID：' . $id . '，产品名称：' . (string)$res['name'] . '，类型：' . $prodType . '，状态：' . $oldStatus . ' -> ' . $newStatus);
                return show(200, 'success', '状态更新成功');

            case 'sort':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $res = Product::find($id);
                if (!$res) {
                    return show(500, 'error', '产品不存在');
                }
                $prodType = (int)$res['type'];
                $prodPerm = $prodType === 1 ? '充值业务 - 产品列表' : ($prodType === 2 ? '查询业务 - 产品列表' : '');
                if ($prodPerm === '' || !$this->directHasAdminPermission($prodPerm)) {
                    return $this->directDenyAdminPermission($prodPerm ?: '产品管理');
                }
                $oldSort = (int)$res['sort'];
                $newSort = (int)($post_info['sort'] ?? 0);
                $res->sort = $newSort;
                $res->save();
                $this->directWriteAdminOperationLog('修改产品排序', '产品管理', '产品ID：' . $id . '，产品名称：' . (string)$res['name'] . '，排序：' . $oldSort . ' -> ' . $newSort);
                return show(200, 'success', '更新成功');

            case 'del':
                $id = (int)($post_info['id'] ?? 0);
                if ($id <= 0) {
                    return show(500, 'error', '参数错误');
                }
                $res = Product::find($id);
                if (!$res) {
                    return show(500, 'error', '产品不存在');
                }
                $prodType = (int)$res['type'];
                $prodPerm = $prodType === 1 ? '充值业务 - 产品列表' : ($prodType === 2 ? '查询业务 - 产品列表' : '');
                if ($prodPerm === '' || !$this->directHasAdminPermission($prodPerm)) {
                    return $this->directDenyAdminPermission($prodPerm ?: '产品管理');
                }
                $productName = (string)$res['name'];
                Product::destroy($id);
                $this->directWriteAdminOperationLog('删除产品', '产品管理', '产品ID：' . $id . '，产品名称：' . $productName . '，类型：' . $prodType);
                return show(200, 'success', '删除成功');

            case 'dels':
                $idsRaw = $post_info['ids'] ?? '';
                if (is_array($idsRaw)) {
                    $ids = array_filter(array_map('intval', $idsRaw), static fn($v) => $v > 0);
                } else {
                    $ids = array_filter(array_map('intval', explode(',', (string)$idsRaw)), static fn($v) => $v > 0);
                }
                if (empty($ids)) {
                    return show(500, 'error', '参数错误');
                }
                $products = Product::where('id', 'in', $ids)->select();
                $deletedCount = 0;
                $productNames = [];
                foreach ($products as $vo) {
                    $prodType = (int)$vo['type'];
                    $prodPerm = $prodType === 1 ? '充值业务 - 产品列表' : ($prodType === 2 ? '查询业务 - 产品列表' : '');
                    if ($prodPerm === '' || !$this->directHasAdminPermission($prodPerm)) {
                        return $this->directDenyAdminPermission($prodPerm ?: '产品管理');
                    }
                    $productNames[] = (string)$vo['name'];
                    Product::destroy($vo['id']);
                    $deletedCount++;
                }
                $this->directWriteAdminOperationLog('批量删除产品', '产品管理', '删除数量：' . $deletedCount . '，产品ID：' . implode(',', $ids) . '，产品名称：' . implode(',', $productNames));
                return show(200, 'success', '删除成功');
                
            default:
                return show(500, 'error', '你不对劲');
        }
    }


    public function user_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'balance':
                return $this->handleBalance($post_info);
            
            case 'password':
                return $this->handleUserPassword($post_info);

            case 'status_switch':
                return $this->handleUserStatusSwitch($post_info);

            case 'twofa_unbind':
                return $this->handleUserTwofaUnbind($post_info);

            case 'rights':
                return $this->handleUserRights($post_info);

            case 'dels':
                return $this->handleUserDeleteBatch($post_info);
            
            case 'del':
                return $this->handleUserDelete($post_info);
            
                    
            default:
                return show(500, 'error', '你不对劲');
        }
    }
    
    /**
     * 双因素认证相关操作
     */
    public function twofa_post(string $action)
    {
        $post_info = $this->request->post();
        $currentAdminId = (int)($this->admin_info['id'] ?? 0);
        
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
            
            switch ($action) {
                case 'generate':
                    $payload = $this->beginAdminTwofaSetup($model);

                    Log::info("{$logPrefix}初始化2FA绑定");
                    return show(200, 'success', '请使用身份验证器扫描二维码并输入当前验证码完成绑定', $payload);

                case 'enable':
                    $code = trim((string)($post_info['code'] ?? $post_info['twofa_code'] ?? ''));
                    $inputError = $this->validateAdminTwofaCodeInput($code);
                    if ($inputError !== null) {
                        return show(500, 'error', $inputError);
                    }

                    $secret = (string)Session::get('admin_twofa_temp_secret', '');
                    $tempAdminId = (int)Session::get('admin_twofa_temp_admin_id', 0);
                    if ($secret === '' || $tempAdminId !== (int)$model->id) {
                        return show(500, 'error', '请先开始2FA绑定');
                    }

                    $twofa = new TwoFactorAuth();
                    if (!$twofa->verifyCode($secret, $code, 2)) {
                        Log::warning("{$logPrefix}2FA启用失败：验证码错误");
                        return show(500, 'error', '请确认APP时间同步后再重试');
                    }

                    $recoveryCodes = Session::get('admin_twofa_temp_recovery_codes');
                    if (!is_array($recoveryCodes) || $recoveryCodes === []) {
                        return show(500, 'error', '恢复码初始化失败，请重新开始绑定');
                    }

                    $model->twofa_secret = $this->encryptAdminData($secret);
                    $model->twofa_recovery_codes = $this->hashAdminRecoveryCodes($recoveryCodes);
                    $model->twofa_enabled = 1;
                    $model->save();

                    $this->clearPendingAdminTwofaSetup();

                    $this->directWriteAdminOperationLog('启用管理员2FA', '管理员管理', '管理员账号：' . $account . '，已启用2FA认证', [
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

                    $verified = $this->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'disable');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $model->twofa_enabled = 0;
                    $model->twofa_secret = null;
                    $model->twofa_recovery_codes = null;
                    $model->save();

                    $this->clearPendingAdminTwofaSetup();

                    $this->directWriteAdminOperationLog('禁用管理员2FA', '管理员管理', '管理员账号：' . $account . '，已禁用2FA认证', [
                        'target_id' => (int)($model->id ?? 0),
                        'target_type' => 'admin',
                    ]);

                    Log::info("{$logPrefix}成功禁用2FA");
                    return show(200, 'success', '2FA已成功禁用');

                case 'verify':
                    $verified = $this->verifyAdminTwofaCode($model, trim((string)($post_info['code'] ?? $post_info['twofa_code'] ?? '')));
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证码不正确'));
                    }

                    return show(200, 'success', '验证码验证成功');

                case 'recover':
                    $verified = $this->consumeAdminRecoveryCode($model, (string)($post_info['recovery_code'] ?? ''), 'recover');
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

                    $verified = $this->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'regenerate_recovery_codes');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $recoveryCodes = $this->generateAdminRecoveryCodes(8);
                    $model->twofa_recovery_codes = $this->hashAdminRecoveryCodes($recoveryCodes);
                    $model->save();

                    $this->directWriteAdminOperationLog('重置管理员2FA恢复码', '管理员管理', '管理员账号：' . $account . '，已重新生成2FA恢复码', [
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

                    $verified = $this->verifyAdminTwofaOrRecovery($model, (array)$post_info, 'reset');
                    if (empty($verified['ok'])) {
                        return show(500, 'error', (string)($verified['message'] ?? '验证失败'));
                    }

                    $payload = $this->beginAdminTwofaSetup($model, true);

                    Log::info("{$logPrefix}重置2FA绑定");
                    return show(200, 'success', '2FA已重置，请使用新的密钥重新绑定', $payload);
                    
                default:
                    return show(500, 'error', '无效的操作');
            }
        } catch (Exception $e) {
            Log::error("2FA操作失败：" . $e->getMessage());
            return show(500, 'error', '操作失败：' . $e->getMessage());
        }
    }


    public function admin_post(string $action)
    {
        $post_info = $this->request->post();
        try {
            switch ($action) {
                case 'add_modify':
                    if (!$this->directHasAdminPermission('管理员列表')) {
                        return $this->directDenyAdminPermission('管理员列表');
                    }
                    if (!$this->directRequestPathMatches('admin_post/add_modify')) {
                        return show(403, 'error', '管理员请求路径错误');
                    }
                    if (!$this->directValidateRequiredCsrfToken()) {
                        Log::warning('admin add_modify invalid csrf blocked', [
                            'admin_id' => (int)($this->admin_info['id'] ?? 0),
                            'ip' => (string)$this->request->ip(),
                            'path' => $this->directCurrentRequestPath(),
                        ]);
                        return show(403, 'error', '管理员请求校验失败');
                    }
                    // P5-P1-002: 操作者权限边界
                    $isSuperAdmin = $this->directIsCurrentAdminSuperAdmin();
                    $currentAdminId = (int)($this->admin_info['id'] ?? 0);
                    $AdminModel = AdminModel::find($post_info['id']);
                    if(empty($post_info['account'])){
                        return show(500, 'error', '请输入登录账号');
                    }
                    if(empty($post_info['name'])){
                        return show(500, 'error', '请输入管理员名称');
                    }
                    $salt = randomkeys(4);
                    if($AdminModel){
                        $beforeAdmin = $AdminModel->getData();
                        $targetId = (int)$AdminModel['id'];
                        // P5-P1-002: 非超级管理员禁止修改超级管理员
                        if (!$isSuperAdmin && $targetId === 1) {
                            return show(500, 'error', '无权修改超级管理员');
                        }
                        // P5-P1-002: power 白名单校验
                        $powerResult = $this->directValidateAdminPowerValue((string)($post_info['power'] ?? ''));
                        if (empty($powerResult['ok'])) {
                            return show(500, 'error', (string)($powerResult['message'] ?? '权限校验失败'));
                        }
                        $cleanedPower = (string)($powerResult['cleaned'] ?? '');
                        if($post_info['account'] != $AdminModel['account']){
                            $AdminModels = AdminModel::where('account', $post_info['account'])->find();
                            if($AdminModels){
                                return show(500, 'error', '登录账号已存在，请修改');
                            }
                        }
                        $AdminModel->account = $post_info['account'];
                        $AdminModel->name = $post_info['name'];
                        // P5-P1-002: 仅超级管理员可修改 power 字段，防止自我提权和横向提权
                        if ($isSuperAdmin) {
                            $AdminModel->power = $cleanedPower;
                        }
                        // P5-P1-003: 非超级管理员不能修改其他管理员的密码（防止账号接管）
                        if (!$isSuperAdmin && $targetId !== $currentAdminId && !empty($post_info['password'])) {
                            return show(500, 'error', '仅超级管理员可修改其他管理员密码');
                        }
                        // P5-P1-002: 修改密码需敏感操作二次验证
                        if(!empty($post_info['password'])){
                            $sensitiveResult = $this->directValidateSensitiveOperation($post_info, 'admin_password_change');
                            if (empty($sensitiveResult['ok'])) {
                                return show(500, 'error', (string)($sensitiveResult['message'] ?? '敏感操作验证失败'));
                            }
                            $AdminModel->password = password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT);
                            $AdminModel->salt = $salt;
                        }
                        $AdminModel->save();
                        $powerChangedText = $isSuperAdmin
                            ? ('，权限：' . (string)($beforeAdmin['power'] ?? '无') . ' -> ' . $cleanedPower)
                            : '';
                        $this->directWriteAdminOperationLog('修改管理员', '管理员管理', '管理员ID：' . (int)($AdminModel['id'] ?? 0) . '，账号：' . (string)($beforeAdmin['account'] ?? '') . ' -> ' . (string)$post_info['account'] . '，名称：' . (string)($beforeAdmin['name'] ?? '') . ' -> ' . (string)$post_info['name'] . $powerChangedText . (!empty($post_info['password']) ? '，密码：已重置' : ''), [
                            'target_id' => (int)($AdminModel['id'] ?? 0),
                            'target_type' => 'admin',
                        ]);
                        return show(200, 'success', '修改成功');
                    }
                    // P5-P1-002: 仅超级管理员可创建新管理员
                    if (!$isSuperAdmin) {
                        return show(500, 'error', '仅超级管理员可创建管理员');
                    }
                    if(empty($post_info['password'])){
                        return show(500, 'error', '请输入登录密码');
                    }
                    $AdminModel = AdminModel::where('account', $post_info['account'])->find();
                    if($AdminModel){
                        return show(500, 'error', '登录账号已存在，请修改');
                    }
                    // P5-P1-002: 新建管理员 power 白名单校验
                    $powerResult = $this->directValidateAdminPowerValue((string)($post_info['power'] ?? ''));
                    if (empty($powerResult['ok'])) {
                        return show(500, 'error', (string)($powerResult['message'] ?? '权限校验失败'));
                    }
                    $cleanedPower = (string)($powerResult['cleaned'] ?? '');
                    AdminModel::create([
                        'account' => $post_info['account'],
                        'password' => password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT),
                        'salt' => $salt,
                        'name' => $post_info['name'],
                        'power' => $cleanedPower,
                        // 新增：默认禁用2FA
                        'twofa_enabled' => 0,
                        'twofa_secret' => null,
                        'twofa_recovery_codes' => null
                    ]);
                    $newAdmin = AdminModel::where('account', $post_info['account'])->find();
                    $this->directWriteAdminOperationLog('新增管理员', '管理员管理', '新增管理员账号：' . (string)$post_info['account'] . '，名称：' . (string)$post_info['name'] . '，权限：' . $cleanedPower, [
                        'target_id' => (int)($newAdmin['id'] ?? 0),
                        'target_type' => 'admin',
                    ]);
                    return show(200, 'success', '添加成功');
                    
                case 'info':
                    // P2-001: 权限校验
                    if (!$this->directHasAdminPermission('管理员列表')) {
                        return $this->directDenyAdminPermission('管理员列表');
                    }
                    // P2-001: 路径校验
                    if (!$this->directRequestPathMatches('admin_post/info')) {
                        return show(403, 'error', '管理员请求路径错误');
                    }
                    // P2-001: CSRF 校验
                    if (!$this->directValidateRequiredCsrfToken()) {
                        Log::warning('admin info invalid csrf blocked', [
                            'admin_id' => (int)($this->admin_info['id'] ?? 0),
                            'ip' => (string)$this->request->ip(),
                            'path' => $this->directCurrentRequestPath(),
                        ]);
                        return show(403, 'error', '管理员请求校验失败');
                    }
                    $id = (int)($post_info['id'] ?? 0);
                    if ($id <= 0) {
                        return show(500, 'error', '参数错误');
                    }

                    $res = AdminModel::find($id);
                    if (!$res) {
                        return show(500, 'error', '管理员不存在');
                    }

                    $street = [
                        "用户列表", "支付管理", "充值业务 - 产品列表", "查询业务 - 产品列表", "充值业务 - 订单列表",
                        "查询业务 - 订单列表", "交易挂单数据", "交易订单数据", "充值订单记录", "提现订单记录",
                        "返佣记录", "首页轮播图", "积分管理", "管理员列表", "操作记录", "系统设置管理"
                    ];

                    $powerValue = (string)($res['power'] ?? '');
                    $power_selected = '';
                    foreach ($street as $name) {
                        $selected = (strpos($powerValue, $name) !== false) ? 'selected' : '';
                        $power_selected .= "<option value=\"{$name}\" {$selected}>{$name}</option>";
                    }

                    // P2-001: 字段白名单，禁止返回 password/salt/twofa_secret/twofa_recovery_codes 等敏感字段
                    $data = [
                        'id' => (int)($res['id'] ?? 0),
                        'account' => (string)($res['account'] ?? ''),
                        'name' => (string)($res['name'] ?? ''),
                        'power' => (string)($res['power'] ?? ''),
                        'power_selected' => $power_selected,
                    ];

                    return show(200, 'success', '获取信息成功', $data);

                case 'del':
                    // P5-P1-001: 权限校验
                    if (!$this->directHasAdminPermission('管理员列表')) {
                        return $this->directDenyAdminPermission('管理员列表');
                    }
                    // P5-P1-001: 路径校验
                    if (!$this->directRequestPathMatches('admin_post/del')) {
                        return show(403, 'error', '管理员请求路径错误');
                    }
                    // P5-P1-001: CSRF 校验
                    if (!$this->directValidateRequiredCsrfToken()) {
                        Log::warning('admin del invalid csrf blocked', [
                            'admin_id' => (int)($this->admin_info['id'] ?? 0),
                            'ip' => (string)$this->request->ip(),
                            'path' => $this->directCurrentRequestPath(),
                        ]);
                        return show(403, 'error', '管理员请求校验失败');
                    }
                    $targetId = (int)($post_info['id'] ?? 0);
                    $currentAdminId = (int)($this->admin_info['id'] ?? 0);
                    // P5-P1-001: 禁止删除超级管理员
                    if ($targetId === 1) {
                        return show(500, 'error', '禁止删除超级管理员');
                    }
                    // P5-P1-001: 禁止删除自己
                    if ($targetId === $currentAdminId) {
                        return show(500, 'error', '禁止删除当前登录账号');
                    }
                    if ($targetId <= 0) {
                        return show(500, 'error', '参数错误');
                    }
                    // P5-P1-001: 敏感操作二次验证
                    $sensitiveResult = $this->directValidateSensitiveOperation($post_info, 'admin_delete');
                    if (empty($sensitiveResult['ok'])) {
                        return show(500, 'error', (string)($sensitiveResult['message'] ?? '敏感操作验证失败'));
                    }
                    // P5-P1-001: 删除前确认目标存在
                    $deleteAdmin = AdminModel::find($targetId);
                    if (!$deleteAdmin) {
                        return show(500, 'error', '管理员不存在');
                    }
                    AdminModel::destroy($targetId);
                    $this->directWriteAdminOperationLog('删除管理员', '管理员管理', '删除管理员ID：' . (int)($deleteAdmin['id'] ?? 0) . '，账号：' . (string)($deleteAdmin['account'] ?? '') . '，名称：' . (string)($deleteAdmin['name'] ?? ''), [
                        'target_id' => (int)($deleteAdmin['id'] ?? 0),
                        'target_type' => 'admin',
                    ]);
                    return show(200, 'success', '删除成功');
                default:
                    return show(500, 'error', '你不对劲');
            }
        } catch (DbException $e) {
            return show(500, 'error', $e->getMessage());
        }
    }


    public function account_post(string $action)
    {
        // P2-004 P3-002: 显式 CSRF 双重保护（全局 CsrfCheck 已保护，此处增加 controller 层校验）
        if (!$this->directValidateRequiredCsrfToken()) {
            return show(403, 'error', '请求校验失败');
        }
        $post_info = $this->request->post();
        try {
            switch ($action) {
                case 'account':
                    if(empty($post_info['account'])){
                        return show(500, 'error', '登录账号不可为空');
                    }
                    $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
                    if(!empty($post_info['password'])){
                        $salt = randomkeys(4);
                        $admin_info->password = password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT);
                        $admin_info->salt = $salt;
                    }
                    $admin_info->account = $post_info['account'];
                    $admin_info->save();
                    return show(200, 'success', '信息修改成功');
                case 'avatar':
                    try {
                        $stored = (new UploadService())->storeImageUpload(
                            (string)$this->request->post('result'),
                            [
                                'directory' => 'storage/avatar',
                                'basename' => (string)($this->admin_info['account'] ?? ''),
                                'allowed_mimes' => ['image/jpeg', 'image/png'],
                                'empty_message' => '图片上传错误',
                            ]
                        );
                        $admin_info = AdminModel::where('id', $this->admin_info['id'])->find();
                        if (!$admin_info) {
                            return show(500, 'error', '管理员信息不存在');
                        }
                        $admin_info->avatar = $stored['public_path'];
                        $admin_info->save();
                        return show(200, 'success', '头像上传成功');
                    } catch (Exception $e) {
                        return show(500, 'error', $e->getMessage());
                    }
                default:
                    return show(500, 'error', '你不对劲');
            }
        } catch (DbException $e) {
            return show(500, 'error', $e->getMessage());
        }
    }

    /**
     * 修改登录方法，添加2FA验证
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
        
        // 检查是否启用了2FA
        if ($admin_info->twofa_enabled) {
            $verificationCode = trim((string)($post_info['twofa_code'] ?? ''));
            if ($verificationCode === '') {
                return show(403, 'need_twofa', '请输入二步验证码或恢复码', [
                    'twofa_required' => 1,
                ], 403);
            }

            $twofaResult = $this->verifyAdminTwofaOrRecovery($admin_info, [
                'verification_code' => $verificationCode,
            ], 'login');
            if (empty($twofaResult['ok'])) {
                $attemptMode = $this->detectAdminLoginVerificationAttempt($verificationCode);
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
        $this->rotateSessionForAdminLogin($admin_info->getData());
        $this->directWriteAdminOperationLog('管理员登录成功', '管理员管理', '管理员账号：' . $account . $loginVerificationSummary, [
            'admin' => $admin_info->getData(),
            'target_id' => (int)($admin_info['id'] ?? 0),
            'target_type' => 'admin',
        ]);
        return show(200, 'success', '登录成功', getConfig('backstage_entrance'));
    }

    // 后台管理员退出登录
    public function logout()
    {
        // 防被动登出：后台退出仅允许 POST，阻断第三方页面通过 GET 链接或图片直接触发退出。
        if (!$this->request->isPost()) {
            return show(405, 'error', '不支持的请求方法', null, 405);
        }

        $account = $this->admin_info['account'] ?? '未知管理员';
        Log::info("管理员{$account}退出登录");
        $this->destroyAdminSession();
        return redirect((string)url(getConfig('backstage_entrance').'/login'));
    }


    // 图片上传（Logo & 二维码）
    public function upload_post()
    {
        $fileBag = (array)$this->request->file();
        $keyname = array_key_first($fileBag);
        $file = $keyname !== null ? ($fileBag[$keyname] ?? null) : null;
        if ($keyname === null || !is_object($file)) {
            return show(404, 'error', '请选择图片');
        }

        // P2-004 P2-002: 按 keyname 动态权限检查
        $settingKeys = ['a_recommend_upload', 'b_recommend_upload', 'contact_service_upload', 'user_avatar_upload'];
        if (in_array($keyname, $settingKeys, true)) {
            if (!$this->directHasAdminPermission('系统设置管理')) {
                return $this->directDenyAdminPermission('系统设置管理');
            }
        } elseif ($keyname === 'upload') {
            // 通用上传：产品管理/轮播图/系统设置任意一个权限即可
            $hasUploadPerm = $this->directHasAdminPermission('充值业务 - 产品列表')
                || $this->directHasAdminPermission('查询业务 - 产品列表')
                || $this->directHasAdminPermission('首页轮播图')
                || $this->directHasAdminPermission('系统设置管理');
            if (!$hasUploadPerm) {
                return $this->directDenyAdminPermission('文件上传');
            }
        }

        $uploader = new UploadService();
        if (in_array($keyname, ['a_recommend_upload', 'b_recommend_upload', 'contact_service_upload', 'user_avatar_upload', 'upload'], true)) {
            try {
                $stored = $uploader->storeImageUpload($file, [
                    'directory' => 'storage',
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif'],
                ]);
                return show(200, 'success', '上传成功', $stored['public_path']);
            } catch (Exception $e) {
                return show(404, 'error', $e->getMessage());
            }
        }

        return show(404, 'error', '非白名单文件，禁止上传' . $keyname);
    }

    public function message_send()
    {
        // P2-007: 消息发送权限检查
        if (!$this->directHasAdminPermission('系统设置管理')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $post_info = $this->request->post();

        try {
            $isGlobal = (int)($post_info['is_global'] ?? 0) > 0 ? 1 : 0;
            $userId = (int)($post_info['user_id'] ?? $post_info['uid'] ?? 0);
            $account = trim((string)($post_info['account'] ?? $post_info['mobile'] ?? ''));
            $title = trim((string)($post_info['title'] ?? ''));
            $summary = trim((string)($post_info['summary'] ?? ''));
            $content = trim((string)($post_info['content'] ?? ''));
            $isPinned = (int)($post_info['is_pinned'] ?? 0) > 0 ? 1 : 0;
            $messageType = UserMessageService::normalizeMessageType((string)($post_info['message_type'] ?? 'official'));
            $actionType = UserMessageService::normalizeActionType((string)($post_info['action_type'] ?? 'none'));
            $actionValue = trim((string)($post_info['action_value'] ?? ''));
            $normalizedActionValue = UserMessageService::normalizeActionValue($actionType, $actionValue);

            if ($title === '') {
                return show(500, 'error', '请输入消息标题');
            }
            if ($content === '') {
                return show(500, 'error', '请输入消息正文');
            }

            if ($isGlobal === 1) {
                $publishResult = UserMessageService::publishGlobalMessage(
                    $title,
                    $content,
                    'admin',
                    $actionType,
                    $normalizedActionValue,
                    (int)($this->admin_info['id'] ?? 0),
                    $summary === '' ? null : $summary,
                    $isPinned
                );

                $message = $publishResult['template'];
                $queued = (int)($publishResult['queued'] ?? 0);

                return show(200, 'success', '全局消息发送成功', [
                    'id' => (int)($message['id'] ?? 0),
                    'is_global' => 1,
                    'queued' => $queued,
                    'message_type' => 'global',
                ]);
            }

            $user = null;
            if ($userId > 0) {
                $user = UserModel::where('id', $userId)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('mobile', $account)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('nickname', $account)->find();
            }
            if (!$user && $account !== '') {
                $user = UserModel::where('surname', $account)->find();
            }
            if (!$user) {
                return show(500, 'error', '目标用户不存在，请检查用户ID或账号');
            }

            if ($actionType !== 'none' && $actionValue === '') {
                return show(500, 'error', '请选择动作类型后填写跳转地址');
            }
            if ($actionType !== 'none' && $normalizedActionValue === null) {
                return show(500, 'error', '跳转地址不安全或不在允许范围内');
            }

            $message = createUserMessage(
                (int)$user['id'],
                $title,
                $content,
                'admin',
                $messageType,
                null,
                $actionType,
                $normalizedActionValue,
                (int)($this->admin_info['id'] ?? 0),
                $summary === '' ? null : $summary,
                $isPinned
            );

            return show(200, 'success', '消息发送成功', [
                'id' => (int)($message['id'] ?? 0),
                'user_id' => (int)($user['id'] ?? 0),
                'account' => (string)($user['mobile'] ?? $user['nickname'] ?? $user['surname'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('admin message_send error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'post' => $post_info,
            ]);
            return show(500, 'error', '消息发送失败：' . $e->getMessage());
        }
    }

    public function message_detail()
    {
        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            return show(500, 'error', '缺少消息ID');
        }

        $message = UserMessage::find($id);
        if (!$message) {
            return show(500, 'error', '消息不存在');
        }

        $user = UserModel::field('id,mobile,nickname,surname')->find((int)($message['user_id'] ?? 0));
        $sender = AdminModel::field('id,name,account')->find((int)($message['sender_admin_id'] ?? 0));

        return show(200, 'success', '查询成功', [
            'id' => (int)($message['id'] ?? 0),
            'user_id' => (int)($message['user_id'] ?? 0),
            'title' => (string)($message['title'] ?? ''),
            'summary' => UserMessageService::buildSummary((string)($message['summary'] ?? ''), (string)($message['content'] ?? '')),
            'content' => (string)($message['content'] ?? ''),
            'source_type' => (string)($message['source_type'] ?? 'admin'),
            'message_type' => (string)($message['message_type'] ?? 'official'),
            'action_type' => (string)($message['action_type'] ?? 'none'),
            'action_value' => (string)($message['action_value'] ?? ''),
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
            'is_read' => (int)($message['is_read'] ?? 0),
            'read_time' => (string)($message['read_time'] ?? ''),
            'created_at' => (string)($message['created_at'] ?? ''),
            'updated_at' => (string)($message['updated_at'] ?? ''),
            'user_info' => $user ? [
                'id' => (int)($user['id'] ?? 0),
                'mobile' => (string)($user['mobile'] ?? ''),
                'nickname' => (string)($user['nickname'] ?? ''),
                'surname' => (string)($user['surname'] ?? ''),
                'account' => (string)($user['mobile'] ?? $user['nickname'] ?? $user['surname'] ?? ''),
            ] : null,
            'sender_admin' => $sender ? [
                'id' => (int)($sender['id'] ?? 0),
                'name' => (string)($sender['name'] ?? ''),
                'account' => (string)($sender['account'] ?? ''),
            ] : null,
        ]);
    }

    public function message_pin()
    {
        // P2-007: 消息置顶权限检查
        if (!$this->directHasAdminPermission('系统设置管理')) {
            return $this->directDenyAdminPermission('系统设置管理');
        }
        $post_info = $this->request->post();
        $id = (int)($post_info['id'] ?? 0);
        $isPinned = (int)($post_info['is_pinned'] ?? -1);

        if ($id <= 0) {
            return show(500, 'error', '缺少消息ID');
        }

        if ($isPinned !== 0 && $isPinned !== 1) {
            return show(500, 'error', '置顶状态错误');
        }

        $message = UserMessage::find($id);
        if (!$message) {
            return show(500, 'error', '消息不存在');
        }

        $message->is_pinned = $isPinned;
        $message->save();

        return show(200, 'success', $isPinned === 1 ? '置顶成功' : '已取消置顶', [
            'id' => (int)($message['id'] ?? 0),
            'is_pinned' => (int)($message['is_pinned'] ?? 0),
        ]);
    }

public function message_delete()
{
    // P2-007: 消息删除权限检查
    if (!$this->directHasAdminPermission('系统设置管理')) {
        return $this->directDenyAdminPermission('系统设置管理');
    }
    $post_info = $this->request->post();
    $id = (int)($post_info['id'] ?? 0);

    if ($id <= 0) {
        return show(500, 'error', '缺少消息ID');
    }

    $message = UserMessage::find($id);
    if (!$message) {
        return show(500, 'error', '消息不存在');
    }

    if ((int)($message['is_deleted'] ?? 0) === 1) {
        return show(200, 'success', '消息已删除', [
            'id' => (int)($message['id'] ?? 0),
            'deleted' => 1,
        ]);
    }

    $message->is_deleted = 1;
    $message->updated_at = date('Y-m-d H:i:s');
    $message->save();

    return show(200, 'success', '删除成功', [
        'id' => (int)($message['id'] ?? 0),
        'deleted' => 1,
    ]);
}

    public function admin_footer(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'out_order':
                $order_cz = Order::where('status', 0)->where('type', 1)->count();
                $order_cx = Order::where('status', 0)->where('type', 2)->count();
                $recharge = Recharge::where('status', 1)->count();
                $withdrawal = Withdrawal::where('status', 0)->count();
                $data = [
                    'order_cz' => $order_cz,
                    'order_cx' => $order_cx,
                    'recharge' => $recharge,
                    'withdrawal' => $withdrawal,
                ];
                return show(200, 'success', '查询成功', $data);

            default:
                return show(500, 'error', '请求出错');
        }
    }
    


public function setting_post(string $action)
    {
        switch ($action) {
            case 'setting':
                return $this->handleSetting((array)$this->request->post());
                

            case 'upload':
                return $this->handleSettingUpload();


            default:
                return show(500, 'error', '你不对劲');
        }

    }
}
