<?php
declare(strict_types=1);

namespace tests\Integration;

use app\common\library\TelegramHelper;
use app\job\BatchElectricityQuery;
use app\job\BatchPhoneQuery;
use tests\Support\TestDataFactory;
use think\facade\Db;

/**
 * F14 / F15 回归测试
 *
 * F14（P2）— BatchPhoneQuery / BatchElectricityQuery 的 $data 变量遮蔽：
 *   失败号码/账号的返还必须使用 Job 原始 trace_id（$jobTraceId）生成非空 refund_key
 *   并进入 RefundIntent Outbox，不能读取被查询结果遮蔽后的 $data['trace_id']（恒为 ''）。
 *
 * F15（P3）— null / empty identity fallback：
 *   空 trace_id / 空 messageId 不得静默直接 PointsService::addPoints()（绕过 Outbox/幂等），
 *   必须 fail-closed（明确失败 + critical 日志，由运营介入）。
 *
 * 测试策略说明：
 * - processBatchQuery()/fire() 内部静态调用 TelegramHelper::queryPhoneBalance()
 *   （真实外部 API，不可 mock），因此：
 *     (a) 行为测试通过反射调用私有 refundPointsOnce() 直接验证返还路径（传非空/空 traceId）；
 *     (b) 遮蔽是否在真实循环中消除，用静态源码断言验证（既有先例 testTransactionBoundaryCodeStructure）。
 * - 本轮不修改任何生产代码（生产代码已在 F14/F15 最小修复中修改完毕），此处仅验证。
 *
 * Test 4 并发说明：
 *   并发仲裁点是 DB 唯一键（cz_refund_intent.uk_refund_key + cz_points_record.uk_refund_key），
 *   该保证与处理时序无关（先到先得，第二个 INSERT 命中 UNIQUE 后回查为 completed）。
 *   故"同 key 二次处理"（确定性）即等价验证两个 Worker 同时处理同一 refund identity 的最终结果。
 */
