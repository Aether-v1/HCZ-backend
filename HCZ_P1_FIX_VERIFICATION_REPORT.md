# HCZ P1-001 / P1-002 修复验证报告

**修复日期：** 2026-08-28
**修复目标：** 解除 BLOCKED 状态
**修复范围：** 仅 P1-001（已取消订单回调入账）+ P1-002（数据库索引验证），不修改其他 P2/P3
**原则：** 只审计验证，代码修改仅限 P1-001 必要修复

---

## 一、修改文件清单

| 文件 | 修改类型 | 修改点 |
|------|----------|--------|
| `app/controller/Notify.php` | 修改 | bepusdt 支付回调增加严格状态机 |
| `app/controller/indexapi/FinanceActions.php` | 修改 | epay 支付回调增加严格状态机 |

**未修改文件：** 其他所有文件保持原样。管理员手动充值入口（AdminApi.php）已有 `status==1` 检查，无需修改。

---

## 二、每个修改点详情

### 修改点 1：Notify.php — bepusdt 回调状态机

**文件：** `app/controller/Notify.php`
**方法：** `api_callback_bepusdt()`
**原代码（第150-153行）：**
```php
if ((int)($recharge['status'] ?? 0) === 3) {
    Db::commit();
    return response('ok', 200);
}
```

**修复后（第150-265行）：**
```php
$localStatus = (int)($recharge['status'] ?? 0);

// status=3: 已完成，幂等返回
if ($localStatus === 3) {
    Db::commit();
    return response('ok', 200);
}

// status=2: 已取消，明确拒绝，不产生任何资金变更
if ($localStatus === 2) {
    Log::warning('bepusdt notify rejected: recharge already cancelled, no fund change allowed', [...]);
    Db::commit();
    return response('ok', 200);
}

// ... 网关 status=1（等待确认）和 status=3（网关取消）的处理 ...

// 网关 status=2（支付成功）时，再次确认本地 status 必须为 0
if ($localStatus !== 0) {
    Log::warning('bepusdt notify rejected: payment success but local recharge status is not pending', [...]);
    Db::commit();
    return response('ok', 200);
}

// 只有此时才允许进入正常入账逻辑
$amount = round((float)($recharge['amount'] ?? 0), 2);
...
```

**关键变化：**
1. 引入 `$localStatus` 变量，统一状态判断
2. 增加 `status===2` 拦截：已取消订单直接返回 ok，不修改订单状态、不增加余额、不创建流水
3. 增加支付成功入账前的 `status!==0` 二次拦截：只有待支付订单才能入账
4. 拦截时记录安全日志，包含 order_number、uid、amount、gateway_status

---

### 修改点 2：FinanceActions.php — epay 回调状态机

**文件：** `app/controller/indexapi/FinanceActions.php`
**方法：** `handleEpayNotifyUrl()`
**原代码（第1165-1167行）：**
```php
if ((int) ($recharge['status'] ?? 0) === 3) {
    return;
}
```

**修复后：**
```php
$localStatus = (int) ($recharge['status'] ?? 0);

// status=3: 已完成，幂等返回
if ($localStatus === 3) {
    return;
}

// status=2: 已取消，明确拒绝，不产生任何资金变更
if ($localStatus === 2) {
    Log::warning('epay notify rejected: recharge already cancelled, no fund change allowed', [...]);
    return;
}

// status!==0: 非待支付状态，拒绝入账
if ($localStatus !== 0) {
    Log::warning('epay notify rejected: payment success but local recharge status is not pending', [...]);
    return;
}

// 只有此时才允许进入正常入账逻辑
$payType = (string) ($recharge['pay_type'] ?? '');
...
```

**关键变化：** 与 Notify.php 一致的三层状态机防护。

---

## 三、P1-001 修复前后状态机对比

### 修复前（有漏洞）

