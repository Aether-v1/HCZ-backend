-- ============================================================================
-- HCZ：历史订单归档列（F3）
-- 表：cz_order
-- 新增列：archived / archived_time
--
-- 用途：
--   command/Cron.php 原对「complete_time 早于 30 天」的订单执行物理 DELETE，
--   会永久销毁财务对账/售后/退款/审计所需的订单原始记录（且无 status 过滤）。
--   现改为“归档”：仅对已完成（status=2）订单打 archived=1 标记，记录保留。
--   本 SQL 为 cz_order 增加归档列与索引；Cron 在归档列不存在时会跳过并告警，
--   绝不回退为物理删除，因此该列未落地前不影响现有订单读写。
--
-- 用法（务必先预检，再执行）：
--   第一步：执行下方「列存在性预检」只读查询。
--       返回 0 -> 可执行第二步创建列。
--       返回 2 -> 列已存在，无需重复执行。
--   第二步：执行下方「新增归档列」（幂等写法，可重复执行）。
--
-- 注意：此操作仅 ADD COLUMN（DDL 结构变更），不修改任何业务数据。
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 第一步：列存在性预检（只读，不修改数据）
-- ----------------------------------------------------------------------------
SELECT COUNT(1) AS existing_archive_columns
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'cz_order'
  AND column_name IN ('archived', 'archived_time');

-- ----------------------------------------------------------------------------
-- 第二步：新增归档列（幂等：列已存在则跳过）
-- ----------------------------------------------------------------------------
SET @col_archived := (
    SELECT COUNT(1) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_order'
      AND column_name = 'archived'
);
SET @ddl1 := IF(
    @col_archived = 0,
    'ALTER TABLE `cz_order` ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''系统归档标记 0未归档 1已归档'' AFTER `user_deleted_time`',
    'SELECT ''cz_order.archived 已存在，跳过'' AS result'
);
PREPARE stmt1 FROM @ddl1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @col_archived_time := (
    SELECT COUNT(1) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_order'
      AND column_name = 'archived_time'
);
SET @ddl2 := IF(
    @col_archived_time = 0,
    'ALTER TABLE `cz_order` ADD COLUMN `archived_time` datetime DEFAULT NULL COMMENT ''系统归档时间'' AFTER `archived`',
    'SELECT ''cz_order.archived_time 已存在，跳过'' AS result'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- ----------------------------------------------------------------------------
-- 第三步：归档查询索引（幂等）
-- ----------------------------------------------------------------------------
SET @idx_archived := (
    SELECT COUNT(1) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_order'
      AND index_name = 'idx_order_status_complete_archived'
);
SET @ddl3 := IF(
    @idx_archived = 0,
    'ALTER TABLE `cz_order` ADD INDEX `idx_order_status_complete_archived` (`status`, `complete_time`, `archived`)',
    'SELECT ''idx_order_status_complete_archived 已存在，跳过'' AS result'
);
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;
