<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\admin\PointsExchangeOrders as PointsExchangeOrdersController;
use tests\Support\TestDataFactory;
use think\facade\Db;
use think\facade\Session;

/**
 * HCZ B10-21: points_exchange_orders_json 只读查询迁移测试（admin\PointsExchangeOrders）
 *
 * 验证范围（与 B10-18 Design / B10-20 Preflight Contract 一致）：
 *  - RBAC：有 admin.points.view → ALLOW；无 → DENY（'权限不足'）
 *  - 参数：page / limit / status / keyword（默认值 + 边界 + 白名单）
 *  - status 语义：0 / 1 / 2 有效；非法值忽略（保持 OLD）
 *  - keyword 语义：item_title LIKE / 数字 uid / matched user uid IN / 无命中
 *  - Pagination：total / page / limit / list（id desc）/ 越界空 list
 *  - Response：list/total/page/limit + 行字段白名单
 *  - OLD/NEW 双路径等价：points_exchange_orders_json（OLD forwarder）vs admin/points-exchange-orders（NEW）
 *  - Data Safety：查询前后记录数不变（纯只读，无 INSERT/UPDATE/DELETE）
 *
 * 测试隔离（遵守 B10-20/21 红线）：
 *  - 本测试【零 DDL】：不执行任何 CREATE/ALTER/DROP/TRUNCATE；
 *    cz_points_exchange_order 由 B10-13 正式 migration（2024_01_08_000001）保证存在，
 *    cz_admin / cz_user 为既有表。
 *  - fixture 事务隔离（beginTransaction/rollback），无 DDL → 无 MySQL implicit commit 风险。
 *  - 禁止调用 PointsActions::ensurePointsExchangeOrderTable()（含 DDL）。
 *
 * 环境限制（如实标注，不伪造 HTTP PASS）：
 *  - CLI direct-controller 测试无法观察真实 HTTP middleware invocation count
 *    → AdminAuth runtime exactly-once = NOT VERIFIED — ENVIRONMENT / TEST HARNESS LIMITATION
 *  - 真实 HTTP route resolution（backstage_entrance 前缀）无法在 CLI 验证 → NOT VERIFIED
 */
class PointsExchangeOrdersAdminActionTest extends DbTestCase
{
    /** points_exchange_orders_json 的权限码（与 OLD 一致，保持不变） */
    private const PERM = 'admin.points.view';

    private const USER_A = 810001; // 13812345678 / 测试用户A
    private const USER_B = 810002; // 13912345678 / 测试用户B

    protected function setUp(): void
    {
        parent::setUp(); // DbTestCase 保证测试数据库可用

        // CLI 下 Request::session 属性为 null：全局注入 Session（app('request') 为单例）
        app()->request->withSession(app('session'));

        // 事务隔离：测试数据随 rollback 回滚（本测试零 DDL，无 implicit commit，回滚可靠）
        $this->beginTransaction();

        // Test Harness 隔离（B10-21 独立发现，非生产缺陷）：
        // ThinkPHP 容器 app(PointsExchangeOrders::class) 缓存 controller 实例（CLI 单进程下为进程级单例，
        // 生产 PHP-FPM 每次请求 new App() 重建容器，无此残留）。BaseController::$adminIdentity 为实例级惰性缓存，
        // 前序测试通过 forwarder 调用后会残留 adminIdentity 快照，导致后续测试误用前序 admin 身份。
        // 每次测试开始前重置容器实例的 adminIdentity，保证 OLD forwarder 路径按当前 Session 判定授权。
        $this->resetContainerAdminIdentity();
    }

