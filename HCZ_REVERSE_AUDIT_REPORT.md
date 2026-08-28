# HCZ 服务充值网站后端 — 第一轮审计报告反向复核审计

**审计日期：** 2026-08-28
**审计性质：** 独立第二名审计人员反向复核
**审计原则：** 推翻上一轮结论，从源码重新验证；只审计不修改
**被复核报告：** HCZ_PRODUCTION_SECURITY_AUDIT_REPORT.md（第一轮，结论 CONDITIONAL PASS）

---

## 一、最终结论

### 判定：BLOCKED（阻断上线）

**存在 P1 级可利用的业务逻辑漏洞：已取消充值订单可被支付回调入账。** 上一轮将此问题评为 P2 是严重低估。

对于 bepusdt（USDT 链上支付），用户创建订单后获得唯一钱包地址，**即使用户主动取消订单或 Cron 自动取消订单，用户仍可向该钱包地址转账**，bepusdt 检测到到账后发送支付成功回调，回调逻辑只检查 `status===3`（已完成）就跳过，**不检查 `status===2`（已取消）**，导致已取消订单被入账，余额增加，订单状态非法地从 CANCELLED 变为 COMPLETED。

---

## 二、风险等级统计（本轮重新判级）

| 等级 | 数量 | 说明 |
|------|------|------|
| **P0** | **0** | 无直接凭空资金损失 / 权限接管 / RCE |
| **P1** | **2** | 已取消订单可被回调入账（上一轮误判为P2）；资金唯一索引需手动创建（上一轮P1维持） |
| **P2** | **4** | 第二套流水无唯一索引；充值创建无事务；Cron批量取消无锁；金额float类型 |
| **P3** | **3** | 注册无密码强度；无密码找回；订单号可预测性 |

---

## 三、上一轮审计错误问题清单

### 错误-001：已取消充值订单可被支付回调入账 — 上一轮评为 P2，本轮纠正为 P1

| 项 | 内容 |
|----|------|
| **上一轮评级** | P2（"已取消订单回调处理"，列为 P2-002 的子项） |
| **本轮评级** | **P1（严重业务逻辑漏洞，可利用）** |
| **文件** | `app/controller/Notify.php` 第150行 + 第189行；`app/controller/indexapi/FinanceActions.php` 第1167行 + 第1217行 |
| **根因** | 两个支付回调的状态判断逻辑均为：`if (recharge.status === 3) return;` 然后直接进入入账流程。**缺少 `if (recharge.status === 2) reject;`**。已取消订单（status=2）不会被拦截，会被直接入账。 |

**完整调用链验证（bepusdt）：**
```
POST /api/callback/bepusdt
  → verifyNotify() 签名验证 ✓
  → Db::startTrans()
  → Recharge::where(order_number)->lock(true)->find()
  → if status === 3: return ok  ← 只拦截已完成
  → if 网关status === 1: 更新网关信息, return
  → if 网关status === 3: 取消, return
  → if 网关status !== 2: 抛异常
  → 网关status === 2 (支付成功):
    → amount = recharge.amount (从数据库读取)
    → paidAmount >= amount 验证
    → directLockUser(uid)  ← 锁定用户
    → recharge.status = 3  ← 已取消订单被改为已完成！
    → UserFundLedgerService::changeLockedUserWallet(+amount)  ← 余额增加！
    → directWriteBalanceLog()  ← 流水生成
  → Db::commit()
```

**可利用性证明（bepusdt）：**
1. 用户创建充值订单（status=0，金额=100 USDT），获得 bepusdt 钱包地址
2. 用户点击"取消订单"（status=0→2，`handleApiFinanceRechargeSubmit` action=cancel，第1632行）
3. **用户仍持有该钱包地址**，向该地址转账 100 USDT
4. bepusdt 监听到链上转账，发送 `status=2` 回调，`order_id` = 已取消订单号
5. 回调中：本地 status=2 ≠ 3，不被拦截；网关 status=2，进入入账逻辑
6. **结果：余额 +100，订单 status 2→3，生成资金流水**

