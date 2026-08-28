# HCZ 生产环境上线前第二轮反向复核审计报告

**审计日期：** 2026-08-28
**审计范围：** 本地源码（commit 7a43153）+ 已部署生产代码 + 生产数据库
**审计原则：** 只审计，不修改代码；不接受上一轮结论，全部重新从源码验证
**审计目标：** 验证 P1-001/P1-002 修复有效性，寻找绕过资金安全模型的隐藏路径

---

## 一、最终结论

### CONDITIONAL PASS（有条件通过）

**核心资金安全链路已验证安全，未发现新的 P0/P1。**

P1-001（已取消订单回调入账）和 P1-002（资金唯一索引）均已修复并验证。核心资金链路（充值回调、余额变更、幂等机制、事务原子性）经源码级反向审计确认安全。

**解除 BLOCKED 的条件已满足：**
- ✅ P1-001 代码修复正确，bepusdt + epay 双渠道严格状态机
- ✅ P1-002 生产数据库 6 个关键唯一索引全部存在（用户已实际验证）
- ✅ 未发现新的 P0/P1 资金安全漏洞

**上线前必须完成的验证项（无法从当前环境证明）：**
1. ⚠️ 生产服务器代码 commit 确认为 7a43153（需在生产执行 `git log --oneline -1`）
2. ⚠️ 生产 PHP-FPM 已重启 / opcache 已清理（防止旧代码仍在运行）
3. ⚠️ 生产 .env 配置正确（APP_DEBUG=false、支付密钥为生产密钥）
4. ⚠️ 历史异常订单排查（需在生产数据库执行 SQL）

---

## 二、风险等级统计

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 | 0 | 无致命漏洞 |
| P1 | 0 | 无严重资金安全漏洞（上一轮 2 个 P1 已修复） |
| P2 | 3 | 财务记录物理删除、生产部署一致性待验证、历史数据待排查 |
| P3 | 0 | 本轮未发现新的低风险问题 |

---

## 三、P2 问题清单

### P2-001：充值订单可被管理员物理删除，无状态检查

**文件：** `app/controller/AdminApi.php`
**位置：** 第 3126-3136 行（`recharge_post` 方法的 `del` / `dels` case）
**代码：**
```php
case 'del':
    Recharge::destroy($post_info['id']);
    return show(200, 'success', '删除成功');

case 'dels':
    $data = Recharge::where('id', 'in', $post_info['ids'])->select();
    foreach($data as $key => $vo) {
        Recharge::destroy($vo['id']);
    }
```

**问题：**
- 无状态检查，可删除任何状态的充值订单（包括 status=3 已完成）
- 无关联资金流水检查，删除订单后资金流水仍然存在
- 无审计日志（对比其他操作都有 `directWriteAdminOperationLog`）
- 批量删除无事务保护

**影响：**
- 已完成充值订单被删除后，用户余额仍然存在，但订单记录消失，无法对账
- 审计链断裂，无法追溯资金来源
- 可能被用于掩盖异常充值记录

**触发条件：** 拥有"财务管理"权限的管理员调用删除接口

**修复建议：** 禁止物理删除已完成（status=3）的充值订单；删除前检查关联资金流水；增加审计日志；批量删除加事务。

**评级理由：** 不直接导致资金损失，但破坏财务审计链和数据一致性，属于 P2。

---

### P2-002：普通产品订单可被物理删除

**文件：** `app/controller/AdminApi.php`
**位置：** 第 2778-2786 行（`order_post` 方法的 `del` / `dels` case）
**问题：** 与 P2-001 类似，产品订单可被物理删除，无状态检查。
**影响：** 已支付/已完成订单被删除后，可能导致用户已购买服务无法追溯，或退款逻辑异常。
**修复建议：** 同 P2-001。

---

### P2-003：生产部署一致性与历史数据无法从当前环境证明

**问题：**
- 无法从本地环境证明生产服务器运行的代码确为 commit 7a43153
- 无法证明生产 PHP-FPM 已重启 / opcache 已清理
- 无法证明生产 .env 配置正确（APP_DEBUG、支付密钥等）
- 无法证明生产数据库无历史异常订单（status=3 且有 cancel_time）

**验证方法（上线前必须在生产执行）：**