    /**
     * 重置容器缓存的 PointsExchangeOrders 实例的 adminIdentity（Test Harness 隔离）
     */
    private function resetContainerAdminIdentity(): void
    {
        $instance = app(\app\controller\admin\PointsExchangeOrders::class);
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

    /** 种子测试数据：用户 + 积分兑换订单（事务回滚隔离） */
    private function seedExchangeOrdersData(): void
    {
        foreach ([
            [self::USER_A, '13812345678', '测试用户A', 'inv810001'],
            [self::USER_B, '13912345678', '测试用户B', 'inv810002'],
        ] as [$uid, $mobile, $nickname, $invite]) {
            Db::name('user')->insert([
                'id' => $uid,
                'mobile' => $mobile,
                'password' => 'x',
                'salt' => 'x',
                'nickname' => $nickname,
                'invite_code' => $invite,
                'trc20' => '',
                'id_card' => '',
                'token' => '',
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        foreach ([
            [830001, self::USER_A, 'a1', 'coupon', '话费充值卡', 100, 0, '2026-08-01 10:00:00'],
            [830002, self::USER_A, 'a2', 'coupon', '京东E卡', 200, 1, '2026-08-02 10:00:00'],
            [830003, self::USER_B, 'a3', 'coupon', '手机壳', 50, 2, '2026-08-03 10:00:00'],
            [830004, self::USER_B, 'a4', 'coupon', '话费券', 30, 0, '2026-08-04 10:00:00'],
        ] as [$id, $uid, $itemId, $itemType, $itemTitle, $points, $status, $time]) {
            Db::name('points_exchange_order')->insert([
                'id' => $id,
                'uid' => $uid,
                'item_id' => $itemId,
                'item_type' => $itemType,
                'item_title' => $itemTitle,
                'points' => $points,
                'status' => $status,
                'remark' => '',
                'create_time' => $time,
                'update_time' => $time,
            ]);
        }
    }

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

    private function makeController(array $get, string $path): PointsExchangeOrdersController
    {
        $ref = new \ReflectionClass(PointsExchangeOrdersController::class);
        $ctrl = $ref->newInstanceWithoutConstructor();

        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());

        $request = new \think\Request();
        $request->withSession(app('session'))->withGet($get);
        $pathProp = new \ReflectionProperty(\think\Request::class, 'pathinfo');
        $pathProp->setAccessible(true);
        $pathProp->setValue($request, trim($path, '/'));

        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, $request);

        return $ctrl;
    }

    private function sessionLogin(int $adminId): void
    {
        Session::set('admin', [
            'id' => $adminId,
            'account' => 'test_admin_' . $adminId,
            'name' => '测试管理员',
        ]);
    }

    private function parseResponse($response): array
    {
        $content = method_exists($response, 'getContent') ? (string)$response->getContent() : json_encode($response);
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['code' => null, 'message' => $content];
    }

    /** 直接调用新 Controller（PATH B：NEW route → PointsExchangeOrders） */
    private function callGet(int $adminId, array $get, string $path): array
    {
        $this->sessionLogin($adminId);
        $this->seedAdmin($adminId);
        TestDataFactory::createAdminWithPermissions([self::PERM], $adminId);
        $ctrl = $this->makeController($get, $path);
        return $this->parseResponse($ctrl->points_exchange_orders_json());
    }

    /** OLD forwarder 调用（PATH A：OLD route → AdminApi → PointsExchangeOrders） */
    private function callOldGet(int $adminId, array $get): array
    {
        $this->sessionLogin($adminId);
        $this->seedAdmin($adminId);
        TestDataFactory::createAdminWithPermissions([self::PERM], $adminId);
        app()->request->withGet($get);
        $ref = new \ReflectionClass(\app\controller\AdminApi::class);
        $ctrl = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());
        $request = new \think\Request();
        $request->withSession(app('session'))->withGet($get);
        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, $request);
        return $this->parseResponse($ctrl->points_exchange_orders_json());
    }

    // ==================== T1: Security / RBAC ====================

