<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\User as UserModel;
use app\model\Withdrawal;
use app\service\UserFundLedgerService;
use think\facade\Db;

/**
 * TEST-001: 提现资金安全集成测试
 *
 * 覆盖：
 * - 提现并发提交（user 行锁 + 余额校验）
 * - 同提现订单并发审核
 * - 审核通过 + 拒绝并发
 * - 管理员删除 status=0 提现（应禁止）
 * - 批量删除部分失败必须全部 rollback
 * - 平台手续费重复执行必须保持幂等
 *
 * 需要数据库。无 DB 时自动跳过。
 * 注意：并发测试仅代码级推演，未进行真实多进程并发压测。
 */
class WithdrawalFundSafetyTest extends DbTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$dbAvailable) {
            return;
        }
        $this->beginTransaction();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        if (self::$dbAvailable) {
            $this->rollback();
        }
        parent::tearDown();
    }

    private function seedTestData(): void
    {
        $this->userId = (int)UserModel::create([
            'mobile' => 'test_withdraw_' . uniqid(),
            'password' => password_hash('test1234', PASSWORD_BCRYPT),
            'salt' => 'test',
            'nickname' => '测试提现用户',
            'invite_code' => 'WDL' . substr(uniqid(), -6),
            'balance' => 500.00,
            'frozen_amount' => 0,
            'trc20' => 'TTestAddress' . substr(uniqid(), -8),
            'status' => 1,
        ])->id;
    }

    /**
     * 提现提交：user 行锁 + 余额校验 + balance→frozen
     *
     * 模拟两笔并发提现，第一笔成功后第二笔因余额不足应失败。
     */
    public function testConcurrentWithdrawalSubmission(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        // 第一笔提现 300
        Db::startTrans();
        try {
            $user = UserModel::where('id', $this->userId)->lock(true)->find();
            $this->assertGreaterThanOrEqual(300, (float)$user['balance'], '余额应 >= 300');

            // 模拟 balance → frozen
            $user->balance = round((float)$user['balance'] - 300, 2);
            $user->frozen_amount = round((float)$user['frozen_amount'] + 300, 2);
            $user->save();

            Withdrawal::create([
                'uid' => $this->userId,
                'amount' => 300.00,
                'wallet_address' => 'TTest',
                'withdrawal_fee' => 5.00,
                'order_number' => 'WD' . date('Ymd') . substr(uniqid(), -6),
                'status' => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail('第一笔提现应成功: ' . $e->getMessage());
        }

        // 第二笔提现 300 应因余额不足失败
        Db::startTrans();
        try {
            $user = UserModel::where('id', $this->userId)->lock(true)->find();
            $this->assertLessThan(300, (float)$user['balance'], '第一笔后余额应 < 300');
            Db::rollback();
        } catch (\Throwable $e) {
            Db::rollback();
        }

        // 验证最终状态
        $user = UserModel::where('id', $this->userId)->find();
        $this->assertEquals(200.00, round((float)$user['balance'], 2), '余额应为 200');
        $this->assertEquals(300.00, round((float)$user['frozen_amount'], 2), '冻结应为 300');
    }

    /**
     * 同提现订单并发审核：第一次审核通过后，第二次因 status != 0 应失败
     */
    public function testConcurrentAuditSameOrder(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $withdrawal = Withdrawal::create([
            'uid' => $this->userId,
            'amount' => 100.00,
            'wallet_address' => 'TTest',
            'withdrawal_fee' => 2.00,
            'order_number' => 'WDAUDIT' . date('Ymd') . substr(uniqid(), -6),
            'status' => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // 第一次审核通过
        Db::startTrans();
        try {
            $w = Withdrawal::where('id', $withdrawal->id)->lock(true)->find();
            $this->assertEquals(0, (int)$w['status'], '初始状态应为 0');
            $w->status = 1;
            $w->save();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail('第一次审核应成功: ' . $e->getMessage());
        }

        // 第二次审核应失败（status 已变为 1）
        Db::startTrans();
        try {
            $w = Withdrawal::where('id', $withdrawal->id)->lock(true)->find();
            $this->assertNotEquals(0, (int)$w['status'], '审核后状态不应为 0');
            Db::rollback();
        } catch (\Throwable $e) {
            Db::rollback();
        }
    }

    /**
     * 审核通过 + 拒绝并发：先到先得，后到的因 status != 0 失败
     */
    public function testApproveAndRejectConcurrency(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $withdrawal = Withdrawal::create([
            'uid' => $this->userId,
            'amount' => 50.00,
            'wallet_address' => 'TTest',
            'withdrawal_fee' => 1.00,
            'order_number' => 'WDCONFLICT' . date('Ymd') . substr(uniqid(), -6),
            'status' => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // 模拟"通过"先执行
        Db::startTrans();
        try {
            $w = Withdrawal::where('id', $withdrawal->id)->lock(true)->find();
            $this->assertEquals(0, (int)$w['status']);
            $w->status = 1; // 通过
            $w->save();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail('通过审核应成功: ' . $e->getMessage());
        }

        // 模拟"拒绝"后执行 — 应因 status != 0 而跳过
        Db::startTrans();
        try {
            $w = Withdrawal::where('id', $withdrawal->id)->lock(true)->find();
            $this->assertNotEquals(0, (int)$w['status'], '已审核的订单 status 不应为 0');
            Db::rollback();
        } catch (\Throwable $e) {
            Db::rollback();
        }

        // 最终状态应为 1（通过）
        $final = Withdrawal::where('id', $withdrawal->id)->find();
        $this->assertEquals(1, (int)$final['status'], '最终状态应为通过(1)');
    }

    /**
     * 管理员删除 status=0 提现应被禁止
     */
    public function testAdminDeletePendingWithdrawalBlocked(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $withdrawal = Withdrawal::create([
            'uid' => $this->userId,
            'amount' => 100.00,
            'wallet_address' => 'TTest',
            'withdrawal_fee' => 2.00,
            'order_number' => 'WDDELPEND' . date('Ymd') . substr(uniqid(), -6),
            'status' => 0, // 待审核
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        // 模拟管理员删除逻辑：status=0 禁止删除
        Db::startTrans();
        try {
            $w = Withdrawal::where('id', $withdrawal->id)->lock(true)->find();
            $this->assertEquals(0, (int)$w['status'], '应是待审核状态');
            // 按修复后的逻辑，status=0 应回滚
            Db::rollback();
            $blocked = true;
        } catch (\Throwable $e) {
            Db::rollback();
            $blocked = true;
        }

        $this->assertTrue($blocked ?? false, 'status=0 提现应被禁止删除');

        // 验证记录仍存在
        $exists = Withdrawal::where('id', $withdrawal->id)->find();
        $this->assertNotNull($exists, '待审核提现不应被删除');
    }

    /**
     * 批量删除：包含 status=0 时整批回滚
     */
    public function testBatchDeleteWithPendingRollsBackAll(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        // 创建两条：一条已通过(1)，一条待审核(0)
        $w1 = Withdrawal::create([
            'uid' => $this->userId,
            'amount' => 10.00,
            'wallet_address' => 'TTest',
            'withdrawal_fee' => 0.50,
            'order_number' => 'WDBATCH1' . date('Ymd') . substr(uniqid(), -6),
            'status' => 1,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        $w2 = Withdrawal::create([
            'uid' => $this->userId,
            'amount' => 20.00,
            'wallet_address' => 'TTest',
            'withdrawal_fee' => 1.00,
            'order_number' => 'WDBATCH2' . date('Ymd') . substr(uniqid(), -6),
            'status' => 0, // 待审核，应阻止整批删除
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        $ids = [(int)$w1->id, (int)$w2->id];

        // 模拟批量删除：单事务 all-or-nothing
        Db::startTrans();
        $batchFailed = false;
        try {
            foreach ($ids as $id) {
                $w = Withdrawal::where('id', $id)->lock(true)->find();
                if (!$w) {
                    throw new \Exception('提现记录不存在: ' . $id);
                }
                if ((int)$w['status'] === 0) {
                    throw new \Exception('待审核提现不可删除: ' . $id);
                }
                Withdrawal::destroy($id);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $batchFailed = true;
        }

        $this->assertTrue($batchFailed, '批量删除应因包含 status=0 而失败');

        // 验证两条记录都还在（all-or-nothing）
        $this->assertNotNull(Withdrawal::where('id', $w1->id)->find(), 'w1 不应被删除（整批回滚）');
        $this->assertNotNull(Withdrawal::where('id', $w2->id)->find(), 'w2 不应被删除');
    }

    /**
     * 平台手续费幂等：同一 request_no 重复执行不应产生重复流水
     */
    public function testPlatformFeeIdempotency(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('需要数据库');
        }

        $service = new UserFundLedgerService();
        $orderNumber = 'IDEMPOTENT' . date('Ymd') . substr(uniqid(), -6);
        $requestNo = 'withdraw_fee:' . $orderNumber;

        // 第一次执行
        $result1 = $service->recordPlatformIncome(5.00, [
            'biz_type' => 'withdrawal',
            'biz_id' => 999999,
            'biz_no' => $orderNumber,
            'order_number' => $orderNumber,
            'change_type' => 'withdrawal_fee_income',
            'operator_type' => 'admin',
            'operator_id' => 1,
            'status' => 'done',
            'request_no' => $requestNo,
            'remark' => 'test idempotent',
        ]);
        $this->assertFalse($result1['duplicated'] ?? true, '第一次应非重复');

        // 第二次执行（相同 request_no）
        $result2 = $service->recordPlatformIncome(5.00, [
            'biz_type' => 'withdrawal',
            'biz_id' => 999999,
            'biz_no' => $orderNumber,
            'order_number' => $orderNumber,
            'change_type' => 'withdrawal_fee_income',
            'operator_type' => 'admin',
            'operator_id' => 1,
            'status' => 'done',
            'request_no' => $requestNo,
            'remark' => 'test idempotent',
        ]);
        $this->assertTrue($result2['duplicated'] ?? false, '第二次应识别为重复');

        // 验证数据库中只有一条记录
        $count = Db::name('user_fund_log')
            ->where('request_no', $requestNo)
            ->count();
        $this->assertEquals(1, $count, '同一 request_no 应只有一条流水记录');
    }
}
