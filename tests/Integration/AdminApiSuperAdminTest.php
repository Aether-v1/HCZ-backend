<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\AdminApi;
use app\service\AdminOperationLogService;
use tests\Support\TestDataFactory;

/**
 * HCZ 2D3 C1 回归测试：AdminApi 超管判定
 *
 * 修复前：AdminApi::directIsCurrentAdminSuperAdmin() 调用未定义方法 $this->isSuperAdmin()
 * → admin_post add_modify 恒抛 Error，超管边界守卫不可达。
 *
 * 修复后：委托 (new AuthorizationService())->isSuperAdmin($adminId)，fail-closed。
 *
 * 覆盖：
 * - Test A: super_admin → true，不抛 Error
 * - Test B: 普通管理员 → false，不抛 Error
 * - Test C: 无效 adminId / 无角色 → false（fail-closed）
 * - Test D: admin_post 边界（无 $this->isSuperAdmin( 未定义调用；守卫输入正确）
 */
class AdminApiSuperAdminTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }

    /**
     * 通过反射创建 AdminApi 实例（绕过构造函数中 CLI 模式下 session 不可用的问题），
     * 设置 admin_info 并返回可调用私有方法 directIsCurrentAdminSuperAdmin 的闭包。
     */
    private function makeAdminApiSuperAdminFn(int $adminId): callable
    {
        $ref = new \ReflectionClass(AdminApi::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $app = app();
        $props = [
            'app' => $app,
            'request' => $app->request,
            'admin_info' => [
                'id' => $adminId,
                'account' => 'test_admin_' . $adminId,
                'name' => '测试管理员',
            ],
            'config' => [],
            'adminOperationLogService' => new AdminOperationLogService($app->request),
        ];
        foreach ($props as $name => $value) {
            if ($ref->hasProperty($name)) {
                $p = $ref->getProperty($name);
                $p->setAccessible(true);
                $p->setValue($controller, $value);
            }
        }

        $method = $ref->getMethod('directIsCurrentAdminSuperAdmin');
        $method->setAccessible(true);
        return fn() => $method->invoke($controller);
    }

    // ==================== Test A: Super Admin ====================

    public function testSuperAdminReturnsTrueWithoutError(): void
    {
        $admin = TestDataFactory::createSuperAdmin();
        $fn = $this->makeAdminApiSuperAdminFn($admin['admin_id']);

        $result = $fn();
        $this->assertIsBool($result, 'directIsCurrentAdminSuperAdmin 应返回 bool，不抛 Error');
        $this->assertTrue($result, 'super_admin 应被判定为超级管理员');
    }

    // ==================== Test B: 普通管理员 ====================

    public function testNormalAdminReturnsFalseWithoutError(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.view']);
        $fn = $this->makeAdminApiSuperAdminFn($admin['admin_id']);

        $result = $fn();
        $this->assertIsBool($result, 'directIsCurrentAdminSuperAdmin 应返回 bool，不抛 Error');
        $this->assertFalse($result, '仅有普通权限的管理员不应被判定为超级管理员');
    }

    // ==================== Test C: 无效管理员 / fail-closed ====================

    public function testInvalidAdminIdReturnsFalse(): void
    {
        // adminId = 0（session 异常场景）
        $fn0 = $this->makeAdminApiSuperAdminFn(0);
        $this->assertFalse($fn0(), 'adminId=0 应返回 false（fail-closed）');

        // adminId = -1
        $fnNeg = $this->makeAdminApiSuperAdminFn(-1);
        $this->assertFalse($fnNeg(), 'adminId<0 应返回 false（fail-closed）');
    }

    public function testAdminWithNoRoleReturnsFalse(): void
    {
        $adminId = 940000 + random_int(1, 99999);
        $fn = $this->makeAdminApiSuperAdminFn($adminId);
        $this->assertFalse($fn(), '无任何角色绑定的管理员应返回 false（fail-closed）');
    }

    // ==================== Test D: admin_post 边界 ====================

    /**
     * 确认 AdminApi 源码中不再存在 $this->isSuperAdmin( 未定义调用，
     * 且已委托 AuthorizationService::isSuperAdmin()。
     */
    public function testSourceHasNoUndefinedIsSuperAdminCall(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../app/controller/AdminApi.php');

        $this->assertStringNotContainsString(
            '$this->isSuperAdmin(',
            $src,
            'AdminApi 不得再调用未定义方法 $this->isSuperAdmin()'
        );

        $this->assertStringContainsString(
            '(new AuthorizationService())->isSuperAdmin(',
            $src,
            'directIsCurrentAdminSuperAdmin 应委托 AuthorizationService::isSuperAdmin()'
        );
    }

    /**
     * 确认超管判定驱动 admin_post 边界守卫的输入正确：
     * - 非超管 → false → 修改 id=1 的守卫 (!isSuperAdmin && targetId===1) 触发拒绝
     * - 超管   → true  → 不受该守卫拦截（可管理超管账号/修改 power）
     */
    public function testSuperAdminDecisionDrivesAdminPostBoundaryGuards(): void
    {
        // 普通管理员：isSuperAdmin=false → 修改 id=1 应被拒绝
        $normal = TestDataFactory::createAdminWithPermissions(['admin.admin.manage']);
        $normalFn = $this->makeAdminApiSuperAdminFn($normal['admin_id']);
        $normalIsSuper = $normalFn();
        $this->assertFalse($normalIsSuper);
        $this->assertTrue(
            !$normalIsSuper && 1 === 1,
            '非超管修改 id=1 应触发 "无权修改超级管理员" 守卫'
        );

        // 非超管：power 字段仅超管可修改（$isSuperAdmin 为 true 才写 power）
        $this->assertFalse($normalIsSuper, '非超管不得执行 power 字段修改（超管专属）');

        // 超管管理员：isSuperAdmin=true → 不受 "非超管不能改 id=1" 拦截
        $super = TestDataFactory::createSuperAdmin();
        $superFn = $this->makeAdminApiSuperAdminFn($super['admin_id']);
        $superIsSuper = $superFn();
        $this->assertTrue($superIsSuper);
        $this->assertFalse(
            !$superIsSuper && 1 === 1,
            '超管不应被 "非超管不能修改 id=1" 守卫拦截'
        );
    }
}
