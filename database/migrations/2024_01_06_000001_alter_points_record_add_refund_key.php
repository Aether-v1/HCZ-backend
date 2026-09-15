<?php
/**
 * HCZ INFO-020-B: cz_points_record 增加 refund_key 返还幂等键 Migration
 *
 * 背景：
 *   当前积分返还（单查话费/电费、批量话费/电费）仅依赖 Redis SET NX + EX 应用层幂等。
 *   Redis 不可用 / Redis 幂等失效 + at-least-once 重试时，可能发生重复返还。
 *   本 migration 增加 refund_key 可空唯一键列，作为 Redis SETNX 之外的 DB 持久化兜底。
 *
 * 新增字段（1 个）：
 *   refund_key  varchar(255) NULL DEFAULT NULL  COMMENT '返还幂等键 Redis SETNX key 的 DB 层兜底'
 *
 * 新增索引（1 个）：
 *   uk_refund_key  UNIQUE (refund_key)
 *
 * 设计原则：
 *   - 可空列：非返还记录 refund_key = NULL，MySQL UNIQUE 允许多个 NULL，不影响现有业务
 *   - 幂等：仅返还记录填写完整 Redis key（如 tg:single:refund:{message_id}:{phone}）
 *   - 不修改现有数据：新列默认 NULL，现有行自动填充
 *   - 可回滚：rollback() 逆序删除索引和字段
 *   - 不解决 SETNX 成功 → crash → addPoints 未执行 → 漏返窗口（Finding A 保持 P3 DEFERRED）
 *
 * 用法：
 *   php database/migrations/2024_01_06_000001_alter_points_record_add_refund_key.php migrate
 *   php database/migrations/2024_01_06_000001_alter_points_record_add_refund_key.php rollback
 *   php database/migrations/2024_01_06_000001_alter_points_record_add_refund_key.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_points_record';

    /**
     * 字段定义：name => column DDL
     */
    private array $columns = [
        'refund_key' => "varchar(255) NULL DEFAULT NULL COMMENT '返还幂等键 Redis SETNX key 的 DB 层兜底'",
    ];

    /**
     * 索引定义：name => ['unique' => bool, 'columns' => string]
     */
    private array $indexes = [
        'uk_refund_key' => ['unique' => true, 'columns' => '(`refund_key`)'],
    ];

    public function migrate(): void
    {
        echo "=== HCZ INFO-020-B: cz_points_record 增加 refund_key 返还幂等键 ===\n\n";

        if (!$this->tableExists()) {
            echo "[BLOCKED] cz_points_record 表不存在，无法执行 migration\n";
            return;
        }
        echo "[OK] cz_points_record 表存在\n\n";

        // 1. 添加缺失字段
        echo "--- 添加缺失字段 ---\n";
        $addedColumns = 0;
        foreach ($this->columns as $name => $ddl) {
            if ($this->columnExists($name)) {
                echo "  [SKIP] {$name} 已存在\n";
                continue;
            }
            $sql = "ALTER TABLE `" . self::TABLE . "` ADD COLUMN `{$name}` {$ddl}";
            Db::execute($sql);
            echo "  [OK] ADD COLUMN {$name}\n";
            $addedColumns++;
        }
        echo "  新增字段: {$addedColumns} 个（跳过 " . (count($this->columns) - $addedColumns) . " 个已存在）\n\n";

        // 2. 添加缺失索引
        echo "--- 添加缺失索引 ---\n";
        $addedIndexes = 0;
        foreach ($this->indexes as $name => $def) {
            if ($this->indexExists($name)) {
                echo "  [SKIP] {$name} 已存在\n";
                continue;
            }
            $unique = $def['unique'] ? 'UNIQUE ' : '';
            $sql = "ALTER TABLE `" . self::TABLE . "` ADD {$unique}INDEX `{$name}` {$def['columns']}";
            Db::execute($sql);
            echo "  [OK] ADD {$unique}INDEX {$name} {$def['columns']}\n";
            $addedIndexes++;
        }
        echo "  新增索引: {$addedIndexes} 个（跳过 " . (count($this->indexes) - $addedIndexes) . " 个已存在）\n\n";

        echo "=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ INFO-020-B: cz_points_record refund_key 回滚 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_points_record 表不存在，无需回滚\n";
            return;
        }
        echo "[OK] cz_points_record 表存在\n\n";

        // 1. 先删除索引（逆序）
        echo "--- 删除索引 ---\n";
        $droppedIndexes = 0;
        foreach (array_reverse($this->indexes, true) as $name => $def) {
            if (!$this->indexExists($name)) {
                echo "  [SKIP] {$name} 不存在\n";
                continue;
            }
            Db::execute("ALTER TABLE `" . self::TABLE . "` DROP INDEX `{$name}`");
            echo "  [OK] DROP INDEX {$name}\n";
            $droppedIndexes++;
        }
        echo "  删除索引: {$droppedIndexes} 个\n\n";

        // 2. 再删除字段（逆序）
        echo "--- 删除字段 ---\n";
        $droppedColumns = 0;
        foreach (array_reverse($this->columns, true) as $name => $ddl) {
            if (!$this->columnExists($name)) {
                echo "  [SKIP] {$name} 不存在\n";
                continue;
            }
            Db::execute("ALTER TABLE `" . self::TABLE . "` DROP COLUMN `{$name}`");
            echo "  [OK] DROP COLUMN {$name}\n";
            $droppedColumns++;
        }
        echo "  删除字段: {$droppedColumns} 个\n\n";

        echo "=== 回滚完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n=== cz_points_record 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_points_record: MISSING\n";
            return;
        }

        // 字段统计
        $allCols = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION");
        $allColNames = array_column($allCols, 'COLUMN_NAME');
        echo "  总字段数: " . count($allColNames) . "\n";
        echo "  字段列表: " . implode(', ', $allColNames) . "\n";

        $missingCols = array_filter(array_keys($this->columns), fn($c) => !in_array($c, $allColNames, true));
        echo "  本 migration 目标字段: " . count($this->columns) . "（缺失: " . count($missingCols) . "）\n";
        if (!empty($missingCols)) {
            echo "  缺失字段: " . implode(', ', $missingCols) . "\n";
        }

        // refund_key 详情
        if (in_array('refund_key', $allColNames, true)) {
            $refundCol = Db::query("SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' AND COLUMN_NAME = 'refund_key'");
            echo "  refund_key: type={$refundCol[0]['COLUMN_TYPE']} nullable={$refundCol[0]['IS_NULLABLE']} default=" . var_export($refundCol[0]['COLUMN_DEFAULT'], true) . "\n";
        }

        // 索引统计
        $allIdx = Db::query("SHOW INDEX FROM `" . self::TABLE . "`");
        $allIdxNames = array_unique(array_column($allIdx, 'Key_name'));
        echo "  总索引数: " . count($allIdxNames) . "\n";
        echo "  索引列表: " . implode(', ', $allIdxNames) . "\n";

        $missingIdx = array_filter(array_keys($this->indexes), fn($i) => !in_array($i, $allIdxNames, true));
        echo "  本 migration 目标索引: " . count($this->indexes) . "（缺失: " . count($missingIdx) . "）\n";
        if (!empty($missingIdx)) {
            echo "  缺失索引: " . implode(', ', $missingIdx) . "\n";
        }

        // uk_refund_key 详情
        if (in_array('uk_refund_key', $allIdxNames, true)) {
            $ukIdx = array_filter($allIdx, fn($i) => $i['Key_name'] === 'uk_refund_key');
            $ukInfo = reset($ukIdx);
            echo "  uk_refund_key: Non_unique={$ukInfo['Non_unique']} (0=UNIQUE) Column_name={$ukInfo['Column_name']}\n";
        }

        // 行数
        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  数据行数: " . $cnt[0]['cnt'] . "\n";

        // refund_key 非空行数
        if (in_array('refund_key', $allColNames, true)) {
            $nonNull = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "` WHERE refund_key IS NOT NULL");
            echo "  refund_key 非空行数: " . $nonNull[0]['cnt'] . "\n";
        }
    }

    private function tableExists(): bool
    {
        $result = Db::query("SHOW TABLES LIKE '" . self::TABLE . "'");
        return !empty($result);
    }

    private function columnExists(string $name): bool
    {
        $result = Db::query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [self::TABLE, $name]
        );
        return !empty($result);
    }

    private function indexExists(string $name): bool
    {
        $result = Db::query(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [self::TABLE, $name]
        );
        return !empty($result);
    }
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
