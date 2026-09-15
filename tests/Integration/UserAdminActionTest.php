<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\admin\User as AdminUserController;
use app\controller\AdminApi;
use app\model\Admin as AdminModel;
use app\model\User as UserModel;
use app\service\AdminSensitiveOperationGuard;
use app\service\UserService;
use tests\Support\TestDataFactory;
use think\facade\Config;
use think\facade\Db;
use think\facade\Session;

/**
 * HCZ B08: user_post 非资金 action 迁移测试（admin\User）
 *
 * 验证范围（与 Test 授权书一致）：
 *  - 6 个 action（password/status_switch/twofa_unbind/rights/del/dels）OLD/NEW 行为等价
 *  - balance 隔离：新控制器无 balance case；旧 user_post/balance 继续走 AdminApi::handleBalance（Frozen）
 *  - RBAC 四象限（M1/M2/M3/M4）逐 action；无 B06 式权限收紧
 *  - CSRF（handler 层 directValidateRequiredCsrfToken）
 *  - 2FA Guard 等价（window=2 / 错误文案 / 返回结构 / 异常 / password fallback）
 *  - 事务/锁：password/status_switch/twofa_unbind 行锁+commit；rights user→substation 锁序
 *  - pending check：六种条件逐一拒绝；dels 先全查后删；del 追加'，无法删除'
 *  - 操作日志：password/status_switch/twofa_unbind/del/dels 有日志；rights 无日志（OLD 事实保持）
 *  - 金融隔离：rights 的 Substation 创建仅零值钱包字段，无 Ledger 变化
 *
 * 测试库环境说明（仅限 hcz_test 测试库，非生产库；与 TestDataFactory::ensureTestTables 先例一致）：
 *  - cz_admin / cz_substation 表在生产库存在但测试库缺失 → 本类 ensureB08Schema() 以 CREATE TABLE IF NOT EXISTS 补齐（表结构对照生产模型/迁移）
 *  - cz_user 缺 agent_wallet / twofa_enabled / agent_status 列 → ensureB08Schema() 检测缺失后 ADD COLUMN（不影响既有测试，新列默认值）
 *  - security.data_encryption_key 测试环境未配置 → setUp 运行时注入 32 位测试密钥（内存级，不修改任何文件）
 *  - CLI 下 Request::session 属性为 null → setUp 对 app()->request->withSession(app('session')) 注入（单例，原地生效）
 */
class UserAdminActionTest extends DbTestCase
{
    /** 6 个 action 的 OLD 权限码（与 Re-Audit / RBAC 矩阵一致） */
    private const RBAC_MAP = [
        'password' => 'admin.user.password.reset',
        'status_switch' => 'admin.user.manage',
        'twofa_unbind' => 'admin.user.manage',
        'rights' => 'admin.user.rights',
        'del' => 'admin.user.delete',
        'dels' => 'admin.user.delete',
    ];

    /** 测试数据加密密钥（32 位，运行时注入） */
    private const TEST_ENCRYPTION_KEY = 'B08TestEncryptionKey_0123456789abcdef';

    /** 2FA 明文密钥（TOTP） */
    private const TEST_2FA_SECRET = 'JBSWY3DPEHPK3PXP';

    private static bool $b08SchemaEnsured = false;

    protected function setUp(): void
    {
        parent::setUp(); // 含 DbTestCase::ensureTestTables + DB 可用性检查

        self::ensureB08Schema();

        // CLI 下 Request::session 属性为 null：全局注入 Session（app('request') 为单例，原地生效）
        app()->request->withSession(app('session'));

        // 测试加密密钥（运行时内存级注入；SecurityKeyResolver 每次实时读 config，Guard 实例级缓存）
        if (trim((string)Config::get('security.data_encryption_key', '')) === '') {
            Config::set(['data_encryption_key' => self::TEST_ENCRYPTION_KEY], 'security');
        }

        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }

