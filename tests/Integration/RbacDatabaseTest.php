<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\AdminRole;
use app\model\Permission;
use app\model\Role;
use app\model\RolePermission;
use think\facade\Db;

/**
 * Batch 2-B: RBAC 数据库集成测试
 *
 * 覆盖：
 * - Role 表：创建、code 唯一、status、查询
 * - Permission 表：创建、code 唯一、查询
 * - AdminRole 表：关联创建、(admin_id, role_id) 唯一约束
 * - RolePermission 表：关联创建、(role_id, permission_id) 唯一约束
 * - 数据库完整性：4 张表存在、索引存在
 *
 * 需要数据库。无 DB 时自动跳过。
 * 使用事务回滚，不污染测试数据。
 */
class RbacDatabaseTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbAvailable) {
            return;
        }
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$dbAvailable) {
            $this->rollback();
        }
        parent::tearDown();
    }

    // ==================== 数据库完整性 ====================

    public function testAllRbacTablesExist(): void
    {
        $tables = ['cz_role', 'cz_permission', 'cz_admin_role', 'cz_role_permission'];
        foreach ($tables as $table) {
            $result = Db::query("SHOW TABLES LIKE '{$table}'");
            $this->assertNotEmpty($result, "表 {$table} 应存在");
        }
    }

    public function testRoleTableIndexesExist(): void
    {
        $indexes = Db::query("SHOW INDEX FROM cz_role");
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('PRIMARY', $indexNames);
        $this->assertContains('uk_code', $indexNames);
        $this->assertContains('idx_status', $indexNames);
    }

    public function testPermissionTableIndexesExist(): void
    {
        $indexes = Db::query("SHOW INDEX FROM cz_permission");
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('PRIMARY', $indexNames);
        $this->assertContains('uk_code', $indexNames);
        $this->assertContains('idx_status', $indexNames);
    }

    public function testAdminRoleTableIndexesExist(): void
    {
        $indexes = Db::query("SHOW INDEX FROM cz_admin_role");
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('PRIMARY', $indexNames);
        $this->assertContains('uk_admin_role', $indexNames);
        $this->assertContains('idx_admin_id', $indexNames);
        $this->assertContains('idx_role_id', $indexNames);
    }

    public function testRolePermissionTableIndexesExist(): void
    {
        $indexes = Db::query("SHOW INDEX FROM cz_role_permission");
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('PRIMARY', $indexNames);
        $this->assertContains('uk_role_permission', $indexNames);
        $this->assertContains('idx_role_id', $indexNames);
        $this->assertContains('idx_permission_id', $indexNames);
    }

    // ==================== Role ====================

    public function testCreateRole(): void
    {
        $role = new Role();
        $role->name = '测试角色';
        $role->code = 'test_role_create';
        $role->description = '测试创建';
        $role->status = 1;
        $result = $role->save();

        $this->assertTrue($result);
        $this->assertGreaterThan(0, $role->id);

        $found = Role::where('code', 'test_role_create')->find();
        $this->assertNotNull($found);
        $this->assertSame('测试角色', $found->name);
        $this->assertSame(1, (int)$found->status);
    }

    public function testRoleCodeUniqueConstraint(): void
    {
        Db::name('role')->insert([
            'name' => '角色A', 'code' => 'test_unique_role', 'description' => '', 'status' => 1,
        ]);

        $this->expectException(\Throwable::class);
        Db::name('role')->insert([
            'name' => '角色B', 'code' => 'test_unique_role', 'description' => '', 'status' => 1,
        ]);
    }

    public function testRoleStatusDefault(): void
    {
        $role = new Role();
        $role->name = '默认状态角色';
        $role->code = 'test_default_status';
        $role->save();

        $found = Role::where('code', 'test_default_status')->find();
        $this->assertNotNull($found);
        $this->assertSame(1, (int)$found->status);
    }

    public function testRoleQueryByCode(): void
    {
        Db::name('role')->insert([
            'name' => '查询角色', 'code' => 'test_query_role', 'description' => '查询测试', 'status' => 1,
        ]);

        $role = Role::where('code', 'test_query_role')->find();
        $this->assertNotNull($role);
        $this->assertSame('查询角色', $role->name);
        $this->assertSame('查询测试', $role->description);
    }

    // ==================== Permission ====================

    public function testCreatePermission(): void
    {
        $perm = new Permission();
        $perm->name = '测试权限';
        $perm->code = 'test.perm.create';
        $perm->description = '测试创建';
        $perm->status = 1;
        $result = $perm->save();

        $this->assertTrue($result);
        $this->assertGreaterThan(0, $perm->id);

        $found = Permission::where('code', 'test.perm.create')->find();
        $this->assertNotNull($found);
        $this->assertSame('测试权限', $found->name);
    }

    public function testPermissionCodeUniqueConstraint(): void
    {
        Db::name('permission')->insert([
            'name' => '权限A', 'code' => 'test.unique.perm', 'description' => '', 'status' => 1,
        ]);

        $this->expectException(\Throwable::class);
        Db::name('permission')->insert([
            'name' => '权限B', 'code' => 'test.unique.perm', 'description' => '', 'status' => 1,
        ]);
    }

    public function testPermissionQueryByCode(): void
    {
        Db::name('permission')->insert([
            'name' => '查询权限', 'code' => 'test.query.perm', 'description' => '查询测试', 'status' => 1,
        ]);

        $perm = Permission::where('code', 'test.query.perm')->find();
        $this->assertNotNull($perm);
        $this->assertSame('查询权限', $perm->name);
    }

    // ==================== AdminRole ====================

    public function testCreateAdminRoleRelation(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '关联测试角色', 'code' => 'test_admin_role_rel', 'description' => '', 'status' => 1,
        ]);

        $relation = new AdminRole();
        $relation->admin_id = 99901;
        $relation->role_id = $roleId;
        $result = $relation->save();

        $this->assertTrue($result);
        $this->assertGreaterThan(0, $relation->id);

        $found = AdminRole::where('admin_id', 99901)->where('role_id', $roleId)->find();
        $this->assertNotNull($found);
    }

    public function testAdminRoleUniqueConstraint(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '唯一约束角色', 'code' => 'test_admin_role_unique', 'description' => '', 'status' => 1,
        ]);

        Db::name('admin_role')->insert(['admin_id' => 99902, 'role_id' => $roleId]);

        $this->expectException(\Throwable::class);
        Db::name('admin_role')->insert(['admin_id' => 99902, 'role_id' => $roleId]);
    }

    public function testAdminRoleDifferentAdminCanHaveSameRole(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '多管理员角色', 'code' => 'test_multi_admin_role', 'description' => '', 'status' => 1,
        ]);

        Db::name('admin_role')->insert(['admin_id' => 99903, 'role_id' => $roleId]);
        Db::name('admin_role')->insert(['admin_id' => 99904, 'role_id' => $roleId]);

        $count = AdminRole::where('role_id', $roleId)->count();
        $this->assertSame(2, (int)$count);
    }

    // ==================== RolePermission ====================

    public function testCreateRolePermissionRelation(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '权限关联角色', 'code' => 'test_role_perm_rel', 'description' => '', 'status' => 1,
        ]);
        $permId = Db::name('permission')->insertGetId([
            'name' => '权限关联权限', 'code' => 'test.role.perm.rel', 'description' => '', 'status' => 1,
        ]);

        $relation = new RolePermission();
        $relation->role_id = $roleId;
        $relation->permission_id = $permId;
        $result = $relation->save();

        $this->assertTrue($result);
        $this->assertGreaterThan(0, $relation->id);

        $found = RolePermission::where('role_id', $roleId)->where('permission_id', $permId)->find();
        $this->assertNotNull($found);
    }

    public function testRolePermissionUniqueConstraint(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '唯一约束角色2', 'code' => 'test_role_perm_unique', 'description' => '', 'status' => 1,
        ]);
        $permId = Db::name('permission')->insertGetId([
            'name' => '唯一约束权限2', 'code' => 'test.role.perm.unique', 'description' => '', 'status' => 1,
        ]);

        Db::name('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permId]);

        $this->expectException(\Throwable::class);
        Db::name('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
    }

    public function testRoleCanHaveMultiplePermissions(): void
    {
        $roleId = Db::name('role')->insertGetId([
            'name' => '多权限角色', 'code' => 'test_multi_perm_role', 'description' => '', 'status' => 1,
        ]);
        $perm1 = Db::name('permission')->insertGetId([
            'name' => '权限1', 'code' => 'test.multi.perm1', 'description' => '', 'status' => 1,
        ]);
        $perm2 = Db::name('permission')->insertGetId([
            'name' => '权限2', 'code' => 'test.multi.perm2', 'description' => '', 'status' => 1,
        ]);

        Db::name('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $perm1]);
        Db::name('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $perm2]);

        $count = RolePermission::where('role_id', $roleId)->count();
        $this->assertSame(2, (int)$count);
    }

    // ==================== Model 规范验证 ====================

    public function testRoleModelMapsToCorrectTable(): void
    {
        $role = new Role();
        $this->assertSame('cz_role', $role->getTable());
    }

    public function testPermissionModelMapsToCorrectTable(): void
    {
        $perm = new Permission();
        $this->assertSame('cz_permission', $perm->getTable());
    }

    public function testAdminRoleModelMapsToCorrectTable(): void
    {
        $rel = new AdminRole();
        $this->assertSame('cz_admin_role', $rel->getTable());
    }

    public function testRolePermissionModelMapsToCorrectTable(): void
    {
        $rel = new RolePermission();
        $this->assertSame('cz_role_permission', $rel->getTable());
    }

    public function testAdminRoleHasNoUpdateTimestamp(): void
    {
        // 关联表只有 create_time，没有 update_time
        $rel = new AdminRole();
        $this->assertFalse($rel->getAutoWriteTimestamp());
    }

    public function testRolePermissionHasNoUpdateTimestamp(): void
    {
        $rel = new RolePermission();
        $this->assertFalse($rel->getAutoWriteTimestamp());
    }
}
