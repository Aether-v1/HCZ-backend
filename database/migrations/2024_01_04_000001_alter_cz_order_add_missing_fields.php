<?php
/**
 * HCZ D3-F4 F-006: cz_order 缺失字段补全 Migration
 *
 * 背景：
 *   cz_order 表当前仅有 14 个基础字段，但生产代码自初始 commit 起持续引用
 *   订单类型、金额结算、状态机、审计追踪、分站结算等业务字段。
 *   本 migration 补全代码契约所需的全部缺失字段及索引。
 *
 * 新增字段（22 个）：
 *   核心业务：type, amount_money, discount_amount, cny_amount,
 *             amount_received, commission_base_snapshot, substation_id
 *   状态追踪：confirm_status, complete_time, operator_id, on_line_status
 *   结算退款：settlement_match_amount, settlement_match_discount,
 *             settlement_final_cny_amount, settlement_refund_cny_amount,
 *             settlement_refund_rate, settlement_refund_usdt_amount,
 *             settlement_refund_time, settlement_operator_id
 *   分站扩展：substation_income_status, substation_income_time, tier_key
 *
 * 新增索引（5 个）：
 *   idx_type_status, idx_confirm_status, idx_operator_id,
 *   idx_substation_id, idx_order_status_complete_archived
 *
 * 设计原则：
 *   - 幂等：执行前检查 INFORMATION_SCHEMA，字段/索引已存在则跳过
 *   - 可回滚：rollback() 逆序删除索引和字段
 *   - 遵循项目现有规范：int unsigned、decimal(18,4)、utf8mb4、无外键
 *   - 不修改业务数据：当前 cz_order = 0 rows，无需 backfill
 *
 * 用法：
 *   php database/migrations/2024_01_04_000001_alter_cz_order_add_missing_fields.php migrate
 *   php database/migrations/2024_01_04_000001_alter_cz_order_add_missing_fields.php rollback
 *   php database/migrations/2024_01_04_000001_alter_cz_order_add_missing_fields.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_order';

    /**
     * 字段定义：name => column DDL
     * 顺序即添加顺序，rollback 时逆序删除
     */
    private array $columns = [
        // 核心业务字段
        'type'                          => "tinyint NOT NULL DEFAULT 0 COMMENT '订单类型 1=充值 2=查询/商品'",
        'amount_money'                  => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '订单原始金额(CNY)'",
        'discount_amount'               => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '折扣金额(CNY)'",
        'cny_amount'                    => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '折后应付金额(CNY)'",
        'amount_received'               => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '实际到账金额(CNY)'",
        'commission_base_snapshot'      => "decimal(18,4) DEFAULT NULL COMMENT '佣金基数快照(USDT)'",
        'substation_id'                 => "int unsigned NOT NULL DEFAULT 0 COMMENT '分站ID 0=主站'",
        // 状态与追踪字段
        'confirm_status'                => "tinyint NOT NULL DEFAULT 0 COMMENT '确认状态 0=未完成 1=待确认 2=已确认 3=未收到'",
        'complete_time'                 => "datetime DEFAULT NULL COMMENT '订单完成时间'",
        'operator_id'                   => "int unsigned NOT NULL DEFAULT 0 COMMENT '最后操作管理员ID'",
        'on_line_status'                => "tinyint NOT NULL DEFAULT 0 COMMENT '在线状态'",
        // 结算/部分退款字段
        'settlement_match_amount'       => "decimal(18,4) DEFAULT NULL COMMENT '结算匹配金额(CNY)'",
        'settlement_match_discount'     => "decimal(18,4) DEFAULT NULL COMMENT '结算匹配折扣率'",
        'settlement_final_cny_amount'   => "decimal(18,4) DEFAULT NULL COMMENT '结算最终应付CNY'",
        'settlement_refund_cny_amount'  => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '应退CNY'",
        'settlement_refund_rate'        => "decimal(18,6) DEFAULT NULL COMMENT '退款汇率'",
        'settlement_refund_usdt_amount' => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT '应退USDT'",
        'settlement_refund_time'        => "datetime DEFAULT NULL COMMENT '退款时间'",
        'settlement_operator_id'        => "int unsigned NOT NULL DEFAULT 0 COMMENT '结算操作管理员ID'",
        // 分站扩展字段
        'substation_income_status'      => "tinyint NOT NULL DEFAULT 0 COMMENT '分站收入结算状态 0=未结算 1=已结算'",
        'substation_income_time'        => "datetime DEFAULT NULL COMMENT '分站收入结算时间'",
        'tier_key'                      => "varchar(64) NOT NULL DEFAULT '' COMMENT '产品档位键'",
    ];

    /**
     * 索引定义：name => index DDL (columns part)
     */
    private array $indexes = [
        'idx_type_status'                   => '(`type`, `status`)',
        'idx_confirm_status'                => '(`confirm_status`)',
        'idx_operator_id'                   => '(`operator_id`)',
        'idx_substation_id'                 => '(`substation_id`)',
        'idx_order_status_complete_archived' => '(`status`, `complete_time`, `archived`)',
    ];

    public function migrate(): void
    {
        echo "=== HCZ D3-F4 F-006: cz_order 缺失字段补全 ===\n\n";

        if (!$this->tableExists()) {
            echo "[BLOCKED] cz_order 表不存在，无法执行 migration\n";
            return;
        }
        echo "[OK] cz_order 表存在\n\n";

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
        foreach ($this->indexes as $name => $columns) {
            if ($this->indexExists($name)) {
                echo "  [SKIP] {$name} 已存在\n";
                continue;
            }
            $sql = "ALTER TABLE `" . self::TABLE . "` ADD INDEX `{$name}` {$columns}";
            Db::execute($sql);
            echo "  [OK] ADD INDEX {$name} {$columns}\n";
            $addedIndexes++;
        }
        echo "  新增索引: {$addedIndexes} 个（跳过 " . (count($this->indexes) - $addedIndexes) . " 个已存在）\n\n";

        echo "=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ D3-F4 F-006: cz_order 缺失字段补全 回滚 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_order 表不存在，无需回滚\n";
            return;
        }
        echo "[OK] cz_order 表存在\n\n";

        // 1. 先删除索引（逆序）
        echo "--- 删除索引 ---\n";
        $droppedIndexes = 0;
        foreach (array_reverse($this->indexes, true) as $name => $columns) {
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
        echo "\n=== cz_order 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_order: MISSING\n";
            return;
        }

        // 字段统计
        $allCols = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION");
        $allColNames = array_column($allCols, 'COLUMN_NAME');
        echo "  总字段数: " . count($allColNames) . "\n";

        $missingCols = array_filter(array_keys($this->columns), fn($c) => !in_array($c, $allColNames, true));
        echo "  本 migration 目标字段: " . count($this->columns) . "（缺失: " . count($missingCols) . "）\n";
        if (!empty($missingCols)) {
            echo "  缺失字段: " . implode(', ', $missingCols) . "\n";
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

        // 行数
        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  数据行数: " . $cnt[0]['cnt'] . "\n";
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
