<?php
declare(strict_types=1);

namespace tests\Integration;

use think\facade\Db;

/**
 * R1.3b: Idempotency Record Migration Tests
 *
 * fresh apply / repeat apply / status / schema columns / column types / unique index / expires index / rollback / apply again
 */
class IdempotencyMigrationTest extends DbTestCase
{
    private string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationPath = dirname(__DIR__, 2) . '/database/migrations/2024_01_12_000001_create_idempotency_record_table.php';
        // 确保从干净状态开始
        Db::execute('DROP TABLE IF EXISTS cz_idempotency_record');
    }

    protected function tearDown(): void
    {
        // 确保恢复为正确 schema 的表（不兼容表也必须重建）
        Db::execute('DROP TABLE IF EXISTS cz_idempotency_record');
        $this->runMigration('migrate');
        Db::execute('DELETE FROM cz_idempotency_record');
        parent::tearDown();
    }

    private function runMigration(string $action): string
    {
        $output = [];
        $returnCode = 0;
        exec('php ' . escapeshellarg($this->migrationPath) . ' ' . $action . ' 2>&1', $output, $returnCode);
        $this->assertSame(0, $returnCode, "migration {$action} failed: " . implode("\n", $output));
        return implode("\n", $output);
    }

    // ==================== fresh apply ====================

    public function testFreshApplyCreatesTable(): void
    {
        $output = $this->runMigration('migrate');
        $this->assertStringContainsString('CREATE TABLE cz_idempotency_record: OK', $output);

        $exists = Db::query("SHOW TABLES LIKE 'cz_idempotency_record'");
        $this->assertNotEmpty($exists, 'table must exist after fresh migrate');
    }

    // ==================== repeat apply (idempotent) ====================

    public function testRepeatApplySafeNoError(): void
    {
        $this->runMigration('migrate');
        $output = $this->runMigration('migrate'); // second time
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString('Schema check PASSED', $output);

        // 表仍然存在且只有 1 个
        $exists = Db::query("SHOW TABLES LIKE 'cz_idempotency_record'");
        $this->assertNotEmpty($exists);
    }

    // ==================== status ====================

    public function testStatusShowsTableAndIndexes(): void
    {
        $this->runMigration('migrate');
        $output = $this->runMigration('status');
        $this->assertStringContainsString('cz_idempotency_record', $output);
        $this->assertStringContainsString('EXISTS', $output);
        $this->assertStringContainsString('uk_principal_operation_key', $output);
        $this->assertStringContainsString('idx_expires_at', $output);
    }

    // ==================== schema columns ====================

    public function testSchemaHasAllRequiredColumns(): void
    {
        $this->runMigration('migrate');
        $columns = Db::query("SHOW COLUMNS FROM cz_idempotency_record");
        $colNames = array_column($columns, 'Field');

        $required = [
            'id', 'principal_type', 'principal_id', 'operation',
            'idempotency_key_hash', 'request_hash', 'fingerprint_version',
            'status', 'http_status', 'response_body',
            'resource_type', 'resource_id',
            'create_time', 'update_time', 'expires_at',
        ];
        foreach ($required as $col) {
            $this->assertContains($col, $colNames, "missing column: {$col}");
        }
        $this->assertCount(15, $colNames, 'exactly 15 columns expected');
    }

    // ==================== column types ====================

    public function testColumnTypesCorrect(): void
    {
        $this->runMigration('migrate');
        $columns = Db::query("SHOW COLUMNS FROM cz_idempotency_record");
        $byName = [];
        foreach ($columns as $col) {
            $byName[$col['Field']] = $col;
        }

        $this->assertStringContainsString('bigint unsigned', $byName['id']['Type']);
        $this->assertStringContainsString('varchar(16)', $byName['principal_type']['Type']);
        $this->assertStringContainsString('bigint', $byName['principal_id']['Type']);
        $this->assertStringContainsString('varchar(64)', $byName['operation']['Type']);
        $this->assertStringContainsString('char(64)', $byName['idempotency_key_hash']['Type']);
        $this->assertStringContainsString('char(64)', $byName['request_hash']['Type']);
        $this->assertStringContainsString('smallint unsigned', $byName['fingerprint_version']['Type']);
        $this->assertStringContainsString('varchar(16)', $byName['status']['Type']);
        $this->assertStringContainsString('smallint', $byName['http_status']['Type']);
        $this->assertStringContainsString('text', $byName['response_body']['Type']);
        $this->assertStringContainsString('varchar(32)', $byName['resource_type']['Type']);
        $this->assertStringContainsString('varchar(64)', $byName['resource_id']['Type']);
        $this->assertStringContainsString('datetime', $byName['create_time']['Type']);
        $this->assertStringContainsString('datetime', $byName['update_time']['Type']);
        $this->assertStringContainsString('datetime', $byName['expires_at']['Type']);
    }

    // ==================== unique index ====================

    public function testUniqueIndexExistsOnPrincipalOperationKey(): void
    {
        $this->runMigration('migrate');
        $indexes = Db::query("SHOW INDEX FROM cz_idempotency_record WHERE Key_name = 'uk_principal_operation_key'");
        $this->assertNotEmpty($indexes, 'unique index uk_principal_operation_key must exist');

        $indexCols = array_column($indexes, 'Column_name');
        $this->assertContains('principal_type', $indexCols);
        $this->assertContains('principal_id', $indexCols);
        $this->assertContains('operation', $indexCols);
        $this->assertContains('idempotency_key_hash', $indexCols);

        // Non_unique = 0 means unique
        $this->assertSame('0', (string) $indexes[0]['Non_unique']);
    }

    // ==================== expires index ====================

    public function testExpiresAtIndexExists(): void
    {
        $this->runMigration('migrate');
        $indexes = Db::query("SHOW INDEX FROM cz_idempotency_record WHERE Key_name = 'idx_expires_at'");
        $this->assertNotEmpty($indexes, 'index idx_expires_at must exist');
        $this->assertContains('expires_at', array_column($indexes, 'Column_name'));
    }

    // ==================== no FK ====================

    public function testNoForeignKeys(): void
    {
        $this->runMigration('migrate');
        $fks = Db::query("SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cz_idempotency_record' AND REFERENCED_TABLE_NAME IS NOT NULL");
        $this->assertEmpty($fks, 'table must have NO foreign keys');
    }

    // ==================== rollback ====================

    public function testRollbackDropsTable(): void
    {
        $this->runMigration('migrate');
        $output = $this->runMigration('rollback');
        $this->assertStringContainsString('DROP TABLE cz_idempotency_record: OK', $output);

        $exists = Db::query("SHOW TABLES LIKE 'cz_idempotency_record'");
        $this->assertEmpty($exists, 'table must be dropped after rollback');
    }

    // ==================== rollback then apply again ====================

    public function testRollbackThenApplyAgain(): void
    {
        $this->runMigration('migrate');
        $this->runMigration('rollback');
        $output = $this->runMigration('migrate');
        $this->assertStringContainsString('CREATE TABLE cz_idempotency_record: OK', $output);

        $exists = Db::query("SHOW TABLES LIKE 'cz_idempotency_record'");
        $this->assertNotEmpty($exists);
    }

    // ==================== table engine / charset / comment ====================

    public function testTableEngineCharsetComment(): void
    {
        $this->runMigration('migrate');
        $status = Db::query("SHOW TABLE STATUS LIKE 'cz_idempotency_record'");
        $this->assertNotEmpty($status);
        $this->assertSame('InnoDB', $status[0]['Engine']);
        $this->assertStringContainsString('utf8mb4', $status[0]['Collation']);
        $this->assertStringContainsString('Idempotency', $status[0]['Comment']);
    }

    // ==================== existing-table schema mismatch detection ====================

    public function testExistingTableWithMismatchedColumnsThrows(): void
    {
        // 创建一个不兼容的表（缺少关键列）
        Db::execute("CREATE TABLE cz_idempotency_record (id INT PRIMARY KEY, foo VARCHAR(10))");

        $output = [];
        $returnCode = 0;
        exec('php ' . escapeshellarg($this->migrationPath) . ' migrate 2>&1', $output, $returnCode);

        $this->assertNotSame(0, $returnCode, 'migration must FAIL on schema mismatch');
        $full = implode("\n", $output);
        $this->assertStringContainsString('MISMATCH', $full);
    }
}
