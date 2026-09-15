<?php
declare(strict_types=1);

namespace tests\Support;

use think\facade\Db;

/**
 * HCZ R1.6d-a — Test Schema Self-Provisioning Helper
 *
 * 职责限定：确保测试所需的特定表存在且 schema 正确。
 * 不做泛化的全库 schema 管理。
 *
 * 安全原则：
 * - 任何 destructive 操作（DROP TABLE）前必须验证当前 DB === hcz_test
 * - schema source of truth = 正式 migration 文件，不复制 DDL
 * - 每次 setUp 实际检查 columns/indexes，不使用 static cache
 *   （因为 MigrationTest 可能在后续测试中 DROP 表）
 */
class SchemaEnsurer
{
    private const IDEMPOTENCY_TABLE = 'cz_idempotency_record';

    private const IDEMPOTENCY_MIGRATION = __DIR__ . '/../../database/migrations/2024_01_12_000001_create_idempotency_record_table.php';

    /** 与正式 migration $requiredColumns 一致 */
    private const REQUIRED_COLUMNS = [
        'id', 'principal_type', 'principal_id', 'operation',
        'idempotency_key_hash', 'request_hash', 'fingerprint_version',
        'status', 'http_status', 'response_body',
        'resource_type', 'resource_id',
        'create_time', 'update_time', 'expires_at',
    ];

    /** 与正式 migration $requiredIndexes 一致 */
    private const REQUIRED_INDEXES = [
        'uk_principal_operation_key',
        'idx_expires_at',
    ];

    /**
     * 安全门：验证当前连接的数据库是专用测试库 hcz_test。
     * 任何 destructive 操作前必须调用。
     * 不通过则 throw，避免误操作非测试库。
     */
    public static function assertDedicatedTestDatabase(): void
    {
        $row = Db::query('SELECT DATABASE() AS db');
        $currentDb = $row[0]['db'] ?? '';
        if ($currentDb !== 'hcz_test') {
            throw new \RuntimeException(
                "SchemaEnsurer: refusing destructive operation — current database is '{$currentDb}', expected 'hcz_test'."
            );
        }
    }

    /**
     * 确保 cz_idempotency_record 表存在且 schema 正确。
     *
     * 逻辑：
     * 1. 验证安全门（hcz_test）
     * 2. 检查 schema 是否有效（表存在 + 全部 required columns + required indexes）
     * 3. 若有效：直接返回（不做不必要 DDL）
     * 4. 若无效或表不存在：DROP 旧表（如有）→ 运行正式 migration → 重新验证
     *
     * 每次调用都实际检查，不使用 static cache。
     */
    public static function ensureIdempotencyRecordTable(): void
    {
        self::assertDedicatedTestDatabase();

        if (self::isIdempotencyRecordSchemaValid()) {
            return;
        }

        // schema 无效或表缺失：DROP 陈旧表（如有），然后运行正式 migration
        if (self::tableExists(self::IDEMPOTENCY_TABLE)) {
            Db::execute('DROP TABLE IF EXISTS `' . self::IDEMPOTENCY_TABLE . '`');
        }

        self::runCanonicalMigration('migrate');

        // migration 执行后必须重新验证（不能只相信 exit code）
        if (!self::isIdempotencyRecordSchemaValid()) {
            throw new \RuntimeException(
                'SchemaEnsurer: canonical idempotency migration completed but schema still invalid after verification.'
            );
        }
    }

    /**
     * 检查 cz_idempotency_record schema 是否有效。
     * 检查表存在 + 全部 required columns + required indexes。
     * 不使用列数量启发式，检查具体列名和索引名。
     */
    public static function isIdempotencyRecordSchemaValid(): bool
    {
        if (!self::tableExists(self::IDEMPOTENCY_TABLE)) {
            return false;
        }

        $columns = Db::query('SHOW COLUMNS FROM `' . self::IDEMPOTENCY_TABLE . '`');
        $colNames = array_column($columns, 'Field');
        $missingCols = array_diff(self::REQUIRED_COLUMNS, $colNames);
        if (!empty($missingCols)) {
            return false;
        }

        $indexes = Db::query('SHOW INDEX FROM `' . self::IDEMPOTENCY_TABLE . '`');
        $indexNames = array_unique(array_column($indexes, 'Key_name'));
        $missingIndexes = array_diff(self::REQUIRED_INDEXES, $indexNames);
        if (!empty($missingIndexes)) {
            return false;
        }

        return true;
    }

    private static function tableExists(string $table): bool
    {
        $result = Db::query("SHOW TABLES LIKE '{$table}'");
        return !empty($result);
    }

    /**
     * 运行正式 migration 脚本（独立 PHP 进程，复用 canonical migration 作为 schema source of truth）。
     * 不复制 DDL，避免 production migration 与 test helper 两套 schema 漂移。
     */
    private static function runCanonicalMigration(string $action): void
    {
        $migrationPath = realpath(self::IDEMPOTENCY_MIGRATION);
        if ($migrationPath === false) {
            throw new \RuntimeException(
                'SchemaEnsurer: canonical idempotency migration file not found at ' . self::IDEMPOTENCY_MIGRATION
            );
        }

        $phpBin = PHP_BINARY ?: 'php';
        $cmd = $phpBin . ' ' . escapeshellarg($migrationPath) . ' ' . escapeshellarg($action) . ' 2>&1';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException(
                "SchemaEnsurer: canonical idempotency migration '{$action}' failed (exit code {$returnCode}): "
                . implode("\n", $output)
            );
        }
    }
}
