<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\admin\Admin as AdminAdminController;
use app\model\Admin as AdminModel;
use app\service\AdminSensitiveOperationGuard;
use tests\Support\TestDataFactory;
use think\facade\Config;
use think\facade\Db;
use think\facade\Session;

/**
 * HCZ B09: admin_post 非资金 action 迁移测试（admin\Admin）
 *
 * 验证范围（与 Test 授权书 / Design Contract 一致）：
 *  - 3 个 action（add_modify/info/del）OLD/NEW 行为等价
 *  - RBAC 四象限（M1/M2/M3/M4）逐 action；权限码原样保持（既有 P3 不修）：
 *      add_modify → admin.admin.delete / info → admin.admin.manage / del → admin.admin.manage
 *  - CSRF（handler 层 directValidateRequiredCsrfToken → '管理员请求校验失败'）
 *  - Path 双路径：admin_post/{action}（OLD）与 admin/admin/{action}（NEW）等价；错误路径拒绝
 *  - Super Admin 边界：仅 super 可创建；非 super 禁改 id=1；仅 super 可改 power；
 *      非 super 禁改他人密码；禁删 super/自己
 *  - Sensitive Guard 复用（admin_password_change / admin_delete，password fallback / 2FA window=2）
 *  - Power 白名单 16 项（逐字一致）；info 字段白名单（不返回敏感字段）
 *  - 操作日志：add_modify（新增/修改）/del 有日志；info 无日志（OLD 事实保持）
 *  - 无事务 / 无锁（行为等价保持）
 *  - 既有权限码 mismatch（add_modify=delete / info=manage / del=manage）为既有 P3，不修、不回归
 *
 * 测试库环境说明（仅限 hcz_test 测试库，非生产库；与 B08 先例一致）：
 *  - cz_admin 表测试库可能缺失 → ensureB09Schema() 以 CREATE TABLE IF NOT EXISTS 补齐
 *  - security.data_encryption_key 测试环境未配置 → setUp 运行时注入 32 位测试密钥（内存级）
 *  - CLI 下 Request::session 属性为 null → setUp 对 app()->request->withSession(app('session')) 注入
 */
class AdminAdminActionTest extends DbTestCase
{
    /** 3 个 action 的 OLD 权限码（与 Re-Audit / Preflight 锁定一致，既有 P3 保持） */
    private const RBAC_MAP = [
        'add_modify' => 'admin.admin.delete',
        'info' => 'admin.admin.manage',
        'del' => 'admin.admin.manage',
    ];

    /** 测试数据加密密钥（32 位，运行时注入） */
    private const TEST_ENCRYPTION_KEY = 'B09TestEncryptionKey_0123456789abcdef';

    private static bool $b09SchemaEnsured = false;

