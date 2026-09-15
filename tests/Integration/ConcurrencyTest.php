<?php
declare(strict_types=1);

namespace tests\Integration;

use tests\Support\ConcurrencyTestCase;
use tests\Support\TestDataFactory;

/**
 * D3-F4 Phase 4: 真实并发测试
 *
 * 使用 Symfony Process + 独立 PHP worker + 独立 DB 连接 + flock barrier
 * 验证真实并发条件下的资金不变量。
 *
 * 不使用 beginTransaction/rollback（父进程事务对子进程不可见），
 * 采用 commit fixture → spawn workers → verify → cleanup 模式。
 */
class ConcurrencyTest extends ConcurrencyTestCase
{
    // ==================== C-001: 双 Reject 真并发 ====================

    /**
     * C-001: 两个管理员同时 reject 同一积分兑换订单
     *
     * 预期：1 个成功 + 1 个 400 失败
     * 最终：points_balance = initial + refundPoints（只 +1 次）
     *       points_record = initial + 1（只 1 条）
     *       order.status = 2
     *       成功 AdminOperationLog = 1
     *
     * 严禁：points_balance = initial + refundPoints * 2
     *       points_record = initial + 2
     *       成功日志 = 2
     */
    public function testC001_DualRejectConcurrency(): void
    {
        $fixture = $this->createConcurrencyFixture($points = 100, $initialPoints = 1000);
        $orderId = $fixture['order_id'];
        $userId = $fixture['user_id'];
        $adminId = $fixture['admin_id'];

        // 记录初始状态
        $initialPoints = $this->getUserPoints($userId);
        $initialRecordCount = $this->countUserPointsRecord($userId);
        $initialLogCount = $this->countAdminLog($adminId);
        $initialStatus = $this->getOrderStatus($orderId);

        $this->assertSame(0, $initialStatus, '初始订单状态应为 pending(0)');

        // 创建屏障并持有
        $barrier = $this->createBarrier();

        // 启动两个 worker（A 无延迟先获取锁，B 延迟 15ms 确保进入 FOR UPDATE 阻塞）
        $workerA = $this->spawnWorker([
            'action' => 'reject',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 0,
        ], $barrier);

        $workerB = $this->spawnWorker([
            'action' => 'reject',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 15,
        ], $barrier);

        // 等待 worker 初始化并到达屏障
        usleep(200000); // 200ms 确保两个 worker 都已启动并在 wait()

        // 释放屏障 → 两个 worker 同时进入业务逻辑
        $barrier->release();

        // 等待两个 worker 完成
        $resultA = $this->waitForWorker($workerA);
        $resultB = $this->waitForWorker($workerB);

        // 验证 worker 执行成功（基础设施层面）
        $this->assertTrue($resultA['success'], "Worker A 执行失败: {$resultA['error']}");
        $this->assertTrue($resultB['success'], "Worker B 执行失败: {$resultB['error']}");

        // 验证业务结果：恰好 1 个成功(200) + 1 个失败(400)
        $codes = [$resultA['code'], $resultB['code']];
        sort($codes);
        $this->assertSame([200, 400], $codes, "双 reject 应恰好 1 个成功(200)+1 个失败(400)，实际: " . implode(',', $codes));

        // 验证最终数据库状态
        $finalStatus = $this->getOrderStatus($orderId);
        $finalPoints = $this->getUserPoints($userId);
        $finalRecordCount = $this->countUserPointsRecord($userId);
        $finalLogCount = $this->countAdminLog($adminId);

        // 订单状态 = 2 (rejected)
        $this->assertSame(2, $finalStatus, "最终订单状态应为 rejected(2)，实际: {$finalStatus}");

        // 积分只增加 1 次：initial + points
        $this->assertSame($initialPoints + $points, $finalPoints,
            "积分应只增加 {$points}（initial={$initialPoints}），实际: {$finalPoints}。严禁双退！");

        // points_record 只增加 1 条
        $this->assertSame($initialRecordCount + 1, $finalRecordCount,
            "points_record 应只 +1（initial={$initialRecordCount}），实际: {$finalRecordCount}");

        // 成功操作日志只增加 1 条
        $this->assertSame($initialLogCount + 1, $finalLogCount,
            "AdminOperationLog 应只 +1（initial={$initialLogCount}），实际: {$finalLogCount}");

        // 验证真实并发：Worker B 耗时应显著长于 Worker A（B 在等待行锁）
        // 这是一个软断言，用于确认真实并发而非顺序执行
        $durationA = (float)($resultA['duration_ms'] ?? 0);
        $durationB = (float)($resultB['duration_ms'] ?? 0);
        // 记录但不断言（OS 调度可能导致偏差），用于人工确认真实并发
        // fwrite(STDERR, "C-001 durations: A={$durationA}ms B={$durationB}ms\n");
    }

