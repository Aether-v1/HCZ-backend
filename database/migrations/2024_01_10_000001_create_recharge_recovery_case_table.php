<?php
/**
 * HCZ Pre-R1 Batch A5.1: cz_recharge_recovery_case 充值人工恢复案件表 Migration
 *
 * 背景：
 *   EPay/BEpusdt 均无可靠主动查询 API（A4/A4.1 已确认）。
 *   当 Provider 已收款但 callback 永久丢失时，需要人工恢复案件流程：
 *     Admin 创建 RecoveryCase → 审核批准 → RechargeSettlementService → UserFundLedgerService
 *
 *   RecoveryCase 是独立实体，表达"人工恢复审核事实"，不污染 Recharge 的支付业务状态。
 *
 * 新增表（1 张）：
 *   cz_recharge_recovery_case
 *
 * 核心字段：
 *   id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   recharge_id         INT UNSIGNED NOT NULL UNIQUE  — 一个 recharge 终身只允许一个 recovery case
 *   uid                 INT UNSIGNED NOT NULL           — 冗余用户 ID，便于查询
 *   provider            VARCHAR(32) NOT NULL DEFAULT '' — epay / bepusdt
 *   status              VARCHAR(32) NOT NULL DEFAULT 'open' — open/approved/rejected/settled/closed
 *   claimed_amount      DECIMAL(18,4) NOT NULL DEFAULT 0  — 用户声称已付金额（仅记录，不作为结算金额）
 *   external_reference  VARCHAR(255) NOT NULL DEFAULT ''   — provider trade_no / tx_hash（证据引用）
 *   evidence_note       TEXT NULL                          — 证据备注
 *   created_by_admin_id INT UNSIGNED NOT NULL DEFAULT 0
 *   approved_by_admin_id INT UNSIGNED NOT NULL DEFAULT 0
 *   approval_note       TEXT NULL
 *   settlement_result   VARCHAR(32) NOT NULL DEFAULT ''  — settled_manual / already_paid / failed
 *   ledger_request_no   VARCHAR(255) NOT NULL DEFAULT '' — 结算后记录账本幂等键
 *   created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   approved_at         DATETIME NULL
 *   settled_at          DATETIME NULL
 *   closed_at           DATETIME NULL
 *   updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * 索引：
 *   PRIMARY KEY (id)
 *   UNIQUE KEY uk_recharge_id (recharge_id)
 *   KEY idx_uid (uid)
 *   KEY idx_status (status)
 *
 * 设计原则：
 *   - RecoveryCase 与 Recharge 分离：Recharge 表达支付事实，RecoveryCase 表达人工审核事实
 *   - 一个 recharge 终身只允许一个 active case（uk_recharge_id 全局唯一）
 *   - APPROVED ≠ SETTLED：审核通过只表示获得授权，资金到账需 SettlementService 成功
 *   - 结算金额必须取自 Recharge.amount，不能从 claimed_amount 或调用方输入
 *   - 统一幂等键 recharge_paid:{order_number}，与 callback 共用，保证并发只到账一次
 *   - 可回滚：rollback() DROP TABLE（新表，无历史数据风险）
 *   - 不修改 Recharge / UserFundLedgerService / callback / Cron
 *
 * 用法：
 *   php database/migrations/2024_01_10_000001_create_recharge_recovery_case_table.php migrate
 *   php database/migrations/2024_01_10_000001_create_recharge_recovery_case_table.php rollback
 *   php database/migrations/2024_01_10_000001_create_recharge_recovery_case_table.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_recharge_recovery_case';

    private const CREATE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `cz_recharge_recovery_case` (
  `id`                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `recharge_id`          INT UNSIGNED     NOT NULL COMMENT '关联充值订单ID，一个recharge终身只允许一个recovery case',
  `uid`                  INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '冗余用户ID，便于查询',
  `provider`             VARCHAR(32)      NOT NULL DEFAULT '' COMMENT '支付渠道: epay / bepusdt',
  `status`               VARCHAR(32)      NOT NULL DEFAULT 'open' COMMENT '案件状态: open/approved/rejected/settled/closed',
  `claimed_amount`       DECIMAL(18,4)    NOT NULL DEFAULT 0.0000 COMMENT '用户声称已付金额（仅记录，不作为结算金额）',
  `external_reference`   VARCHAR(255)     NOT NULL DEFAULT '' COMMENT '外部交易引用: provider trade_no / tx_hash',
  `evidence_note`        TEXT             NULL DEFAULT NULL COMMENT '证据备注',
  `created_by_admin_id`  INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '创建者管理员ID',
  `approved_by_admin_id` INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '审核者管理员ID',
  `approval_note`        TEXT             NULL DEFAULT NULL COMMENT '审核备注',
  `settlement_result`    VARCHAR(32)      NOT NULL DEFAULT '' COMMENT '结算结果: settled_manual/already_paid/failed',
  `ledger_request_no`    VARCHAR(255)     NOT NULL DEFAULT '' COMMENT '账本幂等键（结算后记录）',
  `created_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at`          DATETIME         NULL DEFAULT NULL,
  `settled_at`           DATETIME         NULL DEFAULT NULL,
  `closed_at`            DATETIME         NULL DEFAULT NULL,
  `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recharge_id` (`recharge_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='HCZ Pre-R1 A5.1: 充值人工恢复案件表'
SQL;

    public function migrate(): void
    {
        echo "=== HCZ Pre-R1 A5.1: 创建 cz_recharge_recovery_case 充值人工恢复案件表 ===\n\n";

        if ($this->tableExists()) {
            echo "[SKIP] cz_recharge_recovery_case 表已存在\n";
        } else {
            Db::execute(self::CREATE_SQL);
            echo "[OK] CREATE TABLE cz_recharge_recovery_case\n";
        }

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ Pre-R1 A5.1: 回滚 cz_recharge_recovery_case 表 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_recharge_recovery_case 表不存在，无需回滚\n";
            return;
        }

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  当前数据行数: {$cnt[0]['cnt']}\n";

        Db::execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
        echo "[OK] DROP TABLE cz_recharge_recovery_case\n";

        echo "\n=== 回滚完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== cz_recharge_recovery_case 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_recharge_recovery_case: MISSING\n";
            return;
        }

        $allCols = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION");
        $allColNames = array_column($allCols, 'COLUMN_NAME');
        echo "  总字段数: " . count($allColNames) . "\n";
        echo "  字段列表: " . implode(', ', $allColNames) . "\n";

        $allIdx = Db::query("SHOW INDEX FROM `" . self::TABLE . "`");
        $allIdxNames = array_unique(array_column($allIdx, 'Key_name'));
        echo "  总索引数: " . count($allIdxNames) . "\n";
        echo "  索引列表: " . implode(', ', $allIdxNames) . "\n";

        if (in_array('uk_recharge_id', $allIdxNames, true)) {
            $ukIdx = array_filter($allIdx, fn($i) => $i['Key_name'] === 'uk_recharge_id');
            $ukInfo = reset($ukIdx);
            echo "  uk_recharge_id: Non_unique={$ukInfo['Non_unique']} (0=UNIQUE) Column_name={$ukInfo['Column_name']}\n";
        }

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  数据行数: " . $cnt[0]['cnt'] . "\n";

        $statusDist = Db::query("SELECT status, COUNT(*) as cnt FROM `" . self::TABLE . "` GROUP BY status");
        foreach ($statusDist as $row) {
            echo "    status={$row['status']}: {$row['cnt']}\n";
        }
    }

    private function tableExists(): bool
    {
        $result = Db::query("SHOW TABLES LIKE '" . self::TABLE . "'");
        return !empty($result);
    }
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