    protected function setUp(): void
    {
        parent::setUp(); // 含 DbTestCase::ensureTestTables + DB 可用性检查

        self::ensureB09Schema();

        // CLI 下 Request::session 属性为 null：全局注入 Session（app('request') 为单例，原地生效）
        app()->request->withSession(app('session'));

        // 测试加密密钥（运行时内存级注入）
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

    /** 确保 B09 测试所需的 cz_admin 表存在（仅限 hcz_test 测试库，事务外调用，幂等） */
    private static function ensureB09Schema(): void
    {
        if (self::$b09SchemaEnsured) {
            return;
        }

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

        self::$b09SchemaEnsured = true;
    }

    // ==================== Helpers ====================

    /** 反射创建 admin\Admin 实例，注入独立 Request（含 session/post/pathinfo） */
    private function makeAdminController(array $post, string $path): AdminAdminController
    {
        $ref = new \ReflectionClass(AdminAdminController::class);
        $ctrl = $ref->newInstanceWithoutConstructor();

        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());

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

    /** 插入 cz_admin 行（供 super admin 判定与 Guard sensitive verification 使用） */
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
        // 清除该管理员 RBAC 权限缓存（TTL 300s），避免跨事务/跨测试方法残留污染授权判定（Test Harness 隔离）
        app(\app\service\AuthorizationService::class)->invalidateAdminPermissions($adminId);
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
    private function sessionCsrf(string $token = 'b09_csrf_token'): void
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
     * 完整调用链：session 登录 + CSRF + 构造控制器 + 调用 admin_post。
     *
     * @param string|null $csrf null = 不携带 token（CSRF 失败路径）
     */
    private function callAction(int $adminId, string $action, array $post, string $path, ?string $csrf = 'b09_csrf_token'): array
    {
        $this->sessionLogin($adminId);
        if ($csrf !== null) {
            $this->sessionCsrf($csrf);
            $post['_csrf_token'] = $csrf;
        } else {
            $this->sessionCsrf('b09_csrf_token'); // session 有 token，但请求不携带
        }
        $ctrl = $this->makeAdminController($post, $path);
        return $this->parseResponse($ctrl->admin_post($action));
    }

    /** 各 action 最小参数集（用于 RBAC/CSRF 象限） */
    private function postFor(string $action): array
    {
        switch ($action) {
            case 'add_modify':
                return ['id' => 0, 'account' => 'new_admin_' . uniqid(), 'name' => '新管理员'];
            case 'info':
                return ['id' => 1];
            case 'del':
                return ['id' => 1];
        }
        return [];
    }

    // ==================== A. 新控制器基础结构 ====================

    public function testNewControllerHasAdminAuthMiddleware(): void
    {
        $ref = new \ReflectionClass(AdminAdminController::class);
        $prop = $ref->getProperty('middleware');
        $prop->setAccessible(true);
        $middleware = $prop->getValue($ref->newInstanceWithoutConstructor());

        $this->assertSame([\app\middleware\AdminAuth::class], $middleware, 'admin\Admin 必须显式声明 AdminAuth 中间件（缺失 = P0）');
    }

    public function testNewControllerUnknownActionRejected(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions([], 800001);
        $this->seedAdmin(800001);
        $res = $this->callAction(800001, 'unknown_action', [], 'admin/admin/unknown_action');
        $this->assertSame('你不对劲', $res['message'] ?? '', '未知 action 必须保持 OLD default 行为');
    }

    public function testNewControllerNoBalanceLikeCase(): void
    {
        // admin_post 本身无 balance case（balance 属于 user_post 域 Frozen）；未知 action 一律 default
        $admin = TestDataFactory::createAdminWithPermissions([], 800002);
        $this->seedAdmin(800002);
        $res = $this->callAction(800002, 'balance', [], 'admin/admin/balance');
        $this->assertSame('你不对劲', $res['message'] ?? '', 'admin\Admin 不得提供 balance handler（Frozen 隔离）');
    }

    // ==================== B. RBAC 四象限（逐 action） ====================

    public function testRbacQuadrantPerAction(): void
    {
        $loop = 0;
        foreach (self::RBAC_MAP as $action => $perm) {
            $base = 800101 + $loop * 10;
            $post = $this->postFor($action);
            $path = 'admin_post/' . $action;

            // M1: 仅目标权限 → 通过 authorize（响应非'权限不足'）
            TestDataFactory::createAdminWithPermissions([$perm], $base);
            $this->seedAdmin($base);
            $res1 = $this->callAction($base, $action, $post, $path);
            $this->assertNotSame('权限不足', $res1['message'] ?? '', "[$action] M1 应通过 authorize");

            // M2: 其他 admin 权限（view）→ 权限不足（既有语义：view 不能替代 manage/delete）
            TestDataFactory::createAdminWithPermissions(['admin.admin.view'], $base + 1);
            $this->seedAdmin($base + 1);
            $res2 = $this->callAction($base + 1, $action, $post, $path);
            $this->assertSame('权限不足', $res2['message'] ?? '', "[$action] M2 仅 view 权限应被拒绝");

            // M3: 全部相关 admin 权限 → 通过
            TestDataFactory::createAdminWithPermissions([
                'admin.admin.view', 'admin.admin.manage', 'admin.admin.delete',
            ], $base + 2);
            $this->seedAdmin($base + 2);
            $res3 = $this->callAction($base + 2, $action, $post, $path);
            $this->assertNotSame('权限不足', $res3['message'] ?? '', "[$action] M3 应通过 authorize");

            // M4: 无权限 → 权限不足
            TestDataFactory::createAdminWithPermissions([], $base + 3);
            $this->seedAdmin($base + 3);
            $res4 = $this->callAction($base + 3, $action, $post, $path);
            $this->assertSame('权限不足', $res4['message'] ?? '', "[$action] M4 无权限应被拒绝");
            $loop++;
        }
    }

    /** 权限码精确锁定：不允许改为矩阵文档的 manage/view（防权限语义变化） */
    public function testRbacPermissionCodesLocked(): void
    {
        $this->assertSame('admin.admin.delete', self::RBAC_MAP['add_modify'], 'add_modify 保持 OLD 权限码（既有 P3，不修）');
        $this->assertSame('admin.admin.manage', self::RBAC_MAP['info'], 'info 保持 OLD 权限码（既有 P3，不修）');
        $this->assertSame('admin.admin.manage', self::RBAC_MAP['del'], 'del 保持 OLD 权限码（既有 P3，不修）');
    }

    // ==================== C. CSRF（逐 action） ====================

    public function testCsrfFailurePerAction(): void
    {
        $loop = 0;
        foreach (self::RBAC_MAP as $action => $perm) {
            $adminId = 800201 + $loop;
            TestDataFactory::createAdminWithPermissions([$perm], $adminId);
            $this->seedAdmin($adminId);
            $res = $this->callAction($adminId, $action, $this->postFor($action), 'admin_post/' . $action, null);
            $this->assertSame('管理员请求校验失败', $res['message'] ?? '', "[$action] CSRF 缺失应拒绝");
            $loop++;
        }
    }

    // ==================== D. Path 双路径 + 错误路径 ====================

    public function testPathBothOldAndNew(): void
    {
        $loop = 0;
        foreach (self::RBAC_MAP as $action => $perm) {
            $adminId = 800310 + $loop;
            TestDataFactory::createAdminWithPermissions([$perm], $adminId);
            $this->seedAdmin($adminId);

            $resOld = $this->callAction($adminId, $action, $this->postFor($action), 'admin_post/' . $action);
            $this->assertNotSame('管理员请求路径错误', $resOld['message'] ?? '', "[$action] OLD path 应通过");

            $resNew = $this->callAction($adminId, $action, $this->postFor($action), 'admin/admin/' . $action);
            $this->assertNotSame('管理员请求路径错误', $resNew['message'] ?? '', "[$action] NEW path 应通过");
            $loop++;
        }
    }

    public function testWrongPathRejected(): void
    {
        $loop = 0;
        foreach (self::RBAC_MAP as $action => $perm) {
            $adminId = 800320 + $loop;
            TestDataFactory::createAdminWithPermissions([$perm], $adminId);
            $this->seedAdmin($adminId);
            $res = $this->callAction($adminId, $action, $this->postFor($action), 'some/other/' . $action);
            $this->assertSame('管理员请求路径错误', $res['message'] ?? '', "[$action] 错误路径应拒绝");
            $loop++;
        }
    }

    // ==================== E. add_modify ====================

    public function testAddModifyMissingAccount(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800401);
        $this->seedAdmin(800401);
        $res = $this->callAction(800401, 'add_modify', ['id' => 0, 'name' => 'x'], 'admin_post/add_modify');
        $this->assertSame('请输入登录账号', $res['message'] ?? '');
    }