    /**
     * 确保 B08 测试所需的表/列存在（仅限 hcz_test 测试库，事务外调用）。
     */
    private static function ensureB08Schema(): void
    {
        if (self::$b08SchemaEnsured) {
            return;
        }

        // cz_admin（生产 schema：Admin 模型 json=['power_street']；2FA/密码字段见 Guard）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_admin` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `account` varchar(64) NOT NULL DEFAULT '',
            `name` varchar(64) NOT NULL DEFAULT '',
            `password` varchar(255) NOT NULL DEFAULT '',
            `salt` varchar(16) NOT NULL DEFAULT '',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `power` text,
            `power_street` text,
            `twofa_enabled` tinyint(1) NOT NULL DEFAULT '0',
            `twofa_secret` text,
            `twofa_recovery_codes` text,
            `login_ip` varchar(64) NOT NULL DEFAULT '',
            `login_time` datetime DEFAULT NULL,
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // cz_substation（生产 schema：Substation 模型 + rights 创建字段 + SubstationSettlementService 字段）
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_substation` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '0',
            `wallet_balance` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `wallet_total_income` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `wallet_total_transferred` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `income_balance` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `income_total` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `settled_income_total` decimal(18,4) NOT NULL DEFAULT '0.0000',
            `open_time` datetime DEFAULT NULL,
            `reject_reason` varchar(255) DEFAULT NULL,
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // cz_user 缺列补齐（Agent 域 / 2FA 域）
        $cols = array_column(Db::query('SHOW COLUMNS FROM cz_user'), 'Field');
        if (!in_array('agent_wallet', $cols, true)) {
            Db::execute("ALTER TABLE `cz_user` ADD COLUMN `agent_wallet` decimal(18,4) NOT NULL DEFAULT '0.0000'");
        }
        if (!in_array('twofa_enabled', $cols, true)) {
            Db::execute("ALTER TABLE `cz_user` ADD COLUMN `twofa_enabled` tinyint(1) NOT NULL DEFAULT '0'");
        }
        if (!in_array('agent_status', $cols, true)) {
            Db::execute("ALTER TABLE `cz_user` ADD COLUMN `agent_status` tinyint(1) NOT NULL DEFAULT '0'");
        }

        // cz_user.twofa_secret / twofa_recovery_codes：生产为可空（OLD 解绑 2FA 时置 null），测试库若 NOT NULL 则对齐
        $colsInfo = Db::query('SHOW COLUMNS FROM cz_user');
        foreach ($colsInfo as $ci) {
            if ($ci['Field'] === 'twofa_secret' && ($ci['Null'] ?? 'YES') === 'NO') {
                Db::execute("ALTER TABLE `cz_user` MODIFY COLUMN `twofa_secret` text NULL");
            }
            if ($ci['Field'] === 'twofa_recovery_codes' && ($ci['Null'] ?? 'YES') === 'NO') {
                Db::execute("ALTER TABLE `cz_user` MODIFY COLUMN `twofa_recovery_codes` text NULL");
            }
        }

        self::$b08SchemaEnsured = true;
    }

    // ==================== Helpers ====================

    /**
     * 反射创建 admin\User 实例（绕过 CLI 构造器 session 问题），注入独立 Request（含 session/post/pathinfo）。
     */
    private function makeUserController(array $post, string $path): AdminUserController
    {
        $ref = new \ReflectionClass(AdminUserController::class);
        $ctrl = $ref->newInstanceWithoutConstructor();

        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());

        // 独立 Request：避免污染 app()->request 的 post/path（Guard 走 app()->request 的 session，二者分离）
        $request = new \think\Request();
        $request->withSession(app('session'))->withPost($post);
        $pathProp = new \ReflectionProperty(\think\Request::class, 'pathinfo');
        $pathProp->setAccessible(true);
        $pathProp->setValue($request, trim($path, '/'));

        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, $request);

        return $ctrl;
    }

    /**
     * 插入 cz_admin 行（供 Guard sensitive verification）。返回含 password/salt 的完整数据。
     */
    private function seedAdmin(int $adminId, array $overrides = []): array
    {
        $data = array_merge([
            'id' => $adminId,
            'account' => 'test_admin_' . $adminId,
            'name' => '测试管理员',
            'password' => password_hash('adminpass123' . 'testsalt', PASSWORD_BCRYPT),
            'salt' => 'testsalt',
            'status' => 1,
            'twofa_enabled' => 0,
            'twofa_secret' => null,
            'twofa_recovery_codes' => null,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ], $overrides);

        Db::name('admin')->insert($data);
        return $data;
    }

    /** 写入 Session admin 身份 */
    private function sessionLogin(int $adminId): void
    {
        Session::set('admin', [
            'id' => $adminId,
            'account' => 'test_admin_' . $adminId,
            'name' => '测试管理员',
        ]);
    }

    /** 写入 CSRF token */
    private function sessionCsrf(string $token = 'b08_csrf_token'): void
    {
        Session::set('_csrf_token', $token);
    }

    /** 解析 ThinkPHP show() JSON 响应 */
    private function parseResponse($response): array
    {
        $content = method_exists($response, 'getContent') ? (string)$response->getContent() : json_encode($response);
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['code' => null, 'message' => $content];
    }

    /**
     * 完整调用链：session 登录 + CSRF + 构造控制器 + 调用 action。
     *
     * @param string|null $csrf null = 不携带 token（CSRF 失败路径）
     */
    private function callAction(int $adminId, string $action, array $post, string $path, ?string $csrf = 'b08_csrf_token'): array
    {
        // R1.6d-b.1: 每次模拟请求前清除 Session Store 缓存，强制创建全新 Store
        // （含新 session ID / 新 session file），确保同一测试中多次切换管理员身份时
        // 不会读取前一次 callAction 的 session 状态。
        Session::forgetDriver();
        $this->sessionLogin($adminId);
        if ($csrf !== null) {
            $this->sessionCsrf($csrf);
            $post['_csrf_token'] = $csrf;
        } else {
            $this->sessionCsrf('b08_csrf_token'); // session 有 token，但请求不携带
        }
        $ctrl = $this->makeUserController($post, $path);
        return $this->parseResponse($ctrl->user_post($action));
    }

    /** 各 action 最小参数集（用于 RBAC/CSRF 象限） */
    private function postFor(string $action): array
    {
        switch ($action) {
            case 'password':
                return ['uid' => 1, 'password' => 'x'];
            case 'status_switch':
                return ['uid' => 1];
            case 'twofa_unbind':
                return ['uid' => 1];
            case 'rights':
                return ['uid' => 1, 'rights_action' => 'vip_open'];
            case 'del':
                return ['id' => 1];
            case 'dels':
                return ['ids' => [1]];
        }
        return [];
    }

    /** 生成当前 TOTP 验证码 */
    private function totp(string $secret): string
    {
        $twofa = new \RobThree\Auth\TwoFactorAuth();
        return $twofa->getCode($secret);
    }

    /** 用 Guard 密钥加密明文 2FA secret（与生产 encryptAdminData 相同算法） */
    private function encryptSecret(string $plain): string
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $key = $guard->requireEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    // ==================== A. 新控制器基础结构 ====================

    public function testNewControllerHasAdminAuthMiddleware(): void
    {
        $ref = new \ReflectionClass(AdminUserController::class);
        $prop = $ref->getProperty('middleware');
        $prop->setAccessible(true);
        $middleware = $prop->getValue($ref->newInstanceWithoutConstructor());

        $this->assertSame([\app\middleware\AdminAuth::class], $middleware, 'admin\User 必须显式声明 AdminAuth 中间件（缺失 = P0）');
    }

    public function testNewControllerNoBalanceCase(): void
    {
        // balance 不在 6 case 内 → switch 命中 default，无鉴权直接拒绝
        $res = $this->callAction(0, 'balance', [], 'admin/user/balance');
        $this->assertSame('你不对劲', $res['message'] ?? '', 'admin\User 不得提供 balance handler（Frozen）');
    }

    // ==================== B. RBAC 四象限（逐 action） ====================

    public function testRbacQuadrantPerAction(): void
    {
        foreach (self::RBAC_MAP as $action => $perm) {
            $post = $this->postFor($action);
            $path = 'user_post/' . $action;

            // M1: 仅目标权限 → 通过 authorize（响应非'权限不足'）
            $admin1 = TestDataFactory::createAdminWithPermissions([$perm]);
            $res1 = $this->callAction($admin1['admin_id'], $action, $post, $path);
            $this->assertNotSame('权限不足', $res1['message'] ?? '', "[$action] M1 应通过 authorize");

            // M2: 其他 user 权限（view）→ 权限不足
            $admin2 = TestDataFactory::createAdminWithPermissions(['admin.user.view']);
            $res2 = $this->callAction($admin2['admin_id'], $action, $post, $path);
            $this->assertSame('权限不足', $res2['message'] ?? '', "[$action] M2 仅 view 权限应被拒绝");

            // M3: 全部相关 user 权限 → 通过
            $admin3 = TestDataFactory::createAdminWithPermissions([
                'admin.user.view', 'admin.user.manage', 'admin.user.delete', 'admin.user.rights', 'admin.user.password.reset',
            ]);
            $res3 = $this->callAction($admin3['admin_id'], $action, $post, $path);
            $this->assertNotSame('权限不足', $res3['message'] ?? '', "[$action] M3 应通过 authorize");

            // M4: 无权限 → 权限不足
            $admin4 = TestDataFactory::createAdminWithPermissions([]);
            $res4 = $this->callAction($admin4['admin_id'], $action, $post, $path);
            $this->assertSame('权限不足', $res4['message'] ?? '', "[$action] M4 无权限应被拒绝");
        }
    }

    /** 权限码精确锁定：不允许用 admin.user.view 顶替（防 B06 式权限语义变化） */
    public function testRbacPermissionCodesLocked(): void
    {
        $expected = self::RBAC_MAP;
        $this->assertSame('admin.user.password.reset', $expected['password']);
        $this->assertSame('admin.user.manage', $expected['status_switch']);
        $this->assertSame('admin.user.manage', $expected['twofa_unbind']);
        $this->assertSame('admin.user.rights', $expected['rights']);
        $this->assertSame('admin.user.delete', $expected['del']);
        $this->assertSame('admin.user.delete', $expected['dels']);
    }

    // ==================== C. CSRF（逐 action 专属文案） ====================

    public function testCsrfFailurePerAction(): void
    {
        $csrfMsgs = [
            'password' => '密码重置请求校验失败',
            'status_switch' => '状态切换请求校验失败',
            'twofa_unbind' => '解绑请求校验失败',
            'rights' => '权益请求校验失败',
            'del' => '删除请求校验失败',
            'dels' => '删除请求校验失败',
        ];
        foreach ($csrfMsgs as $action => $msg) {
            $admin = TestDataFactory::createAdminWithPermissions([self::RBAC_MAP[$action]]);
            $res = $this->callAction($admin['admin_id'], $action, $this->postFor($action), 'user_post/' . $action, null);
            $this->assertSame(403, $res['code'] ?? null, "[$action] CSRF 失败应 403");
            $this->assertSame($msg, $res['message'] ?? '', "[$action] CSRF 失败文案");
        }
    }

    // ==================== D. password ====================

    public function testPasswordResetSuccessAndLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser();

        $res = $this->callAction($admin['admin_id'], 'password', [
            'uid' => $user->id,
            'password' => 'newsecret456',
            'admin_password' => 'adminpass123',
        ], 'user_post/password');

        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('修改成功', $res['message'] ?? '');

        // DB：密码已更新（salt 已变 + bcrypt 匹配新密码）
        $row = Db::name('user')->where('id', $user->id)->find();
        $this->assertTrue(password_verify('newsecret456' . $row['salt'], $row['password']), '新密码应以 新密码+salt bcrypt 存储');

        // 日志
        $this->assertSame(1, TestDataFactory::countAdminOperationLog($admin['admin_id'], '重置用户密码'));
        $log = Db::name('admin_operation_log')->where('admin_id', $admin['admin_id'])->where('action', '重置用户密码')->find();
        $this->assertSame('user', $log['target_type'] ?? null);
        $this->assertSame((string)$user->id, (string)($log['target_id'] ?? ''));
    }

    public function testPasswordWrongAdminPassword(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser();

        $res = $this->callAction($admin['admin_id'], 'password', [
            'uid' => $user->id,
            'password' => 'newsecret456',
            'admin_password' => 'wrongpass',
        ], 'user_post/password');

        $this->assertSame(403, $res['code'] ?? null);
        $this->assertSame('当前管理员密码错误', $res['message'] ?? '');
        // 密码未被修改（createUser 原始 hash 为 password_hash('test1234')，无 salt 拼接）
        $this->assertTrue(password_verify('test1234', Db::name('user')->where('id', $user->id)->value('password')), 'sensitive 失败时密码不得变更');
    }

    public function testPasswordMissingAdminPassword(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id']);

        $res = $this->callAction($admin['admin_id'], 'password', ['uid' => 1, 'password' => 'x'], 'user_post/password');
        $this->assertSame(403, $res['code'] ?? null);
        $this->assertSame('请输入当前管理员密码', $res['message'] ?? '');
    }

    public function testPasswordSensitiveWithTwofaAdmin(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id'], [
            'twofa_enabled' => 1,
            'twofa_secret' => $this->encryptSecret(self::TEST_2FA_SECRET),
        ]);
        $user = TestDataFactory::createUser();

        // 正确 2FA code → 修改成功（2FA 模式等价）
        $res = $this->callAction($admin['admin_id'], 'password', [
            'uid' => $user->id,
            'password' => 'newsecret456',
            'twofa_code' => $this->totp(self::TEST_2FA_SECRET),
        ], 'user_post/password');
        $this->assertSame(200, $res['code'] ?? null, '2FA 模式应通过 sensitive verification');

        // 错误 2FA code → 拒绝
        $res2 = $this->callAction($admin['admin_id'], 'password', [
            'uid' => $user->id,
            'password' => 'newsecret789',
            'twofa_code' => '000000',
        ], 'user_post/password');
        $this->assertSame(403, $res2['code'] ?? null);
        $this->assertSame('二步验证码不正确', $res2['message'] ?? '');
    }

    public function testPasswordInvalidUidAndEmptyPassword(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id']);

        $res = $this->callAction($admin['admin_id'], 'password', ['uid' => 0, 'password' => 'x', 'admin_password' => 'adminpass123'], 'user_post/password');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('用户参数错误', $res['message'] ?? '');

        $res2 = $this->callAction($admin['admin_id'], 'password', ['uid' => 1, 'password' => '', 'admin_password' => 'adminpass123'], 'user_post/password');
        $this->assertSame(500, $res2['code'] ?? null);
        $this->assertSame('新密码不能为空', $res2['message'] ?? '');
    }

    public function testPasswordUserNotFound(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.password.reset']);
        $this->seedAdmin($admin['admin_id']);

        $res = $this->callAction($admin['admin_id'], 'password', ['uid' => 99999999, 'password' => 'x', 'admin_password' => 'adminpass123'], 'user_post/password');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('用户不存在', $res['message'] ?? '');
    }

    // ==================== E. status_switch ====================

    public function testStatusSwitchToggleAndLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $user = TestDataFactory::createUser(['status' => 1]);

        // 1 → 0
        $res = $this->callAction($admin['admin_id'], 'status_switch', ['uid' => $user->id], 'user_post/status_switch');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('状态更新成功', $res['message'] ?? '');
        $this->assertSame(0, (int)Db::name('user')->where('id', $user->id)->value('status'), '1→0 翻转');

        // 0 → 1
        $res2 = $this->callAction($admin['admin_id'], 'status_switch', ['uid' => $user->id], 'user_post/status_switch');
        $this->assertSame(200, $res2['code'] ?? null);
        $this->assertSame(1, (int)Db::name('user')->where('id', $user->id)->value('status'), '0→1 翻转');

        // 日志（2 次）
        $this->assertSame(2, TestDataFactory::countAdminOperationLog($admin['admin_id'], '切换用户状态'));
    }

    public function testStatusSwitchNoSensitiveRequired(): void
    {
        // OLD 无 sensitive verification：不带 admin_password 也能切换（不新增安全步骤）
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $user = TestDataFactory::createUser(['status' => 1]);

        $res = $this->callAction($admin['admin_id'], 'status_switch', ['uid' => $user->id], 'user_post/status_switch');
        $this->assertSame(200, $res['code'] ?? null, 'status_switch 不应要求 sensitive verification（OLD 无）');
    }

    public function testStatusSwitchUserNotFound(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);

        $res = $this->callAction($admin['admin_id'], 'status_switch', ['uid' => 99999999], 'user_post/status_switch');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('用户不存在', $res['message'] ?? '');
    }

    // ==================== F. twofa_unbind ====================

    public function testTwofaUnbindOldAndNewPathAndLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser([
            'twofa_enabled' => 1,
            'twofa_secret' => 'encrypted_secret_placeholder',
            'twofa_recovery_codes' => 'code1,code2',
        ]);
        $post = ['uid' => $user->id, 'admin_password' => 'adminpass123'];

        // OLD path
        $resOld = $this->callAction($admin['admin_id'], 'twofa_unbind', $post, 'user_post/twofa_unbind');
        $this->assertSame(200, $resOld['code'] ?? null, 'OLD path 应通过');
        $this->assertSame('用户2FA解绑成功', $resOld['message'] ?? '');

        // 3 字段被清空
        $row = Db::name('user')->where('id', $user->id)->find();
        $this->assertSame(0, (int)$row['twofa_enabled']);
        $this->assertEmpty($row['twofa_secret']);
        $this->assertEmpty($row['twofa_recovery_codes']);

        // NEW path（重建 2FA 后再走新路径）
        Db::name('user')->where('id', $user->id)->update([
            'twofa_enabled' => 1, 'twofa_secret' => 's2', 'twofa_recovery_codes' => 'c3',
        ]);
        $resNew = $this->callAction($admin['admin_id'], 'twofa_unbind', $post, 'admin/user/twofa_unbind');
        $this->assertSame(200, $resNew['code'] ?? null, 'NEW path 应通过');

        // 日志
        $this->assertSame(2, TestDataFactory::countAdminOperationLog($admin['admin_id'], '解绑用户2FA'));
    }

    public function testTwofaUnbindWrongPathRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(['twofa_enabled' => 1, 'twofa_secret' => 's', 'twofa_recovery_codes' => 'c']);
        $post = ['uid' => $user->id, 'admin_password' => 'adminpass123'];

        // 任意无关 path → 请求路径错误
        $res = $this->callAction($admin['admin_id'], 'twofa_unbind', $post, 'admin/user/rights');
        $this->assertSame(403, $res['code'] ?? null);
        $this->assertSame('请求路径错误', $res['message'] ?? '');
        // 2FA 未被清空
        $this->assertSame(1, (int)Db::name('user')->where('id', $user->id)->value('twofa_enabled'));
    }

    public function testTwofaUnbindUserNoTwofa(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(); // 三字段全空

        $res = $this->callAction($admin['admin_id'], 'twofa_unbind', ['uid' => $user->id, 'admin_password' => 'adminpass123'], 'user_post/twofa_unbind');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('该用户未启用2FA', $res['message'] ?? '');
    }

    public function testTwofaUnbindSensitiveFail(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.manage']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(['twofa_enabled' => 1, 'twofa_secret' => 's', 'twofa_recovery_codes' => 'c']);

        $res = $this->callAction($admin['admin_id'], 'twofa_unbind', ['uid' => $user->id, 'admin_password' => 'wrong'], 'user_post/twofa_unbind');
        $this->assertSame(403, $res['code'] ?? null);
        $this->assertSame('当前管理员密码错误', $res['message'] ?? '');
        $this->assertSame(1, (int)Db::name('user')->where('id', $user->id)->value('twofa_enabled'), 'sensitive 失败不得清空 2FA');
    }

    // ==================== G. rights ====================

    public function testRightsVipOpenCloseNoLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.rights']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(['agent_status' => 0]);

        // vip_open
        $res = $this->callAction($admin['admin_id'], 'rights', ['uid' => $user->id, 'rights_action' => 'vip_open', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('VIP 已开通', $res['message'] ?? '');
        $this->assertSame(1, (int)Db::name('user')->where('id', $user->id)->value('agent_status'));

        // vip_close
        $res2 = $this->callAction($admin['admin_id'], 'rights', ['uid' => $user->id, 'rights_action' => 'vip_close', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(200, $res2['code'] ?? null);
        $this->assertSame('VIP 已关闭', $res2['message'] ?? '');
        $this->assertSame(0, (int)Db::name('user')->where('id', $user->id)->value('agent_status'));

        // rights 无操作日志（OLD 事实保持）
        $this->assertSame(0, TestDataFactory::countAdminOperationLog($admin['admin_id']), 'rights 不得新增操作日志');
    }

    public function testRightsSvipOpenCreatesSubstationWithZeroWallet(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.rights']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(['agent_status' => 0]);

        $res = $this->callAction($admin['admin_id'], 'rights', ['uid' => $user->id, 'rights_action' => 'svip_open', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('SVIP 已开通（已同步开通 VIP）', $res['message'] ?? '');

        // Substation 创建且钱包字段全 0（无资金副作用）
        $sub = Db::name('substation')->where('uid', $user->id)->find();
        $this->assertNotNull($sub, 'svip_open 应创建 Substation');
        $this->assertSame(2, (int)$sub['status']);
        $this->assertEquals(0, (float)$sub['wallet_balance']);
        $this->assertEquals(0, (float)$sub['wallet_total_income']);
        $this->assertEquals(0, (float)$sub['wallet_total_transferred']);
        $this->assertEquals(0, (float)$sub['income_balance']);
        $this->assertNotEmpty($sub['open_time'], 'open_time 应填充');
        $this->assertNull($sub['reject_reason'], 'reject_reason 应为 null');

        // 业务规则：SVIP 包含 VIP
        $this->assertSame(1, (int)Db::name('user')->where('id', $user->id)->value('agent_status'));

        // 金融隔离：无 Ledger 变化
        $this->assertSame(0, TestDataFactory::countLedger($user->id), 'rights 不得产生资金账本变化');
    }

    public function testRightsSvipOpenExistingSubstationAndClose(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.rights']);
        $this->seedAdmin($admin['admin_id']);
        $user = TestDataFactory::createUser(['agent_status' => 0]);
        // 预置 Substation（已关闭状态）
        Db::name('substation')->insert([
            'uid' => $user->id, 'status' => 0,
            'wallet_balance' => 0, 'wallet_total_income' => 0, 'wallet_total_transferred' => 0, 'income_balance' => 0,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $subId = (int)Db::name('substation')->where('uid', $user->id)->value('id');
        $this->assertGreaterThan(0, $subId);

        // svip_open（已有 substation → 不新建，status 0→2）
        $res = $this->callAction($admin['admin_id'], 'rights', ['uid' => $user->id, 'rights_action' => 'svip_open', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame(2, (int)Db::name('substation')->where('uid', $user->id)->value('status'), '已有 Substation 更新 status=2');
        $this->assertSame($subId, (int)Db::name('substation')->where('uid', $user->id)->value('id'), '不得重复创建 Substation');

        // svip_close
        $res2 = $this->callAction($admin['admin_id'], 'rights', ['uid' => $user->id, 'rights_action' => 'svip_close', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(200, $res2['code'] ?? null);
        $this->assertSame('SVIP 已关闭', $res2['message'] ?? '');
        $this->assertSame(0, (int)Db::name('substation')->where('uid', $user->id)->value('status'));
    }

    public function testRightsInvalidActionAndWrongPath(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.rights']);
        $this->seedAdmin($admin['admin_id']);

        // 非法 rights_action
        $res = $this->callAction($admin['admin_id'], 'rights', ['uid' => 1, 'rights_action' => 'hack_action', 'admin_password' => 'adminpass123'], 'user_post/rights');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('权益操作类型错误', $res['message'] ?? '');

        // 无关 path
        $res2 = $this->callAction($admin['admin_id'], 'rights', ['uid' => 1, 'rights_action' => 'vip_open', 'admin_password' => 'adminpass123'], 'admin/user/twofa_unbind');
        $this->assertSame(403, $res2['code'] ?? null);
        $this->assertSame('请求路径错误', $res2['message'] ?? '');
    }

    public function testRightsSensitiveFail(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.rights']);
        $this->seedAdmin($admin['admin_id']);

        $res = $this->callAction($admin['admin_id'], 'rights', ['uid' => 1, 'rights_action' => 'vip_open', 'admin_password' => 'wrong'], 'user_post/rights');
        $this->assertSame(403, $res['code'] ?? null);
        $this->assertSame('当前管理员密码错误', $res['message'] ?? '');
    }

    // ==================== H. del ====================

    public function testDelPendingRechargeRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        TestDataFactory::createRecharge(['uid' => $user->id, 'status' => 0]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('存在待处理充值订单（1笔），无法删除', $res['message'] ?? '');
        $this->assertNotNull(Db::name('user')->where('id', $user->id)->find(), 'pending 时不得删除');
    }

    public function testDelPendingWithdrawalRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('withdrawal')->insert([
            'uid' => $user->id, 'amount' => 50, 'wallet_address' => 'addr', 'withdrawal_fee' => 0,
            'order_number' => TestDataFactory::tag('WD'), 'status' => 0,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('存在待审核提现订单（1笔），无法删除', $res['message'] ?? '');
    }

    public function testDelPendingC2CBuyOrderRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('transaction_order')->insert([
            'uid' => $user->id, 'sell_uid' => 0, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'),
            'pay_amount' => 100, 'payment_amount' => 100, 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('存在进行中的C2C买入订单（1笔），无法删除', $res['message'] ?? '');
    }

    public function testDelPendingC2CSellOrderRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('transaction_order')->insert([
            'uid' => 0, 'sell_uid' => $user->id, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'),
            'pay_amount' => 100, 'payment_amount' => 100, 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('存在进行中的C2C卖出订单（1笔），无法删除', $res['message'] ?? '');
    }

    public function testDelPendingProductRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('transaction_product')->insert([
            'uid' => $user->id, 'sell_account' => 500, 'unit_price' => 7, 'min_limit' => 100, 'max_limit' => 1000, 'status' => 2,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('存在进行中的交易挂单（1个），无法删除', $res['message'] ?? '');
    }

    public function testDelUserWalletBalanceRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser(['balance' => 100]);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('用户钱包存在余额', $res['message'] ?? '');
        $this->assertStringContainsString('无法删除', $res['message'] ?? '');
    }

    public function testDelNormalDeleteAndLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('删除成功', $res['message'] ?? '');
        $this->assertNull(Db::name('user')->where('id', $user->id)->find(), '用户应被删除');
        $this->assertSame(1, TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除用户'));
    }

    public function testDelUserNotFoundAndInvalidId(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);

        $res = $this->callAction($admin['admin_id'], 'del', ['id' => 99999999], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('用户不存在', $res['message'] ?? '');

        $res2 = $this->callAction($admin['admin_id'], 'del', ['id' => 0], 'user_post/del');
        $this->assertSame(500, $res2['code'] ?? null);
        $this->assertSame('用户参数错误', $res2['message'] ?? '');
    }

    // ==================== I. dels ====================

    public function testDelsEmptyAndInvalidIds(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);

        $res = $this->callAction($admin['admin_id'], 'dels', ['ids' => []], 'user_post/dels');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertSame('请选择要删除的用户', $res['message'] ?? '');

        $res2 = $this->callAction($admin['admin_id'], 'dels', ['ids' => [0, -1]], 'user_post/dels');
        $this->assertSame(500, $res2['code'] ?? null);
        $this->assertSame('用户参数错误', $res2['message'] ?? '');
    }

    public function testDelsOnePendingRejectsAllBeforeDelete(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $userA = TestDataFactory::createUser();
        $userB = TestDataFactory::createUser();
        TestDataFactory::createRecharge(['uid' => $userA->id, 'status' => 0]); // A 有 pending

        $res = $this->callAction($admin['admin_id'], 'dels', ['ids' => [$userA->id, $userB->id]], 'user_post/dels');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('以下用户无法删除', $res['message'] ?? '');
        $this->assertStringContainsString('UID#' . $userA->id, $res['message'] ?? '');

        // 先全查后删：B 未删除（无 pending 也不被删）
        $this->assertNotNull(Db::name('user')->where('id', $userB->id)->find(), '整批拒绝时不得执行任何删除');
        $this->assertNotNull(Db::name('user')->where('id', $userA->id)->find());
        $this->assertSame(0, TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除用户'), '拒绝时无日志');
    }

    public function testDelsAllValidDeleteCountAndPerRowLog(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $userA = TestDataFactory::createUser();
        $userB = TestDataFactory::createUser();
        $userC = TestDataFactory::createUser();

        $res = $this->callAction($admin['admin_id'], 'dels', ['ids' => [$userA->id, $userB->id, 99999999]], 'user_post/dels');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('删除成功（2个）', $res['message'] ?? '', '不存在的 ID 不计入删除数');

        $this->assertNull(Db::name('user')->where('id', $userA->id)->find());
        $this->assertNull(Db::name('user')->where('id', $userB->id)->find());
        // 逐条日志（仅实际删除的 2 条）
        $this->assertSame(2, TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除用户'));
    }

    // ==================== J. balance 隔离（Frozen） ====================

    public function testOldUserPostBalanceStillFrozenInAdminApi(): void
    {
        // 静态验证：AdminApi::user_post 保留 balance → handleBalance 直连（Frozen 未迁移）
        $src = file_get_contents(app_path() . 'controller/AdminApi.php');
        $this->assertStringContainsString("case 'balance'", $src, 'AdminApi::user_post 必须保留 balance case');
        $this->assertStringContainsString('handleBalance', $src, 'balance 必须继续调用 handleBalance');
        // balance 相关金融 helper 仍在 AdminApi
        $this->assertStringContainsString('directAdminAdjustBalanceWithLedger', $src, 'Frozen 金融 helper 不得移除');
        $this->assertStringContainsString('UserFundLedgerService', $src, 'Frozen Ledger 服务不得移除');
    }

    public function testUserPostBalanceForwarderToHandleBalance(): void
    {
        // 运行时验证：user_post/balance 继续进入 AdminApi::handleBalance（Frozen 直连），不落入 default
        $this->sessionLogin(940000 + random_int(1, 99999));
        $this->sessionCsrf('b08_csrf_token');

        $ref = new \ReflectionClass(AdminApi::class);
        $ctrl = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('app'); $p->setAccessible(true); $p->setValue($ctrl, app());
        $req = new \think\Request();
        $req->withSession(app('session'))->withPost([]); // 无 add_minus → handleBalance 首步权限分支
        $pathProp = new \ReflectionProperty(\think\Request::class, 'pathinfo'); $pathProp->setAccessible(true);
        $pathProp->setValue($req, 'user_post/balance');
        $p = $ref->getProperty('request'); $p->setAccessible(true); $p->setValue($ctrl, $req);

        $resp = $ctrl->user_post('balance');
        $data = json_decode((string)$resp->getContent(), true);
        $this->assertNotSame('你不对劲', $data['message'] ?? '', 'user_post/balance 不得落入 default');
        $this->assertSame(403, $data['code'] ?? null, 'handleBalance 首步权限分支应返回 403 权限不足（未给 add_minus）');
    }

    // ==================== K. AdminSensitiveOperationGuard 原语等价 ====================

    public function testGuardValidateTwofaCodeInput(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $this->assertSame('请输入二步验证码', $guard->validateAdminTwofaCodeInput(''));
        $this->assertSame('请输入6位二步验证码', $guard->validateAdminTwofaCodeInput('12345'));
        $this->assertSame('请输入6位二步验证码', $guard->validateAdminTwofaCodeInput('abcdef'));
        $this->assertNull($guard->validateAdminTwofaCodeInput('123456'));
    }

    public function testGuardTwofaNotConfigured(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        $this->seedAdmin($adminId, ['twofa_enabled' => 0, 'twofa_secret' => null]);
        $admin = AdminModel::find($adminId);

        $res = $guard->verifyAdminTwofaCode($admin, '123456');
        $this->assertFalse($res['ok']);
        $this->assertSame('当前管理员未正确配置2FA', $res['message'] ?? '');
    }

    public function testGuardTwofaCorrectCodeMode(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        $this->seedAdmin($adminId, [
            'twofa_enabled' => 1,
            'twofa_secret' => $this->encryptSecret(self::TEST_2FA_SECRET),
        ]);
        $admin = AdminModel::find($adminId);

        $res = $guard->verifyAdminTwofaCode($admin, $this->totp(self::TEST_2FA_SECRET));
        $this->assertTrue($res['ok']);
        $this->assertSame('twofa', $res['mode'] ?? '');
    }

    public function testGuardTwofaWrongCode(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        $this->seedAdmin($adminId, [
            'twofa_enabled' => 1,
            'twofa_secret' => $this->encryptSecret(self::TEST_2FA_SECRET),
        ]);
        $admin = AdminModel::find($adminId);

        $res = $guard->verifyAdminTwofaCode($admin, '000000');
        $this->assertFalse($res['ok']);
        $this->assertSame('二步验证码不正确', $res['message'] ?? '');
    }

    public function testGuardTwofaSecretUndecryptable(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        // twofa_secret 为非法加密串：base64 解码后 < iv 长度 → '敏感数据格式无效' 抛异常 → catch 返回通用文案
        $this->seedAdmin($adminId, [
            'twofa_enabled' => 1,
            'twofa_secret' => 'not-base64!!!',
        ]);
        $admin = AdminModel::find($adminId);

        $res = $guard->verifyAdminTwofaCode($admin, $this->totp(self::TEST_2FA_SECRET));
        $this->assertFalse($res['ok']);
        $this->assertSame('2FA验证失败，请稍后重试', $res['message'] ?? '');
    }

    public function testGuardTwofaSecretEmpty(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        // 解密结果为空 → '2FA密钥异常，请重新绑定'
        $emptyEnc = $this->encryptSecret('');
        $this->seedAdmin($adminId, [
            'twofa_enabled' => 1,
            'twofa_secret' => $emptyEnc,
        ]);
        $admin = AdminModel::find($adminId);

        $res = $guard->verifyAdminTwofaCode($admin, $this->totp(self::TEST_2FA_SECRET));
        $this->assertFalse($res['ok']);
        $this->assertSame('2FA密钥异常，请重新绑定', $res['message'] ?? '');
    }

    public function testGuardDecryptInvalidFormat(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        try {
            $guard->decryptAdminData('!!!not-base64!!!');
            $this->fail('非法 base64 应抛异常');
        } catch (\Exception $e) {
            $this->assertSame('敏感数据格式无效', $e->getMessage());
        }
        try {
            $guard->decryptAdminData(base64_encode('short')); // 解码后 < iv 长度
            $this->fail('短密文应抛异常');
        } catch (\Exception $e) {
            $this->assertSame('敏感数据格式无效', $e->getMessage());
        }
    }

    public function testGuardPasswordModeEquivalence(): void
    {
        $guard = app(AdminSensitiveOperationGuard::class);
        $adminId = 950000 + random_int(1, 99999);
        $this->seedAdmin($adminId); // twofa_enabled=0, salt='testsalt', password=hash('adminpass123'.'testsalt')

        // 通过 Guard 直接验证（password 模式）
        Session::set('admin', ['id' => $adminId, 'account' => 'a']);
        $res = $guard->verifySensitiveOperation(['admin_password' => 'adminpass123'], 'test_scene');
        $this->assertTrue($res['ok']);
        $this->assertSame('password', $res['mode'] ?? '');

        $res2 = $guard->verifySensitiveOperation(['admin_password' => 'wrong'], 'test_scene');
        $this->assertFalse($res2['ok']);
        $this->assertSame('当前管理员密码错误', $res2['message'] ?? '');
    }

    public function testGuardIdentityCodeEnforcedNoExternalAdminId(): void
    {
        // 签名验证：verifySensitiveOperation 不接受 admin_id/AdminModel 参数（身份由 Session 代码强制）
        $ref = new \ReflectionClass(AdminSensitiveOperationGuard::class);
        $method = $ref->getMethod('verifySensitiveOperation');
        $params = $method->getParameters();
        $this->assertCount(2, $params, 'verifySensitiveOperation 只接受 postInfo + scene');
        $this->assertSame('postInfo', $params[0]->getName());
        $this->assertSame('scene', $params[1]->getName());

        // Guard 不依赖 AdminApi（use 无 app\controller）
        $src = file_get_contents(app_path() . 'service/AdminSensitiveOperationGuard.php');
        $this->assertStringNotContainsString('app\\controller', $src, 'Guard 不得依赖任何 Controller');

        // 未登录（session 无 admin）→ 管理员未登录
        Session::set('admin', []);
        $res = $guard = app(AdminSensitiveOperationGuard::class)->verifySensitiveOperation(['admin_password' => 'x'], 's');
        $this->assertFalse($res['ok']);
        $this->assertSame('管理员未登录', $res['message'] ?? '');
    }

    // ==================== L. assertNoPendingBusiness 六步顺序 ====================

    public function testAssertNoPendingBusinessSixStepsOrder(): void
    {
        $svc = app(UserService::class);

        // 无 pending → ok
        $clean = TestDataFactory::createUser();
        $res = $svc->assertNoPendingBusiness($clean->id);
        $this->assertTrue($res['ok']);

        // 1. Recharge status in (0,1)
        $u1 = TestDataFactory::createUser();
        TestDataFactory::createRecharge(['uid' => $u1->id, 'status' => 0]);
        $this->assertSame('存在待处理充值订单（1笔）', $svc->assertNoPendingBusiness($u1->id)['message'] ?? '');

        // 2. Withdrawal status=0
        $u2 = TestDataFactory::createUser();
        Db::name('withdrawal')->insert(['uid' => $u2->id, 'amount' => 1, 'wallet_address' => 'a', 'withdrawal_fee' => 0, 'order_number' => TestDataFactory::tag('WD'), 'status' => 0, 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
        $this->assertSame('存在待审核提现订单（1笔）', $svc->assertNoPendingBusiness($u2->id)['message'] ?? '');

        // 3. TransactionOrder buyer
        $u3 = TestDataFactory::createUser();
        Db::name('transaction_order')->insert(['uid' => $u3->id, 'sell_uid' => 0, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'), 'pay_amount' => 1, 'payment_amount' => 1, 'status' => 1, 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
        $this->assertSame('存在进行中的C2C买入订单（1笔）', $svc->assertNoPendingBusiness($u3->id)['message'] ?? '');

        // 4. TransactionOrder seller
        $u4 = TestDataFactory::createUser();
        Db::name('transaction_order')->insert(['uid' => 0, 'sell_uid' => $u4->id, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'), 'pay_amount' => 1, 'payment_amount' => 1, 'status' => 1, 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
        $this->assertSame('存在进行中的C2C卖出订单（1笔）', $svc->assertNoPendingBusiness($u4->id)['message'] ?? '');

        // 5. TransactionProduct (status in 1,2 AND sell_account>0)
        $u5 = TestDataFactory::createUser();
        Db::name('transaction_product')->insert(['uid' => $u5->id, 'sell_account' => 100, 'unit_price' => 7, 'min_limit' => 1, 'max_limit' => 1000, 'status' => 2, 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
        $this->assertSame('存在进行中的交易挂单（1个）', $svc->assertNoPendingBusiness($u5->id)['message'] ?? '');

        // 6. User balance/frozen/agent_wallet > 0.005
        $u6 = TestDataFactory::createUser(['frozen_amount' => 10]);
        $msg6 = $svc->assertNoPendingBusiness($u6->id)['message'] ?? '';
        $this->assertStringContainsString('用户钱包存在余额', $msg6);
        $this->assertStringContainsString('冻结:10', $msg6);
    }

    public function testAssertNoPendingBusinessIsReadOnly(): void
    {
        // 只读风险闸：不产生写操作（lockById 之外的任何 DB 写入）
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser(['balance' => 5]);
        $svc->assertNoPendingBusiness($user->id);
        // 用户数据未被修改
        $row = Db::name('user')->where('id', $user->id)->find();
        $this->assertEquals(5, (float)$row['balance']);
    }

    // ==================== M. OLD/NEW 静态等价与依赖方向 ====================

    public function testUserServiceDependencyDirection(): void
    {
        $src = file_get_contents(app_path() . 'service/UserService.php');
        // 检查真实依赖引用（注释中的 "AdminApi" 字样不算依赖）
        $this->assertStringNotContainsString('app\\controller\\AdminApi', $src, 'UserService 不得依赖 AdminApi');
        $this->assertStringNotContainsString('app\\controller\\', $src, 'UserService 不得依赖任何 Controller');
        $this->assertStringNotContainsString('AdminSensitiveOperationGuard', $src, 'UserService 不得依赖 Guard');
        $this->assertStringNotContainsString('AdminAuth', $src, 'UserService 不得依赖 AdminAuth');
    }

    public function testUserControllerNoReverseDependency(): void
    {
        // admin\User 不得反向调用 AdminApi（除正常 Service 依赖；注释中的字样不算依赖）
        $src = file_get_contents(app_path() . 'controller/admin/User.php');
        $this->assertStringNotContainsString('app\\controller\\AdminApi', $src, 'admin\User 不得依赖 AdminApi（无循环转发）');
        $this->assertStringNotContainsString("'balance'", $src, 'admin\User 源码不得出现 balance case');
    }

    public function testLockByIdSsoT(): void
    {
        // directLockUser 语义 = UserService::lockById = UserModel lock(true) find（SSOT，无复制）
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        $locked = $svc->lockById($user->id);
        $this->assertInstanceOf(UserModel::class, $locked);
        $this->assertSame($user->id, (int)$locked['id']);

        // 不存在的 uid → null
        $this->assertNull($svc->lockById(99999999));
    }

    // ==================== N. R1.6e: assertNoFinancialHistory 财务历史闸 ====================

    public function testAssertNoFinancialHistoryCleanUserOk(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertTrue($res['ok'], '无财务历史用户应通过');
        $this->assertSame('', $res['message'] ?? 'not-empty');
    }

    public function testAssertNoFinancialHistoryFundLogBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        Db::name('user_fund_log')->insert([
            'uid' => $user->id, 'amount' => 10, 'before_amount' => 0, 'after_amount' => 10,
            'wallet_type' => 'balance', 'direction' => 'in', 'request_no' => 'TEST:' . uniqid(),
            'biz_type' => 'test', 'biz_id' => 0, 'biz_no' => 'test', 'order_number' => '',
            'change_type' => 'test', 'operator_type' => 'system', 'operator_id' => 0,
            'status' => 'done', 'remark' => 'R1.6e test', 'create_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '有资金流水应阻止');
        $this->assertStringContainsString('资金流水', $res['message'] ?? '');
    }

    public function testAssertNoFinancialHistoryRechargeBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        TestDataFactory::createRecharge(['uid' => $user->id, 'status' => 3]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '已支付充值历史应阻止');
        $this->assertStringContainsString('充值历史', $res['message'] ?? '');
    }

    public function testAssertNoFinancialHistoryWithdrawalBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        Db::name('withdrawal')->insert([
            'uid' => $user->id, 'amount' => 50, 'wallet_address' => 'addr', 'withdrawal_fee' => 0,
            'order_number' => TestDataFactory::tag('WD'), 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '已通过提现历史应阻止');
        $this->assertStringContainsString('提现历史', $res['message'] ?? '');
    }

    public function testAssertNoFinancialHistoryOrderBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        Db::name('order')->insert([
            'uid' => $user->id, 'order_number' => TestDataFactory::tag('ORD'),
            'type' => 1, 'status' => 2, 'amount_money' => 100,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '业务订单历史应阻止');
        $this->assertStringContainsString('业务订单', $res['message'] ?? '');
    }

    public function testAssertNoFinancialHistoryTransactionOrderBuyerBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        Db::name('transaction_order')->insert([
            'uid' => $user->id, 'sell_uid' => 0, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'),
            'pay_amount' => 100, 'payment_amount' => 100, 'status' => 3,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '已完成C2C交易（买家）应阻止');
        $this->assertStringContainsString('C2C交易', $res['message'] ?? '');
    }

    public function testAssertNoFinancialHistoryTransactionOrderSellerBlocked(): void
    {
        $svc = app(UserService::class);
        $user = TestDataFactory::createUser();
        Db::name('transaction_order')->insert([
            'uid' => 0, 'sell_uid' => $user->id, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'),
            'pay_amount' => 100, 'payment_amount' => 100, 'status' => 3,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->assertNoFinancialHistory($user->id);
        $this->assertFalse($res['ok'], '已完成C2C交易（卖家）应阻止');
    }

    public function testAssertNoFinancialHistoryInvalidUid(): void
    {
        $svc = app(UserService::class);
        $res = $svc->assertNoFinancialHistory(0);
        $this->assertFalse($res['ok']);
        $this->assertSame('用户参数错误', $res['message'] ?? '');
    }

    // ==================== O. R1.6e: del/dels 财务历史集成测试 ====================

    public function testDelFundLogRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('user_fund_log')->insert([
            'uid' => $user->id, 'amount' => 10, 'before_amount' => 0, 'after_amount' => 10,
            'wallet_type' => 'balance', 'direction' => 'in', 'request_no' => 'TEST:' . uniqid(),
            'biz_type' => 'test', 'biz_id' => 0, 'biz_no' => 'test', 'order_number' => '',
            'change_type' => 'test', 'operator_type' => 'system', 'operator_id' => 0,
            'status' => 'done', 'remark' => 'R1.6e test', 'create_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('资金流水', $res['message'] ?? '');
        $this->assertStringContainsString('无法删除', $res['message'] ?? '');
        $this->assertNotNull(Db::name('user')->where('id', $user->id)->find(), '有资金流水不得删除');
    }

    public function testDelHistoricalRechargeRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        TestDataFactory::createRecharge(['uid' => $user->id, 'status' => 3]);
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('充值历史', $res['message'] ?? '');
        $this->assertNotNull(Db::name('user')->where('id', $user->id)->find());
    }

    public function testDelHistoricalWithdrawalRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('withdrawal')->insert([
            'uid' => $user->id, 'amount' => 50, 'wallet_address' => 'addr', 'withdrawal_fee' => 0,
            'order_number' => TestDataFactory::tag('WD'), 'status' => 1,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('提现历史', $res['message'] ?? '');
    }

    public function testDelOrderRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('order')->insert([
            'uid' => $user->id, 'order_number' => TestDataFactory::tag('ORD'),
            'type' => 1, 'status' => 2, 'amount_money' => 100,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('业务订单', $res['message'] ?? '');
    }

    public function testDelHistoricalTransactionOrderRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        Db::name('transaction_order')->insert([
            'uid' => $user->id, 'sell_uid' => 0, 'pid' => 0, 'order_number' => TestDataFactory::tag('C2C'),
            'pay_amount' => 100, 'payment_amount' => 100, 'status' => 3,
            'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('C2C交易', $res['message'] ?? '');
    }

    public function testDelsOneWithHistoryRejectsAll(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $userA = TestDataFactory::createUser();
        $userB = TestDataFactory::createUser();
        Db::name('user_fund_log')->insert([
            'uid' => $userA->id, 'amount' => 10, 'before_amount' => 0, 'after_amount' => 10,
            'wallet_type' => 'balance', 'direction' => 'in', 'request_no' => 'TEST:' . uniqid(),
            'biz_type' => 'test', 'biz_id' => 0, 'biz_no' => 'test', 'order_number' => '',
            'change_type' => 'test', 'operator_type' => 'system', 'operator_id' => 0,
            'status' => 'done', 'remark' => 'R1.6e test', 'create_time' => date('Y-m-d H:i:s'),
        ]);
        $res = $this->callAction($admin['admin_id'], 'dels', ['ids' => [$userA->id, $userB->id]], 'user_post/dels');
        $this->assertSame(500, $res['code'] ?? null);
        $this->assertStringContainsString('以下用户无法删除', $res['message'] ?? '');
        $this->assertNotNull(Db::name('user')->where('id', $userB->id)->find(), '整批拒绝时不得删除任何用户');
        $this->assertNotNull(Db::name('user')->where('id', $userA->id)->find());
    }

    public function testDelCleanUserStillAllowed(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.user.delete']);
        $user = TestDataFactory::createUser();
        $res = $this->callAction($admin['admin_id'], 'del', ['id' => $user->id], 'user_post/del');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame('删除成功', $res['message'] ?? '');
        $this->assertNull(Db::name('user')->where('id', $user->id)->find());
    }
}