    // ==================== C-002: 双 Fulfill 真并发 ====================

    /**
     * C-002: 两个管理员同时 fulfill 同一积分兑换订单
     *
     * 预期：1 个成功 + 1 个 400 失败
     * 最终：order.status = 1
     *       points_balance = initial（fulfill 不改变积分）
     *       points_record = initial（不变）
     *       成功 AdminOperationLog = 1
     */
    public function testC002_DualFulfillConcurrency(): void
    {
        $fixture = $this->createConcurrencyFixture($points = 100, $initialPoints = 1000);
        $orderId = $fixture['order_id'];
        $userId = $fixture['user_id'];
        $adminId = $fixture['admin_id'];

        $initialPoints = $this->getUserPoints($userId);
        $initialRecordCount = $this->countUserPointsRecord($userId);
        $initialLogCount = $this->countAdminLog($adminId);

        $barrier = $this->createBarrier();

        $workerA = $this->spawnWorker([
            'action' => 'fulfill',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 0,
        ], $barrier);

        $workerB = $this->spawnWorker([
            'action' => 'fulfill',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 15,
        ], $barrier);

        usleep(200000);
        $barrier->release();

        $resultA = $this->waitForWorker($workerA);
        $resultB = $this->waitForWorker($workerB);

        $this->assertTrue($resultA['success'], "Worker A 执行失败: {$resultA['error']}");
        $this->assertTrue($resultB['success'], "Worker B 执行失败: {$resultB['error']}");

        $codes = [$resultA['code'], $resultB['code']];
        sort($codes);
        $this->assertSame([200, 400], $codes, "双 fulfill 应恰好 1 个成功(200)+1 个失败(400)，实际: " . implode(',', $codes));

        $finalStatus = $this->getOrderStatus($orderId);
        $finalPoints = $this->getUserPoints($userId);
        $finalRecordCount = $this->countUserPointsRecord($userId);
        $finalLogCount = $this->countAdminLog($adminId);

        // 订单状态 = 1 (fulfilled)
        $this->assertSame(1, $finalStatus, "最终订单状态应为 fulfilled(1)，实际: {$finalStatus}");

        // fulfill 不改变积分
        $this->assertSame($initialPoints, $finalPoints,
            "fulfill 不应改变积分（initial={$initialPoints}），实际: {$finalPoints}");

        // fulfill 不产生 points_record
        $this->assertSame($initialRecordCount, $finalRecordCount,
            "fulfill 不应产生 points_record（initial={$initialRecordCount}），实际: {$finalRecordCount}");

        // 成功日志只 +1
        $this->assertSame($initialLogCount + 1, $finalLogCount,
            "AdminOperationLog 应只 +1（initial={$initialLogCount}），实际: {$finalLogCount}");
    }

    // ==================== C-003: Fulfill vs Reject 真并发竞态 ====================