    public function testAddModifyMissingName(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800402);
        $this->seedAdmin(800402);
        $res = $this->callAction(800402, 'add_modify', ['id' => 0, 'account' => 'acc_x'], 'admin_post/add_modify');
        $this->assertSame('请输入管理员名称', $res['message'] ?? '');
    }

    public function testAddModifyNormalCannotCreate(): void
    {
        // 非 super admin（仅 delete 权限）创建 → 拒绝
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800403);
        $this->seedAdmin(800403);
        $res = $this->callAction(800403, 'add_modify', [
            'id' => 0,
            'account' => 'new_acc_' . uniqid(),
            'name' => '新管理员',
            'password' => 'newpass123',
        ], 'admin_post/add_modify');
        $this->assertSame('仅超级管理员可创建管理员', $res['message'] ?? '');
    }

    public function testAddModifyNormalCannotModifySuperAdmin(): void
    {
        // 非 super admin 修改 id=1 → 拒绝
        $this->seedAdmin(1, ['account' => 'super_root', 'name' => '超级管理员']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800404);
        $this->seedAdmin(800404);
        $res = $this->callAction(800404, 'add_modify', [
            'id' => 1,
            'account' => 'super_root',
            'name' => '改超级管理员',
        ], 'admin_post/add_modify');
        $this->assertSame('无权修改超级管理员', $res['message'] ?? '');
    }

    public function testAddModifyNormalCannotChangeOtherPassword(): void
    {
        // 非 super admin 修改他人（id!=自己）且带 password → 拒绝
        $this->seedAdmin(800405);
        $this->seedAdmin(999999, ['account' => 'other_admin', 'name' => '他人']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800405);
        $res = $this->callAction(800405, 'add_modify', [
            'id' => 999999,
            'account' => 'other_admin',
            'name' => '他人',
            'password' => 'newpass123',
        ], 'admin_post/add_modify');
        $this->assertSame('仅超级管理员可修改其他管理员密码', $res['message'] ?? '');
    }

    public function testAddModifySuperCreatesAdmin(): void
    {
        $this->seedAdmin(800406);
        TestDataFactory::createSuperAdmin(800406); // super_admin role 关联

        $account = 'created_admin_' . uniqid();
        $res = $this->callAction(800406, 'add_modify', [
            'id' => 0,
            'account' => $account,
            'name' => '创建的管理员',
            'password' => 'newpass123',
            'power' => '用户列表,支付管理',
        ], 'admin_post/add_modify');
        $this->assertSame('添加成功', $res['message'] ?? '');

        $row = Db::name('admin')->where('account', $account)->find();
        $this->assertNotEmpty($row, '新管理员应被创建');
        $this->assertSame(0, (int)$row['twofa_enabled'], '新建管理员默认禁用 2FA');
        $this->assertNull($row['twofa_secret']);
        $this->assertNull($row['twofa_recovery_codes']);
        $this->assertSame('用户列表,支付管理', (string)$row['power'], 'super admin 写入 power');
        $this->assertSame(1, TestDataFactory::countAdminOperationLog(800406, '新增管理员'), '应写新增管理员日志');
    }

    public function testAddModifyDuplicateAccount(): void
    {
        $this->seedAdmin(800407);
        TestDataFactory::createSuperAdmin(800407);
        $this->seedAdmin(800408, ['account' => 'dup_acc', 'name' => '已存在']);

        $res = $this->callAction(800407, 'add_modify', [
            'id' => 0,
            'account' => 'dup_acc',
            'name' => '重复',
            'password' => 'newpass123',
        ], 'admin_post/add_modify');
        $this->assertSame('登录账号已存在，请修改', $res['message'] ?? '');
    }

    public function testAddModifyInvalidPower(): void
    {
        $this->seedAdmin(800409);
        TestDataFactory::createSuperAdmin(800409);

        $res = $this->callAction(800409, 'add_modify', [
            'id' => 0,
            'account' => 'inv_power_' . uniqid(),
            'name' => 'x',
            'password' => 'newpass123',
            'power' => '非法权限项A',
        ], 'admin_post/add_modify');
        $this->assertStringContainsString('包含非法权限项', $res['message'] ?? '', '非法 power 应被白名单拒绝');
    }

    public function testAddModifySuperChangesPasswordRequiresSensitive(): void
    {
        // super admin 修改他人密码需敏感验证（admin_password_change）：缺 admin_password → 拒绝
        $this->seedAdmin(800410); // 当前操作者（super，password=adminpass123）
        TestDataFactory::createSuperAdmin(800410);
        $this->seedAdmin(800411, ['account' => 'target_admin', 'name' => '目标']);

        $res = $this->callAction(800410, 'add_modify', [
            'id' => 800411,
            'account' => 'target_admin',
            'name' => '目标',
            'password' => 'newpass456',
        ], 'admin_post/add_modify');
        $this->assertSame('请输入当前管理员密码', $res['message'] ?? '', '改密需敏感操作二次验证');
    }

    public function testAddModifySuperChangesPasswordWithSensitive(): void
    {
        $this->seedAdmin(800412);
        TestDataFactory::createSuperAdmin(800412);
        $this->seedAdmin(800413, ['account' => 'target2', 'name' => '目标2']);

        $res = $this->callAction(800412, 'add_modify', [
            'id' => 800413,
            'account' => 'target2',
            'name' => '目标2',
            'password' => 'newpass456',
            'admin_password' => 'adminpass123', // 操作者当前密码 → password fallback
        ], 'admin_post/add_modify');
        $this->assertSame('修改成功', $res['message'] ?? '');

        $row = Db::name('admin')->where('id', 800413)->find();
        $this->assertTrue(password_verify('newpass456' . $row['salt'], (string)$row['password']), '新密码应已 hash+salt 保存');
        $this->assertSame(1, TestDataFactory::countAdminOperationLog(800412, '修改管理员'), '应写修改管理员日志');
    }

    public function testAddModifyNormalModifySelfNoPassword(): void
    {
        // 非 super admin 修改自己（无密码）→ 允许；power 不写入
        $this->seedAdmin(800414, ['account' => 'self_admin', 'name' => '自己', 'power' => '用户列表']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.delete'], 800414);

        $res = $this->callAction(800414, 'add_modify', [
            'id' => 800414,
            'account' => 'self_admin',
            'name' => '自己改名',
            'power' => '支付管理', // 非 super → power 不应写入
        ], 'admin_post/add_modify');
        $this->assertSame('修改成功', $res['message'] ?? '');

        $row = Db::name('admin')->where('id', 800414)->find();
        $this->assertSame('用户列表', (string)$row['power'], '非 super admin 修改自己时 power 保持不变（仅 super 可写 power）');
        $this->assertSame('自己改名', (string)$row['name']);
        $this->assertSame(1, TestDataFactory::countAdminOperationLog(800414, '修改管理员'));
    }

    // ==================== F. info ====================

    public function testInfoInvalidId(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800501);
        $this->seedAdmin(800501);
        $res = $this->callAction(800501, 'info', ['id' => 0], 'admin_post/info');
        $this->assertSame('参数错误', $res['message'] ?? '');
    }

    public function testInfoMissingAdmin(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800502);
        $this->seedAdmin(800502);
        $res = $this->callAction(800502, 'info', ['id' => 99999999], 'admin_post/info');
        $this->assertSame('管理员不存在', $res['message'] ?? '');
    }

    public function testInfoFieldWhitelist(): void
    {
        $this->seedAdmin(800503, [
            'account' => 'info_admin',
            'name' => '详情管理员',
            'power' => '用户列表,支付管理',
            'twofa_secret' => 'encrypted_secret_x',
            'twofa_recovery_codes' => 'codes_x',
        ]);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800504);
        $this->seedAdmin(800504);

        $res = $this->callAction(800504, 'info', ['id' => 800503], 'admin_post/info');
        $this->assertSame('获取信息成功', $res['message'] ?? '');
        $data = $res['data'] ?? [];
        $this->assertSame(800503, (int)($data['id'] ?? 0));
        $this->assertSame('info_admin', $data['account'] ?? '');
        $this->assertSame('详情管理员', $data['name'] ?? '');
        $this->assertSame('用户列表,支付管理', $data['power'] ?? '');
        $this->assertStringContainsString('用户列表', (string)($data['power_selected'] ?? ''), 'power_selected 应含已选 option');
        $this->assertArrayNotHasKey('password', $data, '不得返回 password');
        $this->assertArrayNotHasKey('salt', $data, '不得返回 salt');
        $this->assertArrayNotHasKey('twofa_enabled', $data, '不得返回 twofa_enabled');
        $this->assertArrayNotHasKey('twofa_secret', $data, '不得返回 twofa_secret');
        $this->assertArrayNotHasKey('twofa_recovery_codes', $data, '不得返回 twofa_recovery_codes');
    }

    public function testInfoNoOperationLog(): void
    {
        $this->seedAdmin(800505);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800506);
        $this->seedAdmin(800506);
        $res = $this->callAction(800506, 'info', ['id' => 800505], 'admin_post/info');
        $this->assertSame(200, (int)($res['code'] ?? 0));
        $this->assertSame(0, TestDataFactory::countAdminOperationLog(800506), 'info 不得写操作日志（OLD 事实保持）');
    }

    // ==================== G. del ====================

    public function testDelForbidSuperAdmin(): void
    {
        $this->seedAdmin(1, ['account' => 'super_root', 'name' => '超级管理员']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800601);
        $this->seedAdmin(800601);
        $res = $this->callAction(800601, 'del', ['id' => 1], 'admin_post/del');
        $this->assertSame('禁止删除超级管理员', $res['message'] ?? '');
    }

    public function testDelForbidSelf(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800602);
        $this->seedAdmin(800602);
        $res = $this->callAction(800602, 'del', ['id' => 800602], 'admin_post/del');
        $this->assertSame('禁止删除当前登录账号', $res['message'] ?? '');
    }

    public function testDelInvalidTarget(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800603);
        $this->seedAdmin(800603);
        $res = $this->callAction(800603, 'del', ['id' => 0], 'admin_post/del');
        $this->assertSame('参数错误', $res['message'] ?? '');
    }

    public function testDelSensitiveRequired(): void
    {
        // 有效目标但缺 admin_password → 敏感验证拒绝
        $this->seedAdmin(800604, ['account' => 'victim', 'name' => '目标']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800605);
        $this->seedAdmin(800605);
        $res = $this->callAction(800605, 'del', ['id' => 800604], 'admin_post/del');
        $this->assertSame('请输入当前管理员密码', $res['message'] ?? '', 'del 需敏感操作二次验证');
    }

    public function testDelSensitiveWrongPassword(): void
    {
        $this->seedAdmin(800606, ['account' => 'victim2', 'name' => '目标2']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800607);
        $this->seedAdmin(800607);
        $res = $this->callAction(800607, 'del', [
            'id' => 800606,
            'admin_password' => 'wrong_password',
        ], 'admin_post/del');
        $this->assertSame('当前管理员密码错误', $res['message'] ?? '');
    }

    public function testDelMissingTarget(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800608);
        $this->seedAdmin(800608);
        $res = $this->callAction(800608, 'del', [
            'id' => 99999999,
            'admin_password' => 'adminpass123',
        ], 'admin_post/del');
        $this->assertSame('管理员不存在', $res['message'] ?? '');
    }

    public function testDelSuccess(): void
    {
        $this->seedAdmin(800609, ['account' => 'to_delete', 'name' => '待删']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800610);
        $this->seedAdmin(800610);

        $res = $this->callAction(800610, 'del', [
            'id' => 800609,
            'admin_password' => 'adminpass123',
        ], 'admin_post/del');
        $this->assertSame('删除成功', $res['message'] ?? '');
        $this->assertNull(Db::name('admin')->where('id', 800609)->find(), '目标管理员应被删除');
        $this->assertSame(1, TestDataFactory::countAdminOperationLog(800610, '删除管理员'), '应写删除管理员日志');
    }

    // ==================== H. 双路径等价（核心行为走 NEW 路径） ====================

    public function testDelSuccessViaNewPath(): void
    {
        $this->seedAdmin(800611, ['account' => 'to_delete2', 'name' => '待删2']);
        $admin = TestDataFactory::createAdminWithPermissions(['admin.admin.manage'], 800612);
        $this->seedAdmin(800612);

        $res = $this->callAction(800612, 'del', [
            'id' => 800611,
            'admin_password' => 'adminpass123',
        ], 'admin/admin/del');
        $this->assertSame('删除成功', $res['message'] ?? '', 'NEW 路径行为应与 OLD 等价');
    }

    public function testAddModifyInfoViaNewPath(): void
    {
        $this->seedAdmin(800613);
        TestDataFactory::createSuperAdmin(800613);

        $account = 'new_path_admin_' . uniqid();
        $res = $this->callAction(800613, 'add_modify', [
            'id' => 0,
            'account' => $account,
            'name' => '新路径管理员',
            'password' => 'newpass123',
        ], 'admin/admin/add_modify');
        $this->assertSame('添加成功', $res['message'] ?? '', 'NEW 路径 add_modify 应与 OLD 等价');

        $row = Db::name('admin')->where('account', $account)->find();
        $this->assertNotEmpty($row);

        $resInfo = $this->callAction(800613, 'info', ['id' => $row['id']], 'admin/admin/info');
        $this->assertSame('获取信息成功', $resInfo['message'] ?? '', 'NEW 路径 info 应与 OLD 等价');
    }

    // ==================== I. 无事务 / 无锁（行为等价保持，不新增） ====================

    public function testNoTransactionOrLockInActions(): void
    {
        // 静态验证：admin_post 三 action 不含 startTrans/transaction/lock(true)/forUpdate
        $src = (string)file_get_contents(app()->getRootPath() . 'app/controller/admin/Admin.php');
        $this->assertSame(0, substr_count($src, 'startTrans'), '不得新增事务');
        $this->assertSame(0, substr_count($src, 'Db::transaction'), '不得新增事务');
        $this->assertSame(0, substr_count($src, 'lock(true)'), '不得新增锁');
        $this->assertSame(0, substr_count($src, 'forUpdate'), '不得新增锁');
    }
}
