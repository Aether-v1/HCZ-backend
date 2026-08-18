-- HCZ withdrawal historical data audit (READ-ONLY, do not modify data)
-- Run these queries to check for anomalies before/after deployment.
-- All queries are SELECT only. No data modification.

-- 1. Pending withdrawals (status=0) older than 24 hours
SELECT id, uid, amount, withdrawal_fee, order_number, wallet_address, create_time
FROM cz_withdrawal
WHERE status = 0
  AND create_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY create_time ASC;

-- 2. Completed/rejected withdrawals missing fund ledger records
SELECT w.id, w.uid, w.amount, w.withdrawal_fee, w.status, w.order_number
FROM cz_withdrawal w
WHERE w.status IN (1, 2)
  AND w.id NOT IN (
      SELECT biz_id FROM cz_user_fund_log
      WHERE biz_type = 'withdrawal'
        AND change_type IN ('withdraw_deduct', 'withdraw_reject_refund')
  )
ORDER BY w.id DESC;

-- 3. Historical platform fee gap (completed withdrawals with fee > 0 but no platform income log)
SELECT w.id, w.order_number, w.amount, w.withdrawal_fee,
       ROUND(w.amount - w.withdrawal_fee, 2) AS actual_payout
FROM cz_withdrawal w
WHERE w.status = 1
  AND w.withdrawal_fee > 0.005
  AND w.id NOT IN (
      SELECT biz_id FROM cz_user_fund_log
      WHERE biz_type = 'withdrawal'
        AND direction = 'in'
        AND wallet_type = 'balance'
  )
ORDER BY w.id DESC;

-- 4. Abnormal fee: fee < 0
SELECT id, uid, amount, withdrawal_fee, order_number, status
FROM cz_withdrawal
WHERE withdrawal_fee < -0.005;

-- 5. Abnormal fee: fee > amount
SELECT id, uid, amount, withdrawal_fee, order_number, status
FROM cz_withdrawal
WHERE withdrawal_fee > amount + 0.005;

-- 6. Negative user balance
SELECT id, username, balance, frozen_amount
FROM cz_user
WHERE balance < -0.005;

-- 7. Negative user frozen amount
SELECT id, username, balance, frozen_amount
FROM cz_user
WHERE frozen_amount < -0.005;

-- 8. Duplicate order_numbers
SELECT order_number, COUNT(*) AS cnt
FROM cz_withdrawal
GROUP BY order_number
HAVING cnt > 1;

-- 9. Summary: withdrawal totals by status
SELECT status,
       COUNT(*) AS order_count,
       COALESCE(SUM(amount), 0) AS total_amount,
       COALESCE(SUM(withdrawal_fee), 0) AS total_fee
FROM cz_withdrawal
GROUP BY status
ORDER BY status;
