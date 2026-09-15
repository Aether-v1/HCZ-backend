<?php
declare(strict_types=1);

namespace tests\Support;

use app\controller\AdminApi;
use app\service\AdminOperationLogService;
use Symfony\Component\Process\Process;
use tests\Integration\DbTestCase;
use think\facade\Db;
use think\facade\Session;

/**
 * 并发测试基类
 *
 * 与普通集成测试不同：
 * - 不使用 beginTransaction/rollback（父进程事务对子进程不可见）
 * - fixture 创建后 COMMIT
 * - 通过 Symfony Process 启动独立 PHP worker 进程
 * - worker 使用独立 DB 连接、独立事务
 * - flock barrier 确保真实时间重叠
 * - 测试后显式 cleanup（DELETE fixture 数据）
 *
 * 真实并发验证：
 * - 独立 PHP 进程（Symfony Process + proc_open）
 * - 独立 DB 连接（worker 自行初始化 PDO）
 * - InnoDB 行锁（SELECT ... FOR UPDATE）
 * - flock barrier 同步
 */
abstract class ConcurrencyTestCase extends DbTestCase
{
    private const CSRF_TOKEN = 'test_csrf_concurrency_d3f4';

    /** @var array<string> 待清理的临时文件 */
    private array $tempFiles = [];

    /** @var array<int> 待清理的 fixture ID（admin_id, order_id, user_id 等） */
    private array $cleanupIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 并发测试不使用事务隔离（fixture 必须 commit 对子进程可见）
        // 注意：不调用 $this->beginTransaction()