**同样适用于 epay：**
1. 用户创建充值订单，跳转到支付宝/微信支付页面
2. 用户在另一个标签页取消订单
3. 用户在支付页面完成支付
4. epay 发送 TRADE_SUCCESS 回调
5. 回调中：status=2 ≠ 3，不被拦截，直接入账

**实际影响：**
- 状态机非法转换：CANCELLED(2) → COMPLETED(3)
- 用户可以"复活"已取消订单
- 对于 bepusdt，用户完全控制转账时机，这是**主动可利用**的
- 财务对账混乱：已取消订单显示已完成
- 可能被用于绕过业务限制（如促销价格锁定：活动期间创建订单获取价格，活动结束后取消再支付？金额是创建时确定的，但订单已取消后支付仍按原金额入账）

**为什么不是 P0：** 平台确实收到了第三方支付的资金（USDT 或 支付宝/微信），给用户加等值余额在资金上是平衡的，平台没有直接资金损失。但这是严重的业务逻辑漏洞和状态机破坏。

**修复建议：** 在两个支付回调的入账逻辑前增加：
```php
if ((int)($recharge['status'] ?? 0) === 2) {
    Log::warning('paid callback for cancelled recharge rejected', ['order_number' => $orderNumber]);
    Db::rollback(); // 或 commit 后返回 fail
    return response('fail', 400); // 或 ok（让第三方停止重试）但不入账
}
```

---

### 错误-002：上一轮声称"PASS — 所有余额变更均通过 UserFundLedgerService" — 本轮验证确认正确

| 项 | 内容 |
|----|------|
| **上一轮结论** | 所有余额变更均通过 UserFundLedgerService |
| **本轮验证** | **确认正确，PASS** |
| **验证方法** | 全局搜索 `->balance =`, `->frozen_amount =`, `->agent_wallet =`, `balance +=`, `setInc`, `setDec` |
| **结果** | 未发现任何绕过 UserFundLedgerService 直接修改余额的代码路径。所有余额变更均通过 `changeUserWallet()` / `changeLockedUserWallet()` / `transferLockedUserWallet()` 统一入口 |

**余额修改入口全量清单（本轮确认）：**

| 入口 | 文件 | 钱包 | 方向 | Ledger | 事务 | 行锁 | 幂等 |
|------|------|------|------|--------|------|------|------|
| bepusdt充值回调 | Notify.php:218 | balance | + | ✓ | ✓ | ✓ | ✓ |
| epay充值回调 | FinanceActions.php:1232 | balance | + | ✓ | ✓ | ✓ | ✓ |
| 管理员手动充值 | AdminApi.php:3061 | balance | + | ✓ | ✓ | ✓ | ✓ |
| 管理员余额调整 | AdminApi.php:directAdminAdjustBalanceWithLedger | balance/frozen | +/- | ✓ | ✓ | ✓ | ✓ |
| 提现申请 | FinanceActions.php:870 | balance→frozen | transfer | ✓ | ✓ | ✓ | ✓ |
| 提现审核通过 | AdminApi.php:2839 | frozen | - | ✓ | ✓ | ✓ | ✓ |
| 提现审核拒绝 | AdminApi.php:2893 | frozen→balance | transfer | ✓ | ✓ | ✓ | ✓ |
| 产品订单支付(冻结) | OrderActions.php:836 | balance→frozen | transfer | ✓ | ✓ | ✓ | ✓ |
| 产品订单支付(直扣) | OrderActions.php:901 | balance | - | ✓ | ✓ | ✓ | ✓ |
| 产品订单取消退款 | IndexApi.php:directCancelOrderRefund | frozen→balance | transfer | ✓ | ✓ | ✓ | ✓ |
| 产品订单确认收货 | ProductOrderService.php:confirmReceipt | frozen | - | ✓ | ✓ | ✓ | ✓ |
| C2C交易创建 | TransactionOrderService.php | balance→frozen | transfer | ✓ | ✓ | ✓ | ✓ |
| C2C卖家放币 | TransactionOrderService.php:184 | frozen | - | ✓ | ✓ | ✓ | ✓ |
| C2C买家收款 | TransactionOrderService.php:210 | balance | + | ✓ | ✓ | ✓ | ✓ |
| 代理钱包转账 | AgentActions.php | agent→balance | transfer | ✓ | ✓ | ✓ | ✓ |