```bash
# 1. 确认生产代码版本
cd /www/wwwroot/ops.hcz.app
git log --oneline -1
# 预期输出: 7a43153 fix: P1-001 recharge callback state machine + security hardening

# 2. 确认 Notify.php 包含修复
grep -c "already cancelled" app/controller/Notify.php
# 预期输出: 1

# 3. 确认 FinanceActions.php 包含修复
grep -c "already cancelled" app/controller/indexapi/FinanceActions.php
# 预期输出: 1

# 4. 重启 PHP-FPM（根据实际版本）
systemctl restart php-fpm  # 或 php81-fpm / php82-fpm

# 5. 清理 opcache / 缓存
rm -rf runtime/cache/* runtime/temp/*
```

**历史异常订单排查 SQL（在生产数据库执行）：**
```sql
-- 查找 status=3 但有 cancel_time 的异常订单
SELECT id, uid, order_number, amount, status, cancel_time, paid_time, gateway
FROM cz_recharge
WHERE status = 3 AND cancel_time IS NOT NULL AND cancel_time != ''
AND cancel_time != '0000-00-00 00:00:00'
ORDER BY id DESC;

-- 检查同一充值订单是否有多条入账流水
SELECT biz_no, COUNT(*) as cnt, SUM(amount) as total
FROM cz_user_fund_log
WHERE change_type IN ('recharge_paid', 'recharge_manual_paid')
GROUP BY biz_no HAVING cnt > 1 ORDER BY cnt DESC;
```

---

## 四、P1-001 修复验证（已取消订单回调入账）

### 4.1 bepusdt 回调（Notify.php::api_callback_bepusdt）

**修复后状态机（第 150-265 行）：**

| 本地订单状态 | 网关回调状态 | 处理结果 | 资金变更 |
|-------------|-------------|---------|---------|
| 0（待支付） | 2（支付成功） | 正常入账，status 0→3 | ✅ 余额增加 |
| 0（待支付） | 1（等待确认） | 更新网关信息，返回 ok | 无 |
| 0（待支付） | 3（网关取消） | status 0→2，记录取消时间 | 无 |
| 1（已提交） | 2（支付成功） | 第 238 行 `localStatus !== 0` 拦截，拒绝 | 无 |
| 2（已取消） | 2（支付成功） | 第 166 行 `localStatus === 2` 拦截，拒绝 | 无 |
| 3（已完成） | 2（支付成功） | 第 153 行 `localStatus === 3` 幂等返回 | 无 |

**关键防护点：**
1. 第 143 行：`Db::startTrans()` 事务开始
2. 第 145 行：`Recharge::where('order_number', $orderNumber)->lock(true)->find()` 行锁
3. 第 150 行：`$localStatus = (int)($recharge['status'] ?? 0)` 统一状态变量
4. 第 153 行：status===3 幂等返回
5. 第 166 行：status===2 明确拒绝，记录安全日志，不修改任何数据
6. 第 238 行：支付成功入账前二次检查 `localStatus !== 0` 拒绝
7. 第 269 行：只有此时才允许进入正常入账逻辑
8. 第 278 行：用户行锁 `directLockUser`
9. 第 298 行：`changeLockedUserWallet` 统一资金服务（带幂等 request_no）
10. 第 336 行：`Db::commit()` 事务提交

**金额安全：**
- 第 269 行：`$amount = round((float)($recharge['amount'] ?? 0), 2)` — 入账金额来自数据库
- 第 274 行：`if ($paidAmount < $amount)` — 回调金额只用于验证是否足额
- 第 301 行：入账使用 `$amount`（数据库值），不是回调值

**签名验证（BepusdtService::verifyNotify，第 118-129 行）：**
- md5(ksort 参数拼接 + apiToken)
- `hash_equals($expected, $signature)` 安全比较，防时序攻击
- 空签名/空 token 直接返回 false

**结论：✅ bepusdt 回调修复正确，无绕过路径。**

---

### 4.2 epay 回调（FinanceActions.php::handleEpayNotifyUrl）

**修复后状态机（第 1165-1209 行）：**

