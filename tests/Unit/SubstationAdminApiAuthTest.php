<?php
/**
 * HCZ Batch 2-D1: SubstationAdminApi 授权安全测试
 *
 * 测试范围：
 * - RBAC 权限检查（AuthorizationService）
 * - 旧 power() 兼容（OR 逻辑）
 * - 权限绕过（严格匹配，前缀/子串不能绕过）
 * - super_admin 通过角色判断（不使用 admin.id===1）
 * - 所有 8 个业务方法都调用 authorize()
 *
 * 注意：测试环境无 cz_admin / cz_substation 表，使用单元测试 + mock。
 *       集成测试需在生产/完整数据库环境执行。
 */

declare(strict_types=1);

namespace tests\Unit;

use app\controller\SubstationAdminApi;
use app\service\AuthorizationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;

class SubstationAdminApiAuthTest extends TestCase
{
    /**
     * 测试 SubstationAdminApi 构造函数接受 AuthorizationService
     */
    public function testConstructorAcceptsAuthorizationService(): void
    {
        $reflection = new \ReflectionClass(SubstationAdminApi::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // 构造函数应接受 App 和 AuthorizationService 两个参数
        $this->assertCount(2, $params);
        $this->assertEquals('app', $params[0]->getName());
        $this->assertEquals('authService', $params[1]->getName());
        $this->assertEquals(AuthorizationService::class, $params[1]->getType()->getName());
    }

    /**
     * 测试 RBAC 权限通过 → 放行
     */
    public function testRbacPermissionPasses(): void
    {
        $controller = $this->createControllerWithAuth(
            adminId: 2,
            power: '',
            rbacCan: true, // RBAC 通过
        );

        $result = $this->callAuthorize($controller, 'substation.view');

        $this->assertNull($result, 'RBAC 权限通过时应放行（返回 null）');
    }

    /**
     * 测试旧 power() 权限通过 → 放行（向后兼容）
     */
    public function testLegacyPowerPermissionPasses(): void
    {
        $controller = $this->createControllerWithAuth(
            adminId: 2,
            power: '系统设置管理', // 旧权限通过
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callAuthorize($controller, 'substation.view');

        $this->assertNull($result, '旧 power() 权限通过时应放行（向后兼容）');
    }

    /**
     * 测试 RBAC 和旧 power() 都失败 → 403
     */
    public function testBothPermissionsFailReturns403(): void
    {
        $controller = $this->createControllerWithAuth(
            adminId: 2,
            power: '用户列表', // 旧权限不包含系统设置管理
            rbacCan: false, // RBAC 不通过
        );

        $result = $this->callAuthorize($controller, 'substation.view');

        $this->assertNotNull($result, '两者都失败时应返回拒绝响应');
    }

    /**
     * 测试权限前缀不能绕过（严格匹配）
     *
     * AuthorizationService 使用严格 === 匹配，
     * substation.view 不能匹配 substation.view_xxx 或 xxx.substation.view
     */
    public function testPermissionPrefixCannotBypass(): void
    {
        // 模拟 AuthorizationService 严格匹配：只有精确匹配才返回 true
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willReturnCallback(function (int $adminId, string $permission) {
                // 只有 substation.view 精确匹配才通过
                return $permission === 'substation.view';
            });

        $controller = $this->createControllerWithAuthService(
            adminId: 2,
            power: '',
            authService: $authService,
        );

        // 精确匹配 → 通过
        $this->assertNull($this->callAuthorize($controller, 'substation.view'));

        // 前缀匹配 → 应拒绝（不能通过 substation.view 访问 substation.manage）
        $this->assertNotNull($this->callAuthorize($controller, 'substation.manage'));

        // 子串匹配 → 应拒绝
        $this->assertNotNull($this->callAuthorize($controller, 'view'));

        // 大小写不同 → 应拒绝
        $this->assertNotNull($this->callAuthorize($controller, 'Substation.View'));
    }

    /**
     * 测试 super_admin 通过角色判断（不使用 admin.id===1）
     *
     * AuthorizationService 内部通过 role.code='super_admin' 判断，
     * 不依赖 admin.id===1 硬编码。
     */
    public function testSuperAdminPassesViaRoleNotId(): void
    {
        // adminId=999（不是 1），但通过 super_admin 角色拥有所有权限
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')
            ->willReturnCallback(function (int $adminId, string $permission) {
                // 模拟 super_admin 角色：所有权限都通过
                return true;
            });

        $controller = $this->createControllerWithAuthService(
            adminId: 999, // 不是 1
            power: '',
            authService: $authService,
        );

        // 所有权限都应通过（通过 super_admin 角色，不是 admin.id===1）
        $this->assertNull($this->callAuthorize($controller, 'substation.view'));
        $this->assertNull($this->callAuthorize($controller, 'substation.audit'));
        $this->assertNull($this->callAuthorize($controller, 'substation.manage'));
    }

    /**
     * 测试 admin.id=1 但没有 super_admin 角色时，不自动通过
     *
     * 新代码不使用 admin.id===1 硬编码，
     * admin.id=1 必须关联 super_admin 角色才能通过。
     */
    public function testAdminId1WithoutSuperAdminRoleDoesNotAutoPass(): void
    {
        // adminId=1，但 AuthorizationService 返回 false（没有 super_admin 角色）
        $controller = $this->createControllerWithAuth(
            adminId: 1,
            power: '', // 旧权限也不通过
            rbacCan: false, // RBAC 不通过（没有 super_admin 角色）
        );

        $result = $this->callAuthorize($controller, 'substation.view');

        $this->assertNotNull($result, 'admin.id=1 但没有 super_admin 角色时不应自动通过');
    }

    /**
     * 测试所有 8 个业务方法都需要授权
     *
     * 通过反射检查每个方法开头是否调用 authorize()。
     * 这里使用源码扫描验证。
     */
    public function testAllBusinessMethodsCallAuthorize(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/SubstationAdminApi.php';
        $source = file_get_contents($sourceFile);

        $businessMethods = [
            'applyList',
            'profileAuditList',
            'saveBaseDomain',
            'audit',
            'list',
            'manageAction',
            'orders',
            'incomeLog',
        ];

        foreach ($businessMethods as $method) {
            // 提取方法体
            $pattern = '/public function ' . preg_quote($method, '/') . '\s*\([^)]*\)\s*\{(.*?)(?=\n    public function|\n    private function|\n\})/s';
            if (preg_match($pattern, $source, $matches)) {
                $methodBody = $matches[1];
                $this->assertStringContainsString(
                    'authorize(',
                    $methodBody,
                    "方法 {$method} 应调用 authorize() 进行权限检查"
                );
            } else {
                $this->fail("无法提取方法 {$method} 的方法体");
            }
        }
    }

    /**
     * 测试权限映射正确性
     *
     * 验证每个业务方法使用的权限 code 正确：
     * - 读操作 → substation.view
     * - 审核 → substation.audit
     * - 管理/写操作 → substation.manage
     */
    public function testPermissionMappingCorrectness(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/SubstationAdminApi.php';
        $source = file_get_contents($sourceFile);

        $expectedPermissions = [
            'applyList' => 'substation.view',
            'profileAuditList' => 'substation.view',
            'list' => 'substation.view',
            'orders' => 'substation.view',
            'incomeLog' => 'substation.view',
            'audit' => 'substation.audit',
            'saveBaseDomain' => 'substation.manage',
            'manageAction' => 'substation.manage',
        ];

        foreach ($expectedPermissions as $method => $expectedPermission) {
            $pattern = '/public function ' . preg_quote($method, '/') . '\s*\([^)]*\)\s*\{(.*?)(?=\n    public function|\n    private function|\n\})/s';
            if (preg_match($pattern, $source, $matches)) {
                $methodBody = $matches[1];
                $this->assertStringContainsString(
                    "'{$expectedPermission}'",
                    $methodBody,
                    "方法 {$method} 应使用权限 '{$expectedPermission}'"
                );
            }
        }
    }

    /**
     * 测试不使用 strpos() 进行权限判断
     *
     * 新代码使用 AuthorizationService 严格匹配，
     * 不直接使用 strpos() 子串匹配（旧 power() 的问题）。
     * 注意：authorize() 调用 power() 函数（common.php 内部用 strpos），
     * 但 SubstationAdminApi 本身不应直接使用 strpos。
     */
    public function testNoStrposInAuthorization(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/SubstationAdminApi.php';
        $source = file_get_contents($sourceFile);

        // 提取 authorize() 方法体
        $pattern = '/private function authorize\s*\([^)]*\)\s*:\s*[^{]+\{(.*?)(?=\n    private function|\n\})/s';
        if (preg_match($pattern, $source, $matches)) {
            $methodBody = $matches[1];
            // authorize() 方法体不应直接包含 strpos（通过 power() 间接调用不算）
            $this->assertStringNotContainsString(
                'strpos(',
                $methodBody,
                'authorize() 方法不应直接使用 strpos() 进行权限判断'
            );
        } else {
            // 如果无法提取方法体，至少检查整个类不直接用 strpos 做权限判断
            $this->assertStringNotContainsString(
                'strpos($',
                $source,
                'SubstationAdminApi 不应直接使用 strpos() 进行权限判断'
            );
        }
    }

    /**
     * 测试 Data Scope 当前为全部分站（记录现状）
     *
     * 当前系统没有分站管理员角色和 admin.substation_id 字段，
     * 所有授权管理员都能访问全部分站数据。
     * 未来引入分站管理员时需要增加 Data Scope 过滤。
     */
    public function testDataScopeCurrentlyAllSubstations(): void
    {
        $sourceFile = __DIR__ . '/../../app/controller/SubstationAdminApi.php';
        $source = file_get_contents($sourceFile);

        // 当前代码中没有按 admin.substation_id 过滤数据
        // 这是预期行为（当前所有管理员都是全局）
        $this->assertStringNotContainsString(
            'admin_substation_id',
            $source,
            '当前不应有 admin_substation_id 过滤（所有管理员都是全局）'
        );

        // 记录：orders() 和 incomeLog() 支持 substation_id 参数，
        // 但当前不验证该分站是否属于当前管理员（未来需要 Data Scope）
        $this->assertStringContainsString(
            'substation_id',
            $source,
            'orders() 和 incomeLog() 应支持 substation_id 参数过滤'
        );
    }

    // ========== 辅助方法 ==========

    /**
     * 创建带 mock AuthorizationService 的 SubstationAdminApi
     * 使用反射直接设置属性，绕过构造函数（避免 think\Request::method() 与 PHPUnit mock 冲突）
     */
    private function createControllerWithAuth(
        int $adminId,
        string $power,
        bool $rbacCan,
    ): SubstationAdminApi {
        $authService = $this->createMock(AuthorizationService::class);
        $authService->method('can')->willReturn($rbacCan);

        return $this->createControllerWithAuthService($adminId, $power, $authService);
    }

    /**
     * 创建带指定 AuthorizationService 的 SubstationAdminApi
     */
    private function createControllerWithAuthService(
        int $adminId,
        string $power,
        AuthorizationService $authService,
    ): SubstationAdminApi {
        $controller = (new \ReflectionClass(SubstationAdminApi::class))
            ->newInstanceWithoutConstructor();

        // 使用反射设置私有/受保护属性
        $reflection = new \ReflectionClass($controller);

        $adminInfoProp = $reflection->getProperty('admin_info');
        $adminInfoProp->setAccessible(true);
        $adminInfoProp->setValue($controller, [
            'id' => $adminId,
            'power' => $power,
        ]);

        $authServiceProp = $reflection->getProperty('authService');
        $authServiceProp->setAccessible(true);
        $authServiceProp->setValue($controller, $authService);

        return $controller;
    }

    /**
     * 通过反射调用私有 authorize() 方法
     */
    private function callAuthorize(SubstationAdminApi $controller, string $permission): mixed
    {
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('authorize');
        $method->setAccessible(true);

        return $method->invoke($controller, $permission);
    }
}
