-- ============================================================================
-- HCZ：资金账本幂等唯一索引（F15）
-- 表：cz_user_fund_log
-- 索引：uk_uid_wallet_direction_reqno (uid, wallet_type, direction, request_no)
--
-- 用途：
--   UserFundLedgerService::createLogWithIdempotentFallback()
--   依赖该唯一索引拦截 (uid, wallet_type, direction, request_no) 的重复写入，
--   作为支付回调 / 提现 / 返佣 / 佣金转移 / 冻结退款等资金操作的幂等兜底
--   （代码按索引名 'uk_uid_wallet_direction_reqno' 匹配 1062 冲突，索引名不可改动）。
--
-- 用法（务必先预检，再创建）：
--   第一步：执行下方「重复数据预检」只读查询。
--       若返回任何行 -> 立即停止，禁止 DELETE/UPDATE/合并流水，
--       报告重复数据并等待人工确认。
--   第二步：仅当预检无重复时，执行下方「创建唯一索引」（幂等写法，可重复执行）。
--
-- 更安全的自动化方式（推荐）：`php think fund-log:index`（含预检 + 条件创建 + 复验）。
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 第一步：重复数据预检（只读，不修改数据）
-- ----------------------------------------------------------------------------
SELECT `uid`, `wallet_type`, `direction`, `request_no`, COUNT(1) AS cnt
FROM `cz_user_fund_log`
GROUP BY `uid`, `wallet_type`, `direction`, `request_no`
HAVING cnt > 1
ORDER BY cnt DESC;

-- ----------------------------------------------------------------------------
-- 第二步：创建唯一索引（幂等：若索引已存在则跳过）
-- ----------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(1) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = 'cz_user_fund_log'
      AND index_name = 'uk_uid_wallet_direction_reqno'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `cz_user_fund_log` ADD UNIQUE INDEX `uk_uid_wallet_direction_reqno` (`uid`, `wallet_type`, `direction`, `request_no`)',
    'SELECT ''uk_uid_wallet_direction_reqno 已存在，跳过创建'' AS result'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
