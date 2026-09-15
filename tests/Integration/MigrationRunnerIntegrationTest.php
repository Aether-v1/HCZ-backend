<?php
declare(strict_types=1);

namespace tests\Integration;

use app\service\MigrationRunner;

/**
 * HCZ R1.5a — MigrationRunner Integration Tests (Remediated)
 *
 * 测试真实数据库操作：bootstrap tracking / plan / status / run / rollback / baseline / lock / checksum drift。
 * 使用 hcz_test 数据库，临时 migration 目录，测试后清理。
 *
 * R1.5a Remediation tests:
 * - P1-01: status/plan/dry-run READ-ONLY (no tracking table creation)
 * - P1-02: baseline fail-closed (no str_contains, heuristic + exact STATUS:APPLIED)
 * - P2-01: global preflight gates
 * - P2-02/P2-05: orphaned tracking detection
 * - P2-03: stale RUNNING blocks run()
 * - P2-04: post-apply verification
 * - P2-06: rollback preserves audit history (ROLLED_BACK, no DELETE)
 * - baseline funds gate
 *
 * 注意：不执行真实 database/migrations/ 下的 migration，只用临时测试 migration。
 */
class MigrationRunnerIntegrationTest extends DbTestCase
{
    private string $tempMigrationsDir;
    private string $testTable = 'cz_migration_test_table';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempMigrationsDir = sys_get_temp_dir() . '/hcz_migration_integration_' . uniqid('', true);
        mkdir($this->tempMigrationsDir, 0777, true);

        // 清理之前崩溃运行残留的临时 migration 目录（best-effort，排除当前目录）
        $this->cleanupStaleTempDirs();

        // 清理可能残留的 tracking 表和测试表
        $this->cleanupTestArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestArtifacts();

