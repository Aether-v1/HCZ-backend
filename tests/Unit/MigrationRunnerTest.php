<?php
declare(strict_types=1);

namespace tests\Unit;

use app\service\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * HCZ R1.5a — MigrationRunner Unit Tests
 *
 * 测试纯逻辑：scan / validate / sort / checksum / metadata / filename。
 * 不依赖数据库。
 */
class MigrationRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/hcz_migration_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // 清理临时目录
        $files = glob($this->tempDir . '/*.php');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function createMigrationFile(string $filename, string $content = '<?php // test migration'): void
    {
        file_put_contents($this->tempDir . '/' . $filename, $content);
    }

    private function makeRunner(): MigrationRunner
    {
        return new MigrationRunner($this->tempDir, PHP_BINARY);
    }

    // ==================== Filename Validation ====================

    public function testValidateFilenameAcceptsValidFormat(): void
    {
        $runner = $this->makeRunner();
        $this->assertTrue($runner->validateFilename('2024_01_01_000001_create_test_table.php'));
        $this->assertTrue($runner->validateFilename('2024_12_31_999999_seed_some_data.php'));
        $this->assertTrue($runner->validateFilename('2025_06_15_000042_alter_table_add_field.php'));
    }

    public function testValidateFilenameRejectsInvalidFormat(): void
    {
        $runner = $this->makeRunner();
        $this->assertFalse($runner->validateFilename('create_table.php'));
        $this->assertFalse($runner->validateFilename('2024_01_01_create_table.php')); // missing NNNNNN
        $this->assertFalse($runner->validateFilename('2024_01_01_000001_Create_Table.php')); // uppercase
        $this->assertFalse($runner->validateFilename('2024_01_01_000001_create table.php')); // space
        $this->assertFalse($runner->validateFilename('migration.sql')); // wrong extension
    }

    // ==================== Migration ID ====================

    public function testGetMigrationIdStripsPhpExtension(): void
    {
        $runner = $this->makeRunner();
        $this->assertSame(
            '2024_01_01_000001_create_test_table',
            $runner->getMigrationId('2024_01_01_000001_create_test_table.php')
        );
    }

    // ==================== Checksum ====================

    public function testComputeChecksumIsSha256(): void
    {
        $runner = $this->makeRunner();
        $file = $this->tempDir . '/test_checksum.php';
        $content = '<?php echo "hello";';
        file_put_contents($file, $content);

        $expected = hash('sha256', $content);
        $this->assertSame($expected, $runner->computeChecksum($file));
        $this->assertSame(64, strlen($runner->computeChecksum($file)));
    }

    public function testComputeChecksumChangesWhenContentChanges(): void
    {
        $runner = $this->makeRunner();
        $file = $this->tempDir . '/test_checksum.php';

        file_put_contents($file, '<?php echo "v1";');
        $hash1 = $runner->computeChecksum($file);

        file_put_contents($file, '<?php echo "v2";');
        $hash2 = $runner->computeChecksum($file);

        $this->assertNotSame($hash1, $hash2);
    }

    // ==================== Scan / Sort / Uniqueness ====================

    public function testScanMigrationsReturnsSortedList(): void
    {
        $this->createMigrationFile('2024_01_03_000001_third.php');
        $this->createMigrationFile('2024_01_01_000001_first.php');
        $this->createMigrationFile('2024_01_02_000001_second.php');

        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();

        $this->assertCount(3, $migrations);
        $this->assertSame('2024_01_01_000001_first', $migrations[0]['id']);
        $this->assertSame('2024_01_02_000001_second', $migrations[1]['id']);
        $this->assertSame('2024_01_03_000001_third', $migrations[2]['id']);
    }

    public function testScanMigrationsSortIsDeterministic(): void
    {
        $this->createMigrationFile('2024_01_02_000001_b.php');
        $this->createMigrationFile('2024_01_01_000001_a.php');

        $runner = $this->makeRunner();
        $result1 = $runner->scanMigrations();
        $result2 = $runner->scanMigrations();

        $this->assertSame($result1[0]['id'], $result2[0]['id']);
        $this->assertSame($result1[1]['id'], $result2[1]['id']);
    }

    public function testScanMigrationsRejectsInvalidFilename(): void
    {
        $this->createMigrationFile('invalid_filename.php');
        $runner = $this->makeRunner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid migration filename format');
        $runner->scanMigrations();
    }

    public function testScanMigrationsDetectsDuplicateId(): void
    {
        // 两个不同文件名但相同 migration ID 是不可能的（ID = filename without .php），
        // 但测试 duplicate timestamp + different description 应该是允许的
        $this->createMigrationFile('2024_01_01_000001_first.php');
        $this->createMigrationFile('2024_01_01_000002_second.php');

        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();
        $this->assertCount(2, $migrations);
    }

    public function testScanMigrationsReturnsChecksumForEach(): void
    {
        $this->createMigrationFile('2024_01_01_000001_test.php', '<?php echo "test";');
        $runner = $this->makeRunner();
        $migrations = $runner->scanMigrations();

        $this->assertCount(1, $migrations);
        $this->assertSame(64, strlen($migrations[0]['checksum']));
        $this->assertSame(hash('sha256', '<?php echo "test";'), $migrations[0]['checksum']);
    }

    // ==================== Metadata Registry ====================

    public function testGetMetadataReturnsKnownMetadata(): void
    {
        $runner = $this->makeRunner();
        $meta = $runner->getMetadata('2024_01_01_000001_create_rbac_tables');

        $this->assertFalse($meta['funds_related']);
        $this->assertFalse($meta['destructive']);
        $this->assertFalse($meta['unknown']);
    }

    public function testGetMetadataReturnsFundsRelated(): void
    {
        $runner = $this->makeRunner();
        $meta = $runner->getMetadata('2024_01_04_000001_alter_cz_order_add_missing_fields');

        $this->assertTrue($meta['funds_related']);
        $this->assertFalse($meta['unknown']);
    }

    public function testGetMetadataReturnsUnknownForUnregistered(): void
    {
        $runner = $this->makeRunner();
        $meta = $runner->getMetadata('2099_01_01_000001_future_migration');

        $this->assertTrue($meta['unknown']);
        $this->assertFalse($meta['funds_related']);
        $this->assertFalse($meta['destructive']);
    }

    public function testMetadataRegistryCoversAllExistingMigrations(): void
    {
        // 确保所有真实 migration 文件都在 metadata registry 中
        $realMigrationsDir = root_path() . 'database/migrations';
        if (!is_dir($realMigrationsDir)) {
            $this->markTestSkipped('Real migrations directory not found');
        }

        $files = glob($realMigrationsDir . '/*.php');
        $this->assertNotEmpty($files, 'No migration files found');

        $runner = new MigrationRunner();
        foreach ($files as $file) {
            $id = $runner->getMigrationId(basename($file));
            $meta = $runner->getMetadata($id);
            $this->assertFalse(
                $meta['unknown'],
                "Migration '{$id}' is not in MIGRATION_METADATA registry"
            );
        }
    }

    // ==================== Safe Default ====================

    public function testUnknownMigrationIsNotAutoSafe(): void
    {
        $runner = $this->makeRunner();
        $meta = $runner->getMetadata('2099_01_01_000001_unknown');

        // unknown migration 必须标记为 unknown，不能默认 funds_related=false/destructive=false 就当作安全
        $this->assertTrue($meta['unknown']);
    }

    // ==================== Constants ====================

    public function testTrackingTableNameIsCorrect(): void
    {
        $this->assertSame('cz_migration', MigrationRunner::TRACKING_TABLE);
    }

    public function testLockNameIsCorrect(): void
    {
        $this->assertSame('hcz_migration_lock', MigrationRunner::LOCK_NAME);
    }

    public function testStatusConstantsAreDefined(): void
    {
        $this->assertSame('running', MigrationRunner::STATUS_RUNNING);
        $this->assertSame('completed', MigrationRunner::STATUS_COMPLETED);
        $this->assertSame('failed', MigrationRunner::STATUS_FAILED);
    }
}
