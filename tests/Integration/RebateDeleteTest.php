<?php
declare(strict_types=1);

namespace tests\Integration;

use app\controller\AdminApi;
use app\model\RebateRecord;
use app\service\AdminOperationLogService;
use tests\Support\TestDataFactory;
use think\facade\Db;

/**
 * Rebate Delete Golden Behavior 集成测试
 *
 * 真实调用 AdminApi::rebate_record_post('del'/'dels')，经过：
 * authorize() → Transaction → SELECT FOR UPDATE → 存在性检查 → 物理 DELETE → COMMIT → AdminOperationLog
 *
 * 核心资金不变量：删除返佣记录是纯 record-only / non-refund 行为，
 * 不修改用户余额、不删除 WALLET_AGENT ledger、不产生新的资金流水。
 *
 * 注意：rebate_record_post 无内部 CSRF 检查（仅依赖全局 CsrfCheck 中间件），
 * 本测试不伪造 CSRF PASS；完整 HTTP CSRF 集成测试 deferred。
 */
class RebateDeleteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 测试环境使用 file session 驱动，避免依赖 Redis（生产代码不变，仅测试运行时配置）
        $sessionConfig = config('session');
        $sessionConfig['type'] = 'file';
        $sessionConfig['store'] = null;
        config(['session' => $sessionConfig]);
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }

    // ==================== Helper Methods ====================

    /**
     * 构造 AdminApi 实例（newInstanceWithoutConstructor 仅解决 CLI/session 构造依赖，不绕过业务方法）
     */
    private function makeController(int $adminId, array $postData = []): AdminApi
    {
        $ref = new \ReflectionClass(AdminApi::class);
        $controller = $ref->newInstanceWithoutConstructor();

        $app = app();
        $request = $app->request->withPost($postData);

        $props = [
            'app' => $app,
            'request' => $request,
            'admin_info' => [
                'id' => $adminId,
                'account' => 'test_admin_' . $adminId,
                'name' => '测试管理员',
            ],
            'config' => [],
            'adminOperationLogService' => new AdminOperationLogService($request),
        ];
        foreach ($props as $name => $value) {
            if ($ref->hasProperty($name)) {
                $p = $ref->getProperty($name);
                $p->setAccessible(true);
                $p->setValue($controller, $value);
            }
        }

        return $controller;
    }

    /**
     * 解析 Controller 返回的 Json Response 为数组
     */
    private function parseResponse($response): array
    {
        if ($response === null) {
            return [];
        }
        $content = method_exists($response, 'getContent') ? $response->getContent() : (string)$response;
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : ['raw' => $content];
    }

    /**
     * 创建拥有 admin.rebate.delete 权限的测试管理员
     */
    private function createRebateAdmin(): array
    {
        return TestDataFactory::createAdminWithPermissions(['admin.rebate.delete']);
    }

    // ==================== RD-001: 正常删除 ====================

    public function testDeleteRebateRecordSuccess(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));

        $this->assertEquals(200, $resp['code'] ?? null, '正常删除应返回 code=200');
        $this->assertEquals('success', $resp['status'] ?? null);

        // 验证记录已物理删除
        $deleted = RebateRecord::where('id', $rebateId)->find();
        $this->assertNull($deleted, '删除后 cz_rebate_record 中该 ID 不应存在');
    }

    // ==================== RD-002: 不修改代理用户钱包 ====================

    public function testDeleteDoesNotChangeAgentWallet(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $agentUid = (int)$fixture['agentUser']->id;
        $rebateId = (int)$fixture['rebate']['id'];

        $balanceBefore = TestDataFactory::getUserBalance($agentUid);

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $this->parseResponse($controller->rebate_record_post('del'));

        $balanceAfter = TestDataFactory::getUserBalance($agentUid);
        $this->assertEquals($balanceBefore, $balanceAfter, "删除返佣记录不应修改代理用户余额，before={$balanceBefore}, after={$balanceAfter}");
    }

    // ==================== RD-003: 原 ledger 保留 ====================

    public function testDeletePreservesLedger(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $agentUid = (int)$fixture['agentUser']->id;
        $rebateId = (int)$fixture['rebate']['id'];
        $rebateAmount = $fixture['rebate']['amount'];

        // 删除前获取原 ledger 记录
        $ledgerBefore = Db::name('user_fund_log')
            ->where('uid', $agentUid)
            ->where('wallet_type', 'agent')
            ->where('biz_id', $rebateId)
            ->find();
        $this->assertNotNull($ledgerBefore, '删除前应存在 WALLET_AGENT ledger');

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $this->parseResponse($controller->rebate_record_post('del'));

        // 删除后原 ledger 仍然存在
        $ledgerAfter = Db::name('user_fund_log')
            ->where('uid', $agentUid)
            ->where('wallet_type', 'agent')
            ->where('biz_id', $rebateId)
            ->find();

        $this->assertNotNull($ledgerAfter, '删除返佣记录后原 WALLET_AGENT ledger 应仍然存在');
        $this->assertEquals($ledgerBefore['amount'], $ledgerAfter['amount'], 'ledger 金额不变');
        $this->assertEquals('agent', $ledgerAfter['wallet_type'], 'wallet_type 不变');
        $this->assertEquals('agent_rebate', $ledgerAfter['biz_type'], 'biz_type 不变');
        $this->assertEquals($rebateId, (int)$ledgerAfter['biz_id'], 'biz_id 不变');
    }

    // ==================== RD-004: 不产生新资金流水 ====================

    public function testDeleteCreatesNoNewFundRecord(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $agentUid = (int)$fixture['agentUser']->id;
        $rebateId = (int)$fixture['rebate']['id'];

        $ledgerCountBefore = TestDataFactory::countLedger($agentUid, 'agent');

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));
        $this->assertEquals(200, $resp['code'] ?? null);

        $ledgerCountAfter = TestDataFactory::countLedger($agentUid, 'agent');
        $this->assertEquals(0, $ledgerCountAfter - $ledgerCountBefore, '删除返佣记录不应产生新的资金流水，delta 必须为 0');
    }

    // ==================== RD-005: AdminOperationLog ====================

    public function testDeleteCreatesAdminOperationLog(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];
        $recordUid = (int)$fixture['rebate']['uid']; // 日志中的用户UID来自 record['uid']（买家），非 agent
        $rebateAmount = $fixture['rebate']['amount'];

        $logCountBefore = TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录');

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));
        $this->assertEquals(200, $resp['code'] ?? null);

        $logCountAfter = TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录');
        $this->assertEquals(1, $logCountAfter - $logCountBefore, '删除成功应产生 1 条操作日志');

        // 验证日志内容
        $log = Db::name('admin_operation_log')
            ->where('admin_id', $admin['admin_id'])
            ->where('action', '删除返佣记录')
            ->order('id', 'desc')
            ->find();
        $this->assertNotNull($log);
        $this->assertEquals('返佣记录', $log['module']);
        $this->assertStringContainsString((string)$rebateId, $log['content'], '日志内容应包含记录 ID');
        $this->assertStringContainsString((string)$recordUid, $log['content'], '日志内容应包含用户 UID');
        $this->assertStringContainsString((string)$rebateAmount, $log['content'], '日志内容应包含金额');
    }

    // ==================== RD-006: 批量删除 ====================

    public function testBatchDeleteSuccess(): void
    {
        $admin = $this->createRebateAdmin();
        $f1 = TestDataFactory::createIssuedRebate();
        $f2 = TestDataFactory::createIssuedRebate();
        $id1 = (int)$f1['rebate']['id'];
        $id2 = (int)$f2['rebate']['id'];
        $agentUid1 = (int)$f1['agentUser']->id;
        $agentUid2 = (int)$f2['agentUser']->id;

        $ledgerBefore1 = TestDataFactory::countLedger($agentUid1, 'agent');
        $ledgerBefore2 = TestDataFactory::countLedger($agentUid2, 'agent');
        $balanceBefore1 = TestDataFactory::getUserBalance($agentUid1);

        $controller = $this->makeController($admin['admin_id'], ['ids' => [$id1, $id2]]);
        $resp = $this->parseResponse($controller->rebate_record_post('dels'));

        $this->assertEquals(200, $resp['code'] ?? null, '批量删除应返回 code=200');

        // 所有记录均被删除
        $this->assertNull(RebateRecord::where('id', $id1)->find(), 'R1 应被删除');
        $this->assertNull(RebateRecord::where('id', $id2)->find(), 'R2 应被删除');

        // 资金数据不变
        $this->assertEquals($ledgerBefore1, TestDataFactory::countLedger($agentUid1, 'agent'), 'R1 agent ledger 不变');
        $this->assertEquals($ledgerBefore2, TestDataFactory::countLedger($agentUid2, 'agent'), 'R2 agent ledger 不变');
        $this->assertEquals($balanceBefore1, TestDataFactory::getUserBalance($agentUid1), 'R1 agent 余额不变');
    }

    // ==================== RD-007: 批量删除含不存在记录 → rollback ====================

    public function testBatchDeleteWithNonExistentRollsBack(): void
    {
        $admin = $this->createRebateAdmin();
        $f1 = TestDataFactory::createIssuedRebate();
        $f2 = TestDataFactory::createIssuedRebate();
        $id1 = (int)$f1['rebate']['id'];
        $id2 = (int)$f2['rebate']['id'];
        $nonexistentId = 99999999;
        $agentUid1 = (int)$f1['agentUser']->id;

        $ledgerBefore = TestDataFactory::countLedger($agentUid1, 'agent');
        $logCountBefore = TestDataFactory::countAdminOperationLog($admin['admin_id'], '批量删除返佣记录');

        $controller = $this->makeController($admin['admin_id'], ['ids' => [$id1, $id2, $nonexistentId]]);
        $resp = $this->parseResponse($controller->rebate_record_post('dels'));

        // 预期 500（部分记录不存在）
        $this->assertEquals(500, $resp['code'] ?? null, '批量删除含不存在记录应返回 code=500');

        // 全部保留（事务 rollback）
        $this->assertNotNull(RebateRecord::where('id', $id1)->find(), 'R1 应保留（rollback）');
        $this->assertNotNull(RebateRecord::where('id', $id2)->find(), 'R2 应保留（rollback）');

        // 资金数据不变
        $this->assertEquals($ledgerBefore, TestDataFactory::countLedger($agentUid1, 'agent'), 'rollback 后 ledger 不变');

        // 不产生成功删除日志
        $logCountAfter = TestDataFactory::countAdminOperationLog($admin['admin_id'], '批量删除返佣记录');
        $this->assertEquals(0, $logCountAfter - $logCountBefore, 'rollback 后不应产生成功删除日志');
    }

    // ==================== RD-008: 重复删除 ====================

    public function testDeleteAfterDeleteReturnsError(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];
        $agentUid = (int)$fixture['agentUser']->id;

        // 第一次删除
        $c1 = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $r1 = $this->parseResponse($c1->rebate_record_post('del'));
        $this->assertEquals(200, $r1['code'] ?? null);

        $balanceAfterFirst = TestDataFactory::getUserBalance($agentUid);
        $ledgerAfterFirst = TestDataFactory::countLedger($agentUid, 'agent');
        $logAfterFirst = TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录');

        // 第二次删除（同 ID）
        $c2 = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $r2 = $this->parseResponse($c2->rebate_record_post('del'));

        // 预期 500 "记录不存在"（当前生产行为，非 404）
        $this->assertEquals(500, $r2['code'] ?? null, '重复删除应返回 code=500（记录不存在）');
        $this->assertStringContainsString('记录不存在', $r2['message'] ?? '');

        // 第二次无资金变化
        $this->assertEquals($balanceAfterFirst, TestDataFactory::getUserBalance($agentUid), '第二次删除不应改变余额');
        $this->assertEquals($ledgerAfterFirst, TestDataFactory::countLedger($agentUid, 'agent'), '第二次删除不应改变 ledger');

        // 第二次不产生成功删除日志
        $logAfterSecond = TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录');
        $this->assertEquals($logAfterFirst, $logAfterSecond, '第二次删除不应产生第二条成功日志');
    }

    // ==================== PERM-002: 无权限拒绝 ====================

    public function testDeleteWithoutPermissionDenied(): void
    {
        // 管理员没有 admin.rebate.delete 权限
        $adminNoPerm = TestDataFactory::createAdminWithPermissions(['admin.user.view']);
        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];
        $agentUid = (int)$fixture['agentUser']->id;

        $ledgerBefore = TestDataFactory::countLedger($agentUid, 'agent');
        $balanceBefore = TestDataFactory::getUserBalance($agentUid);

        $controller = $this->makeController($adminNoPerm['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));

        $this->assertEquals(403, $resp['code'] ?? null, '无 admin.rebate.delete 权限应返回 403');

        // 记录保留
        $this->assertNotNull(RebateRecord::where('id', $rebateId)->find(), '无权限时记录不应被删除');

        // 资金数据不变
        $this->assertEquals($ledgerBefore, TestDataFactory::countLedger($agentUid, 'agent'), '无权限时 ledger 不变');
        $this->assertEquals($balanceBefore, TestDataFactory::getUserBalance($agentUid), '无权限时余额不变');
    }

    // ==================== PERM-003: view-only 权限拒绝 ====================

    public function testDeleteWithViewOnlyPermissionDenied(): void
    {
        // 仅有查看权限，无删除权限
        $adminViewOnly = TestDataFactory::createAdminWithPermissions(['admin.rebate.view']);
        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];

        $controller = $this->makeController($adminViewOnly['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));

        $this->assertEquals(403, $resp['code'] ?? null, '仅有 view 权限应返回 403');
        $this->assertNotNull(RebateRecord::where('id', $rebateId)->find(), 'view-only 不能删除记录');
    }

    // ==================== RD-EDGE-001: 不存在记录 ====================

    public function testDeleteNonExistentRecord(): void
    {
        $admin = $this->createRebateAdmin();
        $nonexistentId = 99999999;

        // 使用一个真实用户来验证无副作用
        $fixture = TestDataFactory::createIssuedRebate();
        $agentUid = (int)$fixture['agentUser']->id;
        $ledgerBefore = TestDataFactory::countLedger($agentUid, 'agent');
        $logBefore = TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录');

        $controller = $this->makeController($admin['admin_id'], ['id' => $nonexistentId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));

        $this->assertEquals(500, $resp['code'] ?? null, '不存在记录应返回 code=500');
        $this->assertStringContainsString('记录不存在', $resp['message'] ?? '');

        // 无副作用
        $this->assertEquals($ledgerBefore, TestDataFactory::countLedger($agentUid, 'agent'), '不存在记录删除不应影响 ledger');
        $this->assertEquals($logBefore, TestDataFactory::countAdminOperationLog($admin['admin_id'], '删除返佣记录'), '不存在记录不应产生成功日志');
    }

    // ==================== RD-EDGE-002: amount=0 返佣 ====================

    public function testDeleteZeroAmountRebate(): void
    {
        $admin = $this->createRebateAdmin();
        $fixture = TestDataFactory::createIssuedRebate(['amount' => 0]);
        $rebateId = (int)$fixture['rebate']['id'];
        $agentUid = (int)$fixture['agentUser']->id;

        $ledgerBefore = TestDataFactory::countLedger($agentUid, 'agent');
        $balanceBefore = TestDataFactory::getUserBalance($agentUid);

        $controller = $this->makeController($admin['admin_id'], ['id' => $rebateId]);
        $resp = $this->parseResponse($controller->rebate_record_post('del'));

        $this->assertEquals(200, $resp['code'] ?? null, 'amount=0 的返佣记录应可正常删除');
        $this->assertNull(RebateRecord::where('id', $rebateId)->find(), '记录应被删除');

        // 资金数据不变
        $this->assertEquals($ledgerBefore, TestDataFactory::countLedger($agentUid, 'agent'), 'amount=0 删除后 ledger 不变');
        $this->assertEquals($balanceBefore, TestDataFactory::getUserBalance($agentUid), 'amount=0 删除后余额不变');
    }

    // ==================== RD-EDGE-003: 非法 ID 参数 ====================

    public function testDeleteInvalidIdParameter(): void
    {
        $admin = $this->createRebateAdmin();

        // id = 0
        $c1 = $this->makeController($admin['admin_id'], ['id' => 0]);
        $r1 = $this->parseResponse($c1->rebate_record_post('del'));
        $this->assertEquals(500, $r1['code'] ?? null, 'id=0 应返回 500 参数错误');
        $this->assertStringContainsString('参数错误', $r1['message'] ?? '');

        // id < 0
        $c2 = $this->makeController($admin['admin_id'], ['id' => -1]);
        $r2 = $this->parseResponse($c2->rebate_record_post('del'));
        $this->assertEquals(500, $r2['code'] ?? null, 'id<0 应返回 500 参数错误');
    }

    // ==================== RD-TX-001: 事务边界代码结构审计 ====================

    public function testTransactionBoundaryCodeStructure(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/controller/AdminApi.php');

        // del case 事务结构
        $this->assertStringContainsString("case 'del':", $source);
        $this->assertStringContainsString("Db::startTrans()", $source, 'del 必须开启事务');
        $this->assertStringContainsString("RebateRecord::where('id', \$id)->lock(true)->find()", $source, 'del 必须使用 SELECT ... FOR UPDATE');
        $this->assertStringContainsString("RebateRecord::destroy(\$id)", $source, 'del 必须物理删除');
        $this->assertStringContainsString("Db::commit()", $source, 'del 必须提交事务');
        $this->assertStringContainsString("Db::rollback()", $source, 'del 必须有回滚');
        $this->assertStringContainsString("catch (\\Throwable", $source, 'del 必须有异常捕获');

        // dels case 事务结构
        $this->assertStringContainsString("case 'dels':", $source);
        $this->assertStringContainsString("->lock(true)->select()", $source, 'dels 必须使用批量行锁');
        $this->assertStringContainsString("count(\$records) !== count(\$ids)", $source, 'dels 必须检查全部记录存在');

        // 权限
        $this->assertStringContainsString("admin.rebate.delete", $source, '必须使用 admin.rebate.delete 权限');
        $this->assertStringContainsString("\$this->authorize('admin.rebate.delete')", $source, '必须通过 $this->authorize() 检查权限');

        // 资金安全：删除操作中不应出现余额更新
        // （rebate_record_post 方法范围内不应有 UserModel balance update）
        $delSection = $this->extractMethodSource($source, 'rebate_record_post');
        $this->assertStringNotContainsString('points_balance', $delSection, 'rebate 删除不应修改积分');
        $this->assertStringNotContainsString("'balance' =>", $delSection, 'rebate 删除不应修改余额');
    }

    /**
     * 从源码中提取指定方法的内容（用于静态审计）
     */
    private function extractMethodSource(string $source, string $methodName): string
    {
        $pattern = '/public function ' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{/';
        if (preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            $start = $matches[0][1];
            $braceCount = 0;
            $inString = false;
            $stringChar = '';
            for ($i = $start; $i < strlen($source); $i++) {
                $char = $source[$i];
                if ($inString) {
                    if ($char === $stringChar && $source[$i - 1] !== '\\') {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $inString = true;
                    $stringChar = $char;
                    continue;
                }
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        return substr($source, $start, $i - $start + 1);
                    }
                }
            }
        }
        return '';
    }
}