| 本地订单状态 | 收到支付成功回调 | 结果 |
|-------------|-----------------|------|
| 0 (待支付) | 支付成功 | ✅ 正常入账 |
| 1 (已提交) | 支付成功 | ⚠️ 入账（可能合理，但无明确控制） |
| **2 (已取消)** | **支付成功** | **❌ 非法入账！余额增加，订单 2→3** |
| 3 (已完成) | 支付成功 | ✅ 幂等返回 |

### 修复后（严格状态机）

| 本地订单状态 | 收到支付成功回调 | 结果 |
|-------------|-----------------|------|
| 0 (待支付) | 支付成功 | ✅ 正常入账 |
| 1 (已提交) | 支付成功 | ❌ 拒绝（status!==0），记录日志 |
| **2 (已取消)** | **支付成功** | **✅ 拒绝，不产生任何变更，记录安全日志** |
| 3 (已完成) | 支付成功 | ✅ 幂等返回 |

---

## 四、P1-002 数据库索引实际验证结果

### 验证环境

- 配置的数据库：`hcz_test` @ `127.0.0.1:3306`，用户 `root`，无密码
- **验证结果：本地 MySQL 服务未运行，连接被拒绝**

```
Database connection FAILED: SQLSTATE[HY000]  由于目标计算机积极拒绝，无法连接。
```

### 索引验证结论

| 表名 | 字段 | 索引名称 | 是否 UNIQUE | 数据库实际结果 |
|------|------|----------|-------------|---------------|
| cz_user_fund_log | request_no | uk_uid_wallet_direction_reqno | 应为 UNIQUE | **无法证明生产数据库状态** |
| cz_recharge | order_number | (未知) | 应为 UNIQUE | **无法证明生产数据库状态** |
| cz_order | order_number | (未知) | 应为 UNIQUE | **无法证明生产数据库状态** |
| cz_transaction_order | order_number | (未知) | 应为 UNIQUE | **无法证明生产数据库状态** |
| cz_withdrawal | order_number | uk_order_number | 应为 UNIQUE | **无法证明生产数据库状态**（SQL 脚本已提供） |
| cz_user | mobile | (未知) | 应为 UNIQUE | **无法证明生产数据库状态** |

### 源码中已提供的索引 SQL 脚本

| 脚本 | 内容 | 状态 |
|------|------|------|
| `secure-keys/fund_log_idempotent_unique_index.sql` | cz_user_fund_log 唯一索引 | 已提供，需手动执行 |
| `secure-keys/withdrawal_indexes.sql` | cz_withdrawal 唯一索引+复合索引 | 已提供，需手动执行 |
| `secure-keys/transaction_order_pid_status_index.sql` | cz_transaction_order 复合索引 | 已提供，需手动执行 |
| `secure-keys/order_archive_schema.sql` | cz_order archived 列 | 已提供，需手动执行 |
| `secure-keys/global_message_unique_index.sql` | 全局消息唯一索引 | 已提供，需手动执行 |
| `secure-keys/withdrawal_audit.sql` | 提现审计查询（只读） | 已提供 |

**注意：源码中 SQL 脚本存在 ≠ 生产数据库已执行。上线前必须在生产数据库实际验证。**

### 生产环境验证命令（上线前必须执行）

```sql
-- 1. 验证资金账本唯一索引
SHOW INDEX FROM cz_user_fund_log WHERE Key_name = 'uk_uid_wallet_direction_reqno';

-- 2. 验证各表 order_number 唯一索引
SHOW INDEX FROM cz_recharge WHERE Column_name = 'order_number' AND Non_unique = 0;
SHOW INDEX FROM cz_order WHERE Column_name = 'order_number' AND Non_unique = 0;
SHOW INDEX FROM cz_transaction_order WHERE Column_name = 'order_number' AND Non_unique = 0;
SHOW INDEX FROM cz_withdrawal WHERE Key_name = 'uk_order_number';

-- 3. 如缺失，执行对应 SQL 脚本
-- source secure-keys/fund_log_idempotent_unique_index.sql
-- source secure-keys/withdrawal_indexes.sql
-- ...
```

---

## 五、bepusdt 回归测试结果（代码逻辑推演）