| 本地订单状态 | 处理结果 | 资金变更 |
|-------------|---------|---------|
| 0（待支付） | 正常入账，status 0→3 | ✅ 余额增加 |
| 2（已取消） | 第 1174 行拦截，拒绝 | 无 |
| 3（已完成） | 第 1167 行幂等返回 | 无 |
| 其他（1等） | 第 1195 行 `localStatus !== 0` 拦截 | 无 |

**关键防护点：**
1. 第 1107-1140 行：签名验证（md5 + ksort + hash_equals）
2. 第 1147-1154 行：交易状态白名单（只处理 TRADE_SUCCESS 等成功状态）
3. 第 1157 行：`Db::transaction(function () use (...) { ... })` 事务闭包
4. 第 1158 行：recharge 行锁
5. 第 1165 行：`$localStatus` 统一状态变量
6. 第 1167 行：status===3 幂等返回（闭包 return）
7. 第 1174 行：status===2 明确拒绝，记录安全日志
8. 第 1195 行：`localStatus !== 0` 拒绝
9. 第 1210-1220 行：支付渠道验证（pay_type=2 或 gateway=epay）
10. 第 1222 行：用户行锁
11. 第 1227 行：金额来自数据库
12. 第 1232-1256 行：回调金额验证（callbackMoney + 0.01 >= gatewayAmount）
13. 第 1272 行：`changeLockedUserWallet` 统一资金服务（带幂等 request_no）

**结论：✅ epay 回调修复正确，无绕过路径。**

---

### 4.3 其他充值入账入口

全局搜索确认充值资金入账只有 3 个入口：

| 入口 | 文件 | 状态检查 | 安全性 |
|------|------|----------|--------|
| bepusdt 自动回调 | Notify.php | status=3 幂等, status=2 拒绝, status!==0 拒绝 | ✅ |
| epay 自动回调 | FinanceActions.php | status=3 幂等, status=2 拒绝, status!==0 拒绝 | ✅ |
| 管理员手动审核 | AdminApi.php 第 3037 行 | 只处理 status==1（已提交待审核） | ✅ |

**管理员手动充值审核（AdminApi.php 第 3031-3124 行）：**
- 第 3036 行：recharge 行锁
- 第 3037 行：`if($recharge_info && (int)$recharge_info['status'] == 1)` — 只处理 status=1
- 第 3053-3096 行：审核通过（auditStatus=1）时，用户行锁 + changeLockedUserWallet + 审计日志
- 第 3098 行：commit
- 事务 + 行锁 + 状态检查 + 统一资金服务 + 审计日志

**结论：✅ 所有充值入账入口均有严格状态检查，无绕过路径。**

---

## 五、P1-002 数据库唯一索引验证

### 5.1 生产数据库实际验证结果（用户已执行）

| 表 | 索引名称 | 字段 | Non_unique | 状态 |
|---|---|---|---|---|
| cz_user_fund_log | uk_uid_wallet_direction_reqno | uid, wallet_type, direction, request_no | 0 | ✅ 存在 |
| cz_recharge | uk_order_number | order_number | 0 | ✅ 存在 |
| cz_order | uk_order_number | order_number | 0 | ✅ 存在 |
| cz_transaction_order | uk_order_number | order_number | 0 | ✅ 存在 |
| cz_withdrawal | uk_order_number | order_number | 0 | ✅ 存在 |
| cz_user | uk_mobile | mobile | 0 | ✅ 存在 |

**6 个关键唯一索引全部在生产数据库实际存在，Non_unique=0。**

### 5.2 唯一索引覆盖范围验证

**cz_user_fund_log.uk_uid_wallet_direction_reqno（四列复合唯一索引）：**

各业务场景的 request_no 生成规则：

| 业务场景 | request_no 格式 | 幂等性 |
|---------|-----------------|--------|
| 充值入账（bepusdt） | `recharge_paid:{order_number}` | ✅ |
| 充值入账（epay） | `recharge_paid:{order_number}` | ✅ |
| 管理员手动充值 | `recharge_manual_paid:{order_number}` | ✅ |
| 管理员加款 | `admin_balance_add:{bizNo}` | ✅ |
| 管理员扣款 | `admin_balance_subtract:{bizNo}` | ✅ |
| 提现审核通过扣冻结 | `withdraw_deduct:{order_number}` | ✅ |
| 提现拒绝退款 | `withdraw_reject_refund:{order_number}` | ✅ |
| 提现手续费收入 | `withdraw_fee:{order_number}` | ✅ |
| 产品订单支付 | `order_pay:{order_number}` | ✅ |
| 产品订单退款 | `order_refund:{order_number}` | ✅ |
| C2C 放币 | `c2c_release:{order_number}` | ✅ |

