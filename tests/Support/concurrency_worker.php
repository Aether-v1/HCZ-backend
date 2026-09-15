<?php
/**
 * 并发测试 Worker（独立 PHP 进程）
 *
 * 用法：php concurrency_worker.php <config_file>
 *
 * Config JSON 字段：
 *   action          : 'reject' | 'fulfill'
 *   order_id        : int  积分兑换订单 ID
 *   admin_id        : int  管理员 ID（需已拥有 admin.points.manage 权限）
 *   barrier_file    : string 屏障文件路径
 *   result_file     : string 结果输出文件路径
 *   start_delay_ms  : int    开始前延迟毫秒（用于确保并发重叠，0=不延迟）
 *   csrf_token      : string CSRF Token
 *   remark          : string 操作备注（可选）
 *
 * 结果 JSON 字段：
 *   success    : bool   是否成功执行（不代表业务成功，代表 worker 正常运行）
 *   code       : int    业务返回 code（200=成功, 400=业务失败, 500=错误）
 *   status     : string 业务返回 status
 *   message    : string 业务返回 message
 *   worker_pid : int    worker 进程 PID
 *   error      : string 异常信息（如有）
 *   duration_ms: float  执行耗时（毫秒）
 */

declare(strict_types=1);

// 加载 Composer autoloader
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found\n");
    exit(1);
}
require_once $autoload;

use app\controller\AdminApi;
use app\service\AdminOperationLogService;
use tests\Support\Barrier;
use think\facade\Db;
use think\facade\Session;

// 初始化 ThinkPHP 应用（console 模式）
$app = new \think\App();
$app->initialize();
define('HCZ_TESTING', true);

// 读取配置
if ($argc < 2) {
    fwrite(STDERR, "Usage: php concurrency_worker.php <config_file>\n");
    exit(1);
}

$configFile = $argv[1];
if (!is_file($configFile)) {
    fwrite(STDERR, "Config file not found: {$configFile}\n");
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    fwrite(STDERR, "Invalid config JSON: {$configFile}\n");
    exit(1);
}

$action = $config['action'] ?? '';
$orderId = (int)($config['order_id'] ?? 0);
$adminId = (int)($config['admin_id'] ?? 0);
$barrierFile = $config['barrier_file'] ?? '';
$resultFile = $config['result_file'] ?? '';
$startDelayMs = (int)($config['start_delay_ms'] ?? 0);
$csrfToken = $config['csrf_token'] ?? 'test_csrf_concurrency';
$remark = $config['remark'] ?? '';
$recordId = (int)($config['record_id'] ?? 0);
$amountReceived = $config['amount_received'] ?? null;

$result = [
    'success' => false,
    'code' => 0,
    'status' => '',
    'message' => '',
    'worker_pid' => getmypid(),
    'error' => '',
    'duration_ms' => 0,
    'action' => $action,
    'order_id' => $orderId,
    'admin_id' => $adminId,
];

$startTime = microtime(true);

try {
    // 验证必要参数
    if (!in_array($action, ['reject', 'fulfill', 'rebate_delete', 'set_amount_received'], true)) {
        throw new \InvalidArgumentException("Invalid action: {$action}");
    }
    if ($action === 'rebate_delete') {
        if ($recordId <= 0) {
            throw new \InvalidArgumentException("Invalid record_id: {$recordId}");
        }
    } else {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException("Invalid order_id: {$orderId}");
        }
    }
    if ($adminId <= 0) {
        throw new \InvalidArgumentException("Invalid admin_id: {$adminId}");
    }
    if ($barrierFile === '' || !is_file($barrierFile)) {
        throw new \InvalidArgumentException("Invalid barrier_file: {$barrierFile}");
    }
    if ($resultFile === '') {
        throw new \InvalidArgumentException("result_file is required");
    }

    // 测试环境使用 file session 驱动（避免依赖 Redis）
    $sessionConfig = config('session');
    $sessionConfig['type'] = 'file';
    $sessionConfig['store'] = null;
    config(['session' => $sessionConfig]);

    // 构造 AdminApi 实例（newInstanceWithoutConstructor 绕过 CLI 构造函数依赖）
    $ref = new \ReflectionClass(AdminApi::class);
    $controller = $ref->newInstanceWithoutConstructor();

    $appInstance = app();
    // 根据 action 构造 POST 数据（严格按照各 Controller 真实契约）
    $postData = ['_csrf_token' => $csrfToken];
    if ($action === 'reject' || $action === 'fulfill') {
        $postData['id'] = $orderId;
        $postData['remark'] = $remark;
    } elseif ($action === 'rebate_delete') {
        $postData['id'] = $recordId;
    } elseif ($action === 'set_amount_received') {
        $postData['id'] = $orderId;
        $postData['amount_received'] = $amountReceived;
    }
    $request = $appInstance->request->withPost($postData);

    // 设置 CSRF session token
    Session::set('_csrf_token', $csrfToken);

    // 初始化 Controller 必要属性
    $props = [
        'app' => $appInstance,
        'request' => $request,
        'admin_info' => [
            'id' => $adminId,
            'account' => 'concurrency_worker_' . $adminId,
            'name' => '并发测试管理员',
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

    // 等待屏障（确保两个 worker 同时进入业务逻辑）
    $barrier = new Barrier($barrierFile);
    $barrier->wait();

    // 可选延迟（用于确保并发重叠：先启动的 worker 获取行锁后，后启动的 worker 进入阻塞）
    if ($startDelayMs > 0) {
        usleep($startDelayMs * 1000);
    }

    // 执行业务操作（真实调用 Controller，经过 authorize → CSRF → 事务 → FOR UPDATE → status guard → 资金操作 → commit → 日志）
    if ($action === 'reject' || $action === 'fulfill') {
        $response = $controller->points_exchange_order_post($action);
    } elseif ($action === 'rebate_delete') {
        $response = $controller->rebate_record_post('del');
    } elseif ($action === 'set_amount_received') {
        $response = $controller->order_post('set_amount_received');
    } else {
        throw new \InvalidArgumentException("Unhandled action dispatch: {$action}");
    }

    // 解析响应
    $content = method_exists($response, 'getContent') ? $response->getContent() : (string)$response;
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        $result['code'] = (int)($decoded['code'] ?? 0);
        $result['status'] = (string)($decoded['status'] ?? '');
        $result['message'] = (string)($decoded['message'] ?? '');
    } else {
        $result['message'] = 'Raw response: ' . substr($content, 0, 500);
    }

    $result['success'] = true;
} catch (\Throwable $e) {
    $result['error'] = get_class($e) . ': ' . $e->getMessage();
    $result['code'] = 500;
    $result['status'] = 'error';
    fwrite(STDERR, "Worker error: {$result['error']}\n");
}

$result['duration_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// 写入结果文件
$written = file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ($written === false) {
    fwrite(STDERR, "Failed to write result file: {$resultFile}\n");
    exit(1);
}

exit(0);