class BatchRefundIdempotencyTest extends DbTestCase
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

    // ==================== Helpers ====================

    private function createUserWithPoints(int $points = 1000): object
    {
        return TestDataFactory::createUser(['points_balance' => $points]);
    }

    private function getIntentByKey(string $refundKey): ?array
    {
        $row = Db::name('refund_intent')->where('refund_key', $refundKey)->find();
        return is_array($row) ? $row : ($row ? $row->toArray() : null);
    }

    private function countPointsRecordByRefundKey(string $refundKey): int
    {
        return (int)Db::name('points_record')->where('refund_key', $refundKey)->count();
    }

    /**
     * 反射调用 Job 私有 refundPointsOnce()，返回其数组结果。
     */
    private function invokeRefundPointsOnce(string $jobClass, int $uid, int $points, string $traceId, string $event, string $item): array
    {
        $job = new $jobClass();
        $method = new \ReflectionMethod($jobClass, 'refundPointsOnce');
        $method->setAccessible(true);
        return $method->invoke($job, $uid, $points, $traceId, $event, $item, "测试返还 {$item}");
    }

    // ==================== F14 — 静态源码断言（遮蔽已消除） ====================

    public function testF14PhoneShadowingEliminatedInSource(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/job/BatchPhoneQuery.php');

        // $jobTraceId = 1 次赋值 + 3 处 refund 调用（查询失败 / 查询异常 / 无效号码）
        $this->assertGreaterThanOrEqual(4, substr_count($source, '$jobTraceId'),
            'BatchPhoneQuery 应声明并使用 $jobTraceId');

        // $data['trace_id'] 只允许出现在 $jobTraceId 赋值行，refund 调用不得读取被遮蔽的 $data
        $this->assertEquals(1, substr_count($source, "\$data['trace_id']"),
            'BatchPhoneQuery 中 $data[ trace_id ] 只应出现 1 次（$jobTraceId 赋值行）');

        // 遮蔽赋值仍保留（成功分支的数据格式化业务逻辑不变）
        $this->assertStringContainsString("\$data = \$result['data'];", $source,
            '成功分支的数据格式化逻辑应保留（最小修复，不改业务）');
    }

    public function testF14ElectricityShadowingEliminatedInSource(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/job/BatchElectricityQuery.php');

        // $jobTraceId = 1 次赋值 + 2 处 refund 调用（逐户失败返还 / catch 全量补返）
        $this->assertGreaterThanOrEqual(3, substr_count($source, '$jobTraceId'),
            'BatchElectricityQuery 应声明并使用 $jobTraceId');

        $this->assertEquals(1, substr_count($source, "\$data['trace_id']"),
            'BatchElectricityQuery 中 $data[ trace_id ] 只应出现 1 次（$jobTraceId 赋值行）');

        $this->assertStringContainsString("\$data = \$item['data'];", $source,
            '结果消息构建分支的遮蔽应保留（最小修复，不改业务）');
    }

    // ==================== F14 — 行为验证：非空 jobTraceId → 非空 refund_key → Outbox ====================

    public function testF14PhoneFailedNumberRefundUsesJobTraceId(): void
    {
        $user = $this->createUserWithPoints(500);
        $uid = (int)$user->id;
        $traceId = 'f14-phone-' . uniqid();
        $phone = '13800138001';
        $points = 10;

        $result = $this->invokeRefundPointsOnce(BatchPhoneQuery::class, $uid, $points, $traceId, 'phone', $phone);

        $this->assertEquals(1, $result['code'], '非空 trace_id 应进入 Outbox 并成功返还');

        $key = "tg:batch:refund:{$traceId}:phone:{$phone}";
        $this->assertNotEmpty($key);

        $intent = $this->getIntentByKey($key);
        $this->assertNotNull($intent, '应创建 RefundIntent（refund_key 非空）');
        $this->assertEquals('completed', $intent['status'], 'intent 应为 completed');

        $this->assertEquals(1, $this->countPointsRecordByRefundKey($key),
            'points_record 应有且仅 1 条该 refund_key 记录');
        $this->assertEquals(500 + $points, TestDataFactory::getUserPoints($uid),
            '积分应精确增加一次');
    }

    public function testF14ElectricityFailedAccountRefundUsesJobTraceId(): void
    {
        $user = $this->createUserWithPoints(400);
        $uid = (int)$user->id;
        $traceId = 'f14-elec-' . uniqid();
        $account = 'ELEC' . random_int(1000, 9999);
        $points = 15;

        $result = $this->invokeRefundPointsOnce(BatchElectricityQuery::class, $uid, $points, $traceId, 'account', $account);

        $this->assertEquals(1, $result['code'], '非空 trace_id 应进入 Outbox 并成功返还');

        $key = "tg:batch:refund:{$traceId}:account:{$account}";
        $intent = $this->getIntentByKey($key);
        $this->assertNotNull($intent, '应创建 RefundIntent（refund_key 非空）');
        $this->assertEquals(1, $this->countPointsRecordByRefundKey($key),
            'points_record 应有且仅 1 条该 refund_key 记录');
        $this->assertEquals(400 + $points, TestDataFactory::getUserPoints($uid),
            '积分应精确增加一次');
    }

    // ==================== Test 3 — Job 重投：同一 job / 同一业务 identity 不重复返还 ====================

    public function testF14JobRedeliveryDoesNotDoubleAward(): void
    {
        $user = $this->createUserWithPoints(300);
        $uid = (int)$user->id;
        $traceId = 'f14-redeliver-' . uniqid();
        $phone = '13900139000';
        $points = 10;

        // 第一次（原 Job 处理）
        $r1 = $this->invokeRefundPointsOnce(BatchPhoneQuery::class, $uid, $points, $traceId, 'phone', $phone);
        $this->assertEquals(1, $r1['code']);
        $this->assertEquals(310, TestDataFactory::getUserPoints($uid), '第一次应返还 10 积分');

        // Job 重投：同一 job / 同一业务 identity 再次返还（Worker crash 后重投）
        $r2 = $this->invokeRefundPointsOnce(BatchPhoneQuery::class, $uid, $points, $traceId, 'phone', $phone);
        $this->assertEquals(1, $r2['code'], '重投应幂等成功（code=1）');

        $key = "tg:batch:refund:{$traceId}:phone:{$phone}";
        $this->assertEquals(1, $this->countPointsRecordByRefundKey($key),
            '重投后 points_record 仍为 1 条');
        $this->assertEquals(310, TestDataFactory::getUserPoints($uid),
            '重投后积分不得重复增加（仍为 310）');

        $intent = $this->getIntentByKey($key);
        $this->assertEquals('completed', $intent['status'], '重投后 intent 仍为 completed');
    }

    // ==================== Test 4 — Concurrent retry：同 refund identity 不 double award ====================

    public function testF14ConcurrentRetrySingleAward(): void
    {
        $user = $this->createUserWithPoints(200);
        $uid = (int)$user->id;
        $traceId = 'f14-concurrent-' . uniqid();
        $account = 'ACC' . random_int(1000, 9999);
        $points = 10;

        // Worker A
        $ra = $this->invokeRefundPointsOnce(BatchElectricityQuery::class, $uid, $points, $traceId, 'account', $account);
        // Worker B（同一 refund identity，模拟并发处理）
        $rb = $this->invokeRefundPointsOnce(BatchElectricityQuery::class, $uid, $points, $traceId, 'account', $account);

        $this->assertEquals(1, $ra['code'], 'Worker A 应成功');
        $this->assertEquals(1, $rb['code'], 'Worker B 应幂等成功');

        $key = "tg:batch:refund:{$traceId}:account:{$account}";
        $this->assertEquals(1, $this->countPointsRecordByRefundKey($key),
            'points_record = 1（不得 double award）');
        $this->assertEquals(200 + $points, TestDataFactory::getUserPoints($uid),
            '积分增加 = 1 次（200 -> 210）');

        // intent 唯一且 completed
        $intent = $this->getIntentByKey($key);
        $this->assertNotNull($intent, '应存在唯一 RefundIntent');
        $this->assertEquals('completed', $intent['status']);
        $this->assertEquals(1, (int)Db::name('refund_intent')->where('refund_key', $key)->count(),
            '同一 refund_key 只能有 1 条 intent');
    }

    // ==================== Test 5 — F15：null / empty identity fail-closed ====================

    public function testF15EmptyTraceIdFailsClosedNoDirectAddPoints(): void
    {
        $user = $this->createUserWithPoints(100);
        $uid = (int)$user->id;
        $points = 10;

        $before = TestDataFactory::getUserPoints($uid);
        $recordBefore = TestDataFactory::countPointsRecord($uid);

        $result = $this->invokeRefundPointsOnce(BatchPhoneQuery::class, $uid, $points, '', 'phone', '13800000000');

        $this->assertEquals(0, $result['code'], '空 trace_id 应 fail-closed 返回失败');
        $this->assertEquals($before, TestDataFactory::getUserPoints($uid),
            '空 trace_id 不得直接增加积分');
        $this->assertEquals($recordBefore, TestDataFactory::countPointsRecord($uid),
            '空 trace_id 不得直接写入 points_record');
        $this->assertEquals(0, (int)Db::name('refund_intent')->where('uid', $uid)->count(),
            '空 trace_id 不应创建 RefundIntent（无幂等身份）');
    }

    public function testF15NullMessageIdFailsClosedNoDirectAddPoints(): void
    {
        $user = $this->createUserWithPoints(100);
        $uid = (int)$user->id;
        $points = 10;

        $before = TestDataFactory::getUserPoints($uid);
        $recordBefore = TestDataFactory::countPointsRecord($uid);

        // messageId = null → fail-closed
        $resultNull = TelegramHelper::refundPoints($uid, $points, '13700000000', null);
        $this->assertFalse($resultNull, 'messageId=null 应 fail-closed 返回 false');

        // messageId = '' → fail-closed
        $resultEmpty = TelegramHelper::refundPoints($uid, $points, '13700000000', '');
        $this->assertFalse($resultEmpty, "messageId='' 应 fail-closed 返回 false");

        $this->assertEquals($before, TestDataFactory::getUserPoints($uid),
            '空 messageId 不得直接增加积分');
        $this->assertEquals($recordBefore, TestDataFactory::countPointsRecord($uid),
            '空 messageId 不得直接写入 points_record');
        $this->assertEquals(0, (int)Db::name('refund_intent')->where('uid', $uid)->count(),
            '空 messageId 不应创建 RefundIntent');
    }

    // ==================== F15 — 静态源码断言（空 identity 分支不再静默直接返还） ====================

    public function testF15NoSilentDirectAddPointsInSource(): void
    {
        $bpq = file_get_contents(__DIR__ . '/../../app/job/BatchPhoneQuery.php');
        $beq = file_get_contents(__DIR__ . '/../../app/job/BatchElectricityQuery.php');
        $tg = file_get_contents(__DIR__ . '/../../app/common/library/TelegramHelper.php');

        // 三个返还入口必须存在 fail-closed 标记（critical 日志文案）
        $this->assertStringContainsString('拒绝静默直接返还', $bpq, 'BatchPhoneQuery 空 trace_id 分支应 fail-closed');
        $this->assertStringContainsString('拒绝静默直接返还', $beq, 'BatchElectricityQuery 空 trace_id 分支应 fail-closed');
        $this->assertStringContainsString('拒绝静默直接返还', $tg, 'TelegramHelper 空 messageId 分支应 fail-closed');
    }
}
