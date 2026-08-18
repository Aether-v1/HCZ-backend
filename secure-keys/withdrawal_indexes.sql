-- HCZ withdrawal table indexes (idempotent, safe to re-run)
-- Purpose:
--   uk_order_number: enforce unique withdrawal order numbers (W-004 idempotency)
--   idx_uid_status: optimize user withdrawal list + status filter queries (W-007)
--
-- Deployment: execute manually on production database after review.
-- Does not modify existing data. Safe to re-run.

-- 1. Unique index on order_number
SET @idx_exists := (
    SELECT COUNT(1) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_withdrawal'
      AND index_name = 'uk_order_number'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `cz_withdrawal` ADD UNIQUE INDEX `uk_order_number` (`order_number`)',
    'SELECT ''uk_order_number already exists, skipping'' AS result'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Composite index on (uid, status)
SET @idx_exists := (
    SELECT COUNT(1) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_withdrawal'
      AND index_name = 'idx_uid_status'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `cz_withdrawal` ADD INDEX `idx_uid_status` (`uid`, `status`)',
    'SELECT ''idx_uid_status already exists, skipping'' AS result'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