    public function testRbacWithViewPermissionAllowedNew(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810101, [], 'admin/points-exchange-orders');
        $this->assertSame(200, $res['code'] ?? null, 'NEW 有 admin.points.view 应 ALLOW');
    }

    public function testRbacWithViewPermissionAllowedOld(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callOldGet(810101, []);
        $this->assertSame(200, $res['code'] ?? null, 'OLD 有 admin.points.view 应 ALLOW');
    }

    public function testRbacWithoutViewPermissionDeniedNew(): void
    {
        $this->seedExchangeOrdersData();
        $this->sessionLogin(810102);
        $this->seedAdmin(810102);
        TestDataFactory::createAdminWithPermissions(['admin.user.manage'], 810102);
        $ctrl = $this->makeController([], 'admin/points-exchange-orders');
        $res = $this->parseResponse($ctrl->points_exchange_orders_json());
        $this->assertSame('权限不足', $res['message'] ?? '', 'NEW 无 view 权限应 DENY');
    }

    public function testRbacWithoutViewPermissionDeniedOld(): void
    {
        $this->seedExchangeOrdersData();
        $this->sessionLogin(810102);
        $this->seedAdmin(810102);
        TestDataFactory::createAdminWithPermissions(['admin.user.manage'], 810102);
        $ref = new \ReflectionClass(\app\controller\AdminApi::class);
        $ctrl = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('app');
        $p->setAccessible(true);
        $p->setValue($ctrl, app());
        $request = new \think\Request();
        $request->withSession(app('session'))->withGet([]);
        $p = $ref->getProperty('request');
        $p->setAccessible(true);
        $p->setValue($ctrl, $request);
        $res = $this->parseResponse($ctrl->points_exchange_orders_json());
        $this->assertSame('权限不足', $res['message'] ?? '', 'OLD 无 view 权限应 DENY');
    }

    // ==================== T5: Middleware 静态声明 ====================

    public function testMiddlewareDeclaration(): void
    {
        $ref = new \ReflectionClass(PointsExchangeOrdersController::class);
        $prop = $ref->getProperty('middleware');
        $prop->setAccessible(true);
        $middleware = $prop->getValue($ref->newInstanceWithoutConstructor());
        $this->assertSame([\app\middleware\AdminAuth::class], $middleware, 'Controller 必须显式声明 AdminAuth');
    }

    // ==================== T6: Parameters ====================

    public function testDefaultPagination(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810103, [], 'admin/points-exchange-orders');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame(1, $res['data']['page'] ?? null, 'page 默认 1');
        $this->assertSame(20, $res['data']['limit'] ?? null, 'limit 默认 20');
        $this->assertSame(4, $res['data']['total'] ?? null, 'total=4');
    }

    public function testLimitClamp(): void
    {
        $this->seedExchangeOrdersData();
        $r0 = $this->callGet(810103, ['limit' => '0'], 'admin/points-exchange-orders');
        $this->assertSame(1, $r0['data']['limit'] ?? null, 'limit<=0 → 1');
        $r1 = $this->callGet(810103, ['limit' => '101'], 'admin/points-exchange-orders');
        $this->assertSame(100, $r1['data']['limit'] ?? null, 'limit>100 → 100');
        $r2 = $this->callGet(810103, ['page' => '0'], 'admin/points-exchange-orders');
        $this->assertSame(1, $r2['data']['page'] ?? null, 'page<=0 → 1');
    }

    public function testPageOutOfRange(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810103, ['page' => '99', 'limit' => '20'], 'admin/points-exchange-orders');
        $this->assertSame(200, $res['code'] ?? null, '越界 page 仍 200');
        $this->assertSame([], $res['data']['list'] ?? null, '越界 page 空 list');
        $this->assertSame(4, $res['data']['total'] ?? null, 'total 保持 4');
    }

    public function testStatusFilter(): void
    {
        $this->seedExchangeOrdersData();
        $s0 = $this->callGet(810103, ['status' => '0'], 'admin/points-exchange-orders');
        $this->assertCount(2, $s0['data']['list'] ?? [], 'status=0 → 2 条');
        $s1 = $this->callGet(810103, ['status' => '1'], 'admin/points-exchange-orders');
        $this->assertCount(1, $s1['data']['list'] ?? [], 'status=1 → 1 条');
        $s2 = $this->callGet(810103, ['status' => '2'], 'admin/points-exchange-orders');
        $this->assertCount(1, $s2['data']['list'] ?? [], 'status=2 → 1 条');
        $bad = $this->callGet(810103, ['status' => '9'], 'admin/points-exchange-orders');
        $this->assertCount(4, $bad['data']['list'] ?? [], '非法 status 忽略 → 4 条');
    }

    public function testKeywordFilter(): void
    {
        $this->seedExchangeOrdersData();
        $kw1 = $this->callGet(810103, ['keyword' => '话费'], 'admin/points-exchange-orders');
        $this->assertCount(2, $kw1['data']['list'] ?? [], 'item_title LIKE → 2 条');
        $kw2 = $this->callGet(810103, ['keyword' => '810001'], 'admin/points-exchange-orders');
        $this->assertCount(2, $kw2['data']['list'] ?? [], '数字 uid 精确 → 2 条');
        $kw3 = $this->callGet(810103, ['keyword' => '测试用户A'], 'admin/points-exchange-orders');
        $this->assertCount(2, $kw3['data']['list'] ?? [], 'nickname → 2 条');
        $kw4 = $this->callGet(810103, ['keyword' => '不存在xyz'], 'admin/points-exchange-orders');
        $this->assertCount(0, $kw4['data']['list'] ?? [], '无命中 → 0 条');
        $kw5 = $this->callGet(810103, ['keyword' => '   '], 'admin/points-exchange-orders');
        $this->assertCount(4, $kw5['data']['list'] ?? [], '空白 keyword 跳过 → 4 条');
    }

    // ==================== Ordering / Response ====================

    public function testOrderingIdDesc(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810103, [], 'admin/points-exchange-orders');
        $list = $res['data']['list'] ?? [];
        $this->assertSame(830004, $list[0]['id'] ?? null, '首行 id desc');
        $this->assertSame(830001, $list[3]['id'] ?? null, '末行 id desc');
    }

    public function testResponseFieldWhitelist(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810103, [], 'admin/points-exchange-orders');
        $row = $res['data']['list'][0] ?? [];
        $expected = ['create_time', 'id', 'item_id', 'item_title', 'item_type', 'mobile', 'nickname', 'points', 'remark', 'status', 'uid'];
        $keys = array_keys($row);
        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys, '行字段必须为 OLD 白名单 11 字段');
        // 不得泄露敏感字段
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('salt', $row);
        $this->assertArrayNotHasKey('token', $row);
        $this->assertArrayNotHasKey('twofa_secret', $row);
    }

    // ==================== T7: User Mapping ====================

    public function testUserMapping(): void
    {
        $this->seedExchangeOrdersData();
        $res = $this->callGet(810103, [], 'admin/points-exchange-orders');
        $row = $res['data']['list'][0] ?? [];
        $this->assertSame('13912345678', $row['mobile'] ?? '', 'mobile 映射');
        $this->assertSame('测试用户B', $row['nickname'] ?? '', 'nickname 映射');
    }

    // ==================== T8: OLD / NEW 双路等价 ====================

    public function testOldNewEquivalence(): void
    {
        $this->seedExchangeOrdersData();
        $get = ['page' => 1, 'limit' => 20, 'status' => '', 'keyword' => ''];
        $old = $this->callOldGet(810104, $get);
        $new = $this->callGet(810104, $get, 'admin/points-exchange-orders');

        $this->assertSame($old['code'] ?? null, $new['code'] ?? null, 'code 等价');
        $this->assertSame($old['message'] ?? '', $new['message'] ?? '', 'message 等价');
        $this->assertSame($old['data']['total'] ?? null, $new['data']['total'] ?? null, 'total 等价');
        $this->assertSame($old['data']['page'] ?? null, $new['data']['page'] ?? null, 'page 等价');
        $this->assertSame($old['data']['limit'] ?? null, $new['data']['limit'] ?? null, 'limit 等价');
        $this->assertSame($old['data']['list'] ?? null, $new['data']['list'] ?? null, 'list 等价');
    }

    // ==================== Data Safety ====================

    public function testDataSafetyReadOnly(): void
    {
        $this->seedExchangeOrdersData();
        $before = (int)Db::name('points_exchange_order')->count();
        $this->callGet(810105, [], 'admin/points-exchange-orders');
        $this->callOldGet(810105, []);
        $after = (int)Db::name('points_exchange_order')->count();
        $this->assertSame($before, $after, '查询前后记录数不变（纯只读，无 INSERT/UPDATE/DELETE）');
        // 说明：事务内种子数据（830001-830004）由 tearDown() rollback 负责清理，此处仅验证业务查询不改变数据；
        // 事务回滚后无残留由 DbTestCase 事务机制保证（本测试零 DDL，无 implicit commit）。
    }
}
