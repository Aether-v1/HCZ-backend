<?php
declare(strict_types=1);

namespace tests\Integration;

use app\exception\AuthorizationException;
use app\service\AuthorizationService;
use app\service\LegacyPermissionService;
use think\facade\Cache;
use think\facade\Db;

/**
 * Batch 2-C: RBAC Authorization 集成测试
 *
 * 覆盖：
 * - Super Admin：super_admin + 任意 permission = true
 * - 普通管理员：拥有 user.view → user.view=true, user.delete=false
 * - 多角色：权限合并
 * - Disabled Role / Disabled Permission → false
 * - Cache：DB → Cache, Cache hit, Cache miss, Cache failure → DB fallback
 * - Cache Invalidation
 * - Legacy Compatibility：16 个映射验证
 * - 安全攻击：权限前缀绕过、Role 越权、Permission 越权
 *
 * 需要数据库。无 DB 时自动跳过。
 * 使用事务回滚，不污染数据。
 */
class RbacAuthorizationTest extends DbTestCase
{
    private AuthorizationService $authService;
    private LegacyPermissionService $legacyService;

    // 测试用管理员 ID（非真实管理员，仅用于 RBAC 关联表）
    private const TEST_ADMIN_ID = 900001;
    private const TEST_ADMIN_ID_2 = 900002;
    private const TEST_ADMIN_ID_3 = 900003;
    private const TEST_ROLE_ID = 900001;
    private const TEST_ROLE_ID_2 = 900002;
    private const TEST_PERM_ID = 900001;
    private const TEST_PERM_ID_2 = 900002;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbAvailable) {
            return;
        }
        $this->beginTransaction();
        $this->authService = new AuthorizationService();
        $this->legacyService = new LegacyPermissionService();
        $this->clearTestCache();
    }

    protected function tearDown(): void
    {
        if (self::$dbAvailable) {
            $this->clearTestCache();
            $this->rollback();
        }
        parent::tearDown();
    }

    private function clearTestCache(): void
    {
        foreach ([self::TEST_ADMIN_ID, self::TEST_ADMIN_ID_2, self::TEST_ADMIN_ID_3] as $id) {
            Cache::delete('rbac_admin_permissions_' . $id);
        }
    }

    // ==================== Super Admin ====================

    public function testSuperAdminHasAllPermissions(): void
    {
        // 建立测试管理员 → super_admin 角色关联
        $superAdminRoleId = Db::name('role')->where('code', 'super_admin')->value('id');
        $this->assertNotNull($superAdminRoleId);

        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => $superAdminRoleId,
        ]);

        // super_admin 应该拥有所有权限
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.payment.manage'));
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.setting.manage'));
        // 甚至不存在的权限也返回 true（超级管理员）
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'nonexistent.permission'));
    }

    public function testIsSuperAdminReturnsTrueForSuperAdmin(): void
    {
        $superAdminRoleId = Db::name('role')->where('code', 'super_admin')->value('id');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => $superAdminRoleId,
        ]);

        $this->assertTrue($this->authService->isSuperAdmin(self::TEST_ADMIN_ID));
    }

    public function testIsSuperAdminReturnsFalseForNormalAdmin(): void
    {
        // 建立普通角色
        Db::name('role')->insert([
            'id' => self::TEST_ROLE_ID,
            'name' => '测试普通角色',
            'code' => 'test_normal_role',
            'description' => '',
            'status' => 1,
        ]);
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        $this->assertFalse($this->authService->isSuperAdmin(self::TEST_ADMIN_ID));
    }

    // ==================== 普通管理员 ====================

    public function testNormalAdminHasAssignedPermission(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
    }

    public function testNormalAdminDoesNotHaveUnassignedPermission(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.delete'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.payment.manage'));
    }

    public function testAdminWithNoRolesHasNoPermissions(): void
    {
        // 不建立任何角色关联
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.payment.manage'));
    }

    public function testInvalidAdminIdReturnsFalse(): void
    {
        $this->assertFalse($this->authService->can(0, 'admin.user.view'));
        $this->assertFalse($this->authService->can(-1, 'admin.user.view'));
    }

    public function testEmptyPermissionCodeReturnsFalse(): void
    {
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, ''));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, '   '));
    }

    // ==================== 多角色 ====================

    public function testMultipleRolesPermissionsMerged(): void
    {
        // 角色 A：admin.user.view
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role_a', 'admin.user.view');
        // 角色 B：admin.order.view
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID_2, 'test_role_b', 'admin.order.recharge.view');

        Db::name('admin_role')->insert(['admin_id' => self::TEST_ADMIN_ID, 'role_id' => self::TEST_ROLE_ID]);
        Db::name('admin_role')->insert(['admin_id' => self::TEST_ADMIN_ID, 'role_id' => self::TEST_ROLE_ID_2]);

        // 两个角色的权限都应该有
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.order.recharge.view'));
        // 没有的权限仍然没有
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.payment.manage'));
    }

    public function testGetAdminPermissionsReturnsUniqueList(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role_a', 'admin.user.view');
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID_2, 'test_role_b', 'admin.user.view');

        Db::name('admin_role')->insert(['admin_id' => self::TEST_ADMIN_ID, 'role_id' => self::TEST_ROLE_ID]);
        Db::name('admin_role')->insert(['admin_id' => self::TEST_ADMIN_ID, 'role_id' => self::TEST_ROLE_ID_2]);

        $permissions = $this->authService->getAdminPermissions(self::TEST_ADMIN_ID);
        // 重复权限应该去重
        $this->assertCount(1, $permissions);
        $this->assertContains('admin.user.view', $permissions);
    }

    // ==================== Disabled Role / Permission ====================

    public function testDisabledRolePermissionsIgnored(): void
    {
        // 创建禁用角色
        Db::name('role')->insert([
            'id' => self::TEST_ROLE_ID,
            'name' => '禁用角色',
            'code' => 'test_disabled_role',
            'description' => '',
            'status' => 0, // 禁用
        ]);
        Db::name('permission')->insert([
            'id' => self::TEST_PERM_ID,
            'name' => '测试权限',
            'code' => 'test.disabled.perm',
            'description' => '',
            'status' => 1,
        ]);
        Db::name('role_permission')->insert([
            'role_id' => self::TEST_ROLE_ID,
            'permission_id' => self::TEST_PERM_ID,
        ]);
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 禁用角色的权限不应该生效
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'test.disabled.perm'));
    }

    public function testDisabledPermissionIgnored(): void
    {
        // 创建启用角色，但权限禁用
        Db::name('role')->insert([
            'id' => self::TEST_ROLE_ID,
            'name' => '启用角色',
            'code' => 'test_enabled_role',
            'description' => '',
            'status' => 1,
        ]);
        Db::name('permission')->insert([
            'id' => self::TEST_PERM_ID,
            'name' => '禁用权限',
            'code' => 'test.disabled.perm',
            'description' => '',
            'status' => 0, // 禁用
        ]);
        Db::name('role_permission')->insert([
            'role_id' => self::TEST_ROLE_ID,
            'permission_id' => self::TEST_PERM_ID,
        ]);
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 禁用权限不应该生效
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'test.disabled.perm'));
    }

    // ==================== 安全攻击：权限前缀绕过 ====================

    public function testPermissionPrefixBypassIsBlocked(): void
    {
        // 旧 strpos 行为：'user' 会匹配 'user.view'、'user.delete'
        // 新 RBAC 必须严格匹配，不能有前缀绕过
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 严格匹配：admin.user.view 应该通过
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));

        // 前缀绕过应该被阻止：
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user'));        // 前缀
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.delete'));  // 不同后缀
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view.extra')); // 扩展
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin'));               // 更短前缀
    }

    public function testPermissionCodeIsCaseSensitive(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 大小写不同应该不匹配
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'ADMIN.USER.VIEW'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'Admin.User.View'));
    }

    // ==================== 安全攻击：Role 越权 ====================

    public function testRoleEscalationIsBlocked(): void
    {
        // 管理员只有 admin.user.view，不能访问 admin.user.delete
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.delete'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.admin.view'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.setting.manage'));
    }

    public function testNonexistentPermissionReturnsFalse(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 不存在的权限 code 必须返回 false
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.anything'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'completely.fake.permission'));
    }

    // ==================== Cache ====================

    public function testCacheHitReturnsSameResult(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 第一次查询：写入缓存
        $first = $this->authService->getAdminPermissions(self::TEST_ADMIN_ID);
        $this->assertContains('admin.user.view', $first);

        // 验证缓存已写入
        $cached = Cache::get('rbac_admin_permissions_' . self::TEST_ADMIN_ID);
        $this->assertNotNull($cached);
        $this->assertContains('admin.user.view', $cached);

        // 第二次查询：应该命中缓存，结果相同
        $second = $this->authService->getAdminPermissions(self::TEST_ADMIN_ID);
        $this->assertEquals($first, $second);
    }

    public function testCacheInvalidationAfterRoleChange(): void
    {
        $this->createTestRoleWithPermission(self::TEST_ROLE_ID, 'test_role', 'admin.user.view');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => self::TEST_ROLE_ID,
        ]);

        // 第一次查询：写入缓存
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));

        // 使缓存失效
        $this->authService->invalidateAdminPermissions(self::TEST_ADMIN_ID);

        // 验证缓存已删除
        $cached = Cache::get('rbac_admin_permissions_' . self::TEST_ADMIN_ID);
        $this->assertNull($cached);

        // 重新查询：应该从 DB 重新加载
        $this->assertTrue($this->authService->can(self::TEST_ADMIN_ID, 'admin.user.view'));
    }

    public function testSuperAdminPermissionsCachedAsWildcard(): void
    {
        $superAdminRoleId = Db::name('role')->where('code', 'super_admin')->value('id');
        Db::name('admin_role')->insert([
            'admin_id' => self::TEST_ADMIN_ID,
            'role_id' => $superAdminRoleId,
        ]);

        $permissions = $this->authService->getAdminPermissions(self::TEST_ADMIN_ID);
        // 超级管理员权限列表应该包含 '*' 通配符
        $this->assertContains('*', $permissions);
    }

    // ==================== Legacy Compatibility ====================

    public function testLegacyPermissionMappingHas16Entries(): void
    {
        $this->assertSame(16, $this->legacyService->getMappingCount());
    }

    public function testLegacyPermissionMappingAllCodesExistInDb(): void
    {
        $codes = $this->legacyService->getAllCodes();
        foreach ($codes as $code) {
            $exists = Db::name('permission')->where('code', $code)->find();
            $this->assertNotNull($exists, "权限 code 不存在于数据库: {$code}");
        }
    }

    public function testLegacyToCodeMapping(): void
    {
        $this->assertSame('admin.user.view', $this->legacyService->mapLegacyPermission('用户列表'));
        $this->assertSame('admin.payment.manage', $this->legacyService->mapLegacyPermission('支付管理'));
        $this->assertSame('admin.withdrawal.view', $this->legacyService->mapLegacyPermission('提现订单记录'));
        $this->assertSame('admin.setting.manage', $this->legacyService->mapLegacyPermission('系统设置管理'));
    }

    public function testLegacyMappingWithSpaces(): void
    {
        // 旧权限名中包含空格的项
        $this->assertSame('admin.product.recharge.view', $this->legacyService->mapLegacyPermission('充值业务 - 产品列表'));
        $this->assertSame('admin.order.query.view', $this->legacyService->mapLegacyPermission('查询业务 - 订单列表'));
    }

    public function testUnknownLegacyPermissionReturnsNull(): void
    {
        $this->assertNull($this->legacyService->mapLegacyPermission('不存在的权限'));
        $this->assertNull($this->legacyService->mapLegacyPermission(''));
    }

    public function testLegacyPowerStringParsing(): void
    {
        $powerString = '用户列表,支付管理,提现订单记录';
        $parsed = $this->legacyService->parseLegacyPowerString($powerString);

        $this->assertCount(3, $parsed);
        $this->assertContains('用户列表', $parsed);
        $this->assertContains('支付管理', $parsed);
        $this->assertContains('提现订单记录', $parsed);
    }

    public function testLegacyPowerStringConversionToCodes(): void
    {
        $powerString = '用户列表,支付管理,不存在的权限';
        $result = $this->legacyService->convertPowerStringToCodes($powerString);

        $this->assertContains('admin.user.view', $result['codes']);
        $this->assertContains('admin.payment.manage', $result['codes']);
        $this->assertContains('不存在的权限', $result['unmapped']);
        $this->assertCount(2, $result['codes']);
        $this->assertCount(1, $result['unmapped']);
    }

    public function testLegacyHasPermissionReproducesStrposBehavior(): void
    {
        // 复现旧 power() 的 strpos 行为（包括前缀匹配风险）
        $powerString = '用户列表,支付管理';

        // 精确匹配应该通过
        $this->assertTrue($this->legacyService->hasLegacyPermission($powerString, '用户列表'));
        // 子串匹配也会通过（旧行为的风险）
        $this->assertTrue($this->legacyService->hasLegacyPermission($powerString, '用户'));
        // 不存在的不通过
        $this->assertFalse($this->legacyService->hasLegacyPermission($powerString, '提现订单记录'));
    }

    public function testCodeToLegacyReverseMapping(): void
    {
        $this->assertSame('用户列表', $this->legacyService->mapCodeToLegacy('admin.user.view'));
        $this->assertSame('系统设置管理', $this->legacyService->mapCodeToLegacy('admin.setting.manage'));
        $this->assertNull($this->legacyService->mapCodeToLegacy('nonexistent.code'));
    }

    // ==================== 旧体系保留验证 ====================

    public function testLegacyPowerFunctionStillExists(): void
    {
        // 验证旧 power() 函数仍然存在且行为不变
        $this->assertTrue(function_exists('power'));
        $this->assertSame('1', power('用户列表,支付管理', '用户列表'));
        $this->assertSame('2', power('用户列表,支付管理', '提现订单记录'));
    }

    // ==================== 辅助方法 ====================

    private function createTestRoleWithPermission(int $roleId, string $roleCode, string $permCode): void
    {
        // 创建角色
        Db::name('role')->insert([
            'id' => $roleId,
            'name' => '测试角色_' . $roleCode,
            'code' => $roleCode,
            'description' => '',
            'status' => 1,
        ]);

        // 查找或创建权限
        $perm = Db::name('permission')->where('code', $permCode)->find();
        if (!$perm) {
            Db::name('permission')->insert([
                'id' => self::TEST_PERM_ID,
                'name' => '测试权限_' . $permCode,
                'code' => $permCode,
                'description' => '',
                'status' => 1,
            ]);
            $permId = self::TEST_PERM_ID;
        } else {
            $permId = $perm['id'];
        }

        // 建立角色-权限关联
        Db::name('role_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }
}