        // 测试环境 file session
        $sessionConfig = config('session');
        $sessionConfig['type'] = 'file';
        $sessionConfig['store'] = null;
        config(['session' => $sessionConfig]);
    }

    protected function tearDown(): void
    {
        $this->cleanupAll();
        parent::tearDown();
    }

    /**
     * 创建并发测试 fixture（COMMIT 模式，对子进程可见）
     *
     * @return array{admin_id: int, user_id: int, order_id: int, points: int, initial_points: int}
     */
    protected function createConcurrencyFixture(int $points = 100, int $initialPoints = 1000): array
    {
        // 创建拥有 admin.points.manage 权限的管理员
        $admin = TestDataFactory::createAdminWithPermissions(['admin.points.manage']);
        $adminId = (int)$admin['admin_id'];

        // 创建用户（带初始积分）
        $user = TestDataFactory::createUser(['points_balance' => $initialPoints]);
        $userId = (int)$user->id;

        // 创建待处理积分兑换订单（COMMIT）
        $order = TestDataFactory::createPendingPointsExchange([
            'uid' => $userId,
            'points' => $points,
        ]);
        $orderId = (int)$order['id'];

        // 记录 cleanup ID
        $this->cleanupIds['admin'][] = $adminId;
        $this->cleanupIds['user'][] = $userId;
        $this->cleanupIds['order'][] = $orderId;

        return [
            'admin_id' => $adminId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'points' => $points,
            'initial_points' => $initialPoints,
        ];
    }

    /**
     * 创建返佣删除并发测试 fixture（C-004）
     * COMMIT 模式，包含 admin.rebate.delete 权限管理员 + 已发放返佣记录 + agent/buyer 用户 + ledger
     *
     * @return array{admin_id: int, rebate_id: int, agent_user_id: int, buyer_user_id: int, ledger_before: int}
     */
    protected function createRebateConcurrencyFixture(): array
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.rebate.delete']);
        $adminId = (int)$admin['admin_id'];

        $fixture = TestDataFactory::createIssuedRebate();
        $rebateId = (int)$fixture['rebate']['id'];
        $agentUserId = (int)$fixture['agentUser']->id;
        $buyerUserId = (int)$fixture['buyerUser']->id;
        $ledgerBefore = (int)$fixture['ledgerBefore'];

        $this->cleanupIds['admin'][] = $adminId;
        $this->cleanupIds['user'][] = $agentUserId;
        $this->cleanupIds['user'][] = $buyerUserId;
        $this->cleanupIds['rebate'][] = $rebateId;

        return [
            'admin_id' => $adminId,
            'rebate_id' => $rebateId,
            'agent_user_id' => $agentUserId,
            'buyer_user_id' => $buyerUserId,
            'ledger_before' => $ledgerBefore,
        ];
    }

    /**
     * 创建订单金额并发测试 fixture（C-005）
     * COMMIT 模式，包含 admin.order.recharge.view 权限管理员 + type=1 订单 + 订单用户
     *
     * @return array{admin_id: int, order_id: int, user_id: int, initial_amount_received: string}
     */
    protected function createOrderConcurrencyFixture(): array
    {
        $admin = TestDataFactory::createAdminWithPermissions(['admin.order.recharge.view']);
        $adminId = (int)$admin['admin_id'];

        $user = TestDataFactory::createUser();
        $userId = (int)$user->id;

        $order = TestDataFactory::createOrder([
            'uid' => $userId,
            'type' => 1,
            'status' => 0,
            'amount_received' => 0,
        ]);
        $orderId = (int)$order->id;

        $this->cleanupIds['admin'][] = $adminId;
        $this->cleanupIds['user'][] = $userId;
        $this->cleanupIds['regular_order'][] = $orderId;

        return [
            'admin_id' => $adminId,
            'order_id' => $orderId,
            'user_id' => $userId,
            'initial_amount_received' => '0',
        ];
    }

    /**
     * 启动并发 worker（独立 PHP 进程）
     *
     * @param array{action: string, order_id: int, admin_id: int, start_delay_ms?: int, remark?: string} $workerConfig
     * @return array{process: Process, result_file: string, config_file: string}
     */
    protected function spawnWorker(array $workerConfig, Barrier $barrier): array
    {
        $unique = substr(uniqid('', true), -8);
        $configFile = $this->tempPath("worker_config_{$unique}.json");
        $resultFile = $this->tempPath("worker_result_{$unique}.json");

        $config = array_merge([
            'action' => '',
            'order_id' => 0,
            'admin_id' => 0,
            'barrier_file' => $barrier->getFile(),
            'result_file' => $resultFile,
            'start_delay_ms' => 0,
            'csrf_token' => self::CSRF_TOKEN,
            'remark' => '',
        ], $workerConfig);

        file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE));

        $workerScript = __DIR__ . '/concurrency_worker.php';
        $process = new Process(['php', $workerScript, $configFile]);
        $process->setTimeout(30);
        $process->start();

        return [
            'process' => $process,
            'result_file' => $resultFile,
            'config_file' => $configFile,
        ];
    }

    /**
     * 等待 worker 完成并读取结果
     *
     * @return array{success: bool, code: int, status: string, message: string, worker_pid: int, error: string, duration_ms: float, action: string, order_id: int, admin_id: int}
     */
    protected function waitForWorker(array $workerInfo): array
    {
        /** @var Process $process */
        $process = $workerInfo['process'];
        $process->wait();

        $resultFile = $workerInfo['result_file'];
        if (!is_file($resultFile)) {
            return [
                'success' => false,
                'code' => 500,
                'status' => 'error',
                'message' => 'Worker result file not found. stderr: ' . $process->getErrorOutput(),
                'worker_pid' => 0,
                'error' => 'No result file. Exit code: ' . $process->getExitCode(),
                'duration_ms' => 0,
                'action' => '',
                'order_id' => 0,
                'admin_id' => 0,
            ];
        }

        $result = json_decode(file_get_contents($resultFile), true);
        if (!is_array($result)) {
            return [
                'success' => false,
                'code' => 500,
                'status' => 'error',
                'message' => 'Invalid result JSON',
                'worker_pid' => 0,
                'error' => 'JSON decode failed',
                'duration_ms' => 0,
                'action' => '',
                'order_id' => 0,
                'admin_id' => 0,
            ];
        }

        return $result;
    }

    /**
     * 创建并持有屏障
     */
    protected function createBarrier(): Barrier
    {
        $file = $this->tempPath('barrier_' . substr(uniqid('', true), -8) . '.lock');
        $barrier = new Barrier($file);
        $barrier->hold();
        return $barrier;
    }

    /**
     * 生成临时文件路径并注册清理
     */
    private function tempPath(string $name): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'd3f4_concurrency_' . getmypid() . '_' . $name;
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * 清理所有 fixture 和临时文件
     */
    private function cleanupAll(): void
    {
        // 清理积分兑换订单
        if (!empty($this->cleanupIds['order'])) {
            Db::name('points_exchange_order')->where('id', 'in', $this->cleanupIds['order'])->delete();
        }

        // 清理返佣记录（C-004）
        if (!empty($this->cleanupIds['rebate'])) {
            Db::name('rebate_record')->where('id', 'in', $this->cleanupIds['rebate'])->delete();
        }

        // 清理普通订单（C-005，区别于积分兑换订单）
        if (!empty($this->cleanupIds['regular_order'])) {
            Db::name('order')->where('id', 'in', $this->cleanupIds['regular_order'])->delete();
        }

        // 清理积分流水（关联测试用户）
        if (!empty($this->cleanupIds['user'])) {
            Db::name('points_record')->where('uid', 'in', $this->cleanupIds['user'])->delete();
        }

        // 清理资金流水（关联测试用户，C-004 rebate ledger）
        if (!empty($this->cleanupIds['user'])) {
            Db::name('user_fund_log')->where('uid', 'in', $this->cleanupIds['user'])->delete();
        }

        // 清理测试用户
        if (!empty($this->cleanupIds['user'])) {
            Db::name('user')->where('id', 'in', $this->cleanupIds['user'])->delete();
        }

        // 清理管理员角色关联
        if (!empty($this->cleanupIds['admin'])) {
            $roleIds = Db::name('admin_role')->where('admin_id', 'in', $this->cleanupIds['admin'])->column('role_id');
            Db::name('admin_role')->where('admin_id', 'in', $this->cleanupIds['admin'])->delete();
            if (!empty($roleIds)) {
                Db::name('role_permission')->where('role_id', 'in', $roleIds)->delete();
                Db::name('role')->where('id', 'in', $roleIds)->delete();
            }
        }

        // 清理操作日志（关联测试管理员）
        if (!empty($this->cleanupIds['admin'])) {
            Db::name('admin_operation_log')->where('admin_id', 'in', $this->cleanupIds['admin'])->delete();
        }

        // 清理临时文件
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->cleanupIds = [];
        $this->tempFiles = [];
    }

    /**
     * 查询积分兑换订单当前状态
     */
    protected function getOrderStatus(int $orderId): int
    {
        return (int)Db::name('points_exchange_order')->where('id', $orderId)->value('status');
    }

    /**
     * 查询用户当前积分
     */
    protected function getUserPoints(int $userId): int
    {
        return (int)Db::name('user')->where('id', $userId)->value('points_balance');
    }

    /**
     * 查询用户当前余额（C-004 资金不变量）
     */
    protected function getUserBalance(int $userId): float
    {
        return (float)Db::name('user')->where('id', $userId)->value('balance');
    }

    /**
     * 查询用户积分流水数量
     */
    protected function countUserPointsRecord(int $userId): int
    {
        return (int)Db::name('points_record')->where('uid', $userId)->count();
    }

    /**
     * 查询管理员操作日志数量（按 action 过滤）
     */
    protected function countAdminLog(int $adminId, ?string $action = null): int
    {
        $query = Db::name('admin_operation_log')->where('admin_id', $adminId);
        if ($action !== null) {
            $query->where('action', $action);
        }
        return (int)$query->count();
    }

    /**
     * 查询返佣记录是否存在（C-004）
     */
    protected function getRebateRecord(int $rebateId): ?array
    {
        $record = Db::name('rebate_record')->where('id', $rebateId)->find();
        return $record ?: null;
    }

    /**
     * 查询用户资金流水数量（C-004）
     */
    protected function countUserFundLog(int $userId, ?string $walletType = null): int
    {
        $query = Db::name('user_fund_log')->where('uid', $userId);
        if ($walletType !== null) {
            $query->where('wallet_type', $walletType);
        }
        return (int)$query->count();
    }

    /**
     * 查询订单实际到账金额（C-005）
     */
    protected function getOrderAmountReceived(int $orderId): string
    {
        $value = Db::name('order')->where('id', $orderId)->value('amount_received');
        return $value === null ? '' : (string)$value;
    }

    /**
     * 查询订单状态（C-005，普通订单，区别于积分兑换订单）
     */
    protected function getRegularOrderStatus(int $orderId): int
    {
        return (int)Db::name('order')->where('id', $orderId)->value('status');
    }

    /**
     * 查询操作日志内容（用于验证 C-005 旧值→新值）
     */
    protected function getAdminLogContents(int $adminId, ?string $action = null): array
    {
        $query = Db::name('admin_operation_log')->where('admin_id', $adminId);
        if ($action !== null) {
            $query->where('action', $action);
        }
        return $query->column('content');
    }
}
