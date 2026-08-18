-- C2C transaction order composite index
-- Purpose: optimize SELECT SUM(pay_amount) WHERE pid=? AND status IN(0,1) FOR UPDATE
--   executed by: buyer order creation, listing edit, admin close/delete listing.
-- Without this index, the FOR UPDATE query may full-table-scan and lock all rows,
-- causing severe concurrency contention on C2C trading.
-- Execute once on production database. Safe to re-run (use IF NOT EXISTS logic below).
-- Index name: idx_pid_status

ALTER TABLE `cz_transaction_order`
ADD INDEX `idx_pid_status` (`pid`, `status`);