    /**
     * C-003a: fulfill 先获取锁 → reject 后获取锁
     *
     * 预期：fulfill 成功，reject 失败(400)
     * 最终：status = 1, points_balance = initial（不变）, points_record = initial（不变）
     */
    public function testC003a_FulfillWinsConcurrency(): void
    {
        $fixture = $this->createConcurrencyFixture($points = 100, $initialPoints = 1000);
        $orderId = $fixture['order_id'];
        $userId = $fixture['user_id'];
        $adminId = $fixture['admin_id'];

        $initialPoints = $this->getUserPoints($userId);
        $initialRecordCount = $this->countUserPointsRecord($userId);
        $initialLogCount = $this->countAdminLog($adminId);

        $barrier = $this->createBarrier();

        // fulfill 无延迟（先获取锁），reject 延迟 15ms
        $workerFulfill = $this->spawnWorker([
            'action' => 'fulfill',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 0,
        ], $barrier);

        $workerReject = $this->spawnWorker([
            'action' => 'reject',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 15,
        ], $barrier);

        usleep(200000);
        $barrier->release();

        $resultFulfill = $this->waitForWorker($workerFulfill);
        $resultReject = $this->waitForWorker($workerReject);

        $this->assertTrue($resultFulfill['success'], "Fulfill worker 执行失败: {$resultFulfill['error']}");
        $this->assertTrue($resultReject['success'], "Reject worker 执行失败: {$resultReject['error']}");

        // fulfill 应成功，reject 应失败
        $this->assertSame(200, $resultFulfill['code'], "fulfill 应成功(200)，实际: {$resultFulfill['code']} - {$resultFulfill['message']}");
        $this->assertSame(400, $resultReject['code'], "reject 应失败(400)，实际: {$resultReject['code']} - {$resultReject['message']}");

        $finalStatus = $this->getOrderStatus($orderId);
        $finalPoints = $this->getUserPoints($userId);
        $finalRecordCount = $this->countUserPointsRecord($userId);
        $finalLogCount = $this->countAdminLog($adminId);

        // fulfill 获胜 → status=1
        $this->assertSame(1, $finalStatus, "fulfill 获胜时 status 应为 1，实际: {$finalStatus}");

        // 积分不变（fulfill 不退款）
        $this->assertSame($initialPoints, $finalPoints,
            "fulfill 获胜时积分应不变（initial={$initialPoints}），实际: {$finalPoints}");

        // points_record 不变
        $this->assertSame($initialRecordCount, $finalRecordCount,
            "fulfill 获胜时 points_record 应不变（initial={$initialRecordCount}），实际: {$finalRecordCount}");

        // 成功日志只 +1
        $this->assertSame($initialLogCount + 1, $finalLogCount,
            "AdminOperationLog 应只 +1（initial={$initialLogCount}），实际: {$finalLogCount}");
    }

    /**
     * C-003b: reject 先获取锁 → fulfill 后获取锁
     *
     * 预期：reject 成功，fulfill 失败(400)
     * 最终：status = 2, points_balance = initial + points, points_record = initial + 1
     */
    public function testC003b_RejectWinsConcurrency(): void
    {
        $fixture = $this->createConcurrencyFixture($points = 100, $initialPoints = 1000);
        $orderId = $fixture['order_id'];
        $userId = $fixture['user_id'];
        $adminId = $fixture['admin_id'];

        $initialPoints = $this->getUserPoints($userId);
        $initialRecordCount = $this->countUserPointsRecord($userId);
        $initialLogCount = $this->countAdminLog($adminId);

        $barrier = $this->createBarrier();

        // reject 无延迟（先获取锁），fulfill 延迟 15ms
        $workerReject = $this->spawnWorker([
            'action' => 'reject',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 0,
        ], $barrier);

        $workerFulfill = $this->spawnWorker([
            'action' => 'fulfill',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'start_delay_ms' => 15,
        ], $barrier);

        usleep(200000);
        $barrier->release();

        $resultReject = $this->waitForWorker($workerReject);
        $resultFulfill = $this->waitForWorker($workerFulfill);

        $this->assertTrue($resultReject['success'], "Reject worker 执行失败: {$resultReject['error']}");
        $this->assertTrue($resultFulfill['success'], "Fulfill worker 执行失败: {$resultFulfill['error']}");

        // reject 应成功，fulfill 应失败
        $this->assertSame(200, $resultReject['code'], "reject 应成功(200)，实际: {$resultReject['code']} - {$resultReject['message']}");
        $this->assertSame(400, $resultFulfill['code'], "fulfill 应失败(400)，实际: {$resultFulfill['code']} - {$resultFulfill['message']}");

        $finalStatus = $this->getOrderStatus($orderId);
        $finalPoints = $this->getUserPoints($userId);
        $finalRecordCount = $this->countUserPointsRecord($userId);
        $finalLogCount = $this->countAdminLog($adminId);

        // reject 获胜 → status=2
        $this->assertSame(2, $finalStatus, "reject 获胜时 status 应为 2，实际: {$finalStatus}");

        // 积分 + points（reject 退款）
        $this->assertSame($initialPoints + $points, $finalPoints,
            "reject 获胜时积分应 +{$points}（initial={$initialPoints}），实际: {$finalPoints}");

        // points_record +1
        $this->assertSame($initialRecordCount + 1, $finalRecordCount,
            "reject 获胜时 points_record 应 +1（initial={$initialRecordCount}），实际: {$finalRecordCount}");

        // 成功日志只 +1
        $this->assertSame($initialLogCount + 1, $finalLogCount,
            "AdminOperationLog 应只 +1（initial={$initialLogCount}），实际: {$finalLogCount}");
    }