**不同业务使用不同的 request_no 前缀，不会意外冲突。同一业务同一订单使用相同 request_no，唯一索引保证不重复。**

### 5.3 应用层幂等 + 数据库层幂等双重防护

**UserFundLedgerService::changeLockedUserWallet（第 74-144 行）：**

1. **应用层幂等（第 104-110 行）：** 修改余额前，先按 `(uid, wallet_type, direction, request_no)` 查找已有流水。如果存在且金额匹配，直接返回不修改余额。

2. **数据库层幂等（第 474-513 行 createLogWithIdempotentFallback）：** 创建流水时，如果唯一索引冲突（1062 错误），查找已有流水并返回，标记 duplicated=true。

3. **行锁保护：** 用户行在事务开始时锁定（`lock(true)`），并发请求必须等待前一个事务 commit 后才能获得行锁，此时应用层幂等检查就能看到已创建的流水。

**结论：✅ 唯一索引真实存在且覆盖所有资金场景，应用层+数据库层+行锁三重幂等防护有效。**

---

## 六、全局资金入口审计

### 6.1 搜索方法

全局搜索以下模式：
- `->balance =` / `->frozen_amount =` / `->agent_wallet =`（直接赋值）
- `balance = balance +` / `frozen_amount = frozen_amount +`（SQL 原始修改）
- `Db::raw.*balance` / `balance.*Db::raw`（原始表达式）
- `setInc(` / `setDec(` / `increment(` / `decrement(`（ORM 增减）
- `changeUserWallet` / `changeLockedUserWallet` / `transferLockedUserWallet`（统一服务）

### 6.2 搜索结果

- **直接赋值修改余额：** 0 处
- **SQL 原始修改余额：** 1 处（AdminApi.php 第 3410-3413 行，积分系统 `points_balance`，非资金余额）
- **setInc/setDec：** 0 处
- **统一资金服务调用：** 全部余额变更均通过 `UserFundLedgerService`

### 6.3 所有余额变更入口清单

| 入口类型 | 文件 | 方法 | 钱包类型 | 事务 | 行锁 | 幂等 |
|---------|------|------|---------|------|------|------|
| 充值回调 | Notify.php | api_callback_bepusdt | balance | ✅ | ✅ | ✅ |
| 充值回调 | FinanceActions.php | handleEpayNotifyUrl | balance | ✅ | ✅ | ✅ |
| 管理员手动充值 | AdminApi.php | recharge_post(audit) | balance | ✅ | ✅ | ✅ |
| 管理员余额调整 | AdminApi.php | handleBalance | balance | ✅ | ✅ | ✅ |
| 提现申请 | FinanceActions.php | handleApiFinanceWithdraw | balance→frozen | ✅ | ✅ | ✅ |
| 提现审核通过 | AdminApi.php | withdrawal_post(audit) | frozen | ✅ | ✅ | ✅ |
| 提现审核拒绝 | AdminApi.php | withdrawal_post(audit) | frozen→balance | ✅ | ✅ | ✅ |
| 产品订单支付 | OrderActions.php | handleApiOrderPay | balance→frozen | ✅ | ✅ | ✅ |
| 产品订单确认收货 | ProductOrderService.php | confirmOrder | frozen | ✅ | ✅ | ✅ |
| 产品订单取消退款 | OrderActions.php | handleApiOrderCancel | frozen→balance | ✅ | ✅ | ✅ |
| C2C 创建交易 | TransactionActions.php | handleApiTransactionCreate | balance→frozen | ✅ | ✅ | ✅ |
| C2C 放币 | TransactionOrderService.php | releaseOrder | frozen | ✅ | ✅ | ✅ |
| C2C 取消退款 | TransactionOrderService.php | cancelOrder | frozen→balance | ✅ | ✅ | ✅ |
| 代理钱包转账 | AgentActions.php | handleApiAgentTransfer | agent_wallet | ✅ | ✅ | ✅ |
| 分站钱包转账 | SubstationApi.php | walletTransfer | agent_wallet | ✅ | ✅ | ✅ |