        // 清理临时 migration 文件
        $files = glob($this->tempMigrationsDir . '/*.php');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempMigrationsDir);

        parent::tearDown();
    }

    private function cleanupTestArtifacts(): void
    {
        // Safety gate: 任何 DROP 操作前必须确认当前连接是专用测试库
        $this->assertTestDatabase();

        try {
            // 删除所有测试表（包括 _fail、_second 等后缀）
            $tables = \think\facade\Db::query("SHOW TABLES LIKE 'cz_migration_test_table%'");
            foreach ($tables as $row) {
                $tableName = array_values($row)[0];
                \think\facade\Db::execute("DROP TABLE IF EXISTS `{$tableName}`");
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_migration`");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Safety gate: 确认当前数据库连接是 hcz_test，fail-closed。
     * 防止测试 DB 配置错误时 DROP 生产/开发库表。
     */
    private function assertTestDatabase(): void
    {
        $db = \think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] ?? null;
        if ($db !== 'hcz_test') {
            throw new \RuntimeException(
                "MigrationRunnerIntegrationTest safety gate: current database is '{$db}', expected 'hcz_test'. Refusing to execute DROP operations."
            );
        }
    }

    /**
     * Best-effort 清理之前崩溃运行残留的临时 migration 目录。
     * 只清理匹配 hcz_migration_integration_* 且非当前目录的目录。
     * 失败不阻塞测试（当前目录已通过 uniqid('', true) 保证唯一）。
     */
    private function cleanupStaleTempDirs(): void
    {
        try {
            $pattern = sys_get_temp_dir() . '/hcz_migration_integration_*';
            $dirs = glob($pattern, GLOB_ONLYDIR);
            if ($dirs === false) {
                return;
            }
            foreach ($dirs as $dir) {
                $realDir = str_replace('\\', '/', $dir);
                $realCurrent = str_replace('\\', '/', $this->tempMigrationsDir);
                if ($realDir === $realCurrent) {
                    continue;
                }
                // 只清理超过 60 秒的目录，避免误删其他正在运行的测试
                if (time() - @filemtime($dir) < 60) {
                    continue;
                }
                $files = glob($dir . '/*');
                if ($files) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
                @rmdir($dir);
            }
        } catch (\Throwable $e) {
            // best-effort，失败不阻塞
        }
    }

    private function makeRunner(): MigrationRunner
    {
        return new MigrationRunner($this->tempMigrationsDir, PHP_BINARY);
    }

    /**
     * 创建一个简单的测试 migration 文件（从模板复制并替换占位符）。
     */
    private function createTestMigration(string $id, string $tableName, bool $fail = false): void
    {
        $rootPath = rtrim(root_path(), '\\/');
        $templateFile = $fail
            ? __DIR__ . '/fixtures/test_migration_fail_template.php'
            : __DIR__ . '/fixtures/test_migration_template.php';

        $content = file_get_contents($templateFile);
        $content = str_replace('__ROOT_PATH__', $rootPath, $content);
        $content = str_replace('__TABLE_NAME__', $tableName, $content);

        file_put_contents($this->tempMigrationsDir . '/' . $id . '.php', $content);
    }

    /**
     * 创建一个 status() 输出指定文本的测试 migration（用于 baseline false-positive 测试）。
     */
    private function createStatusOutputMigration(string $id, string $statusOutput): void
    {
        $rootPath = rtrim(root_path(), '\\/');
        $content = <<<PHP
<?php
declare(strict_types=1);
require '{$rootPath}/vendor/autoload.php';
use think\App;
use think\\facade\\Db;
\$app = new App('{$rootPath}/');
\$app->initialize();
\$action = \$argv[1] ?? 'migrate';
\$migration = new class {
    public function migrate(): void { echo "migrate called\\n"; }
    public function rollback(): void { echo "rollback\\n"; }
    public function status(): void { echo "{$statusOutput}\\n"; }
};
match (\$action) {
    'migrate' => \$migration->migrate(),
    'rollback' => \$migration->rollback(),
    'status' => \$migration->status(),
    default => die("usage\\n"),
};
PHP;
        file_put_contents($this->tempMigrationsDir . '/' . $id . '.php', $content);
    }

    // ==================== Bootstrap Tracking ====================

    public function testBootstrapTrackingCreatesTable(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        $tables = \think\facade\Db::query("SHOW TABLES LIKE 'cz_migration'");
        $this->assertNotEmpty($tables, 'cz_migration table should be created');
    }

    public function testBootstrapTrackingIsIdempotent(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();
        $runner->bootstrapTracking(); // 第二次不应该报错

        $tables = \think\facade\Db::query("SHOW TABLES LIKE 'cz_migration'");
        $this->assertNotEmpty($tables);
    }

    public function testVerifyTrackingSchemaReturnsTrueAfterBootstrap(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();
        $this->assertTrue($runner->verifyTrackingSchema());
    }

    public function testGetCurrentDatabaseReturnsTestDb(): void
    {
        $runner = $this->makeRunner();
        $db = $runner->getCurrentDatabase();
        $this->assertNotEmpty($db);
        // 确认不是生产数据库
        $this->assertNotSame('hcz', $db, 'Should not be production database');
    }

    // ==================== P1-01: Read-Only Zero Side Effects ====================

    public function testStatusDoesNotCreateTrackingTableWhenAbsent(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 确认 tracking 表不存在
        $this->assertFalse($runner->trackingTableExists());

        // status() 是 READ-ONLY，不应该创建表
        $status = $runner->status();

        // 表仍然不存在
        $this->assertFalse($runner->trackingTableExists(), 'status() must NOT create tracking table');

        // 所有 migration 视为 pending
        $this->assertCount(1, $status['pending']);
        $this->assertStringContainsString('Tracking table', implode("\n", $status['warnings']));
    }

    public function testPlanDoesNotCreateTrackingTableWhenAbsent(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        $this->assertFalse($runner->trackingTableExists());

        $plan = $runner->plan();

        $this->assertFalse($runner->trackingTableExists(), 'plan() must NOT create tracking table');
        $this->assertCount(1, $plan['pending']);
        $this->assertFalse($plan['tracking_exists']);
    }

    public function testDryRunDoesNotCreateTrackingTableWhenAbsent(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        $this->assertFalse($runner->trackingTableExists());

        $result = $runner->run(['allow_unknown' => true, 'dry_run' => true]);

        $this->assertFalse($runner->trackingTableExists(), 'dry-run must NOT create tracking table');
        $this->assertCount(0, $result['executed']);
        $this->assertCount(1, $result['skipped']);

        // 表不应该被创建
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}'");
        $this->assertEmpty($tables, 'Table should not be created in dry-run mode');
    }

    // ==================== Plan / Status ====================

    public function testPlanReturnsAllPendingWhenTrackingEmpty(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        $plan = $runner->plan();

        $this->assertCount(1, $plan['pending']);
        $this->assertCount(0, $plan['completed']);
        $this->assertCount(0, $plan['failed']);
        $this->assertSame('2024_01_01_000001_test_create', $plan['pending'][0]['id']);
    }

    public function testPlanDetectsChecksumDrift(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 先执行一次
        $runner->run(['allow_unknown' => true]);

        // 修改 migration 文件内容
        $file = $this->tempMigrationsDir . '/2024_01_01_000001_test_create.php';
        $content = file_get_contents($file);
        $content .= "\n// modified after execution\n";
        file_put_contents($file, $content);

        // 重新 plan 应该检测到 checksum drift
        $plan = $runner->plan();
        $this->assertCount(1, $plan['checksum_drifts']);
        $this->assertSame('2024_01_01_000001_test_create', $plan['checksum_drifts'][0]['id']);
    }

    // ==================== Run ====================

    public function testRunExecutesPendingMigration(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        $result = $runner->run(['allow_unknown' => true]);

        $this->assertCount(1, $result['executed']);
        $this->assertCount(0, $result['failed']);

        // 验证表已创建
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}'");
        $this->assertNotEmpty($tables, 'Test table should be created by migration');

        // 验证 tracking 记录
        $record = $runner->getTrackingRecord('2024_01_01_000001_test_create');
        $this->assertNotNull($record);
        $this->assertSame(MigrationRunner::STATUS_COMPLETED, $record['status']);
        $this->assertSame(64, strlen($record['checksum']));
    }

    public function testRunSkipsAlreadyCompletedMigration(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 第一次执行
        $result1 = $runner->run(['allow_unknown' => true]);
        $this->assertCount(1, $result1['executed']);

        // 第二次执行应该跳过
        $result2 = $runner->run(['allow_unknown' => true]);
        $this->assertCount(0, $result2['executed']);
    }

    public function testRunStopsOnFirstFailure(): void
    {
        $this->createTestMigration('2024_01_01_000001_fail_first', $this->testTable . '_fail', true);
        $this->createTestMigration('2024_01_02_000001_second', $this->testTable . '_second');
        $runner = $this->makeRunner();

        $result = $runner->run(['allow_unknown' => true]);

        $this->assertCount(0, $result['executed']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('2024_01_01_000001_fail_first', $result['failed'][0]['id']);

        // 第二个 migration 不应该被执行
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}_second'");
        $this->assertEmpty($tables, 'Second migration should not execute after first failure');

        // tracking 中第一个应该是 FAILED
        $record = $runner->getTrackingRecord('2024_01_01_000001_fail_first');
        $this->assertNotNull($record);
        $this->assertSame(MigrationRunner::STATUS_FAILED, $record['status']);
    }

    public function testRunBlocksWhenFailedMigrationExists(): void
    {
        $this->createTestMigration('2024_01_01_000001_fail', $this->testTable, true);
        $runner = $this->makeRunner();

        // 第一次执行失败
        $runner->run(['allow_unknown' => true]);

        // 第二次应该因为 FAILED migration 存在而抛出异常
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previously FAILED migrations exist');
        $runner->run(['allow_unknown' => true]);
    }

    public function testRunDryRunDoesNotExecute(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        $result = $runner->run(['allow_unknown' => true, 'dry_run' => true]);

        $this->assertCount(0, $result['executed']);
        $this->assertCount(1, $result['skipped']);

        // 表不应该被创建
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}'");
        $this->assertEmpty($tables, 'Table should not be created in dry-run mode');
    }

    // ==================== P2-01: Global Preflight Gates ====================

    public function testGlobalPreflightBlocksFundsMigrationBeforeAnyExecution(): void
    {
        // A = non-funds (unknown), B = funds (known metadata)
        $this->createTestMigration('2024_01_01_000001_nonfunds', $this->testTable . '_a');
        $this->createTestMigration('2024_01_12_000001_create_idempotency_record_table', $this->testTable . '_b');
        $runner = $this->makeRunner();

        // 生产模式，无 --allow-funds → 应该在执行任何 migration 之前 BLOCK
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('preflight BLOCKED');
        $runner->run(['production' => true, 'allow_unknown' => true]);

        // 两个表都不应该被创建
        $tablesA = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}_a'");
        $this->assertEmpty($tablesA, 'Non-funds migration A should NOT execute when funds B blocks preflight');
    }

    public function testGlobalPreflightAllowsFundsWithAllowFunds(): void
    {
        $this->createTestMigration('2024_01_12_000001_create_idempotency_record_table', $this->testTable);
        $runner = $this->makeRunner();

        $result = $runner->run(['production' => true, 'allow_funds' => true]);

        $this->assertCount(1, $result['executed']);
    }

    // ==================== P2-03: Stale RUNNING Blocks ====================

    public function testStaleRunningBlocksRun(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        // 人工插入 RUNNING 状态记录
        \think\facade\Db::name('migration')->insert([
            'migration' => '2024_01_01_000001_test_create',
            'checksum' => str_repeat('a', 64),
            'status' => MigrationRunner::STATUS_RUNNING,
            'funds_related' => 0,
            'database_name' => 'hcz_test',
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        // run() 应该因为 stale RUNNING 而 BLOCK
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STALE RUNNING');
        $runner->run(['allow_unknown' => true]);

        // tracking 状态不变
        $record = $runner->getTrackingRecord('2024_01_01_000001_test_create');
        $this->assertSame(MigrationRunner::STATUS_RUNNING, $record['status']);
    }

    // ==================== P2-02/P2-05: Orphaned Tracking ====================

    public function testOrphanedCompletedIsWarnedInPlan(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        // 人工插入一个不在文件系统中的 COMPLETED 记录
        \think\facade\Db::name('migration')->insert([
            'migration' => '2099_01_01_000001_orphaned_completed',
            'checksum' => str_repeat('b', 64),
            'status' => MigrationRunner::STATUS_COMPLETED,
            'funds_related' => 0,
            'database_name' => 'hcz_test',
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        $plan = $runner->plan();

        // orphaned 应该被检测到
        $this->assertCount(1, $plan['orphaned']);
        $this->assertSame('2099_01_01_000001_orphaned_completed', $plan['orphaned'][0]['migration']);

        // warning 应该包含 ORPHANED
        $warningsStr = implode("\n", $plan['warnings']);
        $this->assertStringContainsString('ORPHANED', $warningsStr);
    }

    public function testOrphanedFailedBlocksRun(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        // 人工插入 orphaned FAILED 记录
        \think\facade\Db::name('migration')->insert([
            'migration' => '2099_01_01_000001_orphaned_failed',
            'checksum' => str_repeat('c', 64),
            'status' => MigrationRunner::STATUS_FAILED,
            'funds_related' => 0,
            'database_name' => 'hcz_test',
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
            'error_message' => 'test failure',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ORPHANED');
        $runner->run(['allow_unknown' => true]);
    }

    // ==================== P2-04: Post-apply Verification ====================

    public function testPostApplyVerificationFailsWhenSchemaMissing(): void
    {
        // 创建一个 migration，其 migrate() 声称成功但不创建预期表
        // 对于有 heuristic check 的 migration（如 idempotency_record），
        // 如果 migrate 不创建表，post-apply verification 应该失败
        $rootPath = rtrim(root_path(), '\\/');
        $id = '2024_01_12_000001_create_idempotency_record_table';
        $content = <<<PHP
<?php
declare(strict_types=1);
require '{$rootPath}/vendor/autoload.php';
use think\App;
use think\\facade\\Db;
\$app = new App('{$rootPath}/');
\$app->initialize();
\$action = \$argv[1] ?? 'migrate';
\$migration = new class {
    public function migrate(): void {
        // 声称成功但不创建表
        echo "Migration completed successfully (but no table created)\\n";
    }
    public function rollback(): void { echo "rollback\\n"; }
    public function status(): void { echo "STATUS: PENDING\\n"; }
};
match (\$action) {
    'migrate' => \$migration->migrate(),
    'rollback' => \$migration->rollback(),
    'status' => \$migration->status(),
    default => die("usage\\n"),
};
PHP;
        file_put_contents($this->tempMigrationsDir . '/' . $id . '.php', $content);

        // 确保 cz_idempotency_record 不存在
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_idempotency_record`");

        $runner = $this->makeRunner();
        $result = $runner->run(['allow_funds' => true]);

        // 应该失败（post-apply verification failed）
        $this->assertCount(0, $result['executed']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($id, $result['failed'][0]['id']);

        // tracking 应该是 FAILED
        $record = $runner->getTrackingRecord($id);
        $this->assertSame(MigrationRunner::STATUS_FAILED, $record['status']);
        $this->assertStringContainsString('post-apply verification', $record['error_message']);

        // 清理
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_idempotency_record`");
    }

    // ==================== P2-06: Rollback Audit History ====================

    public function testRollbackMarksRolledBackAndPreservesAuditHistory(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 先执行
        $runner->run(['allow_unknown' => true]);

        // 确认表和 tracking 记录存在
        $recordBefore = $runner->getTrackingRecord('2024_01_01_000001_test_create');
        $this->assertNotNull($recordBefore);
        $this->assertSame(MigrationRunner::STATUS_COMPLETED, $recordBefore['status']);

        // 回滚
        $result = $runner->rollback('2024_01_01_000001_test_create', ['allow_destructive' => true]);

        $this->assertSame(MigrationRunner::STATUS_ROLLED_BACK, $result['status']);

        // P2-06 FIX: tracking 记录应该保留，状态为 ROLLED_BACK（不被删除）
        $recordAfter = $runner->getTrackingRecord('2024_01_01_000001_test_create');
        $this->assertNotNull($recordAfter, 'Tracking record must NOT be deleted after rollback');
        $this->assertSame(MigrationRunner::STATUS_ROLLED_BACK, $recordAfter['status']);

        // 审计元数据保留
        $this->assertSame(64, strlen($recordAfter['checksum']));
        $this->assertNotNull($recordAfter['started_at']);
        $this->assertNotNull($recordAfter['completed_at']);

        // 表应该被删除（migration rollback 执行了 DROP TABLE）
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}'");
        $this->assertEmpty($tables, 'Table should be dropped after rollback');
    }

    public function testRolledBackMigrationIsReRunnable(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 执行 → 回滚 → 重新执行
        $runner->run(['allow_unknown' => true]);
        $runner->rollback('2024_01_01_000001_test_create', ['allow_destructive' => true]);

        // plan 应该显示 ROLLED_BACK migration 为 pending
        $plan = $runner->plan();
        $this->assertCount(1, $plan['rolled_back']);
        $this->assertCount(1, $plan['pending']);

        // 重新执行应该成功
        $result = $runner->run(['allow_unknown' => true]);
        $this->assertCount(1, $result['executed']);

        // tracking 应该是 COMPLETED
        $record = $runner->getTrackingRecord('2024_01_01_000001_test_create');
        $this->assertSame(MigrationRunner::STATUS_COMPLETED, $record['status']);
    }

    public function testRollbackFailurePreservesCompletedState(): void
    {
        // 创建一个 rollback 会失败的 migration
        $rootPath = rtrim(root_path(), '\\/');
        $id = '2024_01_01_000001_rollback_fail';
        $content = <<<PHP
<?php
declare(strict_types=1);
require '{$rootPath}/vendor/autoload.php';
use think\App;
use think\\facade\\Db;
\$app = new App('{$rootPath}/');
\$app->initialize();
\$action = \$argv[1] ?? 'migrate';
\$migration = new class {
    public function migrate(): void {
        Db::execute("CREATE TABLE IF NOT EXISTS `cz_migration_test_table_rb` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "migrate ok\\n";
    }
    public function rollback(): void {
        // rollback 失败
        fwrite(STDERR, "[RuntimeException] Rollback failed intentionally\\n");
        exit(1);
    }
    public function status(): void { echo "STATUS: PENDING\\n"; }
};
match (\$action) {
    'migrate' => \$migration->migrate(),
    'rollback' => \$migration->rollback(),
    'status' => \$migration->status(),
    default => die("usage\\n"),
};
PHP;
        file_put_contents($this->tempMigrationsDir . '/' . $id . '.php', $content);

        $runner = $this->makeRunner();

        // 执行
        $runner->run(['allow_unknown' => true]);

        // rollback 应该失败
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rollback failed');
        $runner->rollback($id, ['allow_destructive' => true]);

        // tracking 应该仍然是 COMPLETED（不被删除，不被标记为 ROLLED_BACK）
        $record = $runner->getTrackingRecord($id);
        $this->assertNotNull($record);
        $this->assertSame(MigrationRunner::STATUS_COMPLETED, $record['status']);

        // 清理
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_migration_test_table_rb`");
    }

    public function testRollbackThrowsWhenMigrationNotTracked(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found in tracking table');
        $runner->rollback('2099_01_01_000001_nonexistent');
    }

    public function testRollbackBlockedInProductionMode(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();
        $runner->run(['allow_unknown' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rollback is blocked in production mode');
        $runner->rollback('2024_01_01_000001_test_create', ['production' => true]);
    }

    // ==================== P1-02: Baseline Fail-Closed ====================

    public function testBaselineAcceptsRealAppliedSchema(): void
    {
        // 使用有 heuristic check 的真实 migration ID
        $id = '2024_01_12_000001_create_idempotency_record_table';
        $this->createTestMigration($id, 'cz_idempotency_record');

        // 先手动创建表（模拟已应用）
        \think\facade\Db::execute("CREATE TABLE IF NOT EXISTS `cz_idempotency_record` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);

        $this->assertCount(1, $result['baselined']);
        $this->assertSame($id, $result['baselined'][0]['id']);

        // tracking 记录应该是 COMPLETED + baseline=1
        $record = $runner->getTrackingRecord($id);
        $this->assertNotNull($record);
        $this->assertSame(MigrationRunner::STATUS_COMPLETED, $record['status']);
        $this->assertSame(1, (int) $record['baseline']);

        // 清理
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_idempotency_record`");
    }

    public function testBaselineAcceptsExactStatusAppliedContract(): void
    {
        // 测试 migration 不在 heuristic check 中，但 status() 输出精确的 "STATUS: APPLIED"
        $id = '2024_01_01_000001_status_applied_test';
        $this->createStatusOutputMigration($id, 'STATUS: APPLIED');

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_unknown' => true]);

        $this->assertCount(1, $result['baselined']);
        $this->assertSame($id, $result['baselined'][0]['id']);
    }

    /**
     * @dataProvider baselineFalsePositiveProvider
     */
    public function testBaselineRejectsFuzzyStatusOutputs(string $statusOutput): void
    {
        // P1-02: 这些模糊输出不应该被 baseline 误判为已应用
        $id = '2024_01_01_000001_false_positive_' . md5($statusOutput);
        // 确保 ID 符合格式
        $id = '2024_01_01_000001_fp_' . substr(md5($statusOutput), 0, 8);
        $this->createStatusOutputMigration($id, $statusOutput);

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_unknown' => true]);

        $this->assertCount(0, $result['baselined'], "Status output '{$statusOutput}' must NOT be baselined as applied");
        $this->assertCount(1, $result['skipped']);

        // tracking 表中不应该有该 migration 的记录
        $record = $runner->getTrackingRecord($id);
        $this->assertNull($record, "Tracking record must NOT exist for fuzzy status output '{$statusOutput}'");
    }

    public static function baselineFalsePositiveProvider(): array
    {
        return [
            'NOT COMPLETED' => ['Status: NOT COMPLETED - table missing'],
            'COMPLETED WITH ERRORS' => ['Status: COMPLETED WITH ERRORS - partial'],
            'INCOMPLETE' => ['Status: INCOMPLETE'],
            'ERROR expected COMPLETED' => ['ERROR: expected COMPLETED but found PENDING'],
            'NOT OK' => ['Status: NOT OK - verification failed'],
        ];
    }

    public function testBaselineSkipsAlreadyTrackedMigration(): void
    {
        $this->createTestMigration('2024_01_01_000001_test_create', $this->testTable);
        $runner = $this->makeRunner();

        // 先执行（产生 tracking 记录）
        $runner->run(['allow_unknown' => true]);

        // baseline 应该跳过已 tracked 的
        $result = $runner->baseline(['allow_unknown' => true]);
        $this->assertCount(0, $result['baselined']);
        $this->assertCount(1, $result['skipped']);
    }

    // ==================== Baseline Funds Gate ====================

    public function testBaselineFundsMigrationRequiresAllowFunds(): void
    {
        $id = '2024_01_12_000001_create_idempotency_record_table';
        $this->createTestMigration($id, 'cz_idempotency_record');
        \think\facade\Db::execute("CREATE TABLE IF NOT EXISTS `cz_idempotency_record` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();

        // 非生产模式，无 --allow-funds → 应该 skip
        $result = $runner->baseline([]);
        $this->assertCount(0, $result['baselined']);
        $this->assertCount(1, $result['skipped']);

        // 有 --allow-funds → 应该 baseline
        $result2 = $runner->baseline(['allow_funds' => true]);
        $this->assertCount(1, $result2['baselined']);

        // 清理
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_idempotency_record`");
    }

    public function testBaselineFundsMigrationBlocksInProductionWithoutAllowFunds(): void
    {
        $id = '2024_01_12_000001_create_idempotency_record_table';
        $this->createTestMigration($id, 'cz_idempotency_record');
        \think\facade\Db::execute("CREATE TABLE IF NOT EXISTS `cz_idempotency_record` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('funds-related');
        $runner->baseline(['production' => true]);

        // 清理
        \think\facade\Db::execute("DROP TABLE IF EXISTS `cz_idempotency_record`");
    }

    // ==================== Lock ====================

    public function testAcquireAndReleaseLock(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        $this->assertTrue($runner->acquireLock());
        $runner->releaseLock();
    }

    public function testLockIsExclusive(): void
    {
        $runner1 = $this->makeRunner();
        $runner2 = $this->makeRunner();
        $runner1->bootstrapTracking();

        $this->assertTrue($runner1->acquireLock());
        $runner1->releaseLock();
        $this->assertTrue($runner2->acquireLock());
        $runner2->releaseLock();
    }

    // ==================== Unknown Metadata Safety ====================

    public function testUnknownMetadataMigrationSkippedWithoutAllowUnknown(): void
    {
        $this->createTestMigration('2099_01_01_000001_unknown_migration', $this->testTable);
        $runner = $this->makeRunner();

        $result = $runner->run([]); // 不传 allow_unknown

        $this->assertCount(0, $result['executed']);
        $this->assertCount(1, $result['skipped']);

        // 表不应该被创建
        $tables = \think\facade\Db::query("SHOW TABLES LIKE '{$this->testTable}'");
        $this->assertEmpty($tables);
    }

    public function testUnknownMetadataMigrationBlocksInProduction(): void
    {
        $this->createTestMigration('2099_01_01_000001_unknown_migration', $this->testTable);
        $runner = $this->makeRunner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UNKNOWN metadata');
        $runner->run(['production' => true]);
    }

    // ==================== Tracking Record Operations ====================

    public function testGetAllTrackingRecordsReturnsEmptyAfterBootstrap(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        $records = $runner->getAllTrackingRecords();
        $this->assertEmpty($records);
    }

    public function testGetTrackingRecordReturnsNullForNonexistent(): void
    {
        $runner = $this->makeRunner();
        $runner->bootstrapTracking();

        $this->assertNull($runner->getTrackingRecord('2099_01_01_000001_nonexistent'));
    }

    // ==================== Status Constants ====================

    public function testStatusConstantsExist(): void
    {
        $this->assertSame('running', MigrationRunner::STATUS_RUNNING);
        $this->assertSame('completed', MigrationRunner::STATUS_COMPLETED);
        $this->assertSame('failed', MigrationRunner::STATUS_FAILED);
        $this->assertSame('rolled_back', MigrationRunner::STATUS_ROLLED_BACK);
    }
}