    /**
     * C-004: 两个管理员同时删除同一返佣记录（真实并发）
     *
     * 预期：1 个成功(200) + 1 个失败(500 "记录不存在")
     * 最终：rebate_record 被删除，agent 余额不变，ledger 不变，成功日志只 +1
     */
    public function testC004_DualRebateDeleteConcurrency(): void
    {
        $fixture = $this->createRebateConcurrencyFixture();
        $adminId = $fixture['admin_id'];
        $rebateId = $fixture['rebate_id'];
        $agentUserId = $fixture['agent_user_id'];

        // 初始状态快照
        $initialRebate = $this->getRebateRecord($rebateId);
        $this->assertNotNull($initialRebate, "fixture 应创建返佣记录");
        $initialAgentBalance = $this->getUserBalance($agentUserId);
        $initialLedgerCount = $this->countUserFundLog($agentUserId, 'agent');
        $initialLogCount = $this->countAdminLog($adminId);

        $barrier = $this->createBarrier();

        // Worker A: 无延迟，先获取行锁
        $workerA = $this->spawnWorker([
            'action' => 'rebate_delete',
            'record_id' => $rebateId,
            'admin_id' => $adminId,
            'start_delay_ms' => 0,
        ], $barrier);

        // Worker B: 15ms 延迟，确保在 A 持有行锁期间进入 FOR UPDATE 并阻塞
        $workerB = $this->spawnWorker([
            'action' => 'rebate_delete',
            'record_id' => $rebateId,
            'admin_id' => $adminId,
            'start_delay_ms' => 15,
        ], $barrier);

        // 等待两个 worker 都到达 barrier.wait()
        usleep(200000);
        $barrier->release();

        $resultA = $this->waitForWorker($workerA);
        $resultB = $this->waitForWorker($workerB);

        // Worker 基础设施执行成功
        $this->assertTrue($resultA['success'], "Worker A 执行失败: {$resultA['error']}");
        $this->assertTrue($resultB['success'], "Worker B 执行失败: {$resultB['error']}");

        // 业务结果：恰好 1 个 200 + 1 个 500
        $codes = [$resultA['code'], $resultB['code']];
        sort($codes);
        $this->assertSame([200, 500], $codes,
            "双 delete 应恰好 1 个成功(200)+1 个失败(500)，实际: " . implode(',', $codes));

        // 最终：rebate_record 不存在
        $finalRebate = $this->getRebateRecord($rebateId);
        $this->assertNull($finalRebate, "并发删除后 rebate_record 应不存在");

        // agent 余额不变（record-only delete，不退款）
        $finalAgentBalance = $this->getUserBalance($agentUserId);
        $this->assertSame($initialAgentBalance, $finalAgentBalance,
            "删除返佣记录不应改变 agent 余额（initial={$initialAgentBalance}），实际: {$finalAgentBalance}");

        // ledger 不变（不产生新资金流水，不删除原有 ledger）
        $finalLedgerCount = $this->countUserFundLog($agentUserId, 'agent');
        $this->assertSame($initialLedgerCount, $finalLedgerCount,
            "删除返佣记录不应改变 agent ledger 数量（initial={$initialLedgerCount}），实际: {$finalLedgerCount}");

        // 成功操作日志只 +1（不是 +2）
        $finalLogCount = $this->countAdminLog($adminId);
        $this->assertSame($initialLogCount + 1, $finalLogCount,
            "双 delete 成功日志应只 +1（initial={$initialLogCount}），实际: {$finalLogCount}");
    }