---

### 错误-003：上一轮 P1-001"资金唯一索引需手动创建" — 本轮维持 P1，但补充证明

| 项 | 内容 |
|----|------|
| **上一轮评级** | P1 |
| **本轮评级** | **P1（维持）** |
| **反向验证问题1** | 没有唯一索引时，正常支付回调是否因 recharge row lock + status=3 而不会重复入账？ |
| **回答** | **是的。** 正常流程下，recharge 行锁 + status=3 检查构成第一道幂等防线，同一订单的并发回调只有第一个能入账。没有唯一索引时，正常流程不会重复入账。 |
| **反向验证问题2** | 有没有真实代码路径可以绕过 recharge status 并重复调用 changeLockedUserWallet()？ |
| **回答** | **有极端场景。** (a) 应用层代码异常导致事务回滚后重试，但 recharge 状态未正确回滚；(b) 管理员手动执行数据库修改后重试；(c) Worker 重试同一资金任务时 request_no 相同但外层无行锁；(d) 未来代码变更引入新的资金入口但遗漏状态检查。 |
| **反向验证问题3** | request_no 是否真的能够重复？ |
| **回答** | **是的。** request_no 格式为 `recharge_paid:ORDER_NUMBER`，同一订单号的回调必然使用相同 request_no。如果外层状态检查失效，两个请求会使用相同 request_no。 |
| **反向验证问题4** | 如果没有唯一索引，重复 request_no 会导致重复余额增加还是只重复流水？ |
| **回答** | **重复余额增加。** `changeLockedUserWallet()` 的流程是：先查询是否存在相同 request_no 的流水 → 不存在则更新用户余额 → 再创建流水。如果两个并发请求都通过了查询（无唯一索引兜底），两个都会更新余额，导致重复增加。唯一索引在 `createLogWithIdempotentFallback()` 中通过捕获 1062 冲突来识别重复，没有索引则该兜底失效。 |
| **反向验证问题5** | 是否存在管理员调整/Worker retry/支付回调/邀请奖励/提现/C2C 等业务在没有数据库唯一索引时产生实际重复资金变更的路径？ |
| **回答** | **支付回调和管理员调整**：正常流程有行锁+状态检查，风险低但非零。**Worker retry**：当前项目的 Job（BatchElectricityQuery 等）不涉及资金操作，风险低。**邀请奖励**：未发现独立的邀请奖励资金操作。**提现/C2C**：均有状态检查+行锁。综合判断：唯一索引是最后一道防线，缺失不会在正常流程下导致重复，但在异常/未来变更时失去兜底。 |
| **最终判断** | **P1 维持。** 理由：(1) 代码明确依赖该索引名称做 1062 冲突匹配，缺失则该代码分支永远不会触发；(2) 这是部署依赖项，生产环境可能遗漏；(3) 资金系统的幂等应该有数据库级不可绕过的保障。 |

---

## 四、上一轮漏审问题

### 漏审-001：产品订单存在两种支付模式，一种直接扣减 balance 不经过 frozen

| 项 | 内容 |
|----|------|
| **文件** | `app/controller/indexapi/OrderActions.php` 第877-928行 `handleQueryBusinessPagePost()` case `confirm_payment` |
| **问题** | 该接口直接从 `balance` 扣减金额（`changeLockedUserWallet(WALLET_BALANCE, -amount)`），不经过 `frozen_amount`。而 `freezeProductOrderPayment()` 方法使用 `transferLockedUserWallet` 从 balance 转到 frozen。两种模式并存。 |
| **安全性** | 直接扣减模式有事务 + 行锁 + 余额不足双重检查（事务前检查一次，事务内锁定后再检查一次），**资金安全**。但产品订单状态机不完整（直接扣减后订单 status 不明确，可能缺少后续确认/取消流程）。 |
| **评级** | P3（代码一致性问题，非资金安全问题） |

### 漏审-002：提现申请有重复检测但无数据库唯一约束

