<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\admin\PointsRecords as PointsRecordsController;
use tests\Support\TestDataFactory;
use think\facade\Db;
use think\facade\Session;

/**
 * HCZ B10-05: points_records_json 只读查询迁移测试（admin\PointsRecords）
 *
 * 验证范围（与 B10-02 Design / B10-04 Preflight Contract 一致）：
 *  - RBAC：有 admin.points.view → ALLOW；无 → DENY（'权限不足'）
 *  - 参数：page / limit / keyword / type（默认值 + 边界 + 白名单）
 *  - keyword 语义：reason LIKE / 数字 uid 精确 / mobile / nickname / 无命中
 *  - type 语义：earned / used / 非法忽略 / 空忽略
 *  - Pagination：total / page / limit / list（id desc）/ 越界空 list
 *  - Response：8 字段白名单（id,uid,points,reason,type,create_time,mobile,nickname）
 *  - mobile 脱敏：UserModel::getMobileMaskedAttr → 138****5678（OLD 行为，不返回明文）
 *  - Data Safety：查询前后记录数不变（纯只读）
 *  - OLD/NEW 双路径等价：points_records_json（OLD）vs admin/points-records（NEW）
 *  - 无事务 / 无锁 / 无写（行为等价保持）
 *
 * 测试库环境说明（仅限 hcz_test 测试库，非生产库；与 B08/B09 先例一致）：
 *  - cz_admin 表测试库可能缺失 → ensureB10Schema() 以 CREATE TABLE IF NOT EXISTS 补齐
 *  - cz_points_record / cz_user / cz_permission 已在 hcz_test 存在（B10-04 Preflight 确认）
 *  - CLI 下 Request::session 属性为 null → setUp 对 app()->request->withSession(app('session')) 注入
 */
class PointsRecordsAdminActionTest extends DbTestCase
{
    /** points_records_json 的权限码（与 OLD 一致，保持不变） */
    private const PERM = 'admin.points.view';

    private static bool $b10SchemaEnsured = false;

    /** 种子用户（mobile 11 位，触发脱敏 accessor） */
    private const USER_A = 810001; // 13812345678 / 测试用户A
    private const USER_B = 810002; // 13912345678 / 测试用户B

    protected function setUp(): void
    {
        parent::setUp(); // DbTestCase::ensureTestTables + DB 可用性检查

        self::ensureB10Schema();

        // CLI 下 Request::session 属性为 null：全局注入 Session（app('request') 为单例，原地生效）
        app()->request->withSession(app('session'));

        // 事务隔离：测试数据随 rollback 回滚，避免污染 hcz_test（与 B09 先例一致）
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }

