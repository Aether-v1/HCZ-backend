<?php
declare(strict_types=1);

namespace tests\Integration;

use app\service\IdempotencyService;
use app\support\IdempotencyResult;
use think\facade\Db;
use tests\Support\SchemaEnsurer;

/**
 * R1.3b: True Two-Connection Concurrency Tests
 *
 * C1: same key + same hash, two independent MySQL connections → one execution + one replay
 * C2: same key + different hash, two independent MySQL connections → one execution + one conflict
 * Lock timeout: Conn A holds unique lock >5s → Conn B (Service) returns IN_PROGRESS, callback not executed
 *
 * 实现方式：两个独立 PHP 进程（raw PDO / Service），controlled interleaving via sleep。
 * Windows 不支持 pcntl_fork，用 proc_open + exec 实现真实并发。
 */
class IdempotencyConcurrencyTest extends DbTestCase
{
    private const TEST_DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=hcz_test;charset=utf8mb4';
    private const TEST_DB_USER = 'root';
    private const TEST_DB_PASS = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        SchemaEnsurer::ensureIdempotencyRecordTable();
        Db::execute('DELETE FROM cz_idempotency_record');
    }

    protected function tearDown(): void
    {
        Db::execute('DELETE FROM cz_idempotency_record');
        parent::tearDown();
    }

    /**
     * 运行子 PHP 脚本（阻塞等待完成），返回输出。
     */
    private function runSubScript(string $script): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'idem_conc_') . '.php';
        file_put_contents($tmpFile, $script);
        $output = [];
        $returnCode = 0;
        exec('php ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $returnCode);
        @unlink($tmpFile);
        return implode("\n", $output);
    }

    /**
     * 后台启动子 PHP 脚本，返回 proc resource。
     */
    private function startBackgroundScript(string $script)
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'idem_bg_') . '.php';
        file_put_contents($tmpFile, $script);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open('php ' . escapeshellarg($tmpFile), $descriptors, $pipes);
        return ['proc' => $proc, 'tmpFile' => $tmpFile, 'pipes' => $pipes];
    }

    private function closeBackgroundScript(array $bg): string
    {
        $output = stream_get_contents($bg['pipes'][1]);
        fclose($bg['pipes'][0]);
        fclose($bg['pipes'][1]);
        fclose($bg['pipes'][2]);
        proc_close($bg['proc']);
        @unlink($bg['tmpFile']);
        return (string) $output;
    }

    private function pdoHeader(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=hcz_test;charset=utf8mb4',
    'root',
    '123456',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
PHP;
    }

    // ==================== C1: same key same hash → one execution + one replay ====================

    public function testC1_trueTwoConnectionSameHashReplay(): void
    {
        $keyHash = hash('sha256', 'conc-c1-key');
        $reqHash = hash('sha256', 'conc-c1-req');

        // Conn A: BEGIN → INSERT PROCESSING → sleep 2s → UPDATE COMPLETED → COMMIT
        $scriptA = $this->pdoHeader() . <<<PHP
\$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
\$pdo->beginTransaction();
\$pdo->exec("INSERT INTO cz_idempotency_record (principal_type, principal_id, operation, idempotency_key_hash, request_hash, fingerprint_version, status, create_time, update_time, expires_at) VALUES ('user', 2001, 'order.create', '{$keyHash}', '{$reqHash}', 1, 'processing', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
echo "A_INSERTED\n";
sleep(2);
\$pdo->exec("UPDATE cz_idempotency_record SET status='completed', http_status=201, response_body='{\"order_id\":4242}' WHERE principal_type='user' AND principal_id=2001 AND operation='order.create' AND idempotency_key_hash='{$keyHash}'");
\$pdo->commit();
echo "A_COMMITTED\n";
PHP;

        // Conn B: sleep 0.5s (A has INSERTed, holding lock) → INSERT same unique → wait → A COMMIT → 1062 → SELECT → replay
        $scriptB = $this->pdoHeader() . <<<PHP
\$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
sleep(1);
try {
    \$pdo->beginTransaction();
    \$pdo->exec("INSERT INTO cz_idempotency_record (principal_type, principal_id, operation, idempotency_key_hash, request_hash, fingerprint_version, status, create_time, update_time, expires_at) VALUES ('user', 2001, 'order.create', '{$keyHash}', '{$reqHash}', 1, 'processing', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    \$pdo->commit();
    echo "B_INSERT_SUCCESS_UNEXPECTED\n";
} catch (PDOException \$e) {
    if (isset(\$e->errorInfo[1]) && (int)\$e->errorInfo[1] === 1062) {
        \$stmt = \$pdo->query("SELECT status, http_status, response_body FROM cz_idempotency_record WHERE principal_type='user' AND principal_id=2001 AND operation='order.create' AND idempotency_key_hash='{$keyHash}'");
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        echo "B_DUPLICATE status=" . \$row['status'] . " http=" . \$row['http_status'] . " body=" . \$row['response_body'] . "\n";
    } else {
        echo "B_ERROR code=" . (\$e->errorInfo[1] ?? '?') . " msg=" . \$e->getMessage() . "\n";
    }
}
PHP;

        // 启动 A（后台），等待 A INSERT，然后运行 B（前台阻塞）
        $bgA = $this->startBackgroundScript($scriptA);
        usleep(800000); // 0.8s，确保 A 已 BEGIN + INSERT
        $outputB = $this->runSubScript($scriptB);
        $outputA = $this->closeBackgroundScript($bgA);

        // 验证 A
        $this->assertStringContainsString('A_INSERTED', $outputA, 'Conn A must insert PROCESSING');
        $this->assertStringContainsString('A_COMMITTED', $outputA, 'Conn A must commit COMPLETED');

        // 验证 B：得到 1062 duplicate，读取到 COMPLETED，http=201，body 正确
        $this->assertStringContainsString('B_DUPLICATE', $outputB, 'Conn B must get duplicate 1062 (not insert success)');
        $this->assertStringContainsString('status=completed', $outputB, 'Conn B must read COMPLETED record');
        $this->assertStringContainsString('http=201', $outputB, 'Conn B must read http_status=201');
        $this->assertStringContainsString('body={"order_id":4242}', $outputB, 'Conn B must read correct replay body');

        // DB 中只有 1 条记录（A 的，B 没有插入成功）
        $count = Db::query("SELECT COUNT(*) AS c FROM cz_idempotency_record WHERE principal_type='user' AND principal_id=2001 AND operation='order.create' AND idempotency_key_hash='{$keyHash}'")[0]['c'];
        $this->assertSame(1, (int) $count, 'exactly one record for this scope');
    }

    // ==================== C2: same key different hash → one execution + one conflict ====================

    public function testC2_trueTwoConnectionDifferentHashConflict(): void
    {
        $keyHash = hash('sha256', 'conc-c2-key');
        $reqHashA = hash('sha256', 'conc-c2-req-a');
        $reqHashB = hash('sha256', 'conc-c2-req-b');

        // Conn A: same as C1
        $scriptA = $this->pdoHeader() . <<<PHP
\$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
\$pdo->beginTransaction();
\$pdo->exec("INSERT INTO cz_idempotency_record (principal_type, principal_id, operation, idempotency_key_hash, request_hash, fingerprint_version, status, create_time, update_time, expires_at) VALUES ('user', 2002, 'order.create', '{$keyHash}', '{$reqHashA}', 1, 'processing', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
echo "A_INSERTED\n";
sleep(2);
\$pdo->exec("UPDATE cz_idempotency_record SET status='completed', http_status=200, response_body='{\"order_id\":1}' WHERE principal_type='user' AND principal_id=2002 AND operation='order.create' AND idempotency_key_hash='{$keyHash}'");
\$pdo->commit();
echo "A_COMMITTED\n";
PHP;

        // Conn B: different request_hash → 1062 → SELECT → request_hash mismatch → CONFLICT
        $scriptB = $this->pdoHeader() . <<<PHP
\$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
sleep(1);
try {
    \$pdo->beginTransaction();
    \$pdo->exec("INSERT INTO cz_idempotency_record (principal_type, principal_id, operation, idempotency_key_hash, request_hash, fingerprint_version, status, create_time, update_time, expires_at) VALUES ('user', 2002, 'order.create', '{$keyHash}', '{$reqHashB}', 1, 'processing', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    \$pdo->commit();
    echo "B_INSERT_SUCCESS_UNEXPECTED\n";
} catch (PDOException \$e) {
    if (isset(\$e->errorInfo[1]) && (int)\$e->errorInfo[1] === 1062) {
        \$stmt = \$pdo->query("SELECT request_hash, status FROM cz_idempotency_record WHERE principal_type='user' AND principal_id=2002 AND operation='order.create' AND idempotency_key_hash='{$keyHash}'");
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        if (\$row['request_hash'] !== '{$reqHashB}') {
            echo "B_CONFLICT stored_hash=" . \$row['request_hash'] . " my_hash={$reqHashB}\n";
        } else {
            echo "B_HASH_MATCH_UNEXPECTED\n";
        }
    } else {
        echo "B_ERROR code=" . (\$e->errorInfo[1] ?? '?') . "\n";
    }
}
PHP;

        $bgA = $this->startBackgroundScript($scriptA);
        usleep(800000);
        $outputB = $this->runSubScript($scriptB);
        $outputA = $this->closeBackgroundScript($bgA);

        $this->assertStringContainsString('A_COMMITTED', $outputA);
        $this->assertStringContainsString('B_CONFLICT', $outputB, 'Conn B must detect request_hash mismatch → conflict');
        $this->assertStringNotContainsString('B_INSERT_SUCCESS_UNEXPECTED', $outputB);
    }

    // ==================== Lock wait timeout → IN_PROGRESS (Service-level) ====================

    public function testLockWaitTimeoutReturnsInProgressCallbackNotExecuted(): void
    {
        $keyHash = hash('sha256', 'conc-lock-key');
        $reqHash = hash('sha256', 'conc-lock-req');

        // Conn A: BEGIN → INSERT → sleep 8s (hold lock > Service 5s timeout) → ROLLBACK
        $scriptA = $this->pdoHeader() . <<<PHP
\$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
\$pdo->beginTransaction();
\$pdo->exec("INSERT INTO cz_idempotency_record (principal_type, principal_id, operation, idempotency_key_hash, request_hash, fingerprint_version, status, create_time, update_time, expires_at) VALUES ('user', 2003, 'order.create', '{$keyHash}', '{$reqHash}', 1, 'processing', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
echo "A_HOLDING_LOCK\n";
sleep(8);
\$pdo->rollBack();
echo "A_ROLLED_BACK\n";
PHP;

        // Conn B: use IdempotencyService → lock wait 5s → 1205 → IN_PROGRESS
        $projectRoot = dirname(__DIR__, 2);
        $scriptB = <<<PHP
<?php
declare(strict_types=1);
require '{$projectRoot}/vendor/autoload.php';
use think\App;
use app\service\IdempotencyService;
use app\support\IdempotencyResult;
\$app = new App('{$projectRoot}');
\$app->initialize();
sleep(1); // wait for A to hold lock
\$service = new IdempotencyService();
\$counter = 0;
\$result = \$service->execute(
    principalType: 'user',
    principalId: 2003,
    operation: 'order.create',
    keyHash: '{$keyHash}',
    requestHash: '{$reqHash}',
    ttlSeconds: 86400,
    business: function () use (&\$counter) {
        \$counter++;
        return ['http_status' => 200, 'body' => []];
    }
);
echo "B_STATUS=" . \$result->status . " counter=" . \$counter . "\n";
PHP;

        $bgA = $this->startBackgroundScript($scriptA);
        usleep(800000);
        $outputB = $this->runSubScript($scriptB);
        $outputA = $this->closeBackgroundScript($bgA);

        $this->assertStringContainsString('A_HOLDING_LOCK', $outputA);
        $this->assertStringContainsString('B_STATUS=' . IdempotencyResult::STATUS_IN_PROGRESS, $outputB, 'Service must return IN_PROGRESS on lock wait timeout');
        $this->assertStringContainsString('counter=0', $outputB, 'business callback must NOT execute on lock timeout');
    }
}