| 项 | 内容 |
|----|------|
| **文件** | `FinanceActions.php` 第842行 `directFindRecentPendingWithdrawal()` |
| **问题** | 提现申请使用"查询最近相同金额+钱包地址的待处理订单"做重复检测，这是 check-then-act，无数据库唯一约束。但提现申请有用户行锁（`directLockUser`），同一用户的并发提现申请会串行执行，所以实际重复风险低。 |
| **数据库约束** | `secure-keys/withdrawal_indexes.sql` 提供了 `uk_order_number` 唯一索引，但需手动执行。 |
| **评级** | P3（有行锁保护，风险低） |

### 漏审-003：管理后台存在财务记录物理删除路径

| 项 | 内容 |
|----|------|
| **文件** | `AdminApi.php` 第2960行 `withdrawal_post case 'del'`；第3126行 `recharge_post case 'del'` |
| **问题** | 管理后台可以物理删除提现记录和充值记录。使用 `Withdrawal::where('id', $id)->delete()` 和 `Recharge::where('id', $id)->delete()`。 |
| **风险** | 财务记录被物理删除后，资金流水（cz_user_fund_log）仍然存在，但订单记录缺失，导致对账困难。如果删除的是待处理订单，可能导致用户资金被冻结但订单消失。 |
| **缓解** | 删除操作有 AdminAuth 中间件保护，仅管理员可操作。但财务记录原则上不应物理删除。 |
| **评级** | P2（财务记录物理删除，违反资金系统审计原则） |

---

## 五、被错误判定为 PASS 的项目

### 误判-001：上一轮"订单状态机 — PASS" — 实际存在 CANCELLED → COMPLETED 非法转换

上一轮报告声称"未发现非法状态跳转"，但实际上充值订单存在 **CANCELLED(2) → COMPLETED(3)** 的非法转换（见错误-001）。这是上一轮最严重的误判。

### 误判-002：上一轮"数据库唯一约束 — 建议添加" — 实际源码无法证明生产已存在

上一轮报告使用了大量"应唯一""推断""建议唯一""需确认"等表述。本轮明确：

| 表.字段 | 源码证据 | 生产状态 |
|---------|----------|----------|
| cz_user.mobile | 模型无 unique 定义，无 migration | **源码无法证明** |
| cz_recharge.order_number | 无 unique 索引 SQL 脚本 | **源码无法证明** |
| cz_order.order_number | 无 unique 索引 SQL 脚本 | **源码无法证明** |
| cz_withdrawal.order_number | `secure-keys/withdrawal_indexes.sql` 提供，需手动执行 | **需手动执行，无法证明生产已存在** |
| cz_transaction_order.order_number | 无 unique 索引 SQL 脚本 | **源码无法证明** |
| cz_user_fund_log.request_no | `secure-keys/fund_log_idempotent_unique_index.sql` 提供，需手动执行 | **需手动执行，无法证明生产已存在** |

**结论：源码无法证明生产数据库已经存在这些约束。上线前必须实际验证。**

---

## 六、10 个关键问题回答

### Q1：已取消充值订单是否还能入账？
**是。** 两个支付回调都只检查 `status===3`，不检查 `status===2`。已取消订单收到支付成功回调会直接入账。对于 bepusdt，用户获得钱包地址后可随时转账，这是主动可利用的。**P1。**

### Q2：没有资金唯一索引时，是否真的可能重复入账？
**正常流程下不会**（recharge 行锁 + status=3 检查），但极端场景（代码异常/手动运维/Worker重试/未来变更）下失去最后一道数据库级兜底。代码明确依赖该索引名称做 1062 冲突匹配。**P1 维持。**

### Q3：是否存在任何绕过 UserFundLedgerService 的余额修改？
**不存在。** 全局搜索确认所有余额变更均通过统一入口。**PASS。**

### Q4：提现是否存在重复审核/重复退款/重复出款？
**不存在。** 提现审核有事务 + 行锁 + 状态检查（只处理 status=0），重复审核被状态检查拦截。提现申请有重复检测 + 用户行锁。**PASS。**

