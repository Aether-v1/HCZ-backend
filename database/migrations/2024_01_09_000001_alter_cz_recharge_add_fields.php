<?php
/**
 * HCZ Pre-R1 Batch A: cz_recharge 字段补全 + cancel_source
 *
 * 背景：
 *   本地测试库 cz_recharge 仅有 8 个基础字段 (id,uid,order_number,amount,status,
 *   payment_method,create_time,update_time)，但生产代码自初始 commit 起持续引用
 *   pay_type, gateway, gateway_*, paid_time, cancel_time 等业务字段。
 *   本 migration 补全代码契约所需的全部缺失字段，并新增 cancel_source 用于
 *   区分 Cron 超时(EXPIRED 可自动恢复)与用户主动取消(需人工审核)。
 *
 * 新增字段：
 *   核心业务：pay_type, epay_type, wallet_address, image
 *   状态追踪：submit_time, paid_time, complete_time, cancel_time, cancel_source
 *   Provider/Gateway：gateway, gateway_trade_id, gateway_token, gateway_status,
 *                     gateway_actual_amount, gateway_txid, gateway_notify_payload,
 *                     gateway_raw
 *
 * 设计原则：
 *   - 幂等：执行前检查 INFORMATION_SCHEMA，字段已存在则跳过
 *   - 可回滚：rollback() 逆序删除新增字段（不删除历史已存在字段）
 *   - 遵循项目现有规范：int unsigned、decimal(18,4)、utf8mb4、无外键
 *   - 不修改业务数据：不自动将 status=2 改成 PAID
 *
 * 用法：
 *   php database/migrations/2024_01_09_000001_alter_cz_recharge_add_fields.php migrate
 *   php database/migrations/2024_01_09_000001_alter_cz_recharge_add_fields.php rollback
 *   php database/migrations/2024_01_09_000001_alter_cz_recharge_add_fields.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_recharge';

    /**
     * 本 migration 新增字段定义：name => column DDL
     * 顺序即添加顺序，rollback 时逆序删除
     */
    private array $columns = [
        // 核心业务字段
        'pay_type'              => "tinyint NOT NULL DEFAULT 0 COMMENT '支付方式 1=U支付/BEpusdt 2=易支付/EPay'",
        'epay_type'             => "varchar(8) NOT NULL DEFAULT '' COMMENT 'EPay 子类型 1=支付宝 2=微信'",
        'wallet_address'        => "varchar(128) NOT NULL DEFAULT '' COMMENT '用户钱包地址(TRC20)'",
        'image'                 => "varchar(255) NOT NULL DEFAULT '' COMMENT '手动汇款凭证路径'",
        // 状态与时间字段
        'submit_time'           => "datetime DEFAULT NULL COMMENT '提交时间(手动汇款上传凭证)'",
        'paid_time'             => "datetime DEFAULT NULL COMMENT '支付完成时间'",
        'complete_time'         => "datetime DEFAULT NULL COMMENT '订单完成时间'",
        'cancel_time'           => "datetime DEFAULT NULL COMMENT '取消/过期时间'",
        'cancel_source'         => "varchar(32) NOT NULL DEFAULT '' COMMENT '取消来源: cron/user/create_failed/admin_reject/provider_expired'",
        // Provider/Gateway 字段
        'gateway'               => "varchar(32) NOT NULL DEFAULT '' COMMENT '支付网关: bepusdt/epay/manual'",
        'gateway_trade_id'      => "varchar(128) NOT NULL DEFAULT '' COMMENT 'Provider 交易号/订单号'",
        'gateway_token'         => "varchar(64) NOT NULL DEFAULT '' COMMENT 'BEpusdt token(脱敏存储)'",
        'gateway_status'        => "varchar(32) NOT NULL DEFAULT '' COMMENT 'Provider 状态字符串'",
        'gateway_actual_amount' => "decimal(18,4) NOT NULL DEFAULT 0.0000 COMMENT 'Provider 实际金额'",
        'gateway_txid'          => "varchar(128) NOT NULL DEFAULT '' COMMENT '链上交易ID(脱敏)'",
        'gateway_notify_payload'=> "text COMMENT 'Provider 回调原始 payload(JSON)'",
        'gateway_raw'           => "text COMMENT 'Provider 创建响应原始数据(JSON)'",
    ];

    public function migrate(): void
    {
        echo "=== HCZ Pre-R1 Batch A: cz_recharge 字段补全 ===\n\n";

        if (!$this->tableExists()) {
            echo "[BLOCKED] cz_recharge 表不存在，无法执行 migration\n";
            return;
        }
        echo "[OK] cz_recharge 表存在\n\n";

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

        echo "=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ Pre-R1 Batch A: cz_recharge 字段补全 回滚 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_recharge 表不存在，无需回滚\n";
            return;
        }
        echo "[OK] cz_recharge 表存在\n\n";

        echo "--- 删除本 migration 新增字段（逆序）---\n";
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
        echo "\n=== cz_recharge 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_recharge: MISSING\n";
            return;
        }

        $allCols = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION");
        $allColNames = array_column($allCols, 'COLUMN_NAME');
        echo "  总字段数: " . count($allColNames) . "\n";
        echo "  字段列表: " . implode(', ', $allColNames) . "\n";

        $missingCols = array_filter(array_keys($this->columns), fn($c) => !in_array($c, $allColNames, true));
        echo "  本 migration 目标字段: " . count($this->columns) . "（缺失: " . count($missingCols) . "）\n";
        if (!empty($missingCols)) {
            echo "  缺失字段: " . implode(', ', $missingCols) . "\n";
        }

        $allIdx = Db::query("SHOW INDEX FROM `" . self::TABLE . "`");
        $allIdxNames = array_unique(array_column($allIdx, 'Key_name'));
        echo "  索引列表: " . implode(', ', $allIdxNames) . "\n";

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
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
