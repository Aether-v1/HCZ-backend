<?php
/**
 * HCZ R1.5b: cz_points_task_claim 正式 Schema Owner Migration
 *
 * 背景：
 *   此前 cz_points_task_claim 的建表责任由 runtime DDL 承担
 *   （PointsActions::ensurePointsTaskClaimTable 在 HTTP request path 内
 *   执行 CREATE TABLE IF NOT EXISTS），属 R0-P1-01 Request-Time DDL。
 *   本 migration 将建表责任正式收归 database/migrations/，成为该表的
 *   正式 Schema Owner。R1.5b 同步移除 PointsActions 中的 runtime DDL。
 *
 * 表结构（与 PointsActions 历史 ensure DDL 逐字段一致，不做任何优化）：
 *   cz_points_task_claim
 *     id            int(11) unsigned NOT NULL AUTO_INCREMENT
 *     uid           int(11) unsigned NOT NULL DEFAULT '0'
 *     task_key      varchar(64) NOT NULL DEFAULT ''
 *     claim_key     varchar(128) NOT NULL DEFAULT ''
 *     task_type     varchar(16) NOT NULL DEFAULT ''
 *     task_date     date DEFAULT NULL
 *     points        int(11) NOT NULL DEFAULT '0'
 *     status        tinyint(1) NOT NULL DEFAULT '1'
 *     create_time   datetime DEFAULT NULL
 *     update_time   datetime DEFAULT NULL
 *     PRIMARY KEY (id)
 *     UNIQUE KEY uniq_uid_claim_key (uid, claim_key)
 *     KEY idx_uid_task (uid, task_key)
 *     KEY idx_task_date (task_key, task_date)
 *   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
 *
 * 注意：
 *   status 列默认值为 '1'，但业务 insert 时使用 status=0。
 *   此语义不一致保留为 P3-R1.5b-01，本 migration 不做修改。
 *
 * 幂等：
 *   CREATE TABLE IF NOT EXISTS —— 表已存在则 SKIP，不重建、不破坏已有数据。
 *
 * 回滚：
 *   Development/Test：rollback() 按项目 convention DROP TABLE。
 *   Production：不得把 rollback 当作普通部署失败自动动作；生产回滚需单独授权
 *   与数据保留方案，不得直接 DROP 生产表。
 *
 * 用法：
 *   php database/migrations/2024_01_13_000001_create_points_task_claim_table.php migrate
 *   php database/migrations/2024_01_13_000001_create_points_task_claim_table.php rollback
 *   php database/migrations/2024_01_13_000001_create_points_task_claim_table.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_points_task_claim';

    private const CREATE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `cz_points_task_claim` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0',
  `task_key` varchar(64) NOT NULL DEFAULT '',
  `claim_key` varchar(128) NOT NULL DEFAULT '',
  `task_type` varchar(16) NOT NULL DEFAULT '',
  `task_date` date DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
  KEY `idx_uid_task` (`uid`,`task_key`),
  KEY `idx_task_date` (`task_key`,`task_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    public function migrate(): void
    {
        echo "=== HCZ R1.5b: 正式收归 cz_points_task_claim Schema Owner ===\n\n";

        if ($this->tableExists()) {
            echo "[SKIP] cz_points_task_claim 表已存在（不重建、不破坏数据）\n";
        } else {
            Db::execute(self::CREATE_SQL);
            echo "[OK] CREATE TABLE cz_points_task_claim\n";
        }

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ R1.5b: 回滚 cz_points_task_claim 表 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_points_task_claim 表不存在，无需回滚\n";
            return;
        }

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  当前数据行数: {$cnt[0]['cnt']}\n";

        echo "  [WARN] Production: 该表可能已有业务数据，生产回滚需单独授权与数据保留方案，不得直接 DROP。\n";
        echo "  [DEV] 开发/测试环境：按项目 convention DROP TABLE\n";
        Db::execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
        echo "[OK] DROP TABLE cz_points_task_claim\n";

        echo "\n=== 回滚完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== cz_points_task_claim 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_points_task_claim: MISSING\n";
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

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  数据行数: " . $cnt[0]['cnt'] . "\n";
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