### Q5：重复下单是否可能造成异常冻结或重复退款？
**产品订单重复创建**：有用户行锁 + 余额不足检查，第二次会因余额不足失败。但如果用户余额足够，两个订单都能创建并冻结，这是正常业务行为（用户可以下多个订单），非漏洞。**PASS。**

### Q6：C2C 放币是否严格资金守恒？
**是。** `releaseBySeller()` 验证 `usdt_amount + transaction_fees == pay_amount`（容差 0.005），卖家 frozen 扣减 = 买家 balance 增加 + 平台手续费。事务 + 四行锁（order/seller/product/buyer）+ 状态检查（只处理 status=1）。**PASS。**

### Q7：所有 order_number 是否真的有数据库唯一约束？
**源码无法证明。** 仅 cz_withdrawal 和 cz_user_fund_log 提供了手动执行的 SQL 脚本，其余表（cz_recharge, cz_order, cz_transaction_order）无唯一索引定义。**上线前必须实际验证生产数据库。**

### Q8：CORS / Cookie / HTTPS 是否达到生产安全标准？
- **CORS**：白名单模式（https://hcz.app, https://www.hcz.app），非任意 Origin，**PASS**。
- **CSRF**：自定义 CsrfCheck 中间件 + X-CSRF-Token header，**PASS**。
- **Cookie SameSite/Secure**：源码中未发现显式配置，**需确认生产环境 php.ini/session 配置**。
- **HTTPS**：需确认生产环境 Nginx 配置强制 HTTPS，**源码无法证明**。

### Q9：普通用户能否通过任何 API 实现越权或修改资金？
**不能。** 所有用户端数据查询均绑定当前登录用户 uid。管理后台 API 受 AdminAuth 中间件保护。未发现普通用户可调用管理员 API 的路径。未发现 IDOR 漏洞。**PASS。**

### Q10：当前后端到底能不能承载真实资金上线？
**不能直接上线（BLOCKED）。** 原因：
1. **P1**：已取消充值订单可被支付回调入账，状态机被破坏，对于 bepusdt 是主动可利用的。
2. **P1**：资金唯一索引需手动创建，源码无法证明生产已存在。
3. **P2**：管理后台可物理删除财务记录（充值/提现）。
4. **生产数据库关键约束无法从源码确认。**

修复 P1 问题并验证生产数据库约束后，可以重新评估为 CONDITIONAL PASS。

---

## 七、充值资金链路（本轮验证）

```
用户选择金额 → POST /api/finance/recharge
  → 限流（用户10次/分, IP30次/分）✓
  → 金额验证（>0, >=最小充值金额）✓
  → 创建 Recharge 记录 (status=0)
  → 调用第三方支付下单 (bepusdt/epay)
  → 更新 Recharge 网关信息
  → 返回支付链接/钱包地址

用户支付 → 第三方回调
  → bepusdt: POST /api/callback/bepusdt
  → epay: GET/POST /epay_notify_url
    → 签名验证 ✓
    → 事务 + recharge 行锁 ✓
    → if status===3: return (幂等) ✓
    → if status===2: **不检查！直接入账！** ← P1 漏洞
    → 金额验证（从数据库读取 amount，验证回调金额 >= order amount）✓
    → 用户行锁 ✓
    → recharge.status = 3
    → UserFundLedgerService::changeLockedUserWallet(+amount) ✓
    → 流水记录 ✓
    → commit ✓
```

**关键漏洞点：** 回调中缺少 `if status===2: reject`。已取消订单可被入账。

---

## 八、提现资金链路（本轮验证）

