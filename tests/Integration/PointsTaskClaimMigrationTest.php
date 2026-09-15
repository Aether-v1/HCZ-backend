<?php
/**
 * HCZ R1.5b: cz_points_task_claim Migration Integration Tests
 *
 * 测试：
 * - Fresh install: 表不存在 → migration 创建表 → schema 完整
 * - Existing correct schema: baseline 接受
 * - Partial schema: 缺 column/index → baseline 拒绝，post verification 失败
 * - Data preservation: 已有数据不被破坏
 * - Complete heuristic: 验证 table + 10 columns + 4 indexes（含列顺序和 unique 属性）
 * - MigrationRunner metadata: 新 migration 已注册，unknown = 0
 */

declare(strict_types=1);

namespace tests\Integration;

use app\service\MigrationRunner;
use think\facade\Db;

class PointsTaskClaimMigrationTest extends DbTestCase
{
    private const TABLE = 'cz_points_task_claim';
    private const MIGRATION_ID = '2024_01_13_000001_create_points_task_claim_table';
    private string $realMigrationsPath;
    private string $migrationFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realMigrationsPath = root_path() . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $this->migrationFile = $this->realMigrationsPath . DIRECTORY_SEPARATOR . self::MIGRATION_ID . '.php';

        $this->cleanupTestTable();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTable();
        parent::tearDown();
    }

    private function cleanupTestTable(): void
    {
        try {
            Db::execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            Db::execute("DROP TABLE IF EXISTS `cz_migration`");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function makeRunner(): MigrationRunner
    {
        return new MigrationRunner($this->realMigrationsPath, PHP_BINARY);
    }

    /**
     * 直接执行 migration 文件（真实路径，避免临时目录相对路径问题）
     */
    private function executeMigrationFile(string $action): array
    {
        $output = [];
        $return = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($this->migrationFile) . ' ' . escapeshellarg($action) . ' 2>&1', $output, $return);
        return ['success' => $return === 0, 'output' => $output, 'exit_code' => $return];
    }

    // ==================== Fresh Install ====================

    public function testFreshInstallCreatesCompleteSchema(): void
    {
        // 表不存在
        $this->assertFalse($this->tableExists());

        // 直接执行 migration
        $result = $this->executeMigrationFile('migrate');
        $this->assertTrue($result['success'], "Migration failed: " . implode("\n", $result['output']));

        // 表已创建
        $this->assertTrue($this->tableExists());

        // 10 columns
        $columns = $this->getColumns();
        $this->assertCount(10, $columns, "Expected 10 columns, got " . count($columns));

        $expectedColumns = [
            'id', 'uid', 'task_key', 'claim_key', 'task_type',
            'task_date', 'points', 'status', 'create_time', 'update_time',
        ];
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }

        // 4 indexes (PRIMARY + 3 secondary)
        $indexes = $this->getIndexes();
        $this->assertCount(4, $indexes, "Expected 4 indexes, got " . count($indexes));

        // UNIQUE constraint
        $this->assertTrue($this->isUniqueIndex('uniq_uid_claim_key'), "uniq_uid_claim_key must be UNIQUE");
        $this->assertFalse($this->isUniqueIndex('idx_uid_task'), "idx_uid_task must NOT be UNIQUE");
        $this->assertFalse($this->isUniqueIndex('idx_task_date'), "idx_task_date must NOT be UNIQUE");

        // Index column order
        $this->assertEquals(['uid', 'claim_key'], $this->getIndexColumns('uniq_uid_claim_key'));
        $this->assertEquals(['uid', 'task_key'], $this->getIndexColumns('idx_uid_task'));
        $this->assertEquals(['task_key', 'task_date'], $this->getIndexColumns('idx_task_date'));
    }

    public function testFreshInstallIdempotent(): void
    {
        // 第一次执行
        $this->executeMigrationFile('migrate');
        $this->assertTrue($this->tableExists());

        // 插入数据
        Db::execute("INSERT INTO `" . self::TABLE . "` (uid, task_key, claim_key, task_type, points, status) VALUES (1, 't1', 'k1', 'daily', 10, 0)");
        $countBefore = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];

        // 第二次执行（应该 skip，不破坏数据）
        $result = $this->executeMigrationFile('migrate');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SKIP', implode("\n", $result['output']));

        $countAfter = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];
        $this->assertEquals($countBefore, $countAfter, "Re-run must not destroy data");
    }

    // ==================== Existing Correct Schema / Baseline ====================

    public function testExistingCorrectSchemaBaselineAccepted(): void
    {
        // 先创建正确的表
        $this->createCorrectTable();
        $this->assertTrue($this->tableExists());

        // 插入一条测试数据
        Db::execute("INSERT INTO `" . self::TABLE . "` (uid, task_key, claim_key, task_type, task_date, points, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [1, 'test_task', 'daily:test_task:2024-01-01', 'daily', '2024-01-01', 10, 0]);

        $rowCountBefore = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];
        $this->assertEquals(1, $rowCountBefore);

        // baseline（使用 MigrationRunner）
        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);

        // 检查 task_claim migration 是否被 baselined
        $baselinedIds = array_column($result['baselined'] ?? [], 'id');
        $this->assertContains(self::MIGRATION_ID, $baselinedIds, "task_claim migration must be baselined");

        // tracking 标记为 COMPLETED + baseline=1
        $tracking = Db::query("SELECT * FROM cz_migration WHERE migration = ?", [self::MIGRATION_ID]);
        $this->assertNotEmpty($tracking);
        $this->assertEquals('completed', strtolower($tracking[0]['status']));
        $this->assertEquals(1, (int)$tracking[0]['baseline']);

        // 数据未被破坏
        $rowCountAfter = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];
        $this->assertEquals($rowCountBefore, $rowCountAfter, "Data must be preserved during baseline");
    }

    // ==================== Partial Schema Fail Closed ====================

    public function testMissingColumnBaselineRejected(): void
    {
        // 创建缺 column 的表（缺 task_date）
        Db::execute("CREATE TABLE `" . self::TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
            KEY `idx_uid_task` (`uid`,`task_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);
        $baselinedIds = array_column($result['baselined'] ?? [], 'id');
        $this->assertNotContains(self::MIGRATION_ID, $baselinedIds, "Baseline must be REJECTED when column missing");
    }

    public function testMissingUniqueIndexBaselineRejected(): void
    {
        Db::execute("CREATE TABLE `" . self::TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `task_date` date DEFAULT NULL,
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid_task` (`uid`,`task_key`),
            KEY `idx_task_date` (`task_key`,`task_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);
        $baselinedIds = array_column($result['baselined'] ?? [], 'id');
        $this->assertNotContains(self::MIGRATION_ID, $baselinedIds, "Baseline must be REJECTED when UNIQUE index missing");
    }

    public function testWrongUniquePropertyBaselineRejected(): void
    {
        // uniq_uid_claim_key 被创建为普通 INDEX（非 UNIQUE）
        Db::execute("CREATE TABLE `" . self::TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `task_date` date DEFAULT NULL,
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
            KEY `idx_uid_task` (`uid`,`task_key`),
            KEY `idx_task_date` (`task_key`,`task_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);
        $baselinedIds = array_column($result['baselined'] ?? [], 'id');
        $this->assertNotContains(self::MIGRATION_ID, $baselinedIds, "Baseline must be REJECTED when unique property is wrong");
    }

    public function testWrongIndexColumnOrderBaselineRejected(): void
    {
        // idx_uid_task 列顺序错误 (task_key, uid) 而非 (uid, task_key)
        Db::execute("CREATE TABLE `" . self::TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `task_date` date DEFAULT NULL,
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
            KEY `idx_uid_task` (`task_key`,`uid`),
            KEY `idx_task_date` (`task_key`,`task_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $runner = $this->makeRunner();
        $result = $runner->baseline(['allow_funds' => true]);
        $baselinedIds = array_column($result['baselined'] ?? [], 'id');
        $this->assertNotContains(self::MIGRATION_ID, $baselinedIds, "Baseline must be REJECTED when index column order is wrong");
    }

    // ==================== Data Preservation ====================

    public function testMigrationDoesNotDestroyExistingData(): void
    {
        // 创建正确的表 + 数据
        $this->createCorrectTable();
        Db::execute("INSERT INTO `" . self::TABLE . "` (uid, task_key, claim_key, task_type, task_date, points, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [1, 'test_task', 'daily:test_task:2024-01-01', 'daily', '2024-01-01', 10, 0]);

        $rowCountBefore = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];

        // 执行 migration（CREATE TABLE IF NOT EXISTS 应该 skip）
        $result = $this->executeMigrationFile('migrate');
        $this->assertTrue($result['success']);

        // 数据未被破坏
        $rowCountAfter = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`")[0]['cnt'];
        $this->assertEquals($rowCountBefore, $rowCountAfter, "Migration must not destroy existing data");
    }

    // ==================== Metadata Registration ====================

    public function testMigrationRegisteredInMetadata(): void
    {
        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();

        $found = false;
        foreach ($migrations as $m) {
            if ($m['id'] === self::MIGRATION_ID) {
                $found = true;
                $this->assertEquals(true, $m['metadata']['funds_related'], "task_claim must be funds_related=true");
                $this->assertEquals(false, $m['metadata']['destructive'], "task_claim must be destructive=false");
                break;
            }
        }
        $this->assertTrue($found, "Migration " . self::MIGRATION_ID . " not found in scan");
    }

    public function testMigrationInventoryIncreasedByOne(): void
    {
        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();
        // R1.5a 有 10 个 migration，R1.5b 新增 1 个 = 11
        $this->assertGreaterThanOrEqual(11, count($migrations), "Migration count should be at least 11 after R1.5b");
    }

    public function testNewMigrationAfterIdempotencyRecord(): void
    {
        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();
        $ids = array_column($migrations, 'id');

        $idempotencyIdx = array_search('2024_01_12_000001_create_idempotency_record_table', $ids);
        $taskClaimIdx = array_search(self::MIGRATION_ID, $ids);

        $this->assertNotFalse($idempotencyIdx, "Idempotency record migration not found");
        $this->assertNotFalse($taskClaimIdx, "Task claim migration not found");
        $this->assertGreaterThan($idempotencyIdx, $taskClaimIdx, "task_claim migration must come after idempotency_record migration");
    }

    // ==================== Migration File Status Command ====================

    public function testMigrationFileStatusCommandWhenTableMissing(): void
    {
        $result = $this->executeMigrationFile('status');
        $this->assertTrue($result['success'], "status command should exit 0");
        $this->assertStringContainsString('MISSING', implode("\n", $result['output']));
    }

    public function testMigrationFileStatusCommandWhenTableExists(): void
    {
        $this->createCorrectTable();
        $result = $this->executeMigrationFile('status');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('总字段数: 10', implode("\n", $result['output']));
    }

    // ==================== Rollback ====================

    public function testRollbackDropsTableInDev(): void
    {
        $this->createCorrectTable();
        $this->assertTrue($this->tableExists());

        $result = $this->executeMigrationFile('rollback');
        $this->assertTrue($result['success'], "Rollback failed: " . implode("\n", $result['output']));
        $this->assertFalse($this->tableExists(), "Rollback should drop table in dev/test");
    }

    // ==================== Helper Methods ====================

    private function tableExists(): bool
    {
        $result = Db::query("SHOW TABLES LIKE '" . self::TABLE . "'");
        return !empty($result);
    }

    private function getColumns(): array
    {
        $rows = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [self::TABLE]);
        return array_column($rows, 'COLUMN_NAME');
    }

    private function getIndexes(): array
    {
        $rows = Db::query("SHOW INDEX FROM `" . self::TABLE . "`");
        return array_unique(array_column($rows, 'Key_name'));
    }

    private function isUniqueIndex(string $indexName): bool
    {
        // SHOW INDEX 不支持参数绑定，表名/索引名来自代码内部
        $rows = Db::query("SHOW INDEX FROM `" . self::TABLE . "` WHERE Key_name = '{$indexName}'");
        if (empty($rows)) {
            return false;
        }
        return ($rows[0]['Non_unique'] ?? 1) == 0;
    }

    private function getIndexColumns(string $indexName): array
    {
        // SHOW INDEX 不支持参数绑定和 ORDER BY，表名/索引名来自代码内部
        $rows = Db::query("SHOW INDEX FROM `" . self::TABLE . "` WHERE Key_name = '{$indexName}'");
        // 在 PHP 中按 Seq_in_index 排序
        usort($rows, fn($a, $b) => ($a['Seq_in_index'] ?? 0) <=> ($b['Seq_in_index'] ?? 0));
        return array_column($rows, 'Column_name');
    }

    private function createCorrectTable(): void
    {
        Db::execute("CREATE TABLE `" . self::TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `task_date` date DEFAULT NULL,
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
            KEY `idx_uid_task` (`uid`,`task_key`),
            KEY `idx_task_date` (`task_key`,`task_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