| 测试场景 | 初始状态 | 回调参数 | 预期结果 | 实际代码路径 | 通过 |
|----------|----------|----------|----------|-------------|------|
| 1. 正常待支付订单+成功回调 | status=0 | status=2, 金额正确 | 入账成功，status 0→3 | 第150行 localStatus=0 → 第198/211行非支付成功状态跳过 → 第238行 localStatus===0 通过 → 第269行入账 | ✅ |
| 2. 已完成订单+再次回调 | status=3 | status=2 | 幂等返回，不入账 | 第153行 localStatus===3 → commit + return ok | ✅ |
| 3. 已取消订单+成功回调 | status=2 | status=2 | **拒绝，不入账，status 保持 2** | 第166行 localStatus===2 → 记录安全日志 → commit + return ok | ✅ |
| 4. 已取消订单+并发4个回调 | status=2 | status=2 ×4 | 全部拒绝，无任何变更 | 每个请求独立事务，第166行拦截，不修改数据 | ✅ |
| 5. 签名错误 | 任意 | 签名错误 | 拒绝，返回 fail | 第124行 verifyNotify 失败 → return fail | ✅ |
| 6. 金额不足 | status=0 | status=2, paidAmount < amount | 拒绝，回滚 | 第274行 paidAmount < amount → 抛异常 → rollback | ✅ |
| 7. 不存在 order_number | 无此订单 | status=2 | 拒绝，回滚 | 第146行 recharge 不存在 → 抛异常 → rollback | ✅ |
| 8. 已提交订单(status=1)+成功回调 | status=1 | status=2 | 拒绝（status!==0） | 第238行 localStatus!==0 → 记录日志 → return ok | ✅ |

---

## 六、epay 回归测试结果（代码逻辑推演）

| 测试场景 | 初始状态 | 回调参数 | 预期结果 | 实际代码路径 | 通过 |
|----------|----------|----------|----------|-------------|------|
| 1. 正常待支付订单+成功回调 | status=0 | TRADE_SUCCESS, 金额正确 | 入账成功，status 0→3 | localStatus=0 → 三层检查通过 → 入账 | ✅ |
| 2. 已完成订单+再次回调 | status=3 | TRADE_SUCCESS | 幂等返回，不入账 | localStatus===3 → return | ✅ |
| 3. 已取消订单+成功回调 | status=2 | TRADE_SUCCESS | **拒绝，不入账，status 保持 2** | localStatus===2 → 记录安全日志 → return | ✅ |
| 4. 已取消订单+并发4个回调 | status=2 | TRADE_SUCCESS ×4 | 全部拒绝，无任何变更 | 每个请求独立事务，status===2 拦截 | ✅ |
| 5. 签名错误 | 任意 | 签名错误 | 拒绝，返回 fail | hash_equals 失败 → return fail | ✅ |
| 6. 金额不足 | status=0 | callbackMoney < gatewayAmount | 拒绝，回滚 | 金额验证失败 → 抛异常 → rollback | ✅ |
| 7. 不存在 order_number | 无此订单 | TRADE_SUCCESS | 拒绝，回滚 | recharge 不存在 → 抛异常 → rollback | ✅ |
| 8. 非成功状态回调 | status=0 | TRADE_CLOSED | 忽略，返回 success | tradeStatus 不在白名单 → return success | ✅ |

---

## 七、并发回调测试结果

### 场景：已取消订单同时收到 4 个支付成功回调

```
初始状态: recharge.status=2, user.balance=100, 金额=50

请求A: 事务开始 → recharge 行锁 → localStatus=2 → 记录日志 → commit → return ok
请求B: 事务开始 → recharge 行锁（等待A完成）→ localStatus=2 → 记录日志 → commit → return ok
请求C: 同上
请求D: 同上

最终状态: recharge.status=2（不变）, user.balance=100（不变）, fund_log 无新增
结果: 全部拒绝，无任何资金变更 ✅
```

### 场景：正常待支付订单同时收到 4 个支付成功回调

