-- C2C transaction order composite index
-- Purpose: optimize SELECT SUM(pay_amount) WHERE pid=? AND status IN(0,1) FOR UPDATE
--   executed by: buyer order creation, listing edit, admin close/delete listing.
-- Without this index, the FOR UPDATE query may full-table-scan and lock all rows,
-- causing severe concurrency contention on C2C trading.
--
-- IDEMPOTENT: safe to re-run. Checks information_schema before creating.
-- Index name: idx_pid_status

SET @idx_exists := (
    SELECT COUNT(1) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_transaction_order'
      AND index_name = 'idx_pid_status'
);

SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `cz_transaction_order` ADD INDEX `idx_pid_status` (`pid`, `status`)',
    'SELECT ''idx_pid_status already exists, skipping'' AS result'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
