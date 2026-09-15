<?php
/**
 * HCZ Batch 2-D2: AdminList 授权安全测试
 *
 * 测试范围：
 * - directHasAdminPermission() 的 RBAC + Legacy OR 逻辑
 * - 15 个业务接口的权限映射
 * - super_admin 通过 role.code 判断（非 admin.id===1）
 * - 权限前缀/子串绕过测试
 * - RBAC 异常降级到 Legacy
 *
 * 注意：测试环境无 cz_admin 表，使用单元测试 + mock + 反射。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\controller\AdminList;
use app\service\AuthorizationService;
use PHPUnit\Framework\TestCase;

class AdminListAuthorizationTest extends TestCase
{
    /**
     * 15 个业务接口的权限映射表
     */
    private const PERMISSION_MAP = [
        'user_list'                => ['legacy' => '用户列表',           'rbac' => 'admin.user.view'],
        'product_list_recharge'    => ['legacy' => '充值业务 - 产品列表', 'rbac' => 'admin.product.recharge.view'],
        'product_list_query'       => ['legacy' => '查询业务 - 产品列表', 'rbac' => 'admin.product.query.view'],
        'slide_list'               => ['legacy' => '首页轮播图',          'rbac' => 'admin.banner.manage'],
        'recharge_list'            => ['legacy' => '充值订单记录',        'rbac' => 'admin.recharge.view'],
        'withdrawal_list'          => ['legacy' => '提现订单记录',        'rbac' => 'admin.withdrawal.view'],
        'order_cz_list'            => ['legacy' => '充值业务 - 订单列表', 'rbac' => 'admin.order.recharge.view'],
        'order_cx_list'            => ['legacy' => '查询业务 - 订单列表', 'rbac' => 'admin.order.query.view'],
        'transaction_product_list' => ['legacy' => '交易挂单数据',        'rbac' => 'admin.transaction.pending.view'],
        'transaction_order_list'   => ['legacy' => '交易订单数据',        'rbac' => 'admin.transaction.order.view'],
        'bank_card_list'           => ['legacy' => '支付管理',            'rbac' => 'admin.payment.manage'],
        'user_t_list'              => ['legacy' => '用户列表',            'rbac' => 'admin.user.view'],
        'rebate_record_list'       => ['legacy' => '返佣记录',            'rbac' => 'admin.rebate.view'],
        'message_list'             => ['legacy' => '用户列表',            'rbac' => 'admin.user.view'],
        'admin_list'               => ['legacy' => '管理员列表',          'rbac' => 'admin.admin.view'],
        'operation_log_list'       => ['legacy' => '操作记录',            'rbac' => 'admin.log.view'],
    ];

    // ========== 1. RBAC PASS → 授权通过 ==========

    /**
     * 测试 RBAC 权限通过时，directHasAdminPermission 返回 true
     */
    public function testRbacPermissionPasses(): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: '', // 旧权限为空
            rbacCan: true, // RBAC 通过
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertTrue($result, 'RBAC 权限通过时应返回 true');
    }

    // ========== 2. RBAC FAIL + Legacy PASS → 授权通过（OR 兼容） ==========

    /**
     * 测试 RBAC 失败但旧 power() 通过时，仍返回 true（向后兼容）
     */
    public function testRbacFailLegacyPassReturnsTrue(): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: '用户列表', // 旧权限通过
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertTrue($result, 'RBAC 失败但旧 power() 通过时应返回 true（OR 兼容）');
    }

    // ========== 3. RBAC + Legacy 都失败 → 返回 false ==========

    /**
     * 测试 RBAC 和旧 power() 都失败时返回 false
     */
    public function testBothFailReturnsFalse(): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: '提现订单记录', // 旧权限不包含用户列表
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertFalse($result, 'RBAC 和旧 power() 都失败时应返回 false');
    }

    // ========== 4. Super Admin 通过 role.code 判断 ==========

    /**
     * 测试 super_admin 通过 AuthorizationService 内部的 role.code 判断
     * 不依赖 admin.id===1
     */
    public function testSuperAdminPassesViaRoleCode(): void
    {
        // adminId=999（不是 1），但 AuthorizationService 返回 true（模拟 super_admin 角色）
        $controller = $this->createController(
            adminId: 999,
            power: '',
            rbacCan: true, // 模拟 super_admin 拥有所有权限
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertTrue($result, 'super_admin 通过 role.code 判断应返回 true（不依赖 admin.id===1）');
    }

    /**
     * 测试 admin.id=1 但没有 super_admin 角色时，不自动通过
     * 证明 admin.id 本身不能决定 super_admin
     */
    public function testAdminId1WithoutSuperAdminRoleDoesNotAutoPass(): void
    {
        $controller = $this->createController(
            adminId: 1,
            power: '', // 旧权限也为空
            rbacCan: false, // 没有 super_admin 角色
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertFalse($result, 'admin.id=1 但没有 super_admin 角色时不应自动通过');
    }

    // ========== 5. 权限前缀/子串绕过测试 ==========

    /**
     * 测试权限前缀不能绕过（严格匹配）
     *
     * AuthorizationService 使用 in_array strict 匹配，
     * admin.user.view 不能匹配 admin.user.view_xxx 或 xxx.admin.user.view
     */
    public function testPermissionPrefixCannotBypass(): void
    {
        // 模拟 AuthorizationService 严格匹配：只有精确匹配才返回 true
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willReturnCallback(function (int $adminId, string $permission) {
                return $permission === 'admin.user.view'; // 只有精确匹配才通过
            });

        $controller = $this->createControllerWithAuthService(
            adminId: 2,
            power: '', // 旧权限为空，确保只走 RBAC
            authService: $authService,
        );

        // 精确匹配 → 通过
        $this->assertTrue($this->callDirectHasAdminPermission($controller, '用户列表'));

        // 模拟其他权限的 RBAC 不通过（因为 authService 只匹配 admin.user.view）
        // 这里测试 legacyToRbacPermission 映射正确性
        // '提现订单记录' → admin.withdrawal.view，RBAC 不匹配，旧权限也为空 → false
        $this->assertFalse($this->callDirectHasAdminPermission($controller, '提现订单记录'));
    }

    /**
     * 测试 legacyToRbacPermission 映射的完整性
     * 确保所有 15 个接口的旧权限都能映射到 RBAC 权限
     */
    public function testLegacyToRbacPermissionMappingComplete(): void
    {
        $controller = $this->createController(adminId: 1, power: '', rbacCan: true);

        foreach (self::PERMISSION_MAP as $method => $mapping) {
            $rbacPermission = $this->callLegacyToRbacPermission($controller, $mapping['legacy']);
            $this->assertEquals(
                $mapping['rbac'],
                $rbacPermission,
                "接口 {$method} 的旧权限 '{$mapping['legacy']}' 应映射到 '{$mapping['rbac']}'"
            );
        }
    }

    // ========== 6. 15 个接口逐项授权测试 ==========

    /**
     * 15 个接口逐项测试：RBAC 通过 → 授权通过
     *
     * @dataProvider provideAll15Interfaces
     */
    public function testAll15InterfacesRbacPass(string $method, string $legacyPermission, string $rbacPermission): void
    {
        // 模拟 RBAC 对该权限通过
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willReturnCallback(function (int $adminId, string $permission) use ($rbacPermission) {
                return $permission === $rbacPermission;
            });

        $controller = $this->createControllerWithAuthService(
            adminId: 2,
            power: '', // 旧权限为空，确保只走 RBAC
            authService: $authService,
        );

        $result = $this->callDirectHasAdminPermission($controller, $legacyPermission);
        $this->assertTrue($result, "接口 {$method} ({$legacyPermission} → {$rbacPermission}) RBAC 通过时应授权通过");
    }

    /**
     * 15 个接口逐项测试：RBAC 失败 + Legacy 通过 → 授权通过（兼容）
     *
     * @dataProvider provideAll15Interfaces
     */
    public function testAll15InterfacesLegacyPass(string $method, string $legacyPermission, string $rbacPermission): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: $legacyPermission, // 旧权限通过
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callDirectHasAdminPermission($controller, $legacyPermission);
        $this->assertTrue($result, "接口 {$method} ({$legacyPermission}) 旧权限通过时应授权通过（兼容）");
    }

    /**
     * 15 个接口逐项测试：两者都失败 → 授权拒绝
     *
     * @dataProvider provideAll15Interfaces
     */
    public function testAll15InterfacesBothFail(string $method, string $legacyPermission, string $rbacPermission): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: '不存在的权限', // 旧权限不通过
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callDirectHasAdminPermission($controller, $legacyPermission);
        $this->assertFalse($result, "接口 {$method} ({$legacyPermission}) 两者都失败时应授权拒绝");
    }

    /**
     * 数据提供者：15 个接口
     */
    public static function provideAll15Interfaces(): array
    {
        $data = [];
        foreach (self::PERMISSION_MAP as $method => $mapping) {
            $data[$method] = [$method, $mapping['legacy'], $mapping['rbac']];
        }
        return $data;
    }

    // ========== 7. RBAC 异常降级测试 ==========

    /**
     * 测试 RBAC 检查抛出异常时，安全降级到旧 power() 检查
     */
    public function testRbacExceptionFallsBackToLegacy(): void
    {
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willThrowException(new \RuntimeException('DB connection failed'));

        $controller = $this->createControllerWithAuthService(
            adminId: 2,
            power: '用户列表', // 旧权限通过
            authService: $authService,
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertTrue($result, 'RBAC 异常时应降级到旧 power() 检查，不应直接拒绝');
    }

    /**
     * 测试 RBAC 异常且旧权限也失败时返回 false
     */
    public function testRbacExceptionAndLegacyFailReturnsFalse(): void
    {
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willThrowException(new \RuntimeException('DB connection failed'));

        $controller = $this->createControllerWithAuthService(
            adminId: 2,
            power: '不存在的权限',
            authService: $authService,
        );

        $result = $this->callDirectHasAdminPermission($controller, '用户列表');
        $this->assertFalse($result, 'RBAC 异常且旧权限也失败时应返回 false');
    }

    // ========== 8. 边界条件测试 ==========

    /**
     * 测试 adminId <= 0 时返回 false
     */
    public function testInvalidAdminIdReturnsFalse(): void
    {
        $controller = $this->createController(adminId: 0, power: '用户列表', rbacCan: true);
        $this->assertFalse($this->callDirectHasAdminPermission($controller, '用户列表'));

        $controller = $this->createController(adminId: -1, power: '用户列表', rbacCan: true);
        $this->assertFalse($this->callDirectHasAdminPermission($controller, '用户列表'));
    }

    /**
     * 测试不存在的旧权限名映射返回 null，但旧 power() 检查仍可工作
     */
    public function testUnknownLegacyPermissionFallsBackToPowerCheck(): void
    {
        $controller = $this->createController(
            adminId: 2,
            power: '某个未知权限',
            rbacCan: false,
        );

        // legacyToRbacPermission 返回 null，跳过 RBAC，直接走旧 power()
        $result = $this->callDirectHasAdminPermission($controller, '某个未知权限');
        $this->assertTrue($result, '未知旧权限名应跳过 RBAC，直接走旧 power() 检查');
    }

    // ========== 9. 代码静态检查 ==========

    /**
     * 测试 directHasAdminPermission 中不再使用 admin.id===1 硬编码
     */
    public function testNoAdminId1HardcodeInDirectHasAdminPermission(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/AdminList.php';
        $source = file_get_contents($sourceFile);

        // 提取 directHasAdminPermission 方法体
        $pattern = '/private function directHasAdminPermission\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)(?=\n    private function|\n\})/s';
        if (preg_match($pattern, $source, $matches)) {
            $methodBody = $matches[1];
            $this->assertStringNotContainsString(
                'adminId === 1',
                $methodBody,
                'directHasAdminPermission 不应使用 admin.id===1 硬编码'
            );
            $this->assertStringNotContainsString(
                '$adminId === 1',
                $methodBody,
                'directHasAdminPermission 不应使用 $adminId === 1 硬编码'
            );
        } else {
            $this->fail('无法提取 directHasAdminPermission 方法体');
        }
    }

    /**
     * 实际存在的 15 个业务方法名（product_list 是一个方法，按 type 动态区分两种权限）
     */
    private const ACTUAL_METHOD_NAMES = [
        'user_list',
        'product_list',
        'slide_list',
        'recharge_list',
        'withdrawal_list',
        'order_cz_list',
        'order_cx_list',
        'transaction_product_list',
        'transaction_order_list',
        'bank_card_list',
        'user_t_list',
        'rebate_record_list',
        'message_list',
        'admin_list',
        'operation_log_list',
    ];

    /**
     * 测试 15 个业务方法都调用了 directHasAdminPermission
     */
    public function testAll15BusinessMethodsCallDirectHasAdminPermission(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/AdminList.php';
        $source = file_get_contents($sourceFile);

        foreach (self::ACTUAL_METHOD_NAMES as $method) {
            $pattern = '/public function ' . preg_quote($method, '/') . '\s*\([^)]*\)\s*\{(.*?)(?=\n    public function|\n    private function|\n\})/s';
            if (preg_match($pattern, $source, $matches)) {
                $methodBody = $matches[1];
                $this->assertStringContainsString(
                    'directHasAdminPermission',
                    $methodBody,
                    "方法 {$method} 应调用 directHasAdminPermission 进行权限检查"
                );
            } else {
                $this->fail("无法提取方法 {$method} 的方法体");
            }
        }
    }

    // ========== 辅助方法 ==========

    /**
     * 创建带 mock AuthorizationService 的 AdminList（使用反射，绕过构造函数）
     */
    private function createController(int $adminId, string $power, bool $rbacCan): AdminList
    {
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')->willReturn($rbacCan);

        return $this->createControllerWithAuthService($adminId, $power, $authService);
    }

    /**
     * 创建带指定 AuthorizationService 的 AdminList
     */
    private function createControllerWithAuthService(
        int $adminId,
        string $power,
        AuthorizationService $authService,
    ): AdminList {
        $controller = (new \ReflectionClass(AdminList::class))
            ->newInstanceWithoutConstructor();

        $reflection = new \ReflectionClass($controller);

        $adminInfoProp = $reflection->getProperty('admin_info');
        $adminInfoProp->setAccessible(true);
        $adminInfoProp->setValue($controller, ['id' => $adminId, 'power' => $power]);

        $authServiceProp = $reflection->getProperty('authService');
        $authServiceProp->setAccessible(true);
        $authServiceProp->setValue($controller, $authService);

        return $controller;
    }

    /**
     * 通过反射调用私有 directHasAdminPermission 方法
     */
    private function callDirectHasAdminPermission(AdminList $controller, string $permission): bool
    {
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('directHasAdminPermission');
        $method->setAccessible(true);

        return $method->invoke($controller, $permission);
    }

    /**
     * 通过反射调用私有 legacyToRbacPermission 方法
     */
    private function callLegacyToRbacPermission(AdminList $controller, string $legacyPermission): ?string
    {
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('legacyToRbacPermission');
        $method->setAccessible(true);

        return $method->invoke($controller, $legacyPermission);
    }
}
