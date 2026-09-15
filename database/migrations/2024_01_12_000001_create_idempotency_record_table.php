<?php
/**
 * HCZ R1.3b — HTTP Idempotency Record Table Migration
 *
 * 功能：创建 cz_idempotency_record 幂等记录表
 *
 * 设计原则：
 * - 幂等：CREATE TABLE IF NOT EXISTS，可重复执行
 * - 可回滚：提供 rollback() 方法
 * - 遵循项目现有规范：bigint unsigned 主键、datetime、uk_/idx_ 索引、utf8mb4、无外键
 * - MySQL 5.7 兼容：无 JSON column、无生成列、无 CHECK、无 SKIP LOCKED
 * - existing-table safety：表已存在时检查关键 columns/index，mismatch 则 STOP
 *
 * 用法：
 *   php database/migrations/2024_01_12_000001_create_idempotency_record_table.php migrate
 *   php database/migrations/2024_01_12_000001_create_idempotency_record_table.php rollback
 *   php database/migrations/2024_01_12_000001_create_idempotency_record_table.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private string $table = 'cz_idempotency_record';

    /** 关键 column 定义（用于 existing-table safety 检查） */
    private array $requiredColumns = [
        'id', 'principal_type', 'principal_id', 'operation',
        'idempotency_key_hash', 'request_hash', 'fingerprint_version',
        'status', 'http_status', 'response_body',
        'resource_type', 'resource_id',
        'create_time', 'update_time', 'expires_at',
    ];

    private array $requiredIndexes = [
        'uk_principal_operation_key',
        'idx_expires_at',
    ];

    public function migrate(): void
    {
        echo "=== HCZ R1.3b Idempotency Record Migration ===\n\n";

        // existing-table safety：表已存在时检查 schema
        $exists = Db::query("SHOW TABLES LIKE '{$this->table}'");
        if (!empty($exists)) {
            echo "Table {$this->table} already exists, checking schema...\n";
            $this->assertExistingSchema();
            echo "Schema check PASSED (existing table compatible).\n";
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$this->table}` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `principal_type` varchar(16) NOT NULL DEFAULT 'user' COMMENT '主体类型 user/admin',
  `principal_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '主体ID（来自可信认证上下文）',
  `operation` varchar(64) NOT NULL DEFAULT '' COMMENT '稳定操作标识 order.create/withdraw.create',
  `idempotency_key_hash` char(64) NOT NULL DEFAULT '' COMMENT 'Idempotency-Key SHA-256 hex（不存 raw key）',
  `request_hash` char(64) NOT NULL DEFAULT '' COMMENT 'validated payload CanonicalFingerprint SHA-256',
  `fingerprint_version` smallint unsigned NOT NULL DEFAULT '1' COMMENT 'CanonicalFingerprint 算法版本',
  `status` varchar(16) NOT NULL DEFAULT 'processing' COMMENT 'processing/completed/failed_retryable',
  `http_status` smallint unsigned DEFAULT NULL COMMENT '重放 HTTP 状态码',
  `response_body` text COMMENT '重放 logical payload JSON（非完整 HTTP envelope）',
  `resource_type` varchar(32) DEFAULT NULL COMMENT '关联资源类型 order/withdraw（可空）',
  `resource_id` varchar(64) DEFAULT NULL COMMENT '关联资源ID（可空）',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime NOT NULL COMMENT '更新时间',
  `expires_at` datetime NOT NULL COMMENT '过期时间（TTL 截止）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_principal_operation_key` (`principal_type`,`principal_id`,`operation`,`idempotency_key_hash`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='HTTP Idempotency 幂等记录'
SQL;
            Db::execute($sql);
            echo "  CREATE TABLE {$this->table}: OK\n";
        }

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ R1.3b Idempotency Record Rollback ===\n\n";
        Db::execute("DROP TABLE IF EXISTS `{$this->table}`");
        echo "  DROP TABLE {$this->table}: OK\n";
        echo "\n=== Rollback 完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== 表状态 ===\n";
        try {
            $result = Db::query("SHOW TABLES LIKE '{$this->table}'");
            $exists = !empty($result);
            echo sprintf("  %-30s %s\n", $this->table, $exists ? 'EXISTS' : 'MISSING');

            if ($exists) {
                $columns = Db::query("SHOW COLUMNS FROM `{$this->table}`");
                $colNames = array_column($columns, 'Field');
                echo sprintf("    columns: %d (%s)\n", count($colNames), implode(', ', $colNames));

                $indexes = Db::query("SHOW INDEX FROM `{$this->table}`");
                $indexNames = array_unique(array_column($indexes, 'Key_name'));
                echo sprintf("    indexes: %s\n", implode(', ', $indexNames));
            }
        } catch (\Throwable $e) {
            echo sprintf("  %-30s ERROR: %s\n", $this->table, $e->getMessage());
        }
    }

    /**
     * existing-table safety：检查已存在表的关键 columns/index 是否匹配
     * mismatch 则 throw（STOP，不把错误 schema 当 migrate success）
     */
    private function assertExistingSchema(): void
    {
        $columns = Db::query("SHOW COLUMNS FROM `{$this->table}`");
        $colNames = array_column($columns, 'Field');

        $missingCols = array_diff($this->requiredColumns, $colNames);
        if (!empty($missingCols)) {
            throw new \RuntimeException(
                "Existing table {$this->table} schema MISMATCH: missing columns: "
                . implode(', ', $missingCols)
                . " — STOP. Do not overwrite. Manual inspection required."
            );
        }

        $indexes = Db::query("SHOW INDEX FROM `{$this->table}`");
        $indexNames = array_unique(array_column($indexes, 'Key_name'));

        $missingIndexes = array_diff($this->requiredIndexes, $indexNames);
        if (!empty($missingIndexes)) {
            throw new \RuntimeException(
                "Existing table {$this->table} schema MISMATCH: missing indexes: "
                . implode(', ', $missingIndexes)
                . " — STOP. Do not overwrite."
            );
        }
    }
};

try {
    match ($action) {
        'migrate' => $migration->migrate(),
        'rollback' => $migration->rollback(),
        'status' => $migration->status(),
        default => die("Unknown action: {$action}\nUsage: migrate|rollback|status\n"),
    };
} catch (\Throwable $e) {
    fwrite(STDERR, "\n[MIGRATION ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
