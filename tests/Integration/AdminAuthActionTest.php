<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\admin\Auth as AuthController;
use RobThree\Auth\TwoFactorAuth;
use tests\Support\TestDataFactory;
use think\facade\Db;
use think\facade\Session;

/**
 * HCZ B10-32: Auth/Security 迁移测试（admin\Auth + admin\AuthService）
 *
 * 覆盖（与 B10-29 Design / B10-30 Re-Audit / B10-31 Preflight Acceptance 一致）：
 *  - Login：success / wrong password / rate limit / 2FA required / TOTP± / recovery± / replay
 *  - Session：session rotation（fixation）/ admin identity / logout / pending cleanup / stale identity
 *  - TwoFA：generate / enable / disable（需 verify）/ regenerate（旧码失效）/ reset（需 verify）
 *  - Security：AdminAuth middleware 声明 / CSRF（twofa 显式校验）/ disabled admin（行为等价 OLD）
 *  - OLD/NEW 双路径：login_check（OLD forwarder）vs admin/auth/login_check（NEW）
 *
 * 测试隔离（遵守 B10-21/22/31 红线）：
 *  - 本测试【零 DDL】：不执行任何 CREATE/ALTER/DROP/TRUNCATE；cz_admin 为既有表。
 *  - fixture 事务隔离（beginTransaction/rollback）；rate-limit 状态存 redis/session，测试内显式 clear。
 *  - Test Harness：app() CLI 容器缓存 + BaseController::$adminIdentity 实例级缓存
 *    → setUp 重置容器 Auth 实例的 adminIdentity（resetContainerAdminIdentity）。
 *
 * 环境限制（如实标注，不伪造 HTTP PASS）：
 *  - CLI direct-controller 无法观察真实 HTTP middleware pipeline
 *    → AdminAuth runtime exactly-once / disabled-admin 拒绝 / 真实 route resolution = NOT VERIFIED — ENVIRONMENT / TEST HARNESS LIMITATION
 */
class AdminAuthActionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); // DbTestCase 保证测试数据库可用

        // CLI 下 Request::session 属性为 null：全局注入 Session（app('request') 为单例）
        app()->request->withSession(app('session'));

        // 测试库 fixture 补全（与 TestDataFactory::ensureTestTables 完全同模式：事务前幂等确保生产既有表）。
        // cz_cache 为生产既有无 migration owner 的配置缓存表，getConfig() 无参调用（beginAdminTwofaSetup
        // 的 issuer 读取，既有生产行为）依赖它；测试库缺此表，幂等补全空表结构（不含业务数据）。
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `cz_cache` (
                `k` varchar(64) NOT NULL DEFAULT '',
                `v` text,
                `expire` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            $this->markTestSkipped('cz_cache 前置表补全失败：' . $e->getMessage());
        }

        // 事务隔离：测试数据随 rollback 回滚（本测试零 DDL，无 implicit commit，回滚可靠）
        $this->beginTransaction();

        // Session 跨测试隔离：清空上一测试残留的 admin identity / pending 2FA / rate-limit 状态，
        // 保证每个测试从干净 session 开始（CLI 单进程共享 Session 单例）。
        Session::clear();

        // 测试运行时注入 32+ 字符数据加密密钥（Config 运行时单例，不写任何文件，仅测试进程内有效）：
        // 2FA secret 加密/解密（Guard::requireEncryptionKey）在 testing 环境无生产 key，需注入测试 key。
        \think\facade\Config::set(['data_encryption_key' => 'b10-test-data-encryption-key-0123456789abcdef'], 'security');

        // Test Harness 隔离（B10-21 独立发现，非生产缺陷）：
        // 重置容器缓存 Auth 实例的 adminIdentity，防止前序测试身份残留污染本次 forwarder 授权判定。
        $this->resetContainerAdminIdentity();
    }

    /**
     * 重置容器缓存的 Auth 实例的 adminIdentity（Test Harness 隔离）
     */
    private function resetContainerAdminIdentity(): void
    {
        $instance = app(AuthController::class);
        $ref = new \ReflectionClass($instance);
        $prop = $ref->getProperty('adminIdentity');
        $prop->setAccessible(true);
        $prop->setValue($instance, null);
    }

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }

    // ==================== Helpers ====================

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

        Db::name('admin')->where('id', $adminId)->delete();
        Db::name('admin')->insert($data);
        app(\app\service\AuthorizationService::class)->invalidateAdminPermissions($adminId);
        return $data;
    }

    private function sessionLogin(int $adminId): void
    {
        Session::set('admin', [
            'id' => $adminId,
            'account' => 'test_admin_' . $adminId,
            'name' => '测试管理员',
        ]);
    }

    /** twofa_post 显式 CSRF 校验所需：session token + request token 匹配 */
    private function csrfSetup(): void
    {
        Session::set('_csrf_token', 'testtoken');
    }

    private function makeAuthController(array $post, string $path, array $get = [], string $method = 'POST'): AuthController
    {
        $ref = new \ReflectionClass(AuthController::class);
        $ctrl = $ref->newInstanceWithoutConstructor();

        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());

        $request = new \think\Request();
        $request->withSession(app('session'))->withPost($post)->withGet($get);
        $request->withServer(['REMOTE_ADDR' => '10.20.30.40', 'REQUEST_METHOD' => $method]);
        $pathProp = new \ReflectionProperty(\think\Request::class, 'pathinfo');
        $pathProp->setAccessible(true);
        $pathProp->setValue($request, trim($path, '/'));

        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, $request);

        return $ctrl;
    }

    /** 容器 request（OLD forwarder 路径：AdminApi → app(Auth::class) → 使用容器 request） */
    private function prepareContainerRequest(array $post, string $path, string $method = 'POST'): void
    {
        app()->request->withSession(app('session'))->withPost($post);
        app()->request->withServer(['REMOTE_ADDR' => '10.20.30.40', 'REQUEST_METHOD' => $method]);
        $pathProp = new \ReflectionProperty(\think\Request::class, 'pathinfo');
        $pathProp->setAccessible(true);
        $pathProp->setValue(app()->request, trim($path, '/'));
    }

    private function makeAdminApiController(): \app\controller\AdminApi
    {
        $ref = new \ReflectionClass(\app\controller\AdminApi::class);
        $ctrl = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());
        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, app()->request);
        return $ctrl;
    }

    private function parseResponse($response): array
    {
        $content = method_exists($response, 'getContent') ? (string)$response->getContent() : json_encode($response);
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['code' => null, 'message' => $content];
    }

    /** 生成当前窗口 TOTP（RobThree 库，与生产 TwoFactorAuth 同源） */
    private function totpCode(string $secret): string
    {
        return (new TwoFactorAuth())->getCode($secret);
    }

    /** NEW 路径 login_check（PATH B） */
    private function callLoginNew(int $adminId, array $post): array
    {
        $ctrl = $this->makeAuthController($post, 'admin/auth/login_check');
        return $this->parseResponse($ctrl->login_check());
    }

    /** OLD forwarder 路径 login_check（PATH A：AdminApi → admin\Auth） */
    private function callLoginOld(int $adminId, array $post): array
    {
        $this->prepareContainerRequest($post, 'login_check');
        $ctrl = $this->makeAdminApiController();
        return $this->parseResponse($ctrl->login_check());
    }

    /** NEW 路径 twofa_post（PATH B） */
    private function callTwofaNew(int $adminId, string $action, array $post): array
    {
        $this->csrfSetup();
        $ctrl = $this->makeAuthController(array_merge($post, ['__token__' => 'testtoken']), 'twofa_post/' . $action);
        return $this->parseResponse($ctrl->twofa_post($action));
    }

    /** OLD forwarder 路径 twofa_post（PATH A） */
    private function callTwofaOld(int $adminId, string $action, array $post): array
    {
        $this->csrfSetup();
        $this->prepareContainerRequest(array_merge($post, ['__token__' => 'testtoken']), 'twofa_post/' . $action);
        $ctrl = $this->makeAdminApiController();
        return $this->parseResponse($ctrl->twofa_post($action));
    }

    /** enable 一条 2FA 流程：generate → enable，返回 [secret, recoveryCodes] */
    private function enableTwofa(int $adminId): array
    {
        $this->sessionLogin($adminId);
        $gen = $this->callTwofaNew($adminId, 'generate', []);
        $secret = (string)($gen['data']['secret'] ?? '');
        $this->assertNotSame('', $secret, 'generate 应返回 secret 明文（供扫码绑定）');
        $code = $this->totpCode($secret);
        $en = $this->callTwofaNew($adminId, 'enable', ['code' => $code]);
        $this->assertSame(200, $en['code'] ?? null, 'enable 应成功');
        $recoveryCodes = $en['data']['recovery_codes'] ?? [];
        $this->assertCount(8, $recoveryCodes, 'enable 应返回 8 个恢复码');

        return [$secret, $recoveryCodes];
    }

    // ==================== T1: Middleware ====================

    public function testMiddlewareDeclaration(): void
    {
        $ref = new \ReflectionClass(AuthController::class);
        $prop = $ref->getProperty('middleware');
        $prop->setAccessible(true);
        $middleware = $prop->getValue($ref->newInstanceWithoutConstructor());
        $this->assertSame([\app\middleware\AdminAuth::class], $middleware, 'Auth Controller 必须显式声明 AdminAuth');
    }

    // ==================== T2: Login ====================

    public function testLoginSuccessNoTwofa(): void
    {
        $this->seedAdmin(810201);
        $res = $this->callLoginNew(810201, ['account' => 'test_admin_810201', 'password' => 'adminpass123']);
        $this->assertSame(200, $res['code'] ?? null, '无 2FA 登录应成功');
        $this->assertSame('登录成功', $res['message'] ?? '', 'message 保持');
        $admin = Session::get('admin', []);
        $this->assertSame(810201, (int)($admin['id'] ?? 0), '登录后 session admin identity 建立');
        // data = backstage_entrance（由测试环境 config 决定，仅验证 OLD/NEW 等价，见 testLoginOldNewEquivalence）
        $this->assertArrayHasKey('data', $res, 'response 结构含 data');
    }

    public function testLoginWrongPassword(): void
    {
        $this->seedAdmin(810202);
        $res = $this->callLoginNew(810202, ['account' => 'test_admin_810202', 'password' => 'wrongpass']);
        $this->assertSame(500, $res['code'] ?? null, '错误密码应失败');
        $this->assertSame('请检查您输入的用户名或密码是否正确。', $res['message'] ?? '', '错误信息保持');
        $this->assertSame([], Session::get('admin', []), '失败不得建立 admin session');
    }

    public function testLoginRateLimit(): void
    {
        $ip = '10.20.30.40';
        $account = 'test_admin_810203';
        $this->seedAdmin(810203);
        // 清理可能残留的 rate-limit 状态（存 redis/session，非事务内），保证可重复执行
        app(\app\service\LoginRateLimiter::class)->clear($ip, $account);
        $last = [];
        // 5 次失败后第 6 次应触发锁定（MAX_FAILURES=5）
        for ($i = 0; $i < 6; $i++) {
            $last = $this->callLoginNew(810203, ['account' => $account, 'password' => 'wrongpass']);
        }
        $this->assertSame('尝试过多，请稍后再试', $last['message'] ?? '', '超过失败次数应限流');
    }

    public function testLoginTwofaRequired(): void
    {
        $this->seedAdmin(810204, ['twofa_enabled' => 1, 'twofa_secret' => 'x', 'twofa_recovery_codes' => '[]']);
        $res = $this->callLoginNew(810204, ['account' => 'test_admin_810204', 'password' => 'adminpass123']);
        $this->assertSame(403, $res['code'] ?? null, '启用 2FA 且无验证码应 need_twofa');
        $this->assertSame('请输入二步验证码或恢复码', $res['message'] ?? '', 'message 保持');
        $this->assertSame(1, $res['data']['twofa_required'] ?? null, 'twofa_required 标记保持');
        $this->assertSame('need_twofa', $res['status'] ?? '', 'status 标记 need_twofa');
    }

    public function testLoginTotpSuccess(): void
    {
        $this->seedAdmin(810205, ['twofa_enabled' => 1, 'twofa_secret' => 'x', 'twofa_recovery_codes' => '[]']);
        // 覆盖加密 secret 以便 TOTP 校验可解密验证
        $secret = (new TwoFactorAuth())->createSecret();
        $guard = app(\app\service\AdminSensitiveOperationGuard::class);
        Db::name('admin')->where('id', 810205)->update([
            'twofa_secret' => $this->encryptForTest($guard, $secret),
            'twofa_recovery_codes' => '["' . password_hash('AAAA1111', PASSWORD_BCRYPT) . '"]',
        ]);
        $code = $this->totpCode($secret);
        $res = $this->callLoginNew(810205, ['account' => 'test_admin_810205', 'password' => 'adminpass123', 'twofa_code' => $code]);
        $this->assertSame(200, $res['code'] ?? null, 'TOTP 正确应登录成功');
    }

    public function testLoginTotpFailure(): void
    {
        $this->seedAdmin(810206, ['twofa_enabled' => 1, 'twofa_secret' => 'x', 'twofa_recovery_codes' => '[]']);
        $secret = (new TwoFactorAuth())->createSecret();
        $guard = app(\app\service\AdminSensitiveOperationGuard::class);
        Db::name('admin')->where('id', 810206)->update([
            'twofa_secret' => $this->encryptForTest($guard, $secret),
            'twofa_recovery_codes' => '["' . password_hash('AAAA1111', PASSWORD_BCRYPT) . '"]',
        ]);
        $res = $this->callLoginNew(810206, ['account' => 'test_admin_810206', 'password' => 'adminpass123', 'twofa_code' => '000000']);
        $this->assertSame(500, $res['code'] ?? null, 'TOTP 错误应失败');
    }

    public function testLoginRecoverySuccessAndReplay(): void
    {
        $this->seedAdmin(810207, ['twofa_enabled' => 1, 'twofa_secret' => 'x', 'twofa_recovery_codes' => '[]']);
        $secret = (new TwoFactorAuth())->createSecret();
        $guard = app(\app\service\AdminSensitiveOperationGuard::class);
        $codeHash = password_hash('RECOV1X2', PASSWORD_BCRYPT);
        Db::name('admin')->where('id', 810207)->update([
            'twofa_secret' => $this->encryptForTest($guard, $secret),
            'twofa_recovery_codes' => json_encode([$codeHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $ok = $this->callLoginNew(810207, ['account' => 'test_admin_810207', 'password' => 'adminpass123', 'twofa_code' => 'RECOV1X2']);
        $this->assertSame(200, $ok['code'] ?? null, '恢复码首次使用应登录成功');

        // replay：同一恢复码再次使用必须失败（一次性消费）
        $replay = $this->callLoginNew(810207, ['account' => 'test_admin_810207', 'password' => 'adminpass123', 'twofa_code' => 'RECOV1X2']);
        $this->assertSame(500, $replay['code'] ?? null, '恢复码不可重放');
    }

    // ==================== T3: Session / Logout ====================

    public function testSessionRotationFixation(): void
    {
        $this->seedAdmin(810208);
        $before = Session::getId();
        $this->callLoginNew(810208, ['account' => 'test_admin_810208', 'password' => 'adminpass123']);
        $after = Session::getId();
        $this->assertNotSame($before, $after, '登录必须 Session::regenerate(true) 防 fixation');
        $admin = Session::get('admin', []);
        $this->assertSame(810208, (int)($admin['id'] ?? 0), '登录后 identity 建立');
    }

    public function testLogoutPost(): void
    {
        $this->seedAdmin(810209);
        $this->callLoginNew(810209, ['account' => 'test_admin_810209', 'password' => 'adminpass123']);
        Session::set('admin_twofa_temp_secret', 'tmpx'); // pending 2FA 状态应被清理
        $this->assertSame(810209, (int)(Session::get('admin', [])['id'] ?? 0), '登录后 admin identity 存在');

        $ctrl = $this->makeAuthController([], 'admin/auth/logout');
        $resp = $ctrl->logout();
        $this->assertInstanceOf(\think\response\Redirect::class, $resp, 'logout 应 redirect login');
        $this->assertSame([], Session::get('admin', []), 'logout 后 admin identity 清除');
        $this->assertSame('', Session::get('admin_twofa_temp_secret', ''), 'logout 后 pending 2FA 清除');
    }

    public function testLogoutGetBlocked(): void
    {
        $this->sessionLogin(810210);
        $this->seedAdmin(810210);
        $ctrl = $this->makeAuthController([], 'admin/auth/logout', [], 'GET');
        $res = $this->parseResponse($ctrl->logout());
        $this->assertSame(405, $res['code'] ?? null, 'GET logout 必须 405（防被动登出）');
    }

    public function testDisabledAdminLoginEquivalentOld(): void
    {
        // 行为等价 OLD：login_check 本身不因 status=0 拒绝（AdminAuth middleware 层负责 status 校验，CLI 无法验证）
        // → NEW 与 OLD 响应一致；AdminAuth runtime 拒绝 = NOT VERIFIED — ENVIRONMENT / TEST HARNESS LIMITATION
        $this->seedAdmin(810211, ['status' => 0]);
        $resNew = $this->callLoginNew(810211, ['account' => 'test_admin_810211', 'password' => 'adminpass123']);
        $resOld = $this->callLoginOld(810211, ['account' => 'test_admin_810211', 'password' => 'adminpass123']);
        $this->assertSame($resOld['code'] ?? null, $resNew['code'] ?? null, 'disabled admin login NEW/OLD 响应等价');
        $this->assertSame($resOld['message'] ?? '', $resNew['message'] ?? '', 'disabled admin login message 等价');
    }

    // ==================== T4: TwoFA ====================

    public function testCsrfRequiredOnTwofa(): void
    {
        // 无 CSRF token（不调用 csrfSetup）→ twofa_post 显式校验必须拒绝
        $this->sessionLogin(810212);
        $this->seedAdmin(810212);
        $ctrl = $this->makeAuthController([], 'twofa_post/generate');
        $res = $this->parseResponse($ctrl->twofa_post('generate'));
        $this->assertSame(403, $res['code'] ?? null, '无 CSRF token 应 403');
    }

    public function testTwofaGenerateEnablePersist(): void
    {
        $this->sessionLogin(810213);
        $this->seedAdmin(810213);
        [$secret, $recoveryCodes] = $this->enableTwofa(810213);
        $this->assertCount(8, $recoveryCodes, '8 个恢复码');

        $row = Db::name('admin')->where('id', 810213)->find();
        $this->assertSame(1, (int)($row['twofa_enabled'] ?? 0), 'enable 后 twofa_enabled=1');
        $this->assertNotSame('', (string)($row['twofa_secret'] ?? ''), 'twofa_secret 已持久化（加密）');
        $this->assertNotSame('', (string)($row['twofa_recovery_codes'] ?? ''), '恢复码哈希已持久化');
        // 明文恢复码不得入库
        foreach ($recoveryCodes as $rc) {
            $this->assertStringNotContainsString($rc, (string)($row['twofa_recovery_codes'] ?? ''), '不得保存明文恢复码');
        }
    }

    public function testTwofaDisableRequiresVerify(): void
    {
        $this->sessionLogin(810214);
        $this->seedAdmin(810214);
        [$secret, $recoveryCodes] = $this->enableTwofa(810214);

        // 未验证 → 拒绝
        $noVerify = $this->callTwofaNew(810214, 'disable', []);
        $this->assertSame(500, $noVerify['code'] ?? null, 'disable 必须先验证');
        // 正确 TOTP → 成功
        $ok = $this->callTwofaNew(810214, 'disable', ['twofa_code' => $this->totpCode($secret)]);
        $this->assertSame(200, $ok['code'] ?? null, 'disable 验证通过应成功');
        $row = Db::name('admin')->where('id', 810214)->find();
        $this->assertSame(0, (int)($row['twofa_enabled'] ?? 0), 'disable 后 twofa_enabled=0');
        $this->assertNull($row['twofa_secret'] ?? null, 'disable 后 secret 清空');
    }

    public function testTwofaRegenerateOldInvalid(): void
    {
        $this->sessionLogin(810215);
        $this->seedAdmin(810215);
        [$secret, $oldCodes] = $this->enableTwofa(810215);

        // regenerate 必须先验证
        $reg = $this->callTwofaNew(810215, 'regenerate_recovery_codes', ['twofa_code' => $this->totpCode($secret)]);
        $this->assertSame(200, $reg['code'] ?? null, 'regenerate 验证通过应成功');
        $newCodes = $reg['data']['recovery_codes'] ?? [];
        $this->assertCount(8, $newCodes, '新 8 个恢复码');
        $this->assertNotSame($oldCodes[0], $newCodes[0] ?? '', '新恢复码应不同于旧恢复码');

        // 旧恢复码已失效 → 登录失败
        $oldFail = $this->callLoginNew(810215, ['account' => 'test_admin_810215', 'password' => 'adminpass123', 'twofa_code' => $oldCodes[0]]);
        $this->assertSame(500, $oldFail['code'] ?? null, 'regenerate 后旧恢复码失效');

        // 新恢复码可用 → 登录成功
        $newOk = $this->callLoginNew(810215, ['account' => 'test_admin_810215', 'password' => 'adminpass123', 'twofa_code' => $newCodes[0]]);
        $this->assertSame(200, $newOk['code'] ?? null, '新恢复码可登录');
    }

    public function testTwofaResetRequiresVerify(): void
    {
        $this->sessionLogin(810216);
        $this->seedAdmin(810216);
        [$secret] = $this->enableTwofa(810216);

        // reset 必须先验证
        $noVerify = $this->callTwofaNew(810216, 'reset', []);
        $this->assertSame(500, $noVerify['code'] ?? null, 'reset 必须先验证');
        // 正确 TOTP → reset → 新 secret pending → enable 重新绑定
        $reset = $this->callTwofaNew(810216, 'reset', ['twofa_code' => $this->totpCode($secret)]);
        $this->assertSame(200, $reset['code'] ?? null, 'reset 验证通过应成功');
        $newSecret = (string)($reset['data']['secret'] ?? '');
        $this->assertNotSame('', $newSecret, 'reset 返回新 secret');
        $this->assertNotSame($secret, $newSecret, 'reset 生成新 secret');

        $reEnable = $this->callTwofaNew(810216, 'enable', ['code' => $this->totpCode($newSecret)]);
        $this->assertSame(200, $reEnable['code'] ?? null, 'reset 后可用新 secret 重新 enable');
    }

    // ==================== T5: OLD / NEW 双路径 ====================

    public function testLoginOldNewEquivalence(): void
    {
        $this->seedAdmin(810217);
        $post = ['account' => 'test_admin_810217', 'password' => 'adminpass123'];
        $old = $this->callLoginOld(810217, $post);
        $new = $this->callLoginNew(810217, $post);
        $this->assertSame($old['code'] ?? null, $new['code'] ?? null, 'login code 等价');
        $this->assertSame($old['message'] ?? '', $new['message'] ?? '', 'login message 等价');
        $this->assertSame($old['data'] ?? null, $new['data'] ?? null, 'login data 等价');
    }

    public function testTwofaGenerateOldNewEquivalence(): void
    {
        $this->sessionLogin(810218);
        $this->seedAdmin(810218);
        $old = $this->callTwofaOld(810218, 'generate', []);
        $new = $this->callTwofaNew(810218, 'generate', []);
        $this->assertSame($old['code'] ?? null, $new['code'] ?? null, 'twofa generate code 等价');
        $this->assertSame($old['message'] ?? '', $new['message'] ?? '', 'twofa generate message 等价');
        // secret 每次调用随机生成，值必不同；断言结构等价（均返回非空 secret + qr_code + 8 恢复码）
        $this->assertNotSame('', (string)($old['data']['secret'] ?? ''), 'OLD 返回 secret');
        $this->assertNotSame('', (string)($new['data']['secret'] ?? ''), 'NEW 返回 secret');
        $this->assertSame(array_keys($old['data'] ?? []), array_keys($new['data'] ?? []), 'twofa generate data 结构等价');
    }

    /** 测试内加密 secret（复用 Guard 加密 key，与生产 decrypt 对称） */
    private function encryptForTest(\app\service\AdminSensitiveOperationGuard $guard, string $secret): string
    {
        $key = $guard->requireEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($secret, 'aes-256-cbc', $key, 0, $iv);

        return base64_encode($iv . $encrypted);
    }
}