**结论：✅ 所有余额变更均通过 UserFundLedgerService，无绕过统一资金服务的直接修改路径。**

---

## 七、并发与事务场景推演

### 场景 1：同一支付订单 4 个成功回调并发

**初始状态：** recharge.status=0, user.balance=100, amount=50

```
请求A: 获得 recharge 行锁 → localStatus=0 → 获得 user 行锁 → recharge.status=3
       → balance=150 → 创建流水(request_no=recharge_paid:ORD001) → commit
请求B: 等待 recharge 行锁 → 获得锁 → localStatus=3 → 第153行幂等返回 → commit
请求C: 同 B
请求D: 同 B
```

**最终状态：** recharge.status=3, user.balance=150, 流水 1 条
**结论：✅ 只有第一个入账，其余幂等返回，资金守恒。**

---

### 场景 2：取消操作与支付回调同时到达

**子场景 A：支付回调先获得锁**
```
回调A: 获得 recharge 行锁 → localStatus=0 → 入账 → status=3 → commit
取消B: 等待 recharge 行锁 → 获得锁 → status=3 → 取消操作检查 status!=0 → 拒绝
```
**结果：✅ 合法入账，保持 completed，取消被拒绝。**

**子场景 B：取消操作先获得锁**
```
取消A: 获得 recharge 行锁 → status=0→2 → commit
回调B: 等待 recharge 行锁 → 获得锁 → localStatus=2 → 第166行拒绝 → commit
```
**结果：✅ 保持 cancelled，不入账，回调被拒绝。**

**子场景 C：真正同时到达（数据库调度决定顺序）**
- InnoDB 行锁保证只有一个请求先获得锁
- 无论谁先获得锁，最终结果要么是合法入账（completed），要么是取消成功（cancelled）
- 绝不可能出现 cancelled→completed 的非法转换，因为回调在获得锁后会检查 localStatus

**结论：✅ 取消与支付竞态安全，无非法状态转换，无资金与状态不一致。**

---

### 场景 3：同一用户 4 个余额扣款并发

**初始状态：** user.balance=100，4 个请求各扣 80

```
请求A: 获得 user 行锁 → balance=100 → 扣 80 → balance=20 → commit
请求B: 等待 user 行锁 → 获得锁 → balance=20 → 扣 80 → afterAmount=-60
       → 第97行 afterAmount < -0.000001 → 抛异常 → rollback
请求C: 同 B → rollback
请求D: 同 B → rollback
```

**最终状态：** user.balance=20，只有请求 A 成功
**结论：✅ 不会出现负数余额，行锁+负数检查保证资金安全。**

---

### 场景 4：同一提现订单 4 个管理员审核并发

**初始状态：** withdrawal.status=0, user.frozen_amount=100, amount=50

```
请求A: 获得 withdrawal 行锁 → status=0 → 获得 user 行锁 → 扣 frozen 50
       → withdrawal.status=1 → commit
请求B: 等待 withdrawal 行锁 → 获得锁 → status=1 → 第2818行 status!=0 → 拒绝
请求C: 同 B
请求D: 同 B
```

**最终状态：** withdrawal.status=1, user.frozen_amount=50，只有请求 A 成功
**结论：✅ 不会重复审核/重复出款，withdrawal 行锁+status 检查保证幂等。**

---

## 八、管理员资金权限审计

### 8.1 管理员余额调整（AdminApi.php::handleBalance，第 1433-1549 行）

**安全防护层：**
1. ✅ 权限检查：`directHasAdminPermission('用户列表')`
2. ✅ CSRF 验证：`directValidateRequiredCsrfToken()`
3. ✅ 参数污染检测：禁止配置字段（第 1447-1457 行）
4. ✅ 请求路径验证：`directRequestPathMatches('user_post/balance')`
5. ✅ 敏感操作验证：`directValidateSensitiveOperation`（2FA/密码）
6. ✅ 参数白名单：`array_intersect_key`，只允许 uid, balance_cz, add_minus
7. ✅ 事务：`Db::startTrans()`
8. ✅ 用户行锁：`directLockUser`
9. ✅ 金额验证：必须 > 0
10. ✅ 统一资金服务：`directAdminAdjustBalanceWithLedger` → UserFundLedgerService
11. ✅ 审计日志：`directWriteAdminOperationLog`

