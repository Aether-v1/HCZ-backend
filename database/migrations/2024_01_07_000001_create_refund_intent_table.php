<?php
/**
 * HCZ INFO-022-A: cz_refund_intent 积分返还意图表（Outbox / Crash Recovery）Migration
 *
 * 背景：
 *   当前积分返还依赖 Redis SET NX + EX 应用层幂等。
 *   SETNX 成功 → 进程 crash → addPoints 未执行 → Redis key 已存在 → 重试被阻止 → 永久漏返（INFO-022-01）。
 *   本 migration 新增 refund_intent 表，将「返还义务」持久化到 DB，由 Worker 异步恢复执行。
 *
 * 新增表（1 张）：
 *   cz_refund_intent
 *
 * 核心字段：
 *   id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   refund_key        VARCHAR(255) NOT NULL UNIQUE  — 与 points_record.refund_key / Redis SETNX key 完全一致
 *   uid               INT UNSIGNED NOT NULL
 *   points            INT NOT NULL
 *   reason            VARCHAR(255) NOT NULL DEFAULT ''
 *   status            VARCHAR(20) NOT NULL DEFAULT 'pending'  — pending/processing/completed/failed
 *   retry_count       INT UNSIGNED NOT NULL DEFAULT 0
 *   next_retry_time   DATETIME NULL
 *   lease_owner       VARCHAR(64) NULL
 *   lease_expire_time DATETIME NULL
 *   error_message     TEXT NULL
 *   create_time       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   update_time       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * 索引：
 *   PRIMARY KEY (id)
 *   UNIQUE KEY uk_refund_key (refund_key)
 *   KEY idx_status_retry (status, next_retry_time)
 *   KEY idx_uid (uid)
 *
 * 设计原则：
 *   - DB 是唯一权威：refund_intent 持久化返还义务，crash 后 Worker 可恢复
 *   - 三层幂等：refund_intent.uk_refund_key + points_record.uk_refund_key + PointsService.addPoints(refundKey)
 *   - 并发安全：原子 claim + lease + stale lease reclaim
 *   - 重试策略：指数退避，最大 10 次，失败终态需人工介入
 *   - 可回滚：rollback() DROP TABLE（新表，无历史数据风险）
 *   - 不修改 PointsService / points_record Schema / 现有业务逻辑
 *
 * 用法：
 *   php database/migrations/2024_01_07_000001_create_refund_intent_table.php migrate
 *   php database/migrations/2024_01_07_000001_create_refund_intent_table.php rollback
 *   php database/migrations/2024_01_07_000001_create_refund_intent_table.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_refund_intent';

    private const CREATE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `cz_refund_intent` (
  `id`                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `refund_key`        VARCHAR(255)     NOT NULL COMMENT '返还幂等键，与 points_record.refund_key / Redis SETNX key 完全一致',
  `uid`               INT UNSIGNED     NOT NULL COMMENT '用户ID',
  `points`            INT              NOT NULL COMMENT '返还积分数',
  `reason`            VARCHAR(255)     NOT NULL DEFAULT '' COMMENT '返还原因',
  `status`            VARCHAR(20)      NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/completed/failed',
  `retry_count`       INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT '已重试次数',
  `next_retry_time`   DATETIME         NULL DEFAULT NULL COMMENT '下次可重试时间（指数退避）',
  `lease_owner`       VARCHAR(64)      NULL DEFAULT NULL COMMENT '当前处理 Worker 标识',
  `lease_expire_time` DATETIME         NULL DEFAULT NULL COMMENT '租约过期时间（Worker crash 后可回收）',
  `error_message`     TEXT             NULL DEFAULT NULL COMMENT '最近一次失败原因',
  `create_time`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_key` (`refund_key`),
  KEY `idx_status_retry` (`status`, `next_retry_time`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='HCZ INFO-022-A: 积分返还意图表（Outbox/Crash Recovery）'
SQL;

    public function migrate(): void
    {
        echo "=== HCZ INFO-022-A: 创建 cz_refund_intent 积分返还意图表 ===\n\n";

        if ($this->tableExists()) {
            echo "[SKIP] cz_refund_intent 表已存在\n";
        } else {
            Db::execute(self::CREATE_SQL);
            echo "[OK] CREATE TABLE cz_refund_intent\n";
        }

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ INFO-022-A: 回滚 cz_refund_intent 表 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_refund_intent 表不存在，无需回滚\n";
            return;
        }

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  当前数据行数: {$cnt[0]['cnt']}\n";

        Db::execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
        echo "[OK] DROP TABLE cz_refund_intent\n";

        echo "\n=== 回滚完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== cz_refund_intent 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_refund_intent: MISSING\n";
            return;
        }

        // 字段统计
        $allCols = Db::query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION");
        $allColNames = array_column($allCols, 'COLUMN_NAME');
        echo "  总字段数: " . count($allColNames) . "\n";
        echo "  字段列表: " . implode(', ', $allColNames) . "\n";

        // 索引统计
        $allIdx = Db::query("SHOW INDEX FROM `" . self::TABLE . "`");
        $allIdxNames = array_unique(array_column($allIdx, 'Key_name'));
        echo "  总索引数: " . count($allIdxNames) . "\n";
        echo "  索引列表: " . implode(', ', $allIdxNames) . "\n";

        // uk_refund_key 详情
        if (in_array('uk_refund_key', $allIdxNames, true)) {
            $ukIdx = array_filter($allIdx, fn($i) => $i['Key_name'] === 'uk_refund_key');
            $ukInfo = reset($ukIdx);
            echo "  uk_refund_key: Non_unique={$ukInfo['Non_unique']} (0=UNIQUE) Column_name={$ukInfo['Column_name']}\n";
        }

        // 行数与状态分布
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