```
初始状态: recharge.status=0, user.balance=100, 金额=50

请求A: 获得 recharge 行锁 → localStatus=0 → 锁定 user → recharge.status=3 → balance=150 → 创建流水 → commit
请求B: 等待 recharge 行锁 → 获得锁 → localStatus=3 → 幂等返回
请求C: 同 B
请求D: 同 B

最终状态: recharge.status=3, user.balance=150, fund_log 1 条
结果: 只有第一个入账，其余幂等返回 ✅
```

---

## 八、取消订单支付回调测试结果

### 测试：用户主动取消订单后，bepusdt 收到链上转账

```
步骤1: 用户创建充值订单 (status=0, 金额=100 USDT)
步骤2: 用户点击取消 (status=0→2, cancel_time=now)
步骤3: 用户向 bepusdt 钱包地址转账 100 USDT（之前获取的地址）
步骤4: bepusdt 检测到到账，发送 status=2 回调

修复前:
  → 回调中 localStatus=2 ≠ 3，不拦截
  → 入账成功，balance +100，status 2→3 ❌

修复后:
  → 回调中 localStatus=2 → 第166行拦截
  → 记录安全日志: "bepusdt notify rejected: recharge already cancelled"
  → commit + return ok
  → status 保持 2，balance 不变，无流水 ✅
```

**注意：** 对于 bepusdt，用户取消订单后仍持有钱包地址，可以主动转账触发回调。修复后这种情况被正确拦截。平台收到的 USDT 需要人工处理（退款给用户或手动入账），但不会自动入账。

---

## 九、历史异常订单排查结果

### 排查方法

由于本地 MySQL 未运行，无法直接查询数据库。提供以下 SQL 供生产环境执行：

```sql
-- 1. 查找可能的 CANCELLED→COMPLETED 异常订单（status=3 但有 cancel_time）
SELECT id, uid, order_number, amount, status, cancel_time, paid_time, complete_time, gateway, pay_type
FROM cz_recharge
WHERE status = 3
  AND cancel_time IS NOT NULL
  AND cancel_time != ''
  AND cancel_time != '0000-00-00 00:00:00'
ORDER BY id DESC;

-- 2. 统计异常订单数量和总金额
SELECT COUNT(*) as anomaly_count, COALESCE(SUM(amount),0) as total_amount
FROM cz_recharge
WHERE status = 3
  AND cancel_time IS NOT NULL
  AND cancel_time != ''
  AND cancel_time != '0000-00-00 00:00:00';

-- 3. 检查异常订单对应的资金流水
SELECT fl.id, fl.uid, fl.biz_no, fl.amount, fl.change_type, fl.create_time
FROM cz_user_fund_log fl
WHERE fl.biz_no IN (
    SELECT order_number FROM cz_recharge
    WHERE status = 3 AND cancel_time IS NOT NULL AND cancel_time != ''
)
AND fl.change_type IN ('recharge_paid', 'recharge_manual_paid')
ORDER BY fl.id DESC;

-- 4. 检查是否存在重复入账（同一 order_number 多条 recharge_paid 流水）
SELECT biz_no, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
FROM cz_user_fund_log
WHERE change_type IN ('recharge_paid', 'recharge_manual_paid')
GROUP BY biz_no
HAVING cnt > 1
ORDER BY cnt DESC;
```

### 排查结论

**无法从当前环境证明历史数据状态。** 本地 MySQL 未运行，生产数据库需上线前实际执行上述 SQL 排查。

如果发现历史异常订单（status=3 且有 cancel_time），需要：
1. 核实该笔充值是否真实收到资金
2. 如未收到资金，需冲正余额并标记订单异常
3. 如已收到资金，属于合法入账但状态机异常，需补充备注

---

## 十、全局余额入口审计结果

### 所有充值入账入口（共 3 个）

