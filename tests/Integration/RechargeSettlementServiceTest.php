<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\Recharge;
use app\model\User;
use app\service\RechargeSettlementService;
use app\service\UserFundLedgerService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * RechargeSettlementService 集成测试
 *
 * 覆盖 Pre-R1 Batch A 核心安全属性：
 * - PENDING 正常入账
 * - EXPIRED(cron) 迟到付款入账（R0-P0-01 核心修复）
 * - PAID 幂等不重复入账
 * - EXPIRED(user) 用户主动取消不自动入账
 * - 金额无效拒绝
 * - 重复结算幂等
 * - 订单不存在拒绝
 *
 * 所有测试在独立事务中运行，测试结束后回滚，不污染数据库。
 */
class RechargeSettlementServiceTest extends TestCase
{
    private int $testUid = 0;
    private string $testOrderNo = '';

    protected function setUp(): void
    {
        parent::setUp();
        // 每个测试开始新事务，测试结束回滚
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
        parent::tearDown();
    }

    /**
     * 创建测试用户（余额初始为 0）
     */
    private function createTestUser(float $balance = 0.0): int
    {
        $mobile = '138' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $user = new User();
        $user->mobile = $mobile;
        $user->password = md5('test123' . uniqid());
        $user->salt = uniqid();
        $user->nickname = 'TestUser_' . uniqid();
        $user->invite_code = strtoupper(substr(md5(uniqid()), 0, 8));
        $user->balance = $balance;
        $user->status = 1;
        $user->save();
        return (int)$user->id;
    }

    /**
     * 创建测试充值订单
     */
    private function createTestRecharge(int $uid, float $amount, int $status, string $cancelSource = '', string $gateway = 'epay'): string
    {
        $orderNo = 'TEST' . date('YmdHis') . random_int(1000, 9999);
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
        return $orderNo;
    }

    /**
     * 获取用户当前余额
     */
    private function getUserBalance(int $uid): float
    {
        $user = User::where('id', $uid)->find();
        return $user ? round((float)$user->balance, 2) : 0.0;
    }

    /**
     * 获取指定 request_no 的账本流水数
     */
    private function countLedgerByRequestNo(string $requestNo): int
    {
        return (int)Db::name('user_fund_log')
            ->where('request_no', $requestNo)
            ->count();
    }

    // ===== 测试用例 =====

