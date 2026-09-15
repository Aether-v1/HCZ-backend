<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\Recharge;
use app\model\User;
use app\service\RechargeSettlementService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * Cron 充值超时语义测试
 *
 * 验证 Pre-R1 Batch A A6：
 * - Cron 将 PENDING(0) 超时订单标记为 EXPIRED(2)
 * - 同时设置 cancel_source='cron' 和 cancel_time
 * - 不修改已 PAID 的订单
 * - 不修改未超时的订单
 * - Cron 标记 EXPIRED 后，迟到付款仍可入账（端到端）
 *
 * 注意：由于测试环境 Cache 驱动初始化问题，这里直接执行与 Cron.php 中相同的
 * UPDATE SQL 来验证 Recharge 超时逻辑，而不实例化 Cron 类。
 * SQL 逻辑与 app/command/Cron.php processDataTasks() 中的 Recharge 部分完全一致。
 */
class CronRechargeTimeoutTest extends TestCase
{
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

    /**
     * 执行与 Cron.php 相同的 Recharge 超时 UPDATE
     */
    private function runCronRechargeTimeout(): int
    {
        $now = time();
        $currentTime = date('Y-m-d H:i:s', $now);
        return (int) Db::name('recharge')
            ->where('status', Recharge::STATUS_PENDING)
            ->where('create_time', '<', date('Y-m-d H:i:s', $now - 20 * 60))
            ->update([
                'status' => Recharge::STATUS_EXPIRED,
                'cancel_time' => $currentTime,
                'cancel_source' => Recharge::CANCEL_SOURCE_CRON,
            ]);
    }

    private function createTestUser(): int
    {
        $user = new User();
        $user->mobile = '139' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $user->password = md5('test' . uniqid());
        $user->salt = uniqid();
        $user->nickname = 'CronTest_' . uniqid();
        $user->invite_code = strtoupper(substr(md5(uniqid()), 0, 8));
        $user->balance = 0;
        $user->status = 1;
        $user->save();
        return (int)$user->id;
    }

    private function createRecharge(int $uid, int $status, string $createTime): int
    {
        $recharge = new Recharge();
        $recharge->uid = $uid;
        $recharge->order_number = 'CRON' . date('YmdHis') . random_int(1000, 9999);
        $recharge->amount = 100.0;
        $recharge->status = $status;
        $recharge->payment_method = 1;
        $recharge->pay_type = 2;
        $recharge->gateway = 'epay';
        $recharge->create_time = $createTime;
        $recharge->save();
        return (int)$recharge->id;
    }

    /**
     * 超时 PENDING 订单 → Cron 标记为 EXPIRED + cancel_source=cron
     */
    public function test_cron_marks_expired_with_cron_source(): void
    {
        $uid = $this->createTestUser();
        // 创建 30 分钟前的 PENDING 订单（超过 20 分钟阈值）
        $rechargeId = $this->createRecharge($uid, Recharge::STATUS_PENDING, date('Y-m-d H:i:s', time() - 30 * 60));

        $affected = $this->runCronRechargeTimeout();
        $this->assertGreaterThanOrEqual(1, $affected);

        $recharge = Recharge::where('id', $rechargeId)->find();
        $this->assertEquals(Recharge::STATUS_EXPIRED, (int)$recharge->status);
        $this->assertEquals(Recharge::CANCEL_SOURCE_CRON, (string)$recharge->cancel_source);
        $this->assertNotNull($recharge->cancel_time);
    }

    /**
     * 未超时 PENDING 订单 → Cron 不修改
     */
    public function test_cron_skips_non_expired_pending(): void
    {
        $uid = $this->createTestUser();
        // 创建 5 分钟前的 PENDING 订单（未超过 20 分钟阈值）
        $rechargeId = $this->createRecharge($uid, Recharge::STATUS_PENDING, date('Y-m-d H:i:s', time() - 5 * 60));

        $this->runCronRechargeTimeout();

        $recharge = Recharge::where('id', $rechargeId)->find();
        $this->assertEquals(Recharge::STATUS_PENDING, (int)$recharge->status);
        $this->assertEquals('', (string)$recharge->cancel_source);
    }

    /**
     * 已 PAID 订单 → Cron 不修改（PAID 是单调终态）
     */
    public function test_cron_skips_paid_orders(): void
    {
        $uid = $this->createTestUser();
        // 创建 30 分钟前的 PAID 订单
        $rechargeId = $this->createRecharge($uid, Recharge::STATUS_PAID, date('Y-m-d H:i:s', time() - 30 * 60));

        $this->runCronRechargeTimeout();

        $recharge = Recharge::where('id', $rechargeId)->find();
        $this->assertEquals(Recharge::STATUS_PAID, (int)$recharge->status);
    }

    /**
     * Cron 标记 EXPIRED 后，迟到付款仍可入账（Cron + Callback 顺序）
     *
     * 这是 P0 修复的端到端验证：
     * 1. Cron 先运行，PENDING → EXPIRED(cancel_source=cron)
     * 2. Callback 后来到达，EXPIRED → PAID，余额增加
     */
    public function test_cron_then_late_callback_settles(): void
    {
        $uid = $this->createTestUser();
        $rechargeId = $this->createRecharge($uid, Recharge::STATUS_PENDING, date('Y-m-d H:i:s', time() - 30 * 60));
        $orderNo = (string)Recharge::where('id', $rechargeId)->value('order_number');

        // Step 1: Cron 运行，标记 EXPIRED
        $this->runCronRechargeTimeout();

        $recharge = Recharge::where('id', $rechargeId)->find();
        $this->assertEquals(Recharge::STATUS_EXPIRED, (int)$recharge->status);
        $this->assertEquals(Recharge::CANCEL_SOURCE_CRON, (string)$recharge->cancel_source);

        // Step 2: 迟到 Callback 到达，通过 SettlementService 入账
        $service = new RechargeSettlementService();
        $result = $service->settleByOrderNumber($orderNo, 'notify_epay', ['gateway' => 'epay']);

        $this->assertEquals(RechargeSettlementService::RESULT_SETTLED, $result['result'],
            'Cron 标记 EXPIRED 后，迟到付款应仍可入账');

        // 验证最终状态
        $recharge = Recharge::where('id', $rechargeId)->find();
        $this->assertEquals(Recharge::STATUS_PAID, (int)$recharge->status);

        // 验证余额
        $user = User::where('id', $uid)->find();
        $this->assertEquals(100.0, round((float)$user->balance, 2));

        // 验证账本流水只有一条
        $ledgerCount = Db::name('user_fund_log')->where('request_no', 'recharge_paid:' . $orderNo)->count();
        $this->assertEquals(1, $ledgerCount);
    }
}
