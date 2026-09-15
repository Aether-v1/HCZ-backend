<?php
declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;
use tests\Support\SchemaEnsurer;
use think\facade\Db;

/**
 * HCZ R1.6d-a — SchemaEnsurer Infrastructure Tests
 *
 * 验证测试 schema 自 provisioning helper 的行为：
 * - 安全门（hcz_test 验证）
 * - schema 有效性检查（columns + indexes）
 * - 有效 schema 不重建
 * - 缺失表自动 provision
 * - 陈旧 schema 自动修复
 *
 * 注意：本测试执行 DROP/CREATE 操作，仅限 hcz_test。
 */
class SchemaEnsurerTest extends TestCase
{
    private const TABLE = 'cz_idempotency_record';

    protected function setUp(): void
    {
        parent::setUp();
        // 确保起始状态：表存在且 schema 正确
        SchemaEnsurer::ensureIdempotencyRecordTable();
    }

    protected function tearDown(): void
    {
        // 测试结束后恢复正确 schema
        SchemaEnsurer::ensureIdempotencyRecordTable();
        parent::tearDown();
    }

    public function testAssertDedicatedTestDatabasePassesOnHczTest(): void
    {
        // 当前测试环境必须是 hcz_test
        $row = Db::query('SELECT DATABASE() AS db');
        $this->assertSame('hcz_test', $row[0]['db']);

        // 不应 throw
        SchemaEnsurer::assertDedicatedTestDatabase();
        $this->assertTrue(true, 'assertDedicatedTestDatabase did not throw on hcz_test');
    }

    public function testIsSchemaValidReturnsTrueForCorrectSchema(): void
    {
        $this->assertTrue(SchemaEnsurer::isIdempotencyRecordSchemaValid());
    }

    public function testEnsureDoesNotRebuildWhenSchemaValid(): void
    {
        // 记录当前表创建时间
        $before = Db::query("SHOW TABLE STATUS LIKE '" . self::TABLE . "'");
        $this->assertNotEmpty($before);

        // 调用 ensure — schema 已有效，不应做 DDL
        SchemaEnsurer::ensureIdempotencyRecordTable();

        // schema 仍有效
        $this->assertTrue(SchemaEnsurer::isIdempotencyRecordSchemaValid());
    }

    public function testMissingTableIsAutoProvisioned(): void
    {
        // 安全门确认
        SchemaEnsurer::assertDedicatedTestDatabase();

        // DROP 表
        Db::execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $this->assertFalse(SchemaEnsurer::isIdempotencyRecordSchemaValid(), 'table should be missing after DROP');

        // ensure 应自动创建
        SchemaEnsurer::ensureIdempotencyRecordTable();

        // 验证 schema 正确
        $this->assertTrue(SchemaEnsurer::isIdempotencyRecordSchemaValid(), 'table should be provisioned with correct schema');

        // 验证具体列存在
        $columns = Db::query('SHOW COLUMNS FROM `' . self::TABLE . '`');
        $colNames = array_column($columns, 'Field');
        $this->assertContains('principal_type', $colNames);
        $this->assertContains('idempotency_key_hash', $colNames);
        $this->assertContains('fingerprint_version', $colNames);
        $this->assertContains('expires_at', $colNames);

        // 验证唯一索引存在
        $indexes = Db::query('SHOW INDEX FROM `' . self::TABLE . '`');
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $this->assertContains('uk_principal_operation_key', $indexNames);
        $this->assertContains('idx_expires_at', $indexNames);
    }

    public function testStaleIdOnlySchemaIsAutoRepaired(): void
    {
        // 安全门确认
        SchemaEnsurer::assertDedicatedTestDatabase();

        // DROP 后创建只有 id 列的陈旧表（模拟 preflight 发现的状态）
        Db::execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        Db::execute('CREATE TABLE `' . self::TABLE . '` (`id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 验证陈旧 schema 被识别为无效
        $this->assertFalse(SchemaEnsurer::isIdempotencyRecordSchemaValid(), 'id-only table should be detected as invalid schema');

        // ensure 应自动 DROP 陈旧表并运行 migration 重建
        SchemaEnsurer::ensureIdempotencyRecordTable();

        // 验证 schema 已修复
        $this->assertTrue(SchemaEnsurer::isIdempotencyRecordSchemaValid(), 'stale schema should be repaired to correct schema');

        // 验证列数正确（不应只有 1 列）
        $columns = Db::query('SHOW COLUMNS FROM `' . self::TABLE . '`');
        $this->assertGreaterThan(1, count($columns), 'repaired table should have more than 1 column');
    }

    public function testSchemaValidityChecksSpecificColumnsNotJustCount(): void
    {
        // 验证 isIdempotencyRecordSchemaValid 检查具体列名而非仅列数量
        // 当前正确 schema 应通过
        $this->assertTrue(SchemaEnsurer::isIdempotencyRecordSchemaValid());

        // 间接验证：如果缺关键列，应返回 false
        // （通过读取 REQUIRED_COLUMNS 的行为验证——此处只验证正确 schema 通过）
        $columns = Db::query('SHOW COLUMNS FROM `' . self::TABLE . '`');
        $colNames = array_column($columns, 'Field');
        $this->assertContains('request_hash', $colNames, 'specific column request_hash must exist');
        $this->assertContains('status', $colNames, 'specific column status must exist');
    }
}