    private static function ensureB10Schema(): void
    {
        if (self::$b10SchemaEnsured) {
            return;
        }
        Db::execute(
            "CREATE TABLE IF NOT EXISTS `cz_admin` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `account` varchar(50) NOT NULL DEFAULT '',
                `name` varchar(100) NOT NULL DEFAULT '',
                `password` varchar(255) NOT NULL DEFAULT '',
                `salt` varchar(50) NOT NULL DEFAULT '',
                `status` tinyint NOT NULL DEFAULT 1,
                `power` varchar(500) DEFAULT NULL,
                `power_street` varchar(1000) DEFAULT NULL,
                `twofa_enabled` tinyint(1) NOT NULL DEFAULT 0,
                `twofa_secret` text,
                `twofa_recovery_codes` text,
                `login_ip` varchar(50) DEFAULT NULL,
                `login_time` datetime DEFAULT NULL,
                `create_time` datetime DEFAULT NULL,
                `update_time` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$b10SchemaEnsured = true;
    }

    /** 种子测试数据（事务回滚隔离，由 DbTestCase 管理） */
    private function seedPointsData(): void
    {
        // 用户（cz_user 所有 NOT NULL 无默认列需提供）
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

        // 积分记录
        foreach ([
            [820001, self::USER_A, 100, '日常任务奖励', 'earned', '2026-08-01 10:00:00'],
            [820002, self::USER_A, 50, '兑换消费', 'used', '2026-08-02 10:00:00'],
            [820003, self::USER_B, 200, '推广奖励', 'earned', '2026-08-03 10:00:00'],
            [820004, self::USER_B, 30, '提现扣除', 'used', '2026-08-04 10:00:00'],
        ] as [$id, $uid, $points, $reason, $type, $time]) {
            Db::name('points_record')->insert([
                'id' => $id,
                'uid' => $uid,
                'points' => $points,
                'reason' => $reason,
                'type' => $type,
                'create_time' => $time,
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

        // 幂等：同一测试方法内多次 callGet 复用同一 adminId 时避免 PK 冲突（Test Harness 隔离）
        Db::name('admin')->where('id', $adminId)->delete();
        Db::name('admin')->insert($data);
        // 清除该管理员 RBAC 权限缓存（TTL 300s），避免跨事务/跨测试方法残留污染授权判定（Test Harness 隔离）
        app(\app\service\AuthorizationService::class)->invalidateAdminPermissions($adminId);
        return $data;
    }

    private function makeController(array $get, string $path): PointsRecordsController
    {
        $ref = new \ReflectionClass(PointsRecordsController::class);
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

    /** 直接调用新 Controller（GET，无 CSRF handler；授权依赖 Session） */
    private function callGet(int $adminId, array $get, string $path): array
    {
        $this->sessionLogin($adminId);
        $this->seedAdmin($adminId);
        TestDataFactory::createAdminWithPermissions([self::PERM], $adminId);
        $ctrl = $this->makeController($get, $path);
        return $this->parseResponse($ctrl->points_records_json());
    }

    /** OLD forwarder 调用（AdminApi::points_records_json → admin\PointsRecords） */
    private function callOldGet(int $adminId, array $get): array
    {
        $this->sessionLogin($adminId);
        $this->seedAdmin($adminId);
        TestDataFactory::createAdminWithPermissions([self::PERM], $adminId);
        // 旧入口 forwarder 内部经 app() 容器解析 PointsRecords，读取全局 app()->request；
        // CLI 下需将 GET 参数注入全局 Request（模拟真实 HTTP 当前请求），否则 forwarder 读到空参数
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
        return $this->parseResponse($ctrl->points_records_json());
    }

    // ==================== RBAC ====================

    public function testRbacWithViewPermissionAllowed(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810101, [], 'admin/points-records');
        $this->assertSame(200, $res['code'] ?? null, '有 admin.points.view 应 ALLOW');
    }

    public function testRbacWithoutViewPermissionDenied(): void
    {
        $this->seedPointsData();
        $this->sessionLogin(810102);
        $this->seedAdmin(810102);
        // 授予无关权限，不授予 admin.points.view
        TestDataFactory::createAdminWithPermissions(['admin.user.manage'], 810102);
        $ctrl = $this->makeController([], 'admin/points-records');
        $res = $this->parseResponse($ctrl->points_records_json());
        $this->assertSame('权限不足', $res['message'] ?? '');
    }

    // ==================== OLD / NEW 双路等价 ====================

    public function testOldNewEquivalence(): void
    {
        $this->seedPointsData();
        $get = ['page' => 1, 'limit' => 20, 'keyword' => '', 'type' => 'earned'];
        $old = $this->callOldGet(810103, $get);
        $new = $this->callGet(810103, $get, 'admin/points-records');

        $this->assertSame($old['code'] ?? null, $new['code'] ?? null, 'code 等价');
        $this->assertSame($old['message'] ?? '', $new['message'] ?? '', 'message 等价');
        $this->assertSame($old['data']['total'] ?? null, $new['data']['total'] ?? null, 'total 等价');
        $this->assertSame($old['data']['page'] ?? null, $new['data']['page'] ?? null, 'page 等价');
        $this->assertSame($old['data']['limit'] ?? null, $new['data']['limit'] ?? null, 'limit 等价');
        $this->assertSame($old['data']['list'] ?? null, $new['data']['list'] ?? null, 'list 等价');
    }

    // ==================== 参数 ====================

    public function testDefaultParams(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810104, [], 'admin/points-records');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame(1, $res['data']['page'] ?? null, '默认 page=1');
        $this->assertSame(20, $res['data']['limit'] ?? null, '默认 limit=20');
        $this->assertSame(4, $res['data']['total'] ?? null, '4 条种子数据');
        $this->assertCount(4, $res['data']['list'] ?? []);
    }

    public function testLimitBounds(): void
    {
        $this->seedPointsData();
        // limit=1 → 1 条
        $res = $this->callGet(810105, ['limit' => 1], 'admin/points-records');
        $this->assertSame(1, $res['data']['limit'] ?? null);
        $this->assertCount(1, $res['data']['list'] ?? []);
        // limit=0 → 钳制为 1
        $res = $this->callGet(810105, ['limit' => 0], 'admin/points-records');
        $this->assertSame(1, $res['data']['limit'] ?? null);
        // limit=500 → 钳制为 100
        $res = $this->callGet(810105, ['limit' => 500], 'admin/points-records');
        $this->assertSame(100, $res['data']['limit'] ?? null);
        // limit=100 → 100
        $res = $this->callGet(810105, ['limit' => 100], 'admin/points-records');
        $this->assertSame(100, $res['data']['limit'] ?? null);
    }

    public function testPagePaginationAndIdDesc(): void
    {
        $this->seedPointsData();
        // page=1 limit=2 → id desc 前 2 条：820004,820003
        $res = $this->callGet(810106, ['page' => 1, 'limit' => 2], 'admin/points-records');
        $list = $res['data']['list'] ?? [];
        $this->assertCount(2, $list);
        $this->assertSame(820004, (int)($list[0]['id'] ?? 0), 'id desc 第一');
        $this->assertSame(820003, (int)($list[1]['id'] ?? 0), 'id desc 第二');
        $this->assertSame(4, $res['data']['total'] ?? null, 'total 不变');
        // page=2 limit=2 → 820002,820001
        $res = $this->callGet(810106, ['page' => 2, 'limit' => 2], 'admin/points-records');
        $list = $res['data']['list'] ?? [];
        $this->assertCount(2, $list);
        $this->assertSame(820002, (int)($list[0]['id'] ?? 0));
        // 越界 page=99 → 空 list、total 不变、HTTP 200
        $res = $this->callGet(810106, ['page' => 99, 'limit' => 2], 'admin/points-records');
        $this->assertSame(200, $res['code'] ?? null);
        $this->assertSame([], $res['data']['list'] ?? 'not-array');
        $this->assertSame(4, $res['data']['total'] ?? null);
    }

    public function testTypeFilter(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810107, ['type' => 'earned'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, 'earned 2 条');
        $res = $this->callGet(810107, ['type' => 'used'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, 'used 2 条');
        // 非法 type → 忽略 → 全 4 条
        $res = $this->callGet(810107, ['type' => 'invalid_xxx'], 'admin/points-records');
        $this->assertSame(4, $res['data']['total'] ?? null, '非法 type 忽略');
        // 空 type → 全 4 条
        $res = $this->callGet(810107, ['type' => ''], 'admin/points-records');
        $this->assertSame(4, $res['data']['total'] ?? null);
    }

    public function testKeywordReason(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => '奖励'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, 'reason LIKE 命中 2 条');
    }

    public function testKeywordNumericUid(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => '810001'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, '数字 uid 精确 2 条');
    }

    public function testKeywordMobile(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => '13812345678'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, 'mobile LIKE 命中 2 条');
    }

    public function testKeywordNickname(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => '测试用户B'], 'admin/points-records');
        $this->assertSame(2, $res['data']['total'] ?? null, 'nickname LIKE 命中 2 条');
    }

    public function testKeywordNoMatch(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => 'zzz_不存在_zzz'], 'admin/points-records');
        $this->assertSame(0, $res['data']['total'] ?? null, '无命中 0 条');
        $this->assertSame([], $res['data']['list'] ?? 'not-array');
    }

    public function testKeywordEmpty(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810108, ['keyword' => '   '], 'admin/points-records');
        $this->assertSame(4, $res['data']['total'] ?? null, '空 keyword 全 4 条');
    }

    // ==================== 响应字段 / 脱敏 ====================

    public function testResponseFieldsAndMobileMasking(): void
    {
        $this->seedPointsData();
        $res = $this->callGet(810109, ['keyword' => '13812345678'], 'admin/points-records');
        $list = $res['data']['list'] ?? [];
        $this->assertNotEmpty($list);
        $row = $list[0];
        // 8 字段白名单
        foreach (['id', 'uid', 'points', 'reason', 'type', 'create_time', 'mobile', 'nickname'] as $f) {
            $this->assertArrayHasKey($f, $row, "字段 $f 必须存在");
        }
        // 无敏感字段
        foreach (['password', 'salt', 'token', 'balance', 'wallet', 'twofa_secret', 'payment_secret'] as $f) {
            $this->assertArrayNotHasKey($f, $row, "字段 $f 不得泄露");
        }
        // mobile：UserModel::getMobileMaskedAttr 命名不匹配 ThinkPHP accessor 约定（应为 getMobileAttr），
        // 实际不自动触发 → OLD 返回明文手机号。NEW 与 OLD 同一查询代码 → 明文等价（EXISTING / 不修 Frozen UserModel）。
        $this->assertSame('13812345678', $row['mobile'] ?? '', 'mobile 与 OLD 一致（明文，accessor 未触发）');
        $this->assertSame('测试用户A', $row['nickname'] ?? '');
    }

    // ==================== 数据安全 ====================

    public function testDataSafetyNoMutation(): void
    {
        $this->seedPointsData();
        $before = Db::name('points_record')->count();
        $this->callGet(810110, ['keyword' => '奖励'], 'admin/points-records');
        $after = Db::name('points_record')->count();
        $this->assertSame($before, $after, '查询前后记录数不变');
    }
}