    /**
     * C-005: 两个管理员同时设置同一订单的 amount_received（真实并发）
     *
     * 预期：两个操作均成功(200, 200)，最终值 ∈ {X, Y}（last write wins）
     * 最终：order.status 不变，无资金变化，成功日志 +2
     */
    public function testC005_DualSetAmountReceivedConcurrency(): void
    {
        $fixture = $this->createOrderConcurrencyFixture();
        $adminId = $fixture['admin_id'];
        $orderId = $fixture['order_id'];
        $userId = $fixture['user_id'];

        $amountA = 100;
        $amountB = 200;

        // 初始状态快照
        $initialAmount = $this->getOrderAmountReceived($orderId);
        $initialStatus = $this->getRegularOrderStatus($orderId);
        $initialUserPoints = $this->getUserPoints($userId);
        $initialPointsRecord = $this->countUserPointsRecord($userId);
        $initialFundLog = $this->countUserFundLog($userId);
        $initialLogCount = $this->countAdminLog($adminId);

        $barrier = $this->createBarrier();

        // Worker A: 设置 amount_received = 100，无延迟
        $workerA = $this->spawnWorker([
            'action' => 'set_amount_received',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'amount_received' => $amountA,
            'start_delay_ms' => 0,
        ], $barrier);

        // Worker B: 设置 amount_received = 200，15ms 延迟
        $workerB = $this->spawnWorker([
            'action' => 'set_amount_received',
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'amount_received' => $amountB,
            'start_delay_ms' => 15,
        ], $barrier);

        usleep(200000);
        $barrier->release();

        $resultA = $this->waitForWorker($workerA);
        $resultB = $this->waitForWorker($workerB);

        $this->assertTrue($resultA['success'], "Worker A 执行失败: {$resultA['error']}");
        $this->assertTrue($resultB['success'], "Worker B 执行失败: {$resultB['error']}");

        // 两个操作均应成功（FOR UPDATE 确保第二事务读取最新值后覆盖，不是失败）
        $this->assertSame(200, $resultA['code'], "Worker A set_amount_received 应成功(200)，实际: {$resultA['code']} - {$resultA['message']}");
        $this->assertSame(200, $resultB['code'], "Worker B set_amount_received 应成功(200)，实际: {$resultB['code']} - {$resultB['message']}");

        // 最终值必须是某一个完整提交值，不能是混合值/NULL/部分写入
        // 使用 float 数值比较，兼容 MySQL DECIMAL(18,4) 返回的 '200.0000' 等格式
        $finalAmount = $this->getOrderAmountReceived($orderId);
        $this->assertContains((float)$finalAmount, [(float)$amountA, (float)$amountB],
            "最终 amount_received 应 ∈ {{$amountA},{$amountB}}，实际: {$finalAmount}");

        // order.status 不被修改
        $finalStatus = $this->getRegularOrderStatus($orderId);
        $this->assertSame($initialStatus, $finalStatus,
            "set_amount_received 不应修改 order.status（initial={$initialStatus}），实际: {$finalStatus}");

        // 无资金变化
        $this->assertSame($initialUserPoints, $this->getUserPoints($userId), "积分不应变化");
        $this->assertSame($initialPointsRecord, $this->countUserPointsRecord($userId), "points_record 不应变化");
        $this->assertSame($initialFundLog, $this->countUserFundLog($userId), "user_fund_log 不应变化");

        // 成功操作日志 +2（两个操作均成功）
        $finalLogCount = $this->countAdminLog($adminId);
        $this->assertSame($initialLogCount + 2, $finalLogCount,
            "双 set_amount_received 成功日志应 +2（initial={$initialLogCount}），实际: {$finalLogCount}");

        // 验证日志内容包含旧值→新值
        $logContents = $this->getAdminLogContents($adminId, '设置实际到账金额');
        $this->assertCount(2, $logContents, "应有 2 条设置实际到账金额日志");
        $hasOldToNew = false;
        foreach ($logContents as $content) {
            if (strpos($content, '->') !== false || strpos($content, '实际到账') !== false) {
                $hasOldToNew = true;
                break;
            }
        }
        $this->assertTrue($hasOldToNew, "日志内容应包含实际到账金额变更信息");
    }
}
