<?php
/**
 * HCZ B10-13: cz_points_exchange_order 正式 Schema Owner Migration
 *
 * 背景：
 *   此前 cz_points_exchange_order 的建表责任由 runtime DDL 承担（AdminApi::points_exchange_orders_json
 *   与 PointsActions::ensurePointsExchangeOrderTable 各持一份 CREATE TABLE IF NOT EXISTS），
 *   属 Schema Ownership 技术债（GET 请求路径触发 DDL、schema lifecycle 与 request 生命周期耦合）。
 *   本 migration 将建表责任正式收归 database/migrations/，成为该表的正式 Schema Owner。
 *
 * 本批仅处理：
 *   - 新增正式 migration（本文件）
 *   - 移除 AdminApi::points_exchange_orders_json 内的 runtime DDL（另行实施）
 *   PointsActions::ensurePointsExchangeOrderTable 的第二份 runtime DDL 属未来独立 Schema Ownership
 *   Remediation，不在本批范围（其 DDL 与本 migration 逐字段一致，migration 先行后为冗余幂等检查）。
 *
 * 表结构（与 AdminApi / PointsActions 历史 DDL 逐字段一致，不做任何优化）：
 *   cz_points_exchange_order
 *     id           int(11) unsigned NOT NULL AUTO_INCREMENT
 *     uid          int(11) unsigned NOT NULL DEFAULT '0'
 *     item_id      varchar(64) NOT NULL DEFAULT ''
 *     item_type    varchar(16) NOT NULL DEFAULT 'coupon'
 *     item_title   varchar(128) NOT NULL DEFAULT ''
 *     points       int(11) NOT NULL DEFAULT '0'
 *     status       tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已发放 2=已拒绝'
 *     remark       varchar(256) NOT NULL DEFAULT ''
 *     create_time  datetime DEFAULT NULL
 *     update_time  datetime DEFAULT NULL
 *     PRIMARY KEY (id)
 *     KEY idx_uid (uid)
 *     KEY idx_item_status (item_id,status)
 *     KEY idx_status (status)
 *   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
 *
 * 幂等：
 *   CREATE TABLE IF NOT EXISTS —— 表已存在则 SKIP，不重建、不破坏已有数据/索引/AUTO_INCREMENT。
 *
 * 回滚：
 *   Development/Test：rollback() 按项目 convention DROP TABLE。
 *   Production：不得把 rollback 当作普通部署失败自动动作；生产回滚需单独授权与数据保留方案，
 *   不得直接 DROP 生产表（该表已有业务数据）。
 *
 * 用法：
 *   php database/migrations/2024_01_08_000001_create_points_exchange_order_table.php migrate
 *   php database/migrations/2024_01_08_000001_create_points_exchange_order_table.php rollback
 *   php database/migrations/2024_01_08_000001_create_points_exchange_order_table.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private const TABLE = 'cz_points_exchange_order';

    private const CREATE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `cz_points_exchange_order` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0',
  `item_id` varchar(64) NOT NULL DEFAULT '',
  `item_type` varchar(16) NOT NULL DEFAULT 'coupon',
  `item_title` varchar(128) NOT NULL DEFAULT '',
  `points` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已发放 2=已拒绝',
  `remark` varchar(256) NOT NULL DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_item_status` (`item_id`,`status`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    public function migrate(): void
    {
        echo "=== HCZ B10-13: 正式收归 cz_points_exchange_order Schema Owner ===\n\n";

        if ($this->tableExists()) {
            echo "[SKIP] cz_points_exchange_order 表已存在（不重建、不破坏数据）\n";
        } else {
            Db::execute(self::CREATE_SQL);
            echo "[OK] CREATE TABLE cz_points_exchange_order\n";
        }

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ B10-13: 回滚 cz_points_exchange_order 表 ===\n\n";

        if (!$this->tableExists()) {
            echo "[SKIP] cz_points_exchange_order 表不存在，无需回滚\n";
            return;
        }

        $cnt = Db::query("SELECT COUNT(*) as cnt FROM `" . self::TABLE . "`");
        echo "  当前数据行数: {$cnt[0]['cnt']}\n";

        echo "  [WARN] Production: 该表已有业务数据，生产回滚需单独授权与数据保留方案，不得直接 DROP。\n";
        echo "  [DEV] 开发/测试环境：按项目 convention DROP TABLE\n";
        Db::execute("DROP TABLE IF EXISTS `" . self::TABLE . "`");
        echo "[OK] DROP TABLE cz_points_exchange_order\n";

        echo "\n=== 回滚完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== cz_points_exchange_order 当前状态 ===\n";

        if (!$this->tableExists()) {
            echo "  cz_points_exchange_order: MISSING\n";
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