    /**
     * PENDING + 有效付款 → SETTLED，余额增加，账本流水=1
     */
    public function test_settle_pending_order(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_PENDING);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result['result']);
        $this->assertEquals(100.0, $this->getUserBalance($uid));
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));

        // 验证 recharge 状态已更新为 PAID
        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_PAID, (int)$recharge->status);
        $this->assertNotNull($recharge->paid_time);
    }

    /**
     * EXPIRED(cron) + 有效付款 → SETTLED（R0-P0-01 核心修复）
     *
     * 这是 P0 修复的核心验证：Cron 超时标记 status=2(EXPIRED) 后，
     * 真实 Provider 支付回调仍应能入账，不能静默丢单。
     */
    public function test_settle_expired_cron_order(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 50.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result['result'],
            'EXPIRED(cron) 订单应允许迟到付款入账');
        $this->assertEquals(50.0, $this->getUserBalance($uid));
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));

        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_PAID, (int)$recharge->status);
    }

    /**
     * EXPIRED(无 cancel_source，历史数据) + 有效付款 → SETTLED
     *
     * 历史 status=2 数据没有 cancel_source，默认允许自动恢复（兼容）。
     */
    public function test_settle_expired_legacy_no_cancel_source(): void
    {
        $uid = $this->createTestUser(0.0);
        // 不设置 cancel_source（模拟历史数据）
        $orderNo = $this->createTestRecharge($uid, 30.0, Recharge::STATUS_EXPIRED);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result['result']);
        $this->assertEquals(30.0, $this->getUserBalance($uid));
    }

    /**
     * PAID + 重复回调 → ALREADY_PAID，不重复入账
     */
    public function test_settle_already_paid_order(): void
    {
        $uid = $this->createTestUser(100.0);
        $orderNo = $this->createTestRecharge($uid, 50.0, Recharge::STATUS_PAID);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_ALREADY_PAID, $result['result']);
        // 余额不变（初始 100，不增加）
        $this->assertEquals(100.0, $this->getUserBalance($uid));
        // 不产生新的账本流水
        $this->assertEquals(0, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));
    }

    /**
     * EXPIRED(user) + 有效付款 → NOT_SETTLEABLE，不自动入账
     *
     * 用户主动取消的订单不应被自动 settlement，需人工审核。
     */
    public function test_settle_expired_user_cancel_rejected(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_USER);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_NOT_SETTLEABLE, $result['result'],
            '用户主动取消的订单不应自动入账');
        $this->assertEquals(0.0, $this->getUserBalance($uid));
        $this->assertEquals(0, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));

        // 状态保持 EXPIRED，不被改为 PAID
        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_EXPIRED, (int)$recharge->status);
    }

    /**
     * SUBMITTED(手动汇款待审核) + 回调 → NOT_SETTLEABLE
     *
     * 手动汇款订单不应被在线支付回调自动入账。
     */
    public function test_settle_submitted_rejected(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_SUBMITTED, '', 'manual');

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'manual']);

        $this->assertEquals(RechargeSettlementService::RESULT_NOT_SETTLEABLE, $result['result']);
        $this->assertEquals(0.0, $this->getUserBalance($uid));
    }

    /**
     * 金额为 0 → INVALID_AMOUNT
     */
    public function test_settle_invalid_amount(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 0.0, Recharge::STATUS_PENDING);

        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_INVALID_AMOUNT, $result['result']);
        $this->assertEquals(0.0, $this->getUserBalance($uid));
    }

    /**
     * 重复结算幂等：两次 settle → 账本流水=1，余额只增加一次
     *
     * 这是资金安全的核心验证：即使 callback 被 Provider 重复发送，
     * 或 callback 与 reconciliation 同时执行，也只能入账一次。
     */
    public function test_settle_duplicate_idempotent(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 88.0, Recharge::STATUS_PENDING);

        $service = new RechargeSettlementService();

        // 第一次结算
        $result1 = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result1['result']);

        // 第二次结算（模拟重复 callback）
        $result2 = $service->settleByOrderNumber($orderNo, 'test', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_ALREADY_PAID, $result2['result']);

        // 余额只增加一次
        $this->assertEquals(88.0, $this->getUserBalance($uid));
        // 账本流水只有一条
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));
    }

    /**
     * 订单不存在 → NOT_FOUND
     */
    public function test_settle_order_not_found(): void
    {
        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber('NONEXISTENT_ORDER_12345', 'test', []);

        $this->assertEquals(RechargeSettlementService::RESULT_NOT_FOUND, $result['result']);
    }

    /**
     * shouldAckSuccess: SETTLED 和 ALREADY_PAID 返回 true
     */
    public function test_should_ack_success(): void
    {
        $service = new RechargeSettlementService();
        $this->assertTrue($service->shouldAckSuccess(RechargeSettlementService::RESULT_SETTLED));
        $this->assertTrue($service->shouldAckSuccess(RechargeSettlementService::RESULT_ALREADY_PAID));
        $this->assertFalse($service->shouldAckSuccess(RechargeSettlementService::RESULT_NOT_SETTLEABLE));
        $this->assertFalse($service->shouldAckSuccess(RechargeSettlementService::RESULT_INVALID_AMOUNT));
        $this->assertFalse($service->shouldAckSuccess(RechargeSettlementService::RESULT_NOT_FOUND));
    }

    /**
     * EXPIRED(cron) 先结算一次，再用不同 source 结算 → ALREADY_PAID（幂等跨 source）
     *
     * 验证 EPay callback 和 BEpusdt callback 对同一订单不会重复入账。
     */
    public function test_settle_idempotent_across_sources(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 66.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);

        $service = new RechargeSettlementService();

        // 模拟 EPay callback
        $result1 = $service->settleByOrderNumber($orderNo, 'notify_epay', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result1['result']);

        // 模拟 BEpusdt callback（同一订单，不同 source）
        $result2 = $service->settleByOrderNumber($orderNo, 'notify_bepusdt', ['gateway' => 'bepusdt']);
        $this->assertEquals(RechargeSettlementService::RESULT_ALREADY_PAID, $result2['result']);

        // 余额只增加一次
        $this->assertEquals(66.0, $this->getUserBalance($uid));
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));
    }

    // ===== Pre-R1 Batch A.1: User-Cancel Semantic Hardening Tests =====

    /**
     * isAutoSettleable 矩阵：验证各 cancel_source 的自动结算权限
     *
     * Batch A.1 核心：user 取消必须与 cron 过期区分。
     */
    public function test_is_auto_settleable_matrix(): void
    {
        $uid = $this->createTestUser();

        // PENDING → 允许
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_PENDING);
        $pending = Recharge::where('order_number', $orderNo)->find();
        $this->assertTrue($pending->isAutoSettleable(), 'PENDING should be auto-settleable');

        // EXPIRED + cron → 允许（R0-P0-01 已封板路径）
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);
        $cronExpired = Recharge::where('order_number', $orderNo)->find();
        $this->assertTrue($cronExpired->isAutoSettleable(), 'EXPIRED+cron should be auto-settleable');

        // EXPIRED + user → 拒绝（Batch A.1 核心修复）
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_USER);
        $userCancelled = Recharge::where('order_number', $orderNo)->find();
        $this->assertFalse($userCancelled->isAutoSettleable(), 'EXPIRED+user should NOT be auto-settleable');

        // EXPIRED + create_failed → 允许
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CREATE_FAILED);
        $createFailed = Recharge::where('order_number', $orderNo)->find();
        $this->assertTrue($createFailed->isAutoSettleable(), 'EXPIRED+create_failed should be auto-settleable');

        // EXPIRED + provider_expired → 允许
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_PROVIDER_EXPIRED);
        $providerExpired = Recharge::where('order_number', $orderNo)->find();
        $this->assertTrue($providerExpired->isAutoSettleable(), 'EXPIRED+provider_expired should be auto-settleable');

        // EXPIRED + '' (历史数据) → 允许（兼容）
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_EXPIRED, '');
        $legacy = Recharge::where('order_number', $orderNo)->find();
        $this->assertTrue($legacy->isAutoSettleable(), 'EXPIRED+empty (legacy) should be auto-settleable for compatibility');

        // PAID → 拒绝（PAID 由 SettlementService 单独处理为 ALREADY_PAID）
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_PAID);
        $paid = Recharge::where('order_number', $orderNo)->find();
        $this->assertFalse($paid->isAutoSettleable(), 'PAID should not be auto-settleable (handled as ALREADY_PAID)');

        // SUBMITTED → 拒绝
        $orderNo = $this->createTestRecharge($uid, 10.0, Recharge::STATUS_SUBMITTED);
        $submitted = Recharge::where('order_number', $orderNo)->find();
        $this->assertFalse($submitted->isAutoSettleable(), 'SUBMITTED should not be auto-settleable');
    }

    /**
     * 用户取消流程完整模拟：PENDING → user cancel → EXPIRED(user) → valid callback → NO CREDIT
     *
     * 模拟 FinanceActions.php handleApiFinanceRechargeSubmit() cancel action 的行为：
     * status=EXPIRED, cancel_source='user', cancel_time=now
     * 然后 Provider callback 到达，验证不自动入账。
     */
    public function test_user_cancelled_then_valid_callback_no_credit(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 50.0, Recharge::STATUS_PENDING);

        // Step 1: 模拟用户主动取消（与 FinanceActions cancel action 完全一致）
        $recharge = Recharge::where('order_number', $orderNo)->lock(true)->find();
        $recharge->status = Recharge::STATUS_EXPIRED;
        $recharge->cancel_time = date('Y-m-d H:i:s');
        $recharge->cancel_source = Recharge::CANCEL_SOURCE_USER;
        $recharge->save();

        // 验证取消后的状态
        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_EXPIRED, (int)$recharge->status);
        $this->assertEquals(Recharge::CANCEL_SOURCE_USER, (string)$recharge->cancel_source);

        // Step 2: 模拟 Provider valid callback 到达（签名+金额均有效）
        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'notify_epay', [
            'gateway' => 'epay',
            'gateway_trade_id' => 'TEST_TRADE_12345',
            'gateway_status' => 'TRADE_SUCCESS',
            'gateway_actual_amount' => 50.0,
        ]);

        // 验证：不自动入账
        $this->assertEquals(RechargeSettlementService::RESULT_NOT_SETTLEABLE, $result['result'],
            'User-cancelled recharge must NOT be auto-settled');
        $this->assertEquals(0.0, $this->getUserBalance($uid), 'Balance must remain unchanged');
        $this->assertEquals(0, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo),
            'No ledger row must be created');

        // 验证 recharge 状态保持 EXPIRED(user)，不被改为 PAID
        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_EXPIRED, (int)$recharge->status);
        $this->assertEquals(Recharge::CANCEL_SOURCE_USER, (string)$recharge->cancel_source);
    }

    /**
     * Cron 过期 + 重复 callback → 只入账一次（回归测试，确保 Batch A.1 不破坏已封板路径）
     */
    public function test_cron_expired_duplicate_callback_credit_once(): void
    {
        $uid = $this->createTestUser(0.0);
        $orderNo = $this->createTestRecharge($uid, 88.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);

        $service = new RechargeSettlementService();

        // 第一次 callback（迟到付款）
        $result1 = $service->settleByOrderNumber($orderNo, 'notify_epay', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result1['result'],
            'Cron-expired recharge must accept valid late callback');

        // 第二次 callback（Provider 重复发送）
        $result2 = $service->settleByOrderNumber($orderNo, 'notify_epay', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_ALREADY_PAID, $result2['result'],
            'Duplicate callback must be idempotent');

        // 验证：余额只增加一次，账本流水只有一条
        $this->assertEquals(88.0, $this->getUserBalance($uid));
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNo));

        // 验证 recharge 状态为 PAID
        $recharge = Recharge::where('order_number', $orderNo)->find();
        $this->assertEquals(Recharge::STATUS_PAID, (int)$recharge->status);
    }

    /**
     * 用户取消 vs Cron 过期对比测试：同一金额，两种取消来源，结果必须不同
     *
     * 这是 Batch A.1 的核心对比验证：
     * - cron 过期 → 允许迟到付款入账
     * - user 取消 → 拒绝迟到付款自动入账
     */
    public function test_user_cancel_vs_cron_expiry_comparison(): void
    {
        $uid = $this->createTestUser(0.0);
        $service = new RechargeSettlementService();

        // 订单 A: Cron 过期
        $orderNoA = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_CRON);
        $resultA = $service->settleByOrderNumber($orderNoA, 'notify_epay', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $resultA['result'],
            'Cron-expired order must settle');

        // 订单 B: 用户取消
        $orderNoB = $this->createTestRecharge($uid, 100.0, Recharge::STATUS_EXPIRED, Recharge::CANCEL_SOURCE_USER);
        $resultB = $service->settleByOrderNumber($orderNoB, 'notify_epay', ['gateway' => 'epay']);
        $this->assertEquals(RechargeSettlementService::RESULT_NOT_SETTLEABLE, $resultB['result'],
            'User-cancelled order must NOT settle');

        // 验证：只有订单 A 入账，余额只增加 100（不是 200）
        $this->assertEquals(100.0, $this->getUserBalance($uid),
            'Only cron-expired order should credit, not user-cancelled');
        $this->assertEquals(1, $this->countLedgerByRequestNo('recharge_paid:' . $orderNoA));
        $this->assertEquals(0, $this->countLedgerByRequestNo('recharge_paid:' . $orderNoB));
    }
}