```
用户申请提现 → POST /api/finance/withdrawal-submit
  → 限流（3次/60秒）✓
  → 金额验证（>0, >=最小, <=余额）✓
  → TRC20地址验证 ✓
  → 2FA/密码验证 ✓
  → 事务 + 用户行锁 ✓
  → 重复提现检测（最近相同金额+地址的待处理订单）✓
  → 创建 Withdrawal (status=0)
  → transferLockedUserWallet(balance→frozen, amount) ✓
  → 幂等 request_no ✓
  → commit ✓

管理员审核通过 → POST /admin/withdrawal/audit (status=1)
  → 权限检查 ✓
  → CSRF ✓
  → 敏感操作验证 ✓
  → 事务 + withdrawal 行锁 ✓
  → 状态检查（只处理 status=0）✓
  → 用户行锁 ✓
  → changeLockedUserWallet(frozen, -amount) ✓
  → changeLockedUserWallet(frozen, -fee) 手续费 ✓
  → withdrawal.status = 1 ✓
  → 幂等 request_no ✓
  → commit ✓

管理员审核拒绝 → POST /admin/withdrawal/audit (status=2)
  → 事务 + 行锁 + 状态检查 ✓
  → transferLockedUserWallet(frozen→balance, amount) ✓
  → withdrawal.status = 2 ✓
  → 幂等 request_no ✓
  → commit ✓
```

**提现链路安全，PASS。** 重复审核被状态检查（只处理 status=0）拦截。

---

## 九、C2C 交易资金链路（本轮验证）

```
卖家创建挂单 → 冻结卖家 frozen_amount
买家创建交易订单 → 买家付款（status=0→1）
  → 事务 + 行锁 ✓
  → 卖家 frozen 已包含该订单金额

卖家放币 → TransactionOrderService::releaseBySeller()
  → 事务 ✓
  → TransactionOrder 行锁（卖家维度）✓
  → 状态检查（只处理 status=1）✓
  → seller 行锁 ✓
  → product 行锁 ✓
  → buyer 行锁 ✓
  → 金额验证：sell_account >= pay_amount ✓
  → 守恒验证：usdt_amount + fees == pay_amount ✓
  → order.status = 3 ✓
  → product.sell_account -= pay_amount ✓
  → seller.frozen -= pay_amount (Ledger, request_no=transaction_deduct:ORDER) ✓
  → buyer.balance += usdt_amount (Ledger, request_no=transaction_buyer_income:ORDER) ✓
  → 平台手续费记账 (recordPlatformIncome, request_no=transaction_fee:ORDER) ✓
  → commit ✓
```

**C2C 放币严格资金守恒，PASS。** 重复放币被状态检查（只处理 status=1）拦截。

---

## 十、并发矩阵（本轮验证）

| 业务 | 锁对象 | 锁类型 | 幂等 | 重复执行结果 |
|------|--------|--------|------|-------------|
| 同订单支付回调 | recharge 行 | SELECT FOR UPDATE | status=3检查+request_no | 第一个入账，其余返回 ok |
| **已取消订单回调** | recharge 行 | SELECT FOR UPDATE | **无 status=2 检查** | **入账！P1 漏洞** |
| 同用户余额扣款 | user 行 | SELECT FOR UPDATE | request_no | 第一个成功，第二个余额不足 |
| 同用户提现申请 | user 行 | SELECT FOR UPDATE | 重复检测+request_no | 第一个成功，第二个重复检测拦截 |
| 同提现订单审核 | withdrawal 行 | SELECT FOR UPDATE | status=0检查 | 第一个成功，第二个状态不匹配 |
| 同产品订单确认 | order 行 | SELECT FOR UPDATE | confirm_status+request_no | 第一个成功，第二个状态不匹配 |
| 同交易订单放币 | order+seller+product+buyer | SELECT FOR UPDATE | status=1检查+request_no | 第一个成功，第二个状态不匹配 |
| 管理员余额调整 | user 行 | SELECT FOR UPDATE | request_no | 幂等，重复返回已存在记录 |

---

## 十一、攻击者视角综合推演

假设攻击者：普通注册用户，拥有自己的账号和 Session，可以抓包、修改请求、重复发送、并发发送、重放支付回调（但无合法签名）。