| 入口 | 文件 | 方法 | 状态检查 | 修复状态 |
|------|------|------|----------|----------|
| bepusdt 自动回调 | Notify.php | api_callback_bepusdt() | 修复后：3→幂等, 2→拒绝, !0→拒绝, 0→入账 | ✅ 已修复 |
| epay 自动回调 | FinanceActions.php | handleEpayNotifyUrl() | 修复后：3→幂等, 2→拒绝, !0→拒绝, 0→入账 | ✅ 已修复 |
| 管理员手动审核 | AdminApi.php | recharge_post(audit) | 原有：只处理 status==1（已提交待审核） | ✅ 无需修改 |

### 所有余额变更入口（共 15 个，全部经过 UserFundLedgerService）

全局搜索 `changeLockedUserWallet` / `changeUserWallet` / `transferLockedUserWallet`，确认所有余额变更均通过统一资金服务，无绕过路径。

| 入口类型 | 文件 | 说明 |
|----------|------|------|
| 充值回调 | Notify.php, FinanceActions.php | 已修复状态机 |
| 管理员操作 | AdminApi.php, IndexApi.php | 余额调整/手动充值/退款 |
| 提现 | FinanceActions.php, AdminApi.php | 申请冻结/审核扣减/拒绝退款 |
| 产品订单 | OrderActions.php, ProductOrderService.php | 下单冻结/确认扣减/取消退款 |
| C2C 交易 | TransactionOrderService.php, TransactionActions.php | 创建冻结/放币扣减/买家收款 |
| 代理/分站 | AgentActions.php, SubstationApi.php | 钱包转账 |
| 核心服务 | UserFundLedgerService.php | 统一入口本身 |

**结论：不存在绕过统一资金服务、绕过订单状态检查、绕过幂等机制的充值入账路径。**

---

## 十一、是否仍存在 BLOCKER

### P1-001（已取消订单回调入账）

**状态：已修复。** 两个支付回调均增加了严格状态机，已取消订单收到支付成功回调时：
- 不修改订单状态
- 不增加用户余额
- 不创建资金流水
- 记录安全日志
- 返回 ok（让第三方停止重试）

**代码验证：** PHP 语法检查通过，逻辑推演通过。

### P1-002（数据库唯一索引）

**状态：无法验证。** 本地 MySQL 未运行，无法证明生产数据库状态。源码中已提供索引 SQL 脚本，但需手动执行。

**这是部署验证项，不是代码缺陷。** 上线前必须在生产数据库实际验证。

### 其他 P2/P3

**不在本轮处理范围内。** 按用户要求，本轮只处理 P1-001 和 P1-002，不修改其他问题。

---

## 十二、最终结论

### CONDITIONAL PASS（有条件通过）

**P1-001 已修复，代码层面不再存在已取消订单被支付回调入账的漏洞。**

**解除 BLOCKED 的条件：**

1. ✅ **P1-001 代码修复已完成** — Notify.php 和 FinanceActions.php 均增加严格状态机
2. ⚠️ **P1-002 生产数据库验证** — 上线前必须在生产数据库执行以下验证：
   - `cz_user_fund_log` 存在 `uk_uid_wallet_direction_reqno` 唯一索引
   - `cz_recharge.order_number`、`cz_order.order_number`、`cz_transaction_order.order_number`、`cz_withdrawal.order_number` 存在唯一索引
   - 如缺失，执行 `secure-keys/` 目录下对应 SQL 脚本
3. ⚠️ **历史异常订单排查** — 上线前在生产数据库执行第九节提供的 SQL，排查是否存在 status=3 且有 cancel_time 的异常订单
4. ⚠️ **生产环境密钥替换** — 当前 `.env` 为测试配置，生产环境必须替换所有密钥

**满足以上条件后，可从 BLOCKED 升级为 CONDITIONAL PASS。** 剩余 P2/P3 问题（float 金额、财务记录物理删除、第二套流水等）不阻断上线，但建议后续迭代修复。

---

*修复验证完成时间：2026-08-28*
*修复文件：app/controller/Notify.php, app/controller/indexapi/FinanceActions.php*
*验证方法：PHP 语法检查 + 代码逻辑推演 + 全局入口审计*
*数据库验证：本地 MySQL 未运行，无法证明生产状态，需上线前实际验证*
