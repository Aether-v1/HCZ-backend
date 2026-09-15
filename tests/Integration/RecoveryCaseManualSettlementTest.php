<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\Recharge;
use app\model\RecoveryCase;
use app\model\User;
use app\service\RechargeSettlementService;
use app\service\RecoveryCaseService;
use app\service\UserFundLedgerService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * RecoveryCase & Manual Settlement 集成测试 (Pre-R1 Batch A5.1 + A5.3)
 *
 * 覆盖：
 * - RecoveryCase Domain: create / approve / reject / close / reopen
 * - Manual Settlement: APPROVED case + PENDING/EXPIRED → PAID → credit once
 * - 核心区别: EXPIRED(user) + APPROVED case → 可结算（callback 路径不可）
 * - Security: OPEN/REJECTED case 不可结算；金额不可篡改
 * - PAID 幂等: Recharge 已 PAID + APPROVED case → no second credit
 * - Concurrency: manual × callback → credit once（两种顺序）
 * - 统一幂等键: recharge_paid:{order_number}
 *
 * 所有测试在独立事务中运行，测试结束后回滚。
 */
class RecoveryCaseManualSettlementTest extends TestCase
{
    private int $testUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
        parent::tearDown();
    }

    private function createTestUser(float $balance = 0.0): int
    {
        $mobile = '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $user = new User();
        $user->mobile = $mobile;
        $user->password = md5('test123' . uniqid());
        $user->salt = uniqid();
        $user->nickname = 'A5Test_' . uniqid();
        $user->invite_code = strtoupper(substr(md5(uniqid()), 0, 8));
        $user->balance = $balance;
        $user->status = 1;
        $user->save();
        return (int)$user->id;
    }

    private function createTestRecharge(int $uid, float $amount, int $status, string $cancelSource = '', string $gateway = 'epay'): array
    {
        $orderNo = 'A5TEST' . date('YmdHis') . random_int(1000, 9999);
        $recharge = new Recharge();
        $recharge->uid = $uid;
        $recharge->order_number = $orderNo;
        $recharge->amount = $amount;
        $recharge->status = $status;
        $recharge->payment_method = 1;
        $recharge->pay_type = $gateway === 'epay' ? 2 : 1;
        $recharge->gateway = $gateway;
        $recharge->gateway_actual_amount = $amount;
        if ($cancelSource !== '') {
            $recharge->cancel_source = $cancelSource;
            $recharge->cancel_time = date('Y-m-d H:i:s');
        }
        $recharge->save();
        return ['id' => (int)$recharge->id, 'order_number' => $orderNo];
    }

    private function getUserBalance(int $uid): float
    {
        return (float)User::where('id', $uid)->value('balance');
    }

    private function getLedgerCount(string $requestNo): int
    {
        return (int)Db::table('cz_user_fund_log')->where('request_no', $requestNo)->count();
    }

    // ===== RecoveryCase Domain Tests =====

    public function testCreateCaseForUnpaidRechargeSuccess(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        $service = new RecoveryCaseService();
        $result = $service->createCase($recharge['id'], 1, 'epay', 'test evidence');

        $this->assertSame(RecoveryCaseService::CREATE_OK, $result['result']);
        $this->assertNotNull($result['case']);
        $this->assertSame(RecoveryCase::STATUS_OPEN, $result['case']->status);
        $this->assertSame($recharge['id'], (int)$result['case']->recharge_id);
        $this->assertSame($uid, (int)$result['case']->uid);
        $this->assertSame(100.0, (float)$result['case']->claimed_amount);
    }

    public function testCreateDuplicateCaseReturnsAlreadyExists(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        $service = new RecoveryCaseService();
        $first = $service->createCase($recharge['id'], 1, 'epay', 'first');
        $this->assertSame(RecoveryCaseService::CREATE_OK, $first['result']);

        $second = $service->createCase($recharge['id'], 1, 'epay', 'second');
        $this->assertSame(RecoveryCaseService::CREATE_ALREADY_EXISTS, $second['result']);
        $this->assertSame((int)$first['case']->id, (int)$second['case']->id);
    }

    public function testCreateCaseForPaidRechargeRejected(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PAID);

        $service = new RecoveryCaseService();
        $result = $service->createCase($recharge['id'], 1, 'epay', 'test');

        $this->assertSame(RecoveryCaseService::CREATE_RECHARGE_PAID, $result['result']);
    }

    public function testApproveOpenCaseSuccess(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');

        $result = $service->approve((int)$created['case']->id, 2, 'approved by admin');

        $this->assertSame(RecoveryCaseService::APPROVE_OK, $result['result']);
        $this->assertSame(RecoveryCase::STATUS_APPROVED, $result['case']->status);
        $this->assertSame(2, (int)$result['case']->approved_by_admin_id);
        $this->assertNotNull($result['case']->approved_at);
    }

    public function testApproveAlreadyApprovedCaseRejected(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'first approval');

        $result = $service->approve((int)$created['case']->id, 3, 'second approval');
        $this->assertSame(RecoveryCaseService::APPROVE_INVALID_STATE, $result['result']);
    }

    public function testRejectOpenCaseSuccess(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');

        $result = $service->reject((int)$created['case']->id, 2, 'insufficient evidence');

        $this->assertSame(RecoveryCaseService::REJECT_OK, $result['result']);
        $this->assertSame(RecoveryCase::STATUS_REJECTED, $result['case']->status);
    }

    public function testRejectedCaseCannotSettle(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->reject((int)$created['case']->id, 2, 'rejected');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_CASE_NOT_APPROVED, $result['result']);
        $this->assertSame(0.0, $this->getUserBalance($uid));
    }

    // ===== Manual Settlement Tests =====

    public function testApprovedCaseWithPendingRechargeSettles(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $result['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));

        $updatedRecharge = Recharge::find($recharge['id']);
        $this->assertSame(Recharge::STATUS_PAID, (int)$updatedRecharge->status);

        $updatedCase = RecoveryCase::find($created['case']->id);
        $this->assertSame(RecoveryCase::STATUS_SETTLED, $updatedCase->status);
        $this->assertSame(RecoveryCase::SETTLEMENT_MANUAL, $updatedCase->settlement_result);
    }

    public function testApprovedCaseWithExpiredCronSettles(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $result['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));
    }

    /**
     * 核心区别测试：EXPIRED(user) + APPROVED case → 可结算
     * Callback 路径对 EXPIRED(user) 返回 NOT_SETTLEABLE（A.1 已封板）
     * Manual Recovery 路径有显式 Admin 授权，可以结算
     */
    public function testApprovedCaseWithExpiredUserSettlesCoreDifference(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_USER);

        // 验证 callback 路径不可结算
        $settlementService = new RechargeSettlementService();
        $callbackResult = $settlementService->settleByOrderNumber($recharge['order_number'], 'notify_epay');
        $this->assertSame(RechargeSettlementService::RESULT_NOT_SETTLEABLE, $callbackResult['result']);
        $this->assertSame(0.0, $this->getUserBalance($uid));

        // 创建并批准 recovery case
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'user paid after cancel');
        $service->approve((int)$created['case']->id, 2, 'admin confirmed payment');

        // manual recovery 路径可结算
        $case = RecoveryCase::find($created['case']->id);
        $manualResult = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $manualResult['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));
    }

    public function testOpenCaseCannotSettle(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_CASE_NOT_APPROVED, $result['result']);
        $this->assertSame(0.0, $this->getUserBalance($uid));
    }

    public function testSettlementAmountAlwaysFromRechargeNotCaller(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        // 即使 case.claimed_amount 被篡改（模拟），结算金额仍来自 recharge.amount
        Db::table('cz_recharge_recovery_case')->where('id', $created['case']->id)->update(['claimed_amount' => 999.99]);

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleManualApproved($case);

        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $result['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid)); // 不是 999.99
    }

    public function testAlreadyPaidRechargeWithApprovedCaseNoSecondCredit(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        // 先通过 callback 路径结算
        $settlementService = new RechargeSettlementService();
        $firstResult = $settlementService->settleByOrderNumber($recharge['order_number'], 'notify_epay');
        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $firstResult['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid));

        // 创建并批准 recovery case（此时 recharge 已 PAID）
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        // createCase 会拒绝 PAID recharge，所以手动创建 case 用于测试
        Db::table('cz_recharge_recovery_case')->where('id', $created['case']->id ?? 0)->delete();
        $case = new RecoveryCase();
        $case->recharge_id = $recharge['id'];
        $case->uid = $uid;
        $case->provider = 'epay';
        $case->status = RecoveryCase::STATUS_APPROVED;
        $case->claimed_amount = 100.0;
        $case->created_by_admin_id = 1;
        $case->approved_by_admin_id = 2;
        $case->approved_at = date('Y-m-d H:i:s');
        $case->save();

        // manual settlement 应幂等，不重复入账
        $result = $settlementService->settleManualApproved($case);
        $this->assertSame(RechargeSettlementService::RESULT_ALREADY_PAID, $result['result']);
        $this->assertSame(100.0, $this->getUserBalance($uid)); // 余额不变
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));

        $updatedCase = RecoveryCase::find($case->id);
        $this->assertSame(RecoveryCase::STATUS_SETTLED, $updatedCase->status);
        $this->assertSame(RecoveryCase::SETTLEMENT_ALREADY_PAID, $updatedCase->settlement_result);
    }

    // ===== Concurrency Tests =====

    /**
     * Manual recovery first, then callback arrives → credit once
     */
    public function testManualFirstThenCallbackCreditOnce(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);

        // 1. Manual recovery 先结算
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $manualResult = $settlementService->settleManualApproved($case);
        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $manualResult['result']);

        // 2. Callback 后到达 → 幂等
        $callbackResult = $settlementService->settleByOrderNumber($recharge['order_number'], 'notify_epay');
        $this->assertSame(RechargeSettlementService::RESULT_ALREADY_PAID, $callbackResult['result']);

        // 最终：只到账一次
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));

        $updatedRecharge = Recharge::find($recharge['id']);
        $this->assertSame(Recharge::STATUS_PAID, (int)$updatedRecharge->status);
    }

    /**
     * Callback first, then manual recovery → credit once
     */
    public function testCallbackFirstThenManualCreditOnce(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);

        // 1. Callback 先结算
        $settlementService = new RechargeSettlementService();
        $callbackResult = $settlementService->settleByOrderNumber($recharge['order_number'], 'notify_epay');
        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $callbackResult['result']);

        // 2. Manual recovery 后执行 → 幂等收敛
        $service = new RecoveryCaseService();
        // createCase 会拒绝 PAID recharge，手动创建 case
        $case = new RecoveryCase();
        $case->recharge_id = $recharge['id'];
        $case->uid = $uid;
        $case->provider = 'epay';
        $case->status = RecoveryCase::STATUS_APPROVED;
        $case->claimed_amount = 100.0;
        $case->created_by_admin_id = 1;
        $case->approved_by_admin_id = 2;
        $case->approved_at = date('Y-m-d H:i:s');
        $case->save();

        $manualResult = $settlementService->settleManualApproved($case);
        $this->assertSame(RechargeSettlementService::RESULT_ALREADY_PAID, $manualResult['result']);

        // 最终：只到账一次
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));

        $updatedCase = RecoveryCase::find($case->id);
        $this->assertSame(RecoveryCase::STATUS_SETTLED, $updatedCase->status);
        $this->assertSame(RecoveryCase::SETTLEMENT_ALREADY_PAID, $updatedCase->settlement_result);
    }

    /**
     * Manual × Manual concurrency → credit once
     * （在同一事务中串行调用两个 settlement，模拟并发竞争）
     */
    public function testManualTimesTwoCreditOnce(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        $settlementService = new RechargeSettlementService();

        // 第一次结算
        $case1 = RecoveryCase::find($created['case']->id);
        $result1 = $settlementService->settleManualApproved($case1);
        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $result1['result']);

        // 第二次结算（同一 case）→ 幂等
        $case2 = RecoveryCase::find($created['case']->id);
        $result2 = $settlementService->settleManualApproved($case2);
        $this->assertSame(RechargeSettlementService::RESULT_CASE_NOT_APPROVED, $result2['result']); // case 已 SETTLED，不是 APPROVED

        // 最终：只到账一次
        $this->assertSame(100.0, $this->getUserBalance($uid));
        $this->assertSame(1, $this->getLedgerCount('recharge_paid:' . $recharge['order_number']));
    }

    // ===== Unified Idempotency Key Test =====

    public function testUnifiedIdempotencyKeySameForCallbackAndManual(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        // callback 路径使用的 request_no
        $settlementService = new RechargeSettlementService();
        $result = $settlementService->settleByOrderNumber($recharge['order_number'], 'notify_epay');
        $this->assertSame(RechargeSettlementService::RESULT_SETTLED, $result['result']);

        // 验证账本 request_no
        $ledger = Db::table('cz_user_fund_log')
            ->where('uid', $uid)
            ->where('request_no', 'recharge_paid:' . $recharge['order_number'])
            ->find();

        $this->assertNotNull($ledger);
        $this->assertSame('recharge_paid:' . $recharge['order_number'], $ledger['request_no']);
        $this->assertSame('recharge', $ledger['biz_type']);
        $this->assertSame('recharge_paid', $ledger['change_type']);
    }

    // ===== State Machine Tests =====

    public function testCloseCaseFromSettled(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->approve((int)$created['case']->id, 2, 'approved');

        $case = RecoveryCase::find($created['case']->id);
        $settlementService = new RechargeSettlementService();
        $settlementService->settleManualApproved($case);

        $result = $service->close((int)$created['case']->id, 2, 'closed after settlement');
        $this->assertSame('closed', $result['result']);
        $this->assertSame(RecoveryCase::STATUS_CLOSED, $result['case']->status);
    }

    public function testReopenRejectedCase(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');
        $service->reject((int)$created['case']->id, 2, 'insufficient evidence');

        $result = $service->reopen((int)$created['case']->id, 3, 'new evidence provided');
        $this->assertSame('reopened', $result['result']);
        $this->assertSame(RecoveryCase::STATUS_OPEN, $result['case']->status);
    }

    public function testCannotReopenOpenCase(): void
    {
        $uid = $this->createTestUser();
        $recharge = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);
        $service = new RecoveryCaseService();
        $created = $service->createCase($recharge['id'], 1, 'epay', 'test');

        $result = $service->reopen((int)$created['case']->id, 3, 'try reopen');
        $this->assertSame('invalid_state', $result['result']);
    }
}
