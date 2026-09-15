<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\RecoveryCase;
use app\model\Recharge;
use app\service\AdminRecoveryApplicationService;
use app\service\AuthorizationService;
use think\facade\Db;

/**
 * HCZ Pre-R1 Batch A5.2a — Admin Recovery API & RBAC Integration Tests
 *
 * 覆盖：
 * - ApplicationService: list/detail/create/approve/reject
 * - Create: uid/amount 从 recharge 派生，不接受客户端输入
 * - Approve: 不产生资金变化（balance/ledger 不变）
 * - Reject: 不产生资金变化
 * - Duplicate create: 同一 recharge 只能创建一个 case
 * - Approve note required: 空备注被拒绝
 * - RBAC: 4 个权限独立存在
 * - 静态验证: Controller/ApplicationService 不引用资金 Service
 * - 静态验证: 路由中无 settle 端点
 *
 * 注意：本轮不测试 Controller HTTP 层（需要完整 HTTP 模拟），
 * Controller 的 RBAC/CSRF 逻辑通过代码审查 + 独立回归验证。
 */
class AdminRecoveryApiTest extends DbTestCase
{
    private AdminRecoveryApplicationService $appService;
    private AuthorizationService $authService;

    private const TEST_ADMIN_ID = 950001;
    private const TEST_UID = 950001;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbAvailable) {
            return;
        }
        $this->beginTransaction();
        $this->appService = new AdminRecoveryApplicationService();
        $this->authService = new AuthorizationService();
    }

    protected function tearDown(): void
    {
        if (self::$dbAvailable) {
            $this->rollback();
        }
        parent::tearDown();
    }

    // ==================== Helpers ====================

    private function createTestRecharge(int $status = Recharge::STATUS_PENDING, string $cancelSource = ''): int
    {
        $orderNo = 'A52A_TEST_' . uniqid();
        Db::name('recharge')->insert([
            'uid' => self::TEST_UID,
            'order_number' => $orderNo,
            'amount' => 100.00,
            'status' => $status,
            'cancel_source' => $cancelSource,
            'gateway' => 'epay',
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        return (int)Db::name('recharge')->where('order_number', $orderNo)->value('id');
    }

    private function getUserBalance(): float
    {
        return (float)Db::name('user')->where('id', self::TEST_UID)->value('balance');
    }

    private function getLedgerCount(): int
    {
        return (int)Db::name('user_fund_log')->where('uid', self::TEST_UID)->count();
    }

    // ==================== RBAC Permissions ====================

    public function testRbacPermissionsExist(): void
    {
        $codes = ['recharge_recovery.view', 'recharge_recovery.create', 'recharge_recovery.review', 'recharge_recovery.settle'];
        foreach ($codes as $code) {
            $exists = Db::name('permission')->where('code', $code)->where('status', 1)->find();
            $this->assertNotNull($exists, "Permission {$code} should exist");
        }
    }

    public function testRbacPermissionsAreIndependent(): void
    {
        // 验证没有自动 implication：普通管理员（无任何角色）不拥有这些权限
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'recharge_recovery.view'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'recharge_recovery.create'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'recharge_recovery.review'));
        $this->assertFalse($this->authService->can(self::TEST_ADMIN_ID, 'recharge_recovery.settle'));
    }

    // ==================== Create ====================

    public function testCreateCaseForUnpaidRecharge(): void
    {
        $rechargeId = $this->createTestRecharge(Recharge::STATUS_PENDING);
        $balanceBefore = $this->getUserBalance();
        $ledgerBefore = $this->getLedgerCount();

        $result = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, '用户声称已付款', 'trade_123');

        $this->assertTrue($result['ok']);
        $this->assertEquals(self::TEST_UID, $result['case']['uid']);
        $this->assertEqualsWithDelta(100.0, (float)$result['case']['amount'], 0.001);
        $this->assertEquals(self::TEST_ADMIN_ID, $result['case']['created_by_admin_id']);
        $this->assertEquals('open', $result['case']['case_status']);

        // 资金不变
        $this->assertEquals($balanceBefore, $this->getUserBalance());
        $this->assertEquals($ledgerBefore, $this->getLedgerCount());
    }

    public function testCreateCaseDerivesUidAndAmountFromRecharge(): void
    {
        $rechargeId = $this->createTestRecharge(Recharge::STATUS_EXPIRED, 'cron');

        // ApplicationService 不接受 uid/amount 参数，只接受 recharge_id
        $result = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');

        $this->assertTrue($result['ok']);
        $this->assertEquals(self::TEST_UID, $result['case']['uid']);
        $this->assertEqualsWithDelta(100.0, (float)$result['case']['amount'], 0.001);
    }

    public function testCreateDuplicateCaseReturnsError(): void
    {
        $rechargeId = $this->createTestRecharge();

        $first = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'first');
        $this->assertTrue($first['ok']);

        $second = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'second');
        $this->assertFalse($second['ok']);
        $this->assertEquals('RECOVERY_CASE_ALREADY_EXISTS', $second['error_code']);

        // 数据库只有一个 case
        $count = Db::name('recharge_recovery_case')->where('recharge_id', $rechargeId)->count();
        $this->assertEquals(1, $count);
    }

    public function testCreateCaseForPaidRechargeRejected(): void
    {
        $rechargeId = $this->createTestRecharge(Recharge::STATUS_PAID);
        $result = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $this->assertFalse($result['ok']);
        $this->assertEquals('RECHARGE_ALREADY_PAID', $result['error_code']);
    }

    public function testCreateCaseForNonexistentRechargeRejected(): void
    {
        $result = $this->appService->create(99999999, self::TEST_ADMIN_ID, 'test');
        $this->assertFalse($result['ok']);
        $this->assertEquals('RECHARGE_NOT_FOUND', $result['error_code']);
    }

    // ==================== Approve ====================

    public function testApproveOpenCase(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $caseId = $create['case']['id'];

        $balanceBefore = $this->getUserBalance();
        $ledgerBefore = $this->getLedgerCount();

        $result = $this->appService->approve($caseId, self::TEST_ADMIN_ID, '证据充分，批准恢复');

        $this->assertTrue($result['ok']);
        $this->assertEquals('approved', $result['case']['case_status']);
        $this->assertEquals(self::TEST_ADMIN_ID, $result['case']['approved_by_admin_id']);
        $this->assertNotNull($result['case']['approved_at']);

        // 资金不变 — approve 不结算
        $this->assertEquals($balanceBefore, $this->getUserBalance());
        $this->assertEquals($ledgerBefore, $this->getLedgerCount());

        // Recharge 状态不变
        $rechargeStatus = Db::name('recharge')->where('id', $rechargeId)->value('status');
        $this->assertEquals(Recharge::STATUS_PENDING, (int)$rechargeStatus);
    }

    public function testApproveRequiresNote(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $caseId = $create['case']['id'];

        $result = $this->appService->approve($caseId, self::TEST_ADMIN_ID, '   ');
        $this->assertFalse($result['ok']);
        $this->assertEquals('RECOVERY_APPROVAL_NOTE_REQUIRED', $result['error_code']);
    }

    public function testApproveAlreadyApprovedCaseRejected(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $caseId = $create['case']['id'];

        $first = $this->appService->approve($caseId, self::TEST_ADMIN_ID, 'approved');
        $this->assertTrue($first['ok']);

        $second = $this->appService->approve($caseId, self::TEST_ADMIN_ID, 'approve again');
        $this->assertFalse($second['ok']);
        $this->assertEquals('RECOVERY_CASE_INVALID_STATE', $second['error_code']);
    }

    // ==================== Reject ====================

    public function testRejectOpenCase(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $caseId = $create['case']['id'];

        $balanceBefore = $this->getUserBalance();
        $ledgerBefore = $this->getLedgerCount();

        $result = $this->appService->reject($caseId, self::TEST_ADMIN_ID, '证据不足，拒绝恢复');

        $this->assertTrue($result['ok']);
        $this->assertEquals('rejected', $result['case']['case_status']);

        // 资金不变
        $this->assertEquals($balanceBefore, $this->getUserBalance());
        $this->assertEquals($ledgerBefore, $this->getLedgerCount());
    }

    public function testRejectRequiresReason(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $caseId = $create['case']['id'];

        $result = $this->appService->reject($caseId, self::TEST_ADMIN_ID, '');
        $this->assertFalse($result['ok']);
        $this->assertEquals('RECOVERY_APPROVAL_NOTE_REQUIRED', $result['error_code']);
    }

    // ==================== List / Detail ====================

    public function testListCases(): void
    {
        $rechargeId1 = $this->createTestRecharge();
        $rechargeId2 = $this->createTestRecharge();
        $this->appService->create($rechargeId1, self::TEST_ADMIN_ID, 'case1');
        $this->appService->create($rechargeId2, self::TEST_ADMIN_ID, 'case2');

        $list = $this->appService->list([], 1, 20);
        $this->assertGreaterThanOrEqual(2, $list['total']);
        $this->assertNotEmpty($list['items']);

        // 验证 DTO 不包含敏感字段
        $item = $list['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('case_status', $item);
        $this->assertArrayNotHasKey('approval_note', $item); // list 不返回审批备注
    }

    public function testDetailCase(): void
    {
        $rechargeId = $this->createTestRecharge();
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'evidence', 'ref_123');
        $caseId = $create['case']['id'];

        $detail = $this->appService->detail($caseId);
        $this->assertNotNull($detail);
        $this->assertEquals($caseId, $detail['id']);
        $this->assertEquals('evidence', $detail['evidence_note']);
        $this->assertEquals('ref_123', $detail['external_reference']);
    }

    public function testDetailNonexistentCaseReturnsNull(): void
    {
        $this->assertNull($this->appService->detail(99999999));
    }

    public function testLegacyAmbiguousFlag(): void
    {
        // EXPIRED + cancel_source='' → legacy_ambiguous = true
        $rechargeId = $this->createTestRecharge(Recharge::STATUS_EXPIRED, '');
        $create = $this->appService->create($rechargeId, self::TEST_ADMIN_ID, 'test');
        $detail = $this->appService->detail($create['case']['id']);
        $this->assertTrue($detail['legacy_ambiguous']);

        // EXPIRED + cancel_source='cron' → legacy_ambiguous = false
        $rechargeId2 = $this->createTestRecharge(Recharge::STATUS_EXPIRED, 'cron');
        $create2 = $this->appService->create($rechargeId2, self::TEST_ADMIN_ID, 'test');
        $detail2 = $this->appService->detail($create2['case']['id']);
        $this->assertFalse($detail2['legacy_ambiguous']);
    }

    // ==================== Static Scope Verification ====================

    public function testControllerDoesNotReferenceFundingServices(): void
    {
        $controllerCode = file_get_contents(__DIR__ . '/../../app/controller/admin/Recovery.php');
        // Controller 不直接引用 UserFundLedgerService（资金核心）
        $this->assertStringNotContainsString('use app\\service\\UserFundLedgerService', $controllerCode);
        // Controller 不直接调用 settleManualApproved（通过 ApplicationService 间接调用）
        // 去掉注释后检查实际代码
        $codeWithoutComments = preg_replace('/\/\*.*?\*\//s', '', $controllerCode);
        $codeWithoutComments = preg_replace('/\/\/.*$/m', '', $codeWithoutComments);
        $this->assertStringNotContainsString('settleManualApproved', $codeWithoutComments);
        // A5.2b: Controller 引用 AdminSensitiveOperationGuard 和 AuthorizationService 是预期的
        $this->assertStringContainsString('AdminSensitiveOperationGuard', $controllerCode);
        $this->assertStringContainsString('AuthorizationService', $controllerCode);
    }

    public function testApplicationServiceDoesNotReferenceFundingServices(): void
    {
        $serviceCode = file_get_contents(__DIR__ . '/../../app/service/AdminRecoveryApplicationService.php');
        // ApplicationService 不直接引用 UserFundLedgerService（资金核心，通过 RechargeSettlementService 间接调用）
        $this->assertStringNotContainsString('use app\\service\\UserFundLedgerService', $serviceCode);
        // A5.2b: ApplicationService 引用 RechargeSettlementService 是预期的（唯一结算边界）
        $this->assertStringContainsString('use app\\service\\RechargeSettlementService', $serviceCode);
        $this->assertStringContainsString('settleManualApproved', $serviceCode);
    }

    public function testAllRecoveryRoutesDefined(): void
    {
        $routeCode = file_get_contents(__DIR__ . '/../../route/app.php');
        // A5.2b: 6 个授权路由全部存在
        $this->assertStringContainsString('admin.Recovery/list', $routeCode);
        $this->assertStringContainsString('admin.Recovery/detail', $routeCode);
        $this->assertStringContainsString('admin.Recovery/create', $routeCode);
        $this->assertStringContainsString('admin.Recovery/approve', $routeCode);
        $this->assertStringContainsString('admin.Recovery/reject', $routeCode);
        // A5.2b: settle 路由已添加
        $this->assertStringContainsString('admin.Recovery/settle', $routeCode);
        // 恰好一个 settle 路由
        $this->assertEquals(1, substr_count($routeCode, 'admin.Recovery/settle'));
    }

    public function testControllerDeclaresAdminAuthMiddleware(): void
    {
        $controllerCode = file_get_contents(__DIR__ . '/../../app/controller/admin/Recovery.php');
        $this->assertStringContainsString('AdminAuth::class', $controllerCode);
        $this->assertStringContainsString("protected array \$middleware", $controllerCode);
    }

    public function testControllerUsesRbacAuthorize(): void
    {
        $controllerCode = file_get_contents(__DIR__ . '/../../app/controller/admin/Recovery.php');
        // 4 个权限全部使用
        $this->assertStringContainsString("recharge_recovery.view", $controllerCode);
        $this->assertStringContainsString("recharge_recovery.create", $controllerCode);
        $this->assertStringContainsString("recharge_recovery.review", $controllerCode);
        // A5.2b: settle 权限已加入
        $this->assertStringContainsString("recharge_recovery.settle", $controllerCode);
    }
}
