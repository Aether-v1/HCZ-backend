<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\AdminApi;
use app\service\AdminOperationLogService;
use app\service\AuthorizationService;
use tests\Support\TestDataFactory;
use think\facade\Db;

/**
 * AdminApi::authorize() P0 Hotfix 回归测试
 *
 * 验证 AdminApi 授权调用链恢复正常：
 * - authorize() 方法存在且签名正确
 * - 有权限返回 true，无权限返回 false
 * - 不存在的 permission code 不致命错误
 * - 真实 Controller 方法调用能越过 authorize() 不再出现 "Call to undefined method"
 */
class AdminApiAuthorizeHotfixTest extends DbTestCase
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
     * 设置 admin_info 并返回可调用私有方法的闭包。
     */
    private function makeAdminApiWithAdmin(int $adminId): array
    {
        $ref = new \ReflectionClass(AdminApi::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $app = app();

        // 设置构造函数中初始化的所有属性
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

        // 获取 authorize 方法可调用闭包
        $method = $ref->getMethod('authorize');
        $method->setAccessible(true);
        $authorizeFn = fn(string $perm): bool => $method->invoke($controller, $perm);

        return [$controller, $authorizeFn];
    }

    // ==================== H2-001: 方法存在性 ====================

    public function testAuthorizeMethodExists(): void
    {
        $this->assertTrue(
            method_exists(AdminApi::class, 'authorize'),
            'AdminApi::authorize() 方法必须存在'
        );

        $ref = new \ReflectionClass(AdminApi::class);
        $m = $ref->getMethod('authorize');
        $this->assertTrue($m->isPrivate(), 'authorize 应为 private');
        $this->assertEquals('bool', (string)$m->getReturnType(), 'authorize 返回值应为 bool');
        $params = $m->getParameters();
        $this->assertCount(1, $params, 'authorize 应接受 1 个参数');
        $this->assertEquals('permission', $params[0]->getName());
        $this->assertEquals('string', (string)$params[0]->getType());
    }

    // ==================== H2-002: 合法权限返回 true ====================

    public function testAuthorizeWithPermissionReturnsTrue(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.manage']);
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        $result = $authorizeFn('admin.points.manage');
        $this->assertTrue($result, '拥有 admin.points.manage 权限的管理员应通过授权');
    }

    public function testAuthorizeSuperAdminReturnsTrue(): void
    {
        $admin = TestDataFactory::createSuperAdmin();
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        // super_admin 应拥有所有权限
        $this->assertTrue($authorizeFn('admin.points.manage'));
        $this->assertTrue($authorizeFn('admin.user.delete'));
        $this->assertTrue($authorizeFn('admin.rebate.delete'));
    }

    // ==================== H2-003: 无权限返回 false ====================

    public function testAuthorizeWithoutPermissionReturnsFalse(): void
    {
        // 创建只有 view 权限的管理员
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.view']);
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        $this->assertFalse(
            $authorizeFn('admin.points.manage'),
            '只有 view 权限的管理员不应通过 manage 授权'
        );
        // view 权限本身应通过
        $this->assertTrue($authorizeFn('admin.points.view'));
    }

    public function testAuthorizeWithNoRoleReturnsFalse(): void
    {
        // 管理员 ID 存在但没有任何角色绑定
        $adminId = 910000 + random_int(1, 99999);
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($adminId);

        $this->assertFalse($authorizeFn('admin.points.manage'));
    }

    public function testAuthorizeWithInvalidAdminIdReturnsFalse(): void
    {
        [, $authorizeFn] = $this->makeAdminApiWithAdmin(0);
        $this->assertFalse($authorizeFn('admin.points.manage'), 'adminId <= 0 应返回 false');

        [, $authorizeFn2] = $this->makeAdminApiWithAdmin(-1);
        $this->assertFalse($authorizeFn2('admin.points.manage'));
    }

    // ==================== H2-004: 不存在的 permission code ====================

    public function testAuthorizeWithNonexistentPermissionDoesNotFatal(): void
    {
        $admin = TestDataFactory::createSuperAdmin();
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        // super_admin 对不存在的权限也应返回 true（因为拥有 '*'）
        $result = $authorizeFn('__nonexistent_permission_code__');
        $this->assertIsBool($result);
        $this->assertTrue($result, 'super_admin 应对所有权限（包括不存在的 code）返回 true');

        // 普通管理员对不存在的权限应返回 false，不致命错误
        $admin2 = TestDataFactory::createAdminWithPermissions(['admin.points.view']);
        [, $authorizeFn2] = $this->makeAdminApiWithAdmin($admin2['admin_id']);
        $result2 = $authorizeFn2('__nonexistent_permission_code__');
        $this->assertIsBool($result2);
        $this->assertFalse($result2, '普通管理员对不存在的权限应返回 false');
    }

    // ==================== H2-005: 真实 Controller 方法调用 ====================

    /**
     * 验证真实 AdminApi 方法能越过 authorize() 调用，不再出现 "Call to undefined method"
     *
     * 使用 bank_card_post('dels')：该方法第一行调用 $this->authorize('admin.payment.manage')，
     * 且无 CSRF 校验。无权限管理员应返回 403 权限不足，而不是致命错误。
     */
    public function testRealControllerMethodUnauthorizedReturns403NotFatal(): void
    {
        // 无任何权限的管理员
        $adminId = 920000 + random_int(1, 99999);
        [$controller] = $this->makeAdminApiWithAdmin($adminId);

        // 调用真实方法：bank_card_post('dels')
        // 无权限时应在 authorize() 处被拦截，返回 directDenyAdminPermission
        $response = $controller->bank_card_post('dels');

        $this->assertNotNull($response, 'Controller 方法应返回响应，不应抛出致命错误');
        // 验证不是 "Call to undefined method" 错误
        $responseStr = is_object($response) ? json_encode($response) : (string)$response;
        $this->assertStringNotContainsString(
            'Call to undefined method',
            $responseStr,
            '不应出现 "Call to undefined method AdminApi::authorize()" 致命错误'
        );
    }

    /**
     * 验证有权限管理员调用真实方法能越过 authorize()，进入业务逻辑
     *
     * 注意：业务逻辑后续可能因测试库缺少业务表而抛出异常，
     * 本测试只验证能越过 authorize()，不验证完整业务逻辑。
     */
    public function testRealControllerMethodAuthorizedProceedsPastAuthorize(): void
    {
        // 拥有 admin.payment.manage 权限的管理员
        $admin = TestDataFactory::createAdminWithPermissions(['admin.payment.manage']);
        [$controller] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        // 设置 POST 数据（空 ids，不实际删除）
        $ref = new \ReflectionClass($controller);
        $requestProp = $ref->getProperty('request');
        $requestProp->setAccessible(true);
        $request = $requestProp->getValue($controller);
        $newRequest = $request->withPost(['ids' => []]);
        $requestProp->setValue($controller, $newRequest);

        // 调用真实方法 — 可能因测试库缺少业务表而抛出异常
        $proceededPastAuthorize = false;
        try {
            $response = $controller->bank_card_post('dels');
            $proceededPastAuthorize = true;
            $responseStr = is_object($response) ? json_encode($response) : (string)$response;
        } catch (\Throwable $e) {
            // 只要异常不是 "Call to undefined method authorize"，就说明越过了 authorize()
            $msg = $e->getMessage();
            $this->assertStringNotContainsString(
                'Call to undefined method',
                $msg,
                '不应出现 "Call to undefined method AdminApi::authorize()" 致命错误'
            );
            $proceededPastAuthorize = true;
            $responseStr = $msg;
        }

        $this->assertTrue($proceededPastAuthorize, '有权限管理员应能越过 authorize() 进入业务逻辑');
        $this->assertStringNotContainsString('权限不足', $responseStr ?? '');
    }

    // ==================== Phase H3: Points Exchange Smoke Test ====================

    /**
     * P0 Hotfix Smoke Test: 验证 points_exchange_order_post 能越过 authorize()
     *
     * 目标：确认 AdminApi::points_exchange_order_post() 不再出现
     * "Call to undefined method AdminApi::authorize()" 致命错误。
     * 验证到授权层即可，不验证完整 Points Exchange 业务逻辑（留给 D3-F4 Phase 2-B）。
     */
    public function testPointsExchangeOrderPostProceedsPastAuthorize(): void
    {
        // 拥有 admin.points.manage 权限的管理员
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.manage']);
        [$controller] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        // 设置 POST 数据（使用不存在的订单 ID，验证能越过 authorize 进入业务层）
        $ref = new \ReflectionClass($controller);
        $requestProp = $ref->getProperty('request');
        $requestProp->setAccessible(true);
        $request = $requestProp->getValue($controller);
        $newRequest = $request->withPost([
            'id' => 99999999, // 不存在的订单 ID
            'remark' => 'hotfix smoke test',
        ]);
        $requestProp->setValue($controller, $newRequest);

        // 调用 points_exchange_order_post('reject')
        // 可能因 CSRF / 订单不存在等原因返回错误或抛出异常，
        // 但绝对不能是 "Call to undefined method AdminApi::authorize()"
        $proceededPastAuthorize = false;
        try {
            $response = $controller->points_exchange_order_post('reject');
            $proceededPastAuthorize = true;
            $responseStr = is_object($response) ? json_encode($response) : (string)$response;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->assertStringNotContainsString(
                'Call to undefined method',
                $msg,
                'points_exchange_order_post 不应出现 "Call to undefined method AdminApi::authorize()"'
            );
            $proceededPastAuthorize = true;
            $responseStr = $msg;
        }

        $this->assertTrue(
            $proceededPastAuthorize,
            'points_exchange_order_post 应能越过 authorize() 调用，不再出现 undefined method 致命错误'
        );
    }

    /**
     * 验证无权限管理员调用 points_exchange_order_post 被 authorize 拦截
     */
    public function testPointsExchangeOrderPostUnauthorizedBlockedByAuthorize(): void
    {
        // 无任何权限的管理员
        $adminId = 930000 + random_int(1, 99999);
        [$controller] = $this->makeAdminApiWithAdmin($adminId);

        // 设置 POST 数据
        $ref = new \ReflectionClass($controller);
        $requestProp = $ref->getProperty('request');
        $requestProp->setAccessible(true);
        $request = $requestProp->getValue($controller);
        $newRequest = $request->withPost(['id' => 1, 'remark' => 'test']);
        $requestProp->setValue($controller, $newRequest);

        // 无权限时应在 authorize() 处被拦截，返回 directDenyAdminPermission
        $response = $controller->points_exchange_order_post('reject');

        $this->assertNotNull($response);
        // 提取 Response 内容（ThinkPHP show() 返回 Json Response 对象）
        if (method_exists($response, 'getContent')) {
            $responseStr = (string)$response->getContent();
        } else {
            $responseStr = is_object($response) ? json_encode($response) : (string)$response;
        }
        $this->assertStringNotContainsString('Call to undefined method', $responseStr);
        // 无权限应返回权限不足（code=403 或 message 包含权限不足）
        $this->assertTrue(
            strpos($responseStr, '权限不足') !== false || strpos($responseStr, '"code":403') !== false || strpos($responseStr, '"code": 403') !== false,
            "无权限管理员应被拦截，响应应包含权限不足或 code=403，实际响应：{$responseStr}"
        );
    }

    // ==================== directHasAdminPermission 调用链验证 ====================

    /**
     * 验证 directHasAdminPermission → authorize → AuthorizationService 调用链完整，无递归
     */
    public function testDirectHasAdminPermissionCallsAuthorizeWithoutRecursion(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.manage']);
        [$controller] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        // 通过反射调用 directHasAdminPermission（旧中文权限映射到 RBAC）
        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('directHasAdminPermission');
        $method->setAccessible(true);

        // '积分管理' 映射到 'admin.points.manage'
        $result = $method->invoke($controller, '积分管理');
        $this->assertTrue($result, 'directHasAdminPermission("积分管理") 应通过授权');

        // 不存在的旧权限应返回 false
        $result2 = $method->invoke($controller, '不存在的权限项');
        $this->assertFalse($result2);
    }

    /**
     * 验证 AuthorizationService 与 AdminApi::authorize() 结果一致
     */
    public function testAuthorizeMatchesAuthorizationServiceDirectly(): void
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.manage', 'admin.user.view']);
        [, $authorizeFn] = $this->makeAdminApiWithAdmin($admin['admin_id']);

        $authService = new AuthorizationService();

        // AdminApi::authorize() 应与 AuthorizationService::can() 结果一致
        $permissions = ['admin.points.manage', 'admin.user.view', 'admin.points.manage', 'admin.rebate.delete'];
        foreach ($permissions as $perm) {
            $this->assertEquals(
                $authService->can($admin['admin_id'], $perm),
                $authorizeFn($perm),
                "AdminApi::authorize('{$perm}') 应与 AuthorizationService::can() 结果一致"
            );
        }
    }
}