**结论：✅ 管理员余额调整安全，多层防护，普通用户无法调用。**

### 8.2 管理员手动充值审核（AdminApi.php::recharge_post，第 3031-3124 行）

- ✅ 权限检查
- ✅ recharge 行锁
- ✅ 只处理 status==1
- ✅ 用户行锁
- ✅ 统一资金服务（带幂等 request_no）
- ✅ 事务
- ✅ 审计日志

**结论：✅ 安全。**

### 8.3 普通用户越权风险

- 所有 AdminApi 方法均在路由层有管理员认证中间件
- 管理员 API 路径与用户 API 路径分离（/admin/ vs /api/）
- 未发现普通用户可直接调用管理员 API 的路径

**结论：✅ 无普通用户越权调用管理员资金接口的风险。**

---

## 九、签名与金额安全审计

### 9.1 bepusdt 签名

**BepusdtService::verifyNotify（第 118-129 行）：**
- 签名算法：md5(ksort(参数拼接) + apiToken)
- 安全比较：`hash_equals($expected, $signature)`
- 空值防护：空签名/空 token 直接返回 false
- 长度检查：`strlen($signature) === strlen($expected)`

**结论：✅ 安全，防时序攻击，防伪造。**

### 9.2 epay 签名

**FinanceActions.php::handleEpayNotifyUrl（第 1108-1140 行）：**
- 签名算法：md5(ksort(参数拼接) + epayKey)
- 安全比较：`hash_equals($expectedSign, $sign)`
- 空值防护：空签名/空 key 返回 500
- 排除 sign_type 和空值参数

**结论：✅ 安全。**

### 9.3 金额安全

**bepusdt 回调：**
- 入账金额 `$amount = round((float)($recharge['amount'] ?? 0), 2)` — 来自数据库
- 回调金额 `$paidAmount` 只用于验证 `$paidAmount < $amount`
- 实际入账使用数据库金额，非回调金额

**epay 回调：**
- 入账金额 `$amount = round((float)($recharge['amount'] ?? 0), 2)` — 来自数据库
- 回调金额 `$callbackMoney` 用于验证 `($callbackMoney + 0.01) < $gatewayAmount`
- `$gatewayAmount` 来自数据库 `recharge.gateway_actual_amount`，如为 0 则用 `amount * rate` 计算
- 实际入账使用数据库金额

**浮点精度：**
- 所有金额计算使用 `round($value, 2)`，保留 2 位小数
- 负数检查使用 `-0.000001` 容差，防止浮点精度误差导致误判
- 金额比较使用容差（epay 使用 0.01 容差）

**结论：✅ 金额安全，入账金额始终来自数据库可信字段，回调金额只用于验证。浮点精度处理合理。**

---

## 十、生产部署一致性验证（无法从当前环境证明）

以下项目需要在生产服务器实际验证，本轮审计无法从本地环境证明：

| 验证项 | 验证方法 | 预期结果 | 状态 |
|--------|---------|---------|------|
| 生产代码 commit | `git log --oneline -1` | 7a43153 | ⚠️ 待验证 |
| Notify.php 包含修复 | `grep -c "already cancelled" app/controller/Notify.php` | 1 | ⚠️ 待验证 |
| FinanceActions.php 包含修复 | `grep -c "already cancelled" app/controller/indexapi/FinanceActions.php` | 1 | ⚠️ 待验证 |
| PHP-FPM 已重启 | `systemctl status php-fpm` 或查看重启时间 | 近期重启 | ⚠️ 待验证 |
| opcache 已清理 | `rm -rf runtime/cache/*` | 已执行 | ⚠️ 待验证 |
| APP_DEBUG=false | `grep APP_DEBUG .env` | false | ⚠️ 待验证 |
| 支付密钥为生产密钥 | 检查 .env 中 bepusdt/epay 配置 | 生产密钥 | ⚠️ 待验证 |
| 历史异常订单 | 执行第九节 SQL | 0 条 | ⚠️ 待验证 |