| 攻击目标 | 能否实现 | 原因 |
|----------|----------|------|
| 增加自己的余额 | **能（P1）** | 创建订单→取消→向钱包地址转账→回调入账。平台收到钱但订单已取消。 |
| 重复增加余额 | 不能（正常流程） | 同一订单回调有 status=3 检查+行锁。但如果利用已取消订单漏洞，每个已取消订单只能入账一次。 |
| 修改他人余额 | 不能 | 所有操作绑定当前用户 uid，无 IDOR。 |
| 重复退款 | 不能 | 订单取消退款有状态检查+行锁+幂等。 |
| 重复领取奖励 | 未发现独立奖励系统 | 代理返佣需确认（未深入）。 |
| 创建异常订单 | 不能 | 金额验证+余额不足检查+行锁。 |
| 绕过套餐价格 | 不能 | 产品价格从数据库读取，不依赖前端传入。 |
| 让 cancelled 订单重新入账 | **能（P1）** | 见上。 |
| 让一个支付订单产生两笔流水 | 不能（正常流程） | 行锁+status检查+request_no幂等。 |
| 让 frozen_amount 与真实资金不一致 | 不能 | 所有 frozen 变更通过 Ledger transfer，原子性。 |
| 访问其他用户订单 | 不能 | 所有查询绑定 uid。 |
| 调用管理员 API | 不能 | AdminAuth 中间件+Session。 |
| 上传 WebShell | 不能 | UploadService MIME白名单+getimagesize+随机文件名。 |
| 重放支付回调（无签名） | 不能 | 签名验证失败返回 fail。 |

**攻击者最关键的可利用路径：已取消订单入账（P1）。**

---

## 十二、修复优先级（本轮）

### 上线前必须修复（P1）

| 优先级 | 问题 | 修复方案 |
|--------|------|----------|
| P1-001 | 已取消订单可被回调入账 | 两个支付回调入账逻辑前增加 `if status===2: reject` |
| P1-002 | 资金唯一索引需手动创建 | 执行 `php think fund-log:index`，并验证生产数据库 |

### 上线前强烈建议（P2）

| 优先级 | 问题 | 修复方案 |
|--------|------|----------|
| P2-001 | 管理后台物理删除财务记录 | 改为软删除（增加 deleted_at 字段），或禁止删除已完成/有资金流水的记录 |
| P2-002 | 第二套流水无唯一索引 | 添加 (scene, order_number) 唯一索引，或废弃 UserBalanceLog |
| P2-003 | 充值创建无事务 | 充值订单创建与第三方下单改为事务，或增加对账机制 |
| P2-004 | 金额 float 类型 | 改为 decimal(12,2) |

### 生产数据库必须验证

1. `cz_recharge.order_number` 是否有唯一索引
2. `cz_order.order_number` 是否有唯一索引
3. `cz_transaction_order.order_number` 是否有唯一索引
4. `cz_user_fund_log` 是否有 `uk_uid_wallet_direction_reqno`
5. `cz_withdrawal.order_number` 是否有唯一索引
6. Session Cookie 是否配置 SameSite=Lax + Secure
7. 生产环境是否强制 HTTPS

---

## 十三、最终上线结论

### BLOCKED（阻断上线）

**存在 P1 级可利用的业务逻辑漏洞，必须修复后才能上线。**

**阻断原因：**
1. **P1-001**：已取消充值订单可被支付回调入账。对于 bepusdt（USDT 链上支付），用户获得钱包地址后可随时转账，即使订单已取消，回调仍会入账。这是状态机非法转换（CANCELLED → COMPLETED），主动可利用。上一轮将此问题评为 P2 是严重低估。
2. **P1-002**：资金幂等唯一索引需手动创建，源码无法证明生产数据库已存在。
3. **生产数据库关键约束（order_number 唯一索引）无法从源码确认。**

**解除阻断条件：**
1. 修复 P1-001：两个支付回调增加 `if status===2: reject`
2. 执行并验证 P1-002：`uk_uid_wallet_direction_reqno` 唯一索引
3. 实际验证生产数据库所有 order_number 唯一约束
4. （建议）修复 P2-001：禁止物理删除财务记录

完成以上 1-3 项后，可重新评估为 CONDITIONAL PASS。

---

*反向复核审计完成时间：2026-08-28*
*审计方法：推翻上一轮结论，从源码重新验证每一个关键判断*
*核心发现：上一轮严重低估了已取消订单可被回调入账的风险（P2→P1），这是本轮最重要的纠正*