**重要提示：** 如果生产 PHP-FPM 未重启且 opcache 启用，即使代码已更新，运行的仍可能是旧代码。上线前必须重启 PHP-FPM。

---

## 十一、上线前必须完成事项

1. ✅ **P1-001 代码修复** — 已完成（commit 7a43153）
2. ✅ **P1-002 数据库索引** — 已完成（生产数据库 6 个唯一索引全部存在）
3. ⚠️ **生产代码部署验证** — 确认生产服务器运行 commit 7a43153
4. ⚠️ **PHP-FPM 重启 / opcache 清理** — 防止旧代码仍在运行
5. ⚠️ **生产 .env 配置检查** — APP_DEBUG=false、支付密钥为生产密钥
6. ⚠️ **历史异常订单排查** — 在生产数据库执行 SQL，确认无 status=3 且有 cancel_time 的异常订单
7. ⚠️ **支付回调 URL 可访问性验证** — bepusdt/epay 的 notify_url 可被第三方访问

---

## 十二、上线后建议监控事项

1. **支付回调异常日志监控** — 监控 `bepusdt notify rejected` / `epay notify rejected` 日志，及时发现异常回调
2. **资金流水一致性监控** — 定期核对 user.balance + user.frozen_amount 与 fund_log 的一致性
3. **重复入账告警** — 监控唯一索引冲突（1062 错误），可能是攻击或异常
4. **管理员操作审计** — 定期审查管理员余额调整、充值审核、提现审核日志
5. **订单状态异常监控** — 监控 status=3 且有 cancel_time 的异常订单

---

## 十三、审计覆盖范围说明

**本轮已深度审计：**
- ✅ P1-001 修复验证（bepusdt + epay 双渠道状态机）
- ✅ P1-002 数据库索引验证（6 个唯一索引）
- ✅ 全局资金入口（15 个余额变更入口，全部通过统一服务）
- ✅ 资金幂等机制（应用层 + 数据库层 + 行锁三重防护）
- ✅ 签名验证（bepusdt + epay，hash_equals）
- ✅ 金额安全（入账金额始终来自数据库）
- ✅ 并发场景推演（4 个核心场景）
- ✅ 管理员资金权限（余额调整、手动充值、提现审核）
- ✅ 提现审核状态机
- ✅ 财务记录物理删除

**本轮未深度审计（上一轮已覆盖，本轮未发现新问题）：**
- C2C 交易完整链路（上一轮已审计，状态机+行锁+幂等）
- 产品订单完整链路（上一轮已审计）
- XSS / CSRF / CORS / SSRF（上一轮已审计）
- 注册/登录/密码找回（上一轮已审计）
- 文件上传（上一轮已审计）
- 依赖安全（无法联网查询 CVE）

---

## 十四、最终判定

### CONDITIONAL PASS（有条件通过）

**判定依据：**
- ✅ 无 P0/P1 资金安全漏洞
- ✅ P1-001（已取消订单回调入账）已修复并源码级验证正确
- ✅ P1-002（资金唯一索引）已在生产数据库实际验证存在
- ✅ 核心资金链路（充值回调、余额变更、幂等、事务、并发）经反向审计确认安全
- ✅ 所有余额变更均通过统一资金服务，无绕过路径
- ⚠️ 存在 3 个 P2 问题（财务记录物理删除、生产部署一致性待验证、历史数据待排查），不阻断上线但需关注
- ⚠️ 生产部署一致性无法从本地环境证明，需上线前实际验证

**上线条件：**
1. 确认生产服务器运行 commit 7a43153
2. 重启 PHP-FPM / 清理 opcache
3. 确认生产 .env 配置正确（APP_DEBUG=false、支付密钥为生产密钥）
4. 在生产数据库执行历史异常订单排查 SQL，确认无异常

满足以上条件后，可从 CONDITIONAL PASS 升级为 PASS。

---

*审计完成时间：2026-08-28*
*审计方法：源码级反向审计 + 并发场景推演 + 数据库实际验证*
*审计范围：本地源码 commit 7a43153 + 生产数据库（用户已验证索引）*
*未修改任何代码文件*
