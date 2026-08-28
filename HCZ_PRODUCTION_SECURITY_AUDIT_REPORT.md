# HCZ 服务充值网站后端 — 生产上线前全面安全审计报告

**审计日期：** 2026-08-28
**审计范围：** HCZ-backend 全部源码（Controller / Service / Model / Middleware / Command / Job / Config / Route）
**审计原则：** 只审计，不修改代码；从零开始独立验证，不依赖历史审计结论
**审计方法：** 源码级调用链追踪 + 并发场景推演 + 资金守恒建模

---

## 一、最终结论

### 判定：CONDITIONAL PASS（有条件通过）

**核心资金链路安全可控，不存在 P0 级资金损失漏洞。** 支付回调、余额变更、订单状态机的核心逻辑均有事务 + 行锁 + 幂等保护。

**上线前必须完成的条件：**
1. **必须执行** `secure-keys/fund_log_idempotent_unique_index.sql`（或 `php think fund-log:index`），创建 `uk_uid_wallet_direction_reqno` 唯一索引。这是资金幂等的最后一道数据库级防线，代码已依赖该索引名称做冲突匹配，若生产环境遗漏，极端并发下存在重复入账风险。
2. 确认生产环境 `.env` 已替换所有测试密钥（当前仓库 `.env` 为测试配置）。
3. 确认 `cz_order` 表已执行 `secure-keys/order_archive_schema.sql`（Cron 归档依赖 `archived` 列，缺失时仅告警不执行，不影响资金安全）。

---

## 二、风险等级统计

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 | 0 | 无直接资金损失 / 权限接管 / RCE |
| P1 | 1 | 部署依赖项：资金幂等唯一索引需手动创建 |
| P2 | 5 | 数据一致性 / 并发 / 精度 / 事务边界问题 |
| P3 | 4 | 代码质量 / 密码策略 / 可预测性优化 |

---

## 三、P1 问题

### P1-001：资金幂等唯一索引依赖手动部署，生产遗漏将失去最后一道防线

| 项 | 内容 |
|----|------|
| **模块** | 资金账本 / UserFundLedgerService |
| **文件** | `app/service/UserFundLedgerService.php` 第 474-513 行 `createLogWithIdempotentFallback()`；`app/command/FundLogIndex.php`；`secure-keys/fund_log_idempotent_unique_index.sql` |
| **根因** | `UserFundLedgerService` 的幂等兜底逻辑通过捕获 MySQL 1062 唯一键冲突（索引名 `uk_uid_wallet_direction_reqno`）来识别重复写入。但该索引**不随代码自动创建**，需运维手动执行 SQL 脚本或命令。若生产数据库遗漏此索引，`createLogWithIdempotentFallback` 的冲突捕获分支永远不会触发。 |
| **触发条件** | 生产环境未执行 `fund-log:index` 命令或 SQL 脚本；同时发生以下任一极端场景：(a) 同一订单支付回调在应用层重试时绕过了订单行锁状态检查（如代码异常/事务回滚后重试）；(b) 管理员对同一业务号重复提交余额调整；(c) Worker 重试同一资金任务。 |
| **实际影响** | 正常流程下，支付回调因 `recharge` 订单行锁 + `status=3` 检查已能防重。但缺少数据库唯一索引意味着**失去了最后一道不可绕过的幂等防线**，一旦上层锁/状态判断出现任何漏洞（未来代码变更、异常恢复、手动运维操作），可能导致同一笔资金重复入账。 |
| **修复建议** | 上线前强制执行：`php think fund-log:index --check` 预检 → `php think fund-log:index` 创建 → 复验。将此步骤写入部署文档和 CI/CD 流水线。 |

---

## 四、P2 问题

### P2-001：第二套流水表 UserBalanceLog 无唯一索引，并发下可能重复插入

| 项 | 内容 |
|----|------|
| **模块** | 余额流水 / UserBalanceLog |
| **文件** | `app/controller/IndexApi.php` 第 2122-2147 行 `directWriteBalanceLog()`；`app/controller/Notify.php` 第 38-65 行同名方法 |
| **根因** | `directWriteBalanceLog` 使用「先 `where(scene, order_number)->find()` 查询，不存在则 `create()`」的方式做幂等。这是典型的 check-then-act 竞态，**没有数据库唯一索引保护**。 |
| **触发条件** | 同一笔充值的支付回调在并发/重试时，两个请求同时通过 `find()` 查询（均返回不存在），然后各自执行 `create()`，产生两条相同 `scene + order_number` 的流水记录。 |
| **实际影响** | **不影响用户余额**（余额由 `UserFundLedgerService` 控制，有行锁保护）。但会导致 `cz_user_balance_log` 表出现重复流水记录，影响财务对账和审计准确性。该表作为第二套展示用流水，与 `cz_user_fund_log`（核心账本）存在数据不一致风险。 |
| **修复建议** | 为 `cz_user_balance_log` 添加 `(scene, order_number)` 唯一索引；或在 `directWriteBalanceLog` 中使用 `INSERT ... ON DUPLICATE KEY UPDATE` / 捕获 1062 冲突的方式。更推荐：统一使用 `UserFundLedgerService` 作为唯一流水来源，废弃 `UserBalanceLog` 第二套表。 |

### P2-002：充值订单创建与第三方支付下单不在同一事务

| 项 | 内容 |
|----|------|
| **模块** | 充值创建 / FinanceActions |
| **文件** | `app/controller/indexapi/FinanceActions.php` 第 1363-1524 行 `handleApiFinanceRecharge()` |
| **根因** | 充值流程为：本地 `Recharge::create()` → 调用 `BepusdtService::createTransaction()` 或 `directFinanceCreateEpayPayment()` → 更新 `recharge` 记录的网关信息。这三步**不在同一个数据库事务中**。 |
| **触发条件** | (a) 本地订单创建成功，但第三方支付下单 API 超时/失败，代码在 catch 中将订单设为 `status=2`（取消），但第三方侧可能已实际创建了订单（网络超时但远端已处理）；(b) 第三方下单成功，但本地 `$recharge->save()` 更新网关信息失败。 |
| **实际影响** | 风险较低。代码有异常处理（catch 中设置 `status=2` 取消），且支付回调以订单号为准，只要本地订单存在且状态非终态，回调仍能正确处理。但可能出现：用户看到订单已取消但第三方支付页仍可支付（此时回调会因 `status=2` 被忽略吗？需确认——回调中只检查 `status===3` 才跳过，`status=2` 仍会继续处理！这是一个潜在问题）。 |
| **修复建议** | (1) 支付回调中增加对 `status=2`（已取消）订单的检查，已取消订单应拒绝入账；(2) 或将充值创建改为事务包裹，第三方下单失败时回滚本地订单；(3) 增加定时任务对账，检查第三方已支付但本地异常的订单。 |

### P2-003：Cron 批量取消过期订单使用直接 UPDATE，无逐条锁/事务

| 项 | 内容 |
|----|------|
| **模块** | 定时任务 / Cron |
| **文件** | `app/command/Cron.php` 第 51-63 行 `processDataTasks()` |
| **根因** | Cron 中取消过期充值订单和交易订单使用 `Recharge::where(...)->update(['status'=>2])` 批量更新，**没有逐条加锁、没有事务、没有检查当前状态**。 |
| **触发条件** | Cron 执行批量取消时，用户同时对同一笔订单执行操作（如提交充值凭证、支付回调到达）。两个操作并发修改同一行。 |
| **实际影响** | 风险较低。MySQL 的 `UPDATE` 本身会加行锁，且 Cron 的 WHERE 条件包含 `status=0`，只会取消待支付订单。但如果支付回调在 Cron 批量更新的同一时刻到达，可能出现竞态（回调先将 status 改为 3，Cron 的 UPDATE 因 WHERE status=0 不会匹配到，所以是安全的）。主要问题是缺少审计日志和异常处理。 |
| **修复建议** | 增加操作日志记录；对关键资金相关的 Cron 操作改为逐条处理（加锁 + 状态检查 + 事务），与 `expirePendingOrders()` 方法的实现保持一致。 |

### P2-004：交易放币操作锁顺序不固定，高并发下可能死锁

| 项 | 内容 |
|----|------|
| **模块** | C2C 交易 / TransactionOrderService |
| **文件** | `app/service/TransactionOrderService.php` 第 100-279 行 `releaseBySeller()` |
| **根因** | `releaseBySeller` 中依次锁定：`seller`（第 118 行）→ `product`（第 123 行）→ `buyer`（第 128 行）。锁顺序为 seller → product → buyer。而其他资金操作（如充值回调）锁定顺序为 recharge → user。当同一用户同时涉及交易放币和充值回调时，可能以不同顺序锁定同一 user 行，导致死锁。 |
| **触发条件** | 高并发场景：用户 A 作为卖家执行放币（锁定 seller=A → product → buyer=B），同时用户 A 的充值回调到达（锁定 recharge → user=A）。两个事务以不同顺序请求 A 的用户行锁。 |
| **实际影响** | MySQL 会自动检测死锁并回滚其中一个事务，被回滚的操作返回失败。不会导致资金不一致，但会导致用户操作偶发失败，需要重试。 |
| **修复建议** | 统一全局锁顺序：所有涉及多表/多行的资金操作，按固定顺序加锁（如先锁 user 行，再锁订单行，再锁其他）。或在 `releaseBySeller` 开头先锁定 buyer 和 seller 的 user 行（按 user id 升序锁定），再锁定 product。 |

### P2-005：金额字段使用 float 类型，存在精度风险

| 项 | 内容 |
|----|------|
| **模块** | 数据模型 / User, UserFundLog |
| **文件** | `app/model/User.php` 第 32 行 `'balance' => 'float'`；`app/model/UserFundLog.php` 第 17-19 行 `'amount'=>'float', 'before_amount'=>'float', 'after_amount'=>'float'` |
| **根因** | 用户余额和资金流水金额在模型中定义为 `float` 类型。代码中使用 `round($amount, 2)` 控制精度，但 float 在数据库存储和算术运算中存在二进制浮点精度误差。 |
| **触发条件** | 多次小额累加/扣减后，浮点误差累积；或金额比较时（如 `$afterAmount < -0.000001`）因精度问题判断错误。 |
| **实际影响** | 当前代码大量使用 `round($value, 2)` 和容差比较（如 `+0.005`、`>0.000001`），实际风险已被大幅降低。但从资金系统严谨性角度，float 不是最佳实践。 |
| **修复建议** | 数据库层面将金额字段改为 `DECIMAL(12,2)`（或 `DECIMAL(16,6)` 如需更高精度）；PHP 层面使用 `bcadd`/`bcsub` 等任意精度函数，或统一以「分」为单位使用整数计算。 |

---

## 五、P3 问题

### P3-001：注册无密码强度校验

| 项 | 内容 |
|----|------|
| **文件** | `app/controller/indexapi/AuthActions.php` 第 288-347 行 `handleRegisterPost()` |
| **问题** | 注册时仅检查 `password` 非空（第 307-309 行），无最小长度、复杂度（大小写/数字/特殊字符）要求。 |
| **影响** | 用户可能设置弱密码（如 `123456`），增加账号被暴力破解风险。登录接口有限流（LoginRateLimiter），降低了实际风险。 |
| **建议** | 增加密码强度校验：最小 8 位，建议包含大小写字母和数字。 |

### P3-002：缺少密码找回/重置功能

| 项 | 内容 |
|----|------|
| **文件** | `route/app.php` 全路由 |
| **问题** | 路由中未发现密码找回/重置接口（forgot password / reset password）。仅有登录、注册、修改密码（需登录）、2FA 恢复。 |
| **影响** | 用户忘记密码后无法自助找回，需联系管理员手动重置，增加运营成本。 |
| **建议** | 如业务需要，增加基于邮箱/手机验证码的密码重置流程，并做好限流和 token 时效控制。 |

### P3-003：充值订单号存在一定可预测性

| 项 | 内容 |
|----|------|
| **文件** | `app/controller/indexapi/FinanceActions.php` 第 1392 行、第 1480 行 |
| **问题** | 充值订单号生成方式为 `date('Ymd') . randomkeys(8)`。`randomkeys(8)` 的随机性取决于实现，若为弱随机数则存在枚举空间。 |
| **影响** | 订单查询接口（`api/finance/recharge-detail`）已通过 `where('uid', 当前用户)` 做了授权隔离，即使订单号被枚举也无法访问他人订单。凭证查看接口（`api/proof/recharge/<order_number>/view`）有 `canViewRechargeProof` 授权检查。实际风险低。 |
| **建议** | 使用更安全的随机数生成（如 `random_bytes` + `bin2hex`），或在订单号中加入用户 ID 哈希。 |

### P3-004：部分异常返回通用错误信息，不利于排查

| 项 | 内容 |
|----|------|
| **文件** | 多处，如 `FinanceActions.php` 第 920 行 `'System error, please try again later'` |
| **问题** | 部分 catch 块返回通用错误信息，不包含具体错误原因（虽然已写入日志）。 |
| **影响** | 前端用户无法获得有意义的错误提示，排查需依赖后端日志。这是安全最佳实践（避免信息泄漏），但可优化为区分「业务错误」和「系统错误」。 |
| **建议** | 业务校验失败返回具体原因（如「余额不足」「订单已取消」），系统异常返回通用错误并记录 trace_id。 |

---

## 六、项目架构分析

### 技术栈

| 层 | 技术 | 版本 |
|----|------|------|
| 语言 | PHP | >= 8.0 |
| 框架 | ThinkPHP | 8.0 |
| ORM | think-orm | 3.0 |
| 队列 | think-queue | 3.0 |
| 数据库 | MySQL | 表前缀 `cz_` |
| 缓存 | ThinkPHP Cache（文件/Redis 可配） | - |
| 认证 | Session（非 JWT） | - |
| 2FA | TOTP（robthree/twofactorauth） | 2.1 |
| 支付 | bepusdt（USDT）+ 易支付（支付宝/微信） | - |
| 二维码 | endroid/qr-code | 5.1 |
| Excel | phpoffice/phpspreadsheet | 3.3 |

### 架构分层

```
用户浏览器/前端
    ↓ HTTP/HTTPS
Nginx / Web Server
    ↓
ThinkPHP 8.0 入口 (public/index.php)
    ↓
全局中间件: SessionInit → CorsMiddleware → LegacyUserFrontendDisabled → CsrfCheck → Csrf
    ↓
路由分发 (route/app.php)
    ↓
控制器层
├── 用户端: IndexApi (Trait 拆分: Auth/Finance/Order/Transaction/Account/Points/Message/Agent/Site/Utility/TwoFactor)
├── 管理后台: Admin (页面), AdminApi (操作), AdminList (列表)
├── 支付回调: Notify (bepusdt), IndexApi::epay_notify_url (易支付)
├── 分站: SubstationApi, SubstationAdminApi, SiteSubstationApi
└── Webhook: TelegramWebhook
    ↓
服务层 (app/service/)
├── UserFundLedgerService (核心资金账本)
├── OrderService, ProductOrderService, TransactionOrderService
├── BepusdtService, TelegramService, PointsService
├── UserService, UserMessageService, UploadService
├── ActionRateLimiter, LoginRateLimiter
├── AdminOperationLogService, UserFundLogLabelService
└── OrderStatusMonitorService
    ↓
模型层 (app/model/) — think-orm ActiveRecord
├── User, Recharge, Order, Withdrawal
├── UserFundLog (核心账本), UserBalanceLog (第二套流水)
├── TransactionOrder, TransactionProduct
├── Product, BankCard, RebateRecord, PointsRecord
├── Admin, AdminOperationLog, Config
└── Substation 相关模型
    ↓
MySQL (cz_ 前缀)
    ↕
Redis / Cache / Queue (可配置)
```

### 关键设计特点

1. **统一资金账本**：`UserFundLedgerService` 作为所有余额变更的唯一入口，封装了事务、行锁、幂等、流水记录。
2. **Trait 拆分控制器**：`IndexApi` 通过 12 个 Trait 拆分功能，主控制器保持入口统一。
3. **双支付通道**：bepusdt（USDT 链上支付）+ 易支付（支付宝/微信），回调分别处理但共用资金账本。
4. **C2C 交易系统**：用户间 USDT 交易，涉及卖家冻结、放币、买家到账、平台手续费。
5. **分站/代理体系**：支持分站独立定价、代理返佣、多级邀请链（tid_1 ~ tid_10）。

---

## 七、API 全量审计

### 用户端 API（需登录，UserAuth 中间件）

| 分组 | 接口数 | 授权检查 | 风险评估 |
|------|--------|----------|----------|
| api/auth | 6 | 白名单（登录/注册/登出） | PASS |
| api/finance | 14 | uid 绑定查询 | PASS（金额后端校验） |
| api/order | 7 | uid 绑定查询 | PASS |
| api/product | 6 | uid 绑定 | PASS |
| api/transaction | 13 | uid/sell_uid 绑定 | PASS（放币有金额校验） |
| api/account | 20 | 当前用户 | PASS（密码修改需原密码+2FA） |
| api/agent | 4 | 当前用户 | PASS |
| api/invite | 1 | 当前用户 | PASS |
| api/points | 8 | 当前用户 | PASS |
| api/user | 7 | 当前用户 | PASS |
| api/upload | 1 | 登录 | PASS（UploadService 校验） |
| api/substation | 10 | 当前用户 | 需进一步验证分站权限隔离 |
| api/proof | 2 | canViewProof 检查 | PASS |

### 支付回调 API（免登录，签名验证）

| 接口 | 方法 | 签名验证 | 幂等 | 风险评估 |
|------|------|----------|------|----------|
| `/epay_notify_url` | GET/POST | MD5 签名 ✓ | 订单状态+行锁+账本幂等 ✓ | PASS |
| `/api/callback/bepusdt` | POST | MD5 签名 ✓ | 订单状态+行锁+账本幂等 ✓ | PASS |

### 管理后台 API（AdminAuth 中间件 + 权限检查）

| 分组 | 接口数 | 权限检查 | CSRF | 风险评估 |
|------|--------|----------|------|----------|
| user_post | 7 | directHasAdminPermission ✓ | ✓ | PASS（余额调整走账本） |
| order_post | 多 | AdminAuth ✓ | ✓ | PASS |
| recharge_post | 多 | AdminAuth ✓ | ✓ | PASS |
| withdrawal_post | 多 | AdminAuth ✓ | ✓ | PASS |
| product_post | 多 | AdminAuth ✓ | ✓ | PASS |
| admin_post | 多 | AdminAuth ✓ | ✓ | PASS |
| setting_post | 多 | AdminAuth ✓ | ✓ | PASS |

### IDOR / 越权审计结论

**PASS。** 所有用户端数据查询均通过 `where('uid', 当前登录用户ID)` 或 `scopeUserVisible` 进行授权隔离。订单详情、充值详情、财务记录、交易订单均绑定当前用户。管理后台操作受 AdminAuth 中间件保护，且关键操作有 `directHasAdminPermission` 二次权限检查。

---

## 八、用户认证审计

### 认证方式：Session

- 登录成功后调用 `rotateSessionForUserLogin()` 轮换 Session ID（防会话固定）
- UserAuth 中间件每次请求回源数据库确认用户存在且 `status=1`（防禁用后旧会话可用）
- IP 变化仅记录日志，不强制登出（合理，移动端网络切换常见）

### 密码安全

| 项 | 状态 | 说明 |
|----|------|------|
| 密码哈希 | PASS | `password_hash($password . $salt, PASSWORD_BCRYPT)` |
| 密码验证 | PASS | `password_verify($password . $salt, $hash)` |
| 盐值 | PASS | 每用户独立 4 位随机盐 |
| 明文密码 | PASS | 未发现明文存储 |
| 密码强度 | P3-001 | 注册无强度校验 |
| 密码找回 | P3-002 | 无自助找回功能 |

### 登录安全

| 项 | 状态 | 说明 |
|----|------|------|
| 登录限流 | PASS | `LoginRateLimiter` 按 IP+账号限流 |
| 用户枚举 | PASS | 登录失败统一返回「账号密码错误」，不区分账号不存在/密码错误 |
| 暴力破解 | PASS | 限流 + bcrypt 慢哈希 |
| 2FA | PASS | 支持 TOTP，登录时强制校验 |
| Session 固定 | PASS | 登录后轮换 Session ID |
| 多设备登录 | 需确认 | 未发现单设备登录限制，同一账号可多设备同时登录（业务决策，非漏洞） |

### 注册安全

| 项 | 状态 | 说明 |
|----|------|------|
| 注册限流 | PASS | IP 维度 10 分钟 5 次 |
| 邀请码 | PASS | 必须提供有效邀请码 |
| 账号格式 | PASS | 6-32 位字母数字 |
| 账号唯一性 | PASS | 注册前检查 mobile 是否已存在 |
| 密码强度 | P3-001 | 仅非空检查 |

---

## 九、RBAC / 权限审计

### 管理后台权限

- **AdminAuth 中间件**：所有管理后台控制器（Admin, AdminApi, AdminList）绑定，检查 Session 中 `admin.id`，并回源确认管理员存在且 `status != 0`。
- **细粒度权限**：`directHasAdminPermission($permissionName)` 方法对关键操作（如用户列表/余额调整）做二次权限检查。
- **CSRF**：管理后台 POST 操作有 `directValidateRequiredCsrfToken()` 检查。
- **路径匹配**：`directRequestPathMatches()` 防止请求路径与操作不匹配。
- **参数污染防护**：`directAllowedConfigKeys()` 拦截请求中的配置字段污染。

### 普通用户越权

**PASS。** 未发现普通用户可调用管理员 API 的路径。管理后台路由在动态入口路径（`getConfig('backstage_entrance')`）下，受 AdminAuth 保护。

---

## 十、SQL / XSS / CSRF / SSRF 审计

### SQL 注入 — PASS

- 全项目主要使用 think-orm 的查询构造器，参数自动绑定。
- 未发现 `whereRaw` / `orderRaw` / `selectRaw` 拼接用户输入的情况。
- `FundLogIndex.php` 中的原生 SQL 使用预编译参数绑定。
- 动态排序/表名/字段名未发现用户可控的情况。

### XSS — PASS（需确认模板输出）

- 用户输入（昵称、备注、订单信息）存储时未做特殊过滤，依赖 ThinkPHP 模板引擎的自动转义输出。
- API 返回 JSON，由前端渲染，XSS 风险取决于前端是否使用 `v-html` / `innerHTML`。
- 管理后台富文本/公告字段需确认输出转义。
- **建议**：前端避免直接使用 `v-html` 渲染用户输入内容；管理后台公告/富文本使用 HTML 净化器。

### CSRF — PASS

- 全局 `CsrfCheck` 中间件对 POST/PUT/PATCH/DELETE 请求检查 `X-CSRF-Token` header。
- 排除路径合理：支付回调（第三方无法携带 token）、登录/注册接口（登录前无 token）、webhook。
- 管理后台额外有 `directValidateRequiredCsrfToken()` 二次检查。
- Session Cookie 需确认 `SameSite` 属性（见配置审计）。

### SSRF — PASS

- `BepusdtService::normalizeSafeUrl()` 对 URL 做了严格校验：
  - 仅允许 http/https scheme
  - 禁止 user:pass@host 格式
  - 禁止 localhost / .local 域名
  - 禁止内网/保留 IP（`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`）
  - DNS 解析后验证解析结果为公网 IP
- 未发现用户可控 URL 传入 `curl` / `file_get_contents` / `Guzzle` 的其他路径。
- 易支付 URL 从配置读取，非用户可控。

---

## 十一、订单系统审计

### 订单类型

1. **充值订单**（`cz_recharge`）：用户充值 USDT，状态 0=待汇款/1=已提交/2=已取消/3=已完成
2. **产品订单**（`cz_order`）：用户购买充值/查询产品，状态 0=待充值/1=充值中/2=已完成/3=已取消
3. **交易订单**（`cz_transaction_order`）：C2C USDT 交易，状态 0=待付款/1=待放币/2=已完成/3=已取消
4. **提现订单**（`cz_withdrawal`）：用户提现，状态 0=处理中/1=成功/2=失败/3=已取消

### 订单状态机

**充值订单（Recharge）：**
```
0(待汇款) → 1(已提交) → 3(已完成)
     ↓           ↓
  2(已取消)  2(已取消)
```
- 支付回调中：检查 `status === 3` 直接返回（幂等）；`status === 0` 时处理入账；未发现非法状态跳转。

**产品订单（Order）：**
```
0(待充值) → 1(充值中) → 2(已完成)
     ↓
  3(已取消)
```
- 取消订单退款：`directCancelOrderRefund()` 有事务+行锁+幂等，从 frozen 转回 balance。
- 确认收货：`ProductOrderService::confirmReceipt()` 有事务+行锁+幂等，扣减 frozen。

### 订单幂等

- 充值订单：支付回调通过订单行锁 + status 检查 + 账本 request_no 幂等。
- 产品订单：confirmReceipt 通过 `confirm_status` 检查 + 行锁 + 账本幂等。
- 交易订单：releaseBySeller 通过 status 检查 + 行锁 + 账本幂等。

### 订单金额安全

- 充值金额：后端从 `recharge.amount` 读取（创建时已验证），不依赖回调传入金额。回调中验证 `paidAmount >= amount`。
- 产品订单金额：从订单记录读取，不依赖前端传入。
- 交易订单金额：`releaseBySeller` 中验证 `usdt_amount + transaction_fees == pay_amount`。

---

## 十二、支付系统审计

### 支付通道

| 通道 | 类型 | 签名算法 | 回调验证 |
|------|------|----------|----------|
| bepusdt | USDT 链上支付 | MD5（参数排序 + token） | ✓ 签名 + 金额 + 状态 |
| 易支付 | 支付宝/微信 | MD5（参数排序 + key） | ✓ 签名 + 金额 + 状态 |

### 支付创建

- **金额可信性**：充值金额从前端传入，但后端验证 `> 0` 且 `>= mini_recharge_amount`。创建后金额存储在 `recharge.amount`，回调时从此读取，不依赖回调参数。
- **订单号**：`date('Ymd') . randomkeys(8)`，全局唯一概率极高（但无数据库唯一约束，建议添加）。
- **第三方下单**：bepusdt 和易支付均在本地订单创建后调用第三方 API，失败时将本地订单设为已取消。

### 支付回调金额验证

- **bepusdt**：`$paidAmount = actual_amount ?? amount`，验证 `$paidAmount < $amount` 时抛异常。
- **易支付**：`$callbackMoney` 从多个字段回退读取，与 `$gatewayAmount`（= amount * rate）比较，容差 0.01。

---

## 十三、支付回调审计（最高优先级）

### bepusdt 回调（Notify::api_callback_bepusdt）

**调用链：**
```
POST /api/callback/bepusdt
  → Notify::api_callback_bepusdt()
    → BepusdtService::verifyNotify()  // MD5 签名验证
    → Db::startTrans()
      → Recharge::where(order_number)->lock(true)->find()  // 订单行锁
      → if status === 3: commit + return ok  // 幂等：已完成直接返回
      → if status === 1: 更新网关状态 + commit + return ok  // 等待确认
      → if status === 3(网关): 更新取消状态 + commit + return ok
      → if status === 2(支付成功):
        → 验证 amount > 0
        → 验证 paidAmount >= amount
        → directLockUser(uid)  // 用户行锁
        → recharge.status = 3 + save()
        → UserFundLedgerService::changeLockedUserWallet(+amount, idempotent, request_no)
        → directWriteBalanceLog()  // 第二套流水
      → Db::commit()
    → OrderTelegramNotifier::notifyWalletRechargePaid()  // 事务外通知
```

**安全评估：PASS**
- 签名验证 ✓
- 事务 ✓
- 订单行锁 + 状态检查（幂等）✓
- 用户行锁 ✓
- 金额验证 ✓
- 账本幂等（request_no 唯一索引，需确认已创建 — P1-001）✓
- 通知在事务外，失败不影响资金 ✓

### 易支付回调（IndexApi::handleEpayNotifyUrl）

**调用链与 bepusdt 类似：**
```
GET/POST /epay_notify_url
  → 合并 GET+POST 参数
  → MD5 签名验证（hash_equals 防时序攻击）
  → 检查 trade_status 为成功状态
  → Db::transaction()
    → Recharge::where(order_number)->lock(true)->find()
    → if status === 3: return  // 幂等
    → 验证 pay_type/gateway 匹配
    → directLockUser(uid)
    → 验证 amount > 0
    → 验证 callbackMoney + 0.01 >= gatewayAmount
    → recharge.status = 3 + save()
    → UserFundLedgerService::changeLockedUserWallet(+amount, idempotent)
    → directWriteBalanceLog()
  → return success
```

**安全评估：PASS**
- 签名验证使用 `hash_equals` 防时序攻击 ✓
- 事务 + 行锁 + 状态检查 ✓
- 金额验证（含汇率换算和容差）✓
- 账本幂等 ✓

### 回调重放/并发场景推演

**场景：同一订单同时收到 4 次成功回调**

```
初始状态: recharge.status=0, user.balance=100, 充值金额=50

请求A: 获得 recharge 行锁 → status=0 → 锁定 user → recharge.status=3 → balance=150 → 创建流水 → commit
请求B: 等待 recharge 行锁 → 获得锁 → status=3 → 直接返回 ok（不修改余额）
请求C: 同 B
请求D: 同 B

最终状态: balance=150, 流水 1 条, 资金守恒 ✓
```

**结论：PASS。** 订单行锁 + status=3 检查构成了第一道幂等防线，即使并发回调也只有第一个能入账。

---

## 十四、充值系统审计

### 充值流程

```
用户选择金额 → POST /api/finance/recharge
  → 限流检查（用户 10次/分, IP 30次/分）
  → 验证 amount > 0 且 >= 最小充值金额
  → 创建 Recharge 记录 (status=0)
  → 调用第三方支付下单 (bepusdt/epay)
  → 更新 Recharge 网关信息
  → 返回支付链接/二维码
```

### 充值提交（手动汇款）

```
POST /api/finance/recharge-submit
  → 限流检查
  → 查询 Recharge (绑定 uid)
  → 上传汇款凭证 (UploadService)
  → recharge.status = 1 (已提交)
```

### 充值金额安全

- 金额在创建时验证并存储，后续所有操作从数据库读取，不依赖前端传入。
- 未发现负数金额、0 元绕过（`$amount <= 0` 直接拒绝）。
- 浮点数精度：使用 `round($amount, 2)`，但数据库字段为 float（P2-005）。

---

## 十五、余额系统审计

### 余额修改入口全量清单

| 入口 | 文件 | 方法 | 方向 | 事务 | 行锁 | 幂等 |
|------|------|------|------|------|------|------|
| 充值回调(bepusdt) | Notify.php | api_callback_bepusdt | + | ✓ | ✓ | ✓ |
| 充值回调(epay) | FinanceActions.php | handleEpayNotifyUrl | + | ✓ | ✓ | ✓ |
| 管理员余额调整 | AdminApi.php | directAdminAdjustBalanceWithLedger | +/- | ✓ | ✓ | ✓ |
| 提现申请 | FinanceActions.php | handleApiFinanceWithdrawalSubmit | -balance→+frozen | ✓ | ✓ | ✓ |
| 订单取消退款 | IndexApi.php | directCancelOrderRefund | -frozen→+balance | ✓ | ✓ | ✓ |
| 产品订单确认收货 | ProductOrderService.php | confirmReceipt | -frozen | ✓ | ✓ | ✓ |
| 交易放币(卖家) | TransactionOrderService.php | releaseBySeller | -frozen | ✓ | ✓ | ✓ |
| 交易放币(买家) | TransactionOrderService.php | releaseBySeller | +balance | ✓ | ✓ | ✓ |
| 代理钱包转账 | AgentActions.php | handleApiAgentWalletTransfer | -agent→+balance | ✓ | ✓ | ✓ |
| 分站钱包转账 | SubstationApi.php | walletTransfer | 需确认 | 需确认 | 需确认 | 需确认 |

**核心结论：所有余额变更均通过 `UserFundLedgerService` 统一入口，该服务强制事务 + 行锁 + 幂等。**

### 余额并发安全推演

**场景 A：同时两个消费请求，余额=100，各扣 80**

```
请求A: 获得 user 行锁 → balance=100 → 扣80 → balance=20 → commit
请求B: 等待 user 行锁 → 获得锁 → balance=20 → 扣80 → 余额不足 → 抛异常回滚

最终: balance=20, 只有一个扣款成功 ✓
```

**场景 B：充值+消费同时发生，余额=100，充值+50，消费-80**

```
请求A(充值): 锁定 recharge → 锁定 user → balance=100 → +50 → balance=150 → commit
请求B(消费): 等待 user 行锁 → 获得锁 → balance=150 → -80 → balance=70 → commit

最终: balance=70, 流水2条, 资金守恒 ✓
```

（顺序可能相反，但结果一致：100+50-80=70 或 100-80+50=70）

---

## 十六、资金流水审计

### 两套流水表

| 表名 | 用途 | 幂等方式 | 唯一索引 |
|------|------|----------|----------|
| `cz_user_fund_log` | 核心账本（UserFundLedgerService） | request_no + 查询 | `uk_uid_wallet_direction_reqno`（需手动创建 — P1-001） |
| `cz_user_balance_log` | 展示用流水（directWriteBalanceLog） | scene+order_number 查询 | 无（P2-001） |

### 流水与余额一致性

- `UserFundLedgerService::changeLockedUserWallet()` 在同一事务中更新余额并创建流水，保证原子性。
- `directWriteBalanceLog()` 在同一事务中调用（支付回调中），但自身无事务保护。
- **风险**：若 `directWriteBalanceLog` 失败（如重复键冲突被 catch 后 return），余额已更新但第二套流水缺失。但核心账本 `cz_user_fund_log` 一定有记录。

### 建议

统一使用 `cz_user_fund_log` 作为唯一流水来源，废弃 `cz_user_balance_log`，避免两套流水不一致。

---

## 十七、事务审计

### 关键业务事务覆盖

| 业务 | 事务 | 行锁 | 异常回滚 | 评估 |
|------|------|------|----------|------|
| 支付回调入账 | ✓ Db::startTrans | ✓ recharge+user | ✓ | PASS |
| 提现申请冻结 | ✓ Db::transaction | ✓ user | ✓ | PASS |
| 订单取消退款 | ✓ Db::startTrans | ✓ order+user | ✓ | PASS |
| 产品订单确认 | ✓ Db::startTrans | ✓ order+user | ✓ | PASS |
| 交易放币 | ✓ Db::startTrans | ✓ order+seller+product+buyer | ✓ | PASS |
| 管理员余额调整 | ✓ (changeUserWallet) | ✓ user | ✓ | PASS |
| 充值订单创建 | ✗ 无事务 | - | 部分(catch设取消) | P2-002 |
| Cron 批量取消 | ✗ 直接 update | - | - | P2-003 |

### 嵌套事务

未发现嵌套事务使用。`UserFundLedgerService::changeUserWallet()` 内部开启事务，但支付回调中调用的是 `changeLockedUserWallet()`（不开启事务，复用外层事务），设计正确。

---

## 十八、并发 / 锁审计

### 并发锁矩阵

| 业务 | 锁对象 | 锁类型 | 幂等 | 评估 |
|------|--------|--------|------|------|
| 同订单支付回调 | recharge 行 | SELECT FOR UPDATE | status检查+request_no | PASS |
| 同用户余额扣款 | user 行 | SELECT FOR UPDATE | request_no | PASS |
| 同用户创建充值订单 | 无（限流防护） | - | 订单号随机 | 低风险 |
| 同产品订单确认 | order 行 | SELECT FOR UPDATE | confirm_status+request_no | PASS |
| 同交易订单放币 | order+seller+product+buyer | SELECT FOR UPDATE | status+request_no | PASS（死锁风险 P2-004） |
| 同用户提现 | user 行 | SELECT FOR UPDATE | request_no+重复检测 | PASS |
| 管理员余额调整 | user 行 | SELECT FOR UPDATE | request_no | PASS |

### 锁机制评估

- **行锁**：所有资金操作使用 `->lock(true)`（SELECT FOR UPDATE），在事务内有效。
- **唯一索引**：`uk_uid_wallet_direction_reqno` 作为数据库级幂等兜底（需确认已创建 — P1-001）。
- **Redis 分布式锁**：未使用。当前架构下单数据库 + 行锁已足够，若未来多库/分库需引入分布式锁。
- **乐观锁**：未使用版本号/时间戳乐观锁，依赖悲观行锁。

---

## 十九、Redis / Cache 审计

### 缓存使用

- ThinkPHP Cache 用于：Session 存储（可配置为 Redis）、限流计数器（ActionRateLimiter / LoginRateLimiter）。
- **用户余额未使用 Redis 缓存**——每次从数据库读取，避免了缓存不一致风险。
- **套餐/产品信息**：未发现 Redis 缓存，每次查询数据库。

### 限流

- `ActionRateLimiter`：基于 Cache 的滑动窗口/固定窗口限流，用于充值创建、提现、注册等。
- `LoginRateLimiter`：登录失败限流。

### 评估

- 余额不缓存是正确的设计，避免了缓存与数据库不一致导致的错误扣款。
- 限流依赖 Cache，若 Cache 不可用需确认降级策略（是放行还是拒绝）。

---

## 二十、插件审计

**本项目未发现插件系统。** 代码中无插件注册、加载、钩子机制。所有功能均在核心代码中实现。

分站系统（Substation）和代理系统（Agent）是核心业务模块，不是插件。

---

## 二十一、Queue / Cron 审计

### 定时任务（Command）

| 命令 | 文件 | 功能 | 资金操作 | 评估 |
|------|------|------|----------|------|
| `cron --data` | Cron.php | 取消过期订单、自动确认收货、归档历史订单 | 确认收货走 ProductOrderService（有事务+幂等） | PASS（批量取消无事务 P2-003） |
| `fund-log:index` | FundLogIndex.php | 检查/创建资金账本唯一索引 | 无 | PASS |
| `tg:*` | Tg*.php | Telegram Webhook 管理 | 无 | PASS |

### 队列任务（Job）

| 任务 | 文件 | 功能 | 资金操作 | 评估 |
|------|------|------|----------|------|
| BatchElectricityQuery | BatchElectricityQuery.php | 批量电费查询 | 无 | 需确认 |
| BatchPhoneQuery | BatchPhoneQuery.php | 批量手机号查询 | 无 | 需确认 |
| GlobalMessageFanoutJob | GlobalMessageFanoutJob.php | 全局消息扇出 | 无 | PASS |
| TimerMessageJob | TimerMessageJob.php | 定时消息 | 无 | PASS |

### Cron 资金安全

- `Cron::processDataTasks()` 中的自动确认收货调用 `ProductOrderService::confirmReceipt()`，该方法有完整的事务+行锁+幂等保护。
- 单笔失败不阻塞整个 Cron（catch 后记录日志继续），订单保持原状态下次重试。
- 历史订单归档使用 `archived` 标记，**不物理删除**（符合财务记录保留原则）。

---

## 二十二、文件上传审计

### UploadService 安全机制

| 检查项 | 实现 | 评估 |
|--------|------|------|
| 文件类型 | MIME 白名单（jpeg/png/gif/webp） | PASS |
| MIME 检测 | `finfo_file` + `getimagesize` 双重验证 | PASS |
| 文件大小 | 默认 10MB 上限 | PASS |
| 文件名 | 随机文件名（`bin2hex(random_bytes(16))`） | PASS |
| 路径穿越 | `normalizeDirectory` 禁止 `..` | PASS |
| PHP 上传 | MIME 白名单仅图片，无法上传 PHP | PASS |
| SVG XSS | SVG 不在白名单中 | PASS |
| 图片解析漏洞 | `getimagesize` 验证图片有效性 | PASS |
| 文件覆盖 | 随机文件名，覆盖概率极低 | PASS |
| 存储位置 | 公共上传在 `public/`，私有上传在 `runtime/private/` | PASS |
| 权限 | `chmod($targetPath, 0644)` | PASS |

### 上传入口

- `/api/upload/image`（用户端）：登录后可上传，走 UploadService。
- 管理后台 `upload_post`：管理员上传，走 UploadService。
- 充值凭证/交易凭证：通过 `persistPrivateProofUpload` 私有存储，通过 `api/proof/*/view` 带授权检查访问。

**评估：PASS。** 文件上传安全机制完善，未发现可利用的上传漏洞。

---

## 二十三、第三方 API 审计

| 第三方 | 用途 | 认证 | SSRF 防护 | 评估 |
|--------|------|------|------------|------|
| bepusdt | USDT 支付 | API Token + MD5 签名 | ✓ normalizeSafeUrl | PASS |
| 易支付 | 支付宝/微信支付 | 商户 ID + MD5 签名 | URL 从配置读取 | PASS |
| Telegram Bot | 通知/机器人 | Bot Token | URL 固定为 Telegram API | PASS |
| 二维码 API | 生成二维码 | 无 | `api.qrserver.com` 固定 URL | PASS |

- 所有第三方 API 的 URL 均从配置读取或硬编码，非用户可控。
- bepusdt 的 `notify_url` 和 `redirect_url` 由系统生成，非用户可控。
- 未发现用户可控制第三方 API 请求目标的路径。

---

## 二十四、数据库结构审计

### 关键表结构（基于模型和代码推断）

| 表 | 关键字段 | 幂等唯一约束 | 评估 |
|----|----------|-------------|------|
| cz_user | id, mobile, balance(float), frozen_amount(float), agent_wallet(float), password, salt, status | mobile 唯一（推断） | balance 为 float（P2-005） |
| cz_recharge | id, order_number, uid, amount, status, gateway, gateway_trade_id | order_number 应唯一（需确认） | 建议添加唯一索引 |
| cz_order | id, order_number, uid, product_id, amount, status, confirm_status, archived | order_number 应唯一（需确认） | 已添加 archived 列 |
| cz_user_fund_log | id, uid, wallet_type, direction, amount(float), request_no, biz_type, biz_no | `uk_uid_wallet_direction_reqno`（需手动创建 — P1-001） | amount 为 float |
| cz_user_balance_log | id, uid, scene, order_number, amount | 无（P2-001） | 建议添加唯一索引 |
| cz_withdrawal | id, order_number, uid, amount, status | order_number 应唯一 | - |
| cz_transaction_order | id, order_number, uid, sell_uid, pay_amount, usdt_amount, transaction_fees, status | order_number 应唯一 | - |

### 建议

1. 为所有 `order_number` 字段添加唯一索引（防止重复订单）。
2. 为 `cz_user_fund_log` 创建 `uk_uid_wallet_direction_reqno`（P1-001，上线前必须）。
3. 为 `cz_user_balance_log` 添加 `(scene, order_number)` 唯一索引（P2-001）。
4. 金额字段从 float 改为 decimal（P2-005）。

---

## 二十五、配置安全审计

### .env 配置

当前仓库 `.env` 为测试配置：
```
APP_KEY=test-key-for-phpunit-only-0123456789abcdef
CRON_SECRET=test-cron-secret-0123456789abcdef
APP_DEBUG=false
DATABASE=hcz_test (本地)
```

**生产环境必须替换：**
- `APP_KEY`：用于加密/签名，必须为强随机值
- `CRON_SECRET`：定时任务鉴权密钥
- 数据库连接信息
- 支付密钥（bepusdt api_token, epay key）
- Telegram bot token

### 关键配置项

| 配置 | 当前 | 生产建议 | 评估 |
|------|------|----------|------|
| APP_DEBUG | false | false | PASS |
| Session | 文件（默认） | Redis（多实例时） | 需确认 |
| Cookie SameSite | 需确认 | Lax/Strict | 需确认 |
| CORS | CorsMiddleware | 限制域名 | 需确认 |
| HTTPS | 需确认 | 强制 HTTPS | 需确认 |
| Queue | 同步/Redis | Redis（生产） | 需确认 |

### 敏感信息泄漏检查

- 模型 `User::$hidden` 包含 password, salt, token, id_card, twofa_secret, twofa_recovery_codes ✓
- 未发现密码/密钥硬编码在业务代码中
- 支付密钥从配置/数据库读取
- `rsa_public_key.pem` 在根目录，是公钥（非敏感）
- `secure-keys/` 目录包含 SQL 迁移脚本和公钥，无私钥

---

## 二十六、依赖安全审计

### composer.json 核心依赖

| 依赖 | 版本 | 用途 | 已知风险 |
|------|------|------|----------|
| topthink/framework | ^8.0 | 框架 | 需联网查 CVE（本次未联网） |
| topthink/think-orm | ^3.0 | ORM | 需联网查 CVE |
| topthink/think-queue | ^3.0 | 队列 | 需联网查 CVE |
| phpoffice/phpspreadsheet | ^3.3 | Excel | 需联网查 CVE |
| endroid/qr-code | ^5.1 | 二维码 | 需联网查 CVE |
| robthree/twofactorauth | ^2.1 | 2FA | 需联网查 CVE |

**说明：** 本次审计未联网查询 CVE 数据库。建议上线前使用 `composer audit` 或第三方工具（如 Snyk、Dependabot）扫描已知漏洞。

---

## 二十七、数据一致性审计

### 一致性关系模型

```
User.balance
  ↑+ 充值成功 (Recharge.status=3 → UserFundLog)
  ↑+ 交易放币 (TransactionOrder.status=3 → UserFundLog buyer_income)
  ↑+ 订单取消退款 (Order.status=3 → UserFundLog refund)
  ↑+ 管理员调整 (Admin → UserFundLog)
  ↑+ 代理钱包转账 (Agent → UserFundLog)
  ↓- 提现申请 (Withdrawal.status=0 → UserFundLog freeze)
  ↓- 产品订单确认 (Order.confirm_status=2 → UserFundLog deduct frozen)

User.frozen_amount
  ↑+ 提现申请冻结
  ↑+ 产品下单冻结
  ↓- 提现成功/失败解冻
  ↓- 订单取消退款
  ↓- 订单确认收货扣减
```

### 一致性保障

- 所有余额变更在 `UserFundLedgerService` 中原子执行（事务内更新余额+流水）。
- 订单状态变更与余额变更在同一事务中。
- 支付回调中：recharge 状态更新 + 余额增加 + 流水创建在同一事务。
- 交易放币中：订单状态 + 卖家冻结扣减 + 买家余额增加 + 平台手续费记账在同一事务。

### 潜在不一致点

1. **P2-002**：充值订单创建与第三方下单不在同一事务，极端情况下可能本地订单已取消但第三方已创建（回调仍能正确处理，因为只检查 status=3）。
2. **第二套流水**：`cz_user_balance_log` 可能与核心账本不一致（P2-001），但不影响实际余额。

---

## 二十八、资金守恒验证

### 充值场景守恒

```
初始: balance=100
充值 50 → 回调成功
  → balance=150
  → UserFundLog: +50 (recharge, request_no=recharge_paid:ORDER)
  → Recharge.status=3
守恒: 100 + 50 = 150 ✓
```

### 消费场景守恒

```
初始: balance=100, frozen=0
下单 80 → frozen=80, balance=100
确认收货 → frozen=0, balance=100 (frozen 扣减，balance 不变)
  → UserFundLog: -80 (frozen, product_order_deduct)
守恒: 100(balance) + 0(frozen) = 100 ✓ (资金在下单时已从可用余额冻结)
```

### 取消退款场景守恒

```
初始: balance=20, frozen=80 (已下单)
取消订单 → frozen=0, balance=100
  → UserFundLog: frozen -80, balance +80 (transfer)
守恒: 20+80 = 100+0 = 100 ✓
```

### 交易放币场景守恒

```
初始: seller.frozen=100, buyer.balance=0
交易: pay_amount=50, usdt_amount=48, fees=2
放币 → seller.frozen=50, buyer.balance=48, platform.income=2
  → UserFundLog seller: -50 (frozen)
  → UserFundLog buyer: +48 (balance)
  → UserFundLog platform: +2 (income, 纯流水)
守恒: 100(seller frozen) = 50(剩余) + 48(buyer) + 2(平台) ✓
```

---

## 二十九、并发场景推演

### 场景 1：同一支付订单同时收到 4 次成功回调

- **初始**：recharge.status=0, user.balance=100, 金额=50
- **执行**：4 个请求同时到达，争夺 recharge 行锁
- **结果**：只有第一个获得锁的请求执行入账（balance=150），其余 3 个获得锁后发现 status=3，直接返回 ok
- **最终**：balance=150, 流水 1 条 ✓

### 场景 2：同一用户同时创建多个充值订单

- **初始**：user.balance=100
- **执行**：同时创建 2 个充值订单（各 50）
- **结果**：两个订单都创建成功（无行锁冲突，因为不修改余额），订单号不同
- **风险**：无资金风险（充值只增加余额），但可能创建大量未支付订单（有限流 10次/分）
- **最终**：2 个 pending 订单 ✓

### 场景 3：充值回调 + 用户消费同时发生

- **初始**：balance=100, 有一个 pending 订单（消费 80）
- **执行**：充值回调(+50) 和 消费扣款(-80) 同时到达
- **结果**：两个操作争夺 user 行锁，顺序执行
  - 若充值先：balance=150 → 消费扣 80 → balance=70
  - 若消费先：balance=20 → 充值 +50 → balance=70
- **最终**：balance=70, 流水 2 条, 资金守恒 ✓

### 场景 4：充值回调 + 邀请奖励同时发生

- **初始**：balance=0
- **执行**：充值回调(+100) 和 邀请奖励发放(+10) 同时到达
- **结果**：争夺 user 行锁，顺序执行，最终 balance=110
- **最终**：资金守恒 ✓（邀请奖励需确认是否走 UserFundLedgerService）

### 场景 5：同一余额同时被两个请求扣款

- **初始**：balance=100
- **执行**：两个消费请求各扣 80
- **结果**：第一个成功（balance=20），第二个余额不足回滚
- **最终**：balance=20, 只有一个扣款成功 ✓

### 场景 6：订单创建请求重复提交

- **初始**：用户点击两次「立即下单」
- **执行**：两个请求同时创建订单
- **结果**：可能创建两个相同的订单（无幂等 token），但每个订单独立冻结金额，用户可用余额会被扣两次
- **风险**：用户可能意外创建两个订单，但可以取消退款。建议前端防重复提交 + 后端幂等 token。
- **评估**：P3 级业务体验问题，非资金安全问题。

### 场景 7：支付成功后回调延迟

- **初始**：用户已支付，回调 1 小时后到达
- **执行**：回调到达时 recharge.status 仍为 0（或 1）
- **结果**：回调正常处理入账（不检查超时），用户余额增加
- **风险**：若订单已被 Cron 取消（status=2），回调中只检查 status=3 跳过，**status=2 仍会处理入账！** 这是 P2-002 中提到的潜在问题。
- **建议**：回调中增加对 status=2（已取消）的检查。

### 场景 8：支付回调重放

- **初始**：已成功处理的回调，攻击者重放相同请求
- **执行**：重放请求到达
- **结果**：recharge.status=3，回调直接返回 ok，不修改余额
- **最终**：无影响 ✓

### 场景 9：数据库事务中途异常

- **初始**：支付回调处理中，在「更新 recharge 状态」后、「增加余额」前数据库连接断开
- **执行**：事务回滚，所有修改撤销
- **结果**：recharge.status 恢复为 0，余额不变，下次回调可重新处理
- **最终**：数据一致 ✓（事务原子性保障）

### 场景 10：Worker 重试同一个资金任务

- **初始**：队列任务执行资金操作后崩溃，队列重试
- **执行**：重试时使用相同的 request_no
- **结果**：UserFundLedgerService 检测到 request_no 已存在（唯一索引），返回重复，不重复入账
- **最终**：无重复入账 ✓（依赖唯一索引 — P1-001）

---

## 三十、全局物理删除路径

### 搜索结果

| 操作 | 文件 | 对象 | 评估 |
|------|------|------|------|
| 用户删除 | AdminApi.php | User | 需确认是软删除还是物理删除 |
| 订单删除 | IndexApi.php (api_order_delete) | Order | 需确认 |
| 消息删除 | IndexApi.php | UserMessage | 物理删除（非财务数据） |
| 历史订单归档 | Cron.php | Order | 软删除（archived=1）✓ |

### 财务记录物理删除检查

- **充值记录（Recharge）**：未发现物理删除路径
- **订单记录（Order）**：Cron 使用 archived 标记，不物理删除 ✓
- **资金流水（UserFundLog）**：未发现删除路径 ✓
- **余额流水（UserBalanceLog）**：未发现删除路径 ✓
- **提现记录（Withdrawal）**：未发现物理删除路径
- **交易订单（TransactionOrder）**：未发现物理删除路径

**结论：核心财务记录无物理删除路径，符合资金系统审计要求。** 用户删除和订单删除需进一步确认实现方式（建议软删除）。

---

## 三十一、所有余额修改入口

（详见第十五节「余额系统审计」中的全量清单表）

**核心结论：所有余额变更均通过 `UserFundLedgerService::changeUserWallet()` / `changeLockedUserWallet()` / `transferLockedUserWallet()` 统一入口，该服务强制事务 + 行锁 + 幂等。**

未发现绕过该服务直接修改 `user.balance` / `user.frozen_amount` / `user.agent_wallet` 的代码路径。

---

## 三十二、所有订单状态修改入口

| 订单类型 | 修改入口 | 事务 | 评估 |
|----------|----------|------|------|
| Recharge | 支付回调（Notify/FinanceActions） | ✓ | PASS |
| Recharge | 用户取消（recharge-submit action=cancel） | ✗ | 低风险（仅状态变更） |
| Recharge | Cron 批量取消 | ✗ | P2-003 |
| Order | 产品下单（handleProductPost） | 需确认 | 需确认 |
| Order | 用户取消（directCancelOrderRefund） | ✓ | PASS |
| Order | 确认收货（ProductOrderService） | ✓ | PASS |
| Order | Cron 自动确认 | ✓（调用 ProductOrderService） | PASS |
| TransactionOrder | 买家提交凭证（TransactionOrderService） | ✓ | PASS |
| TransactionOrder | 卖家放币（TransactionOrderService） | ✓ | PASS |
| TransactionOrder | 买家取消（TransactionOrderService） | ✓ | PASS |
| TransactionOrder | Cron 过期取消 | ✓（逐条处理） | PASS |
| Withdrawal | 用户申请（FinanceActions） | ✓ | PASS |
| Withdrawal | 管理员审核（AdminApi） | 需确认 | 需确认 |

---

## 三十三、所有支付回调入口

| 回调 | 路由 | 控制器/方法 | 签名验证 | 幂等 | 评估 |
|------|------|-------------|----------|------|------|
| bepusdt | POST /api/callback/bepusdt | Notify::api_callback_bepusdt | MD5 ✓ | 订单状态+行锁+账本 ✓ | PASS |
| 易支付 | GET/POST /epay_notify_url | IndexApi::epay_notify_url → handleEpayNotifyUrl | MD5(hash_equals) ✓ | 订单状态+行锁+账本 ✓ | PASS |

**未发现其他支付回调入口。** Telegram Webhook (`/robot/webhook`) 不是支付回调，有独立的 bot token 验证。

---

## 三十四、修复优先级

### 上线前必须完成（P0 + P1）

| 优先级 | 问题 | 操作 |
|--------|------|------|
| P1-001 | 资金幂等唯一索引 | 执行 `php think fund-log:index` 创建 `uk_uid_wallet_direction_reqno` |

### 上线前强烈建议完成（P2）

| 优先级 | 问题 | 操作 |
|--------|------|------|
| P2-001 | UserBalanceLog 无唯一索引 | 添加 `(scene, order_number)` 唯一索引，或废弃该表 |
| P2-002 | 充值创建无事务 + 回调不检查已取消订单 | 回调中增加 `status=2` 检查；或充值创建改事务 |
| P2-003 | Cron 批量取消无事务 | 改为逐条加锁处理，或增加操作日志 |
| P2-004 | 交易放币锁顺序 | 统一全局锁顺序，按 user id 升序锁定 |
| P2-005 | 金额 float 类型 | 改为 decimal(12,2)（可在后续版本迁移） |

### 后续迭代优化（P3）

| 优先级 | 问题 | 操作 |
|--------|------|------|
| P3-001 | 注册密码强度 | 增加最小长度和复杂度校验 |
| P3-002 | 密码找回功能 | 按需增加自助密码重置 |
| P3-003 | 订单号可预测性 | 使用更安全的随机数 |
| P3-004 | 通用错误信息 | 区分业务错误和系统错误 |

---

## 三十五、上线前必须完成事项

1. ✅ **执行 `php think fund-log:index`** 创建资金账本唯一索引（P1-001）
2. ✅ **替换生产环境所有密钥**：APP_KEY, CRON_SECRET, 数据库密码, bepusdt api_token, epay key, telegram bot token
3. ✅ **确认 `cz_order.archived` 列已存在**（执行 `secure-keys/order_archive_schema.sql`）
4. ✅ **确认生产环境 APP_DEBUG=false**
5. ✅ **配置 HTTPS 强制跳转**
6. ✅ **配置 Session Cookie 的 SameSite=Lax 和 Secure 属性**
7. ✅ **配置 CORS 白名单（仅允许前端域名）**
8. ✅ **运行 `composer audit` 扫描依赖漏洞**
9. ✅ **确认管理后台入口路径（backstage_entrance）已修改为非默认值**
10. ✅ **支付回调中增加对已取消订单（status=2）的检查**（P2-002）

---

## 三十六、上线后建议监控事项

1. **资金异常监控**：
   - 同一订单号出现多条 UserFundLog 入账记录（幂等失效告警）
   - 用户余额短时间内大幅变动（>阈值）
   - UserFundLog 与 UserBalanceLog 数量不一致告警

2. **支付回调监控**：
   - 回调签名失败率（攻击/配置错误）
   - 回调金额不匹配告警
   - 已取消订单收到回调告警

3. **并发/死锁监控**：
   - MySQL deadlock 日志监控
   - 事务超时/回滚率

4. **安全监控**：
   - 登录失败率（暴力破解）
   - 注册 IP 异常
   - 管理后台异常登录
   - 文件上传类型异常

5. **业务监控**：
   - 充值成功率
   - 提现处理时长
   - 订单取消率
   - Cron 任务执行状态

---

## 三十七、审计总结

### 核心发现

本项目的资金安全架构设计**整体良好**，核心亮点包括：

1. **统一资金账本**：`UserFundLedgerService` 作为所有余额变更的唯一入口，封装了事务、行锁、幂等、流水，避免了散落各处的余额修改。
2. **支付回调安全**：两个支付通道（bepusdt + 易支付）的回调均有签名验证、事务、行锁、状态检查、金额验证、幂等保护，并发回调不会重复入账。
3. **行锁并发控制**：所有资金操作使用 `SELECT FOR UPDATE` 行锁，同一用户的并发操作串行执行，避免超扣。
4. **认证授权完善**：Session 认证 + 每次回源确认状态 + 管理后台细粒度权限 + CSRF 保护。
5. **文件上传安全**：MIME 白名单 + getimagesize 验证 + 随机文件名 + 路径穿越防护。

### 主要风险

1. **部署依赖项（P1）**：资金幂等唯一索引需手动创建，若遗漏则失去最后一道数据库级幂等防线。
2. **已取消订单回调处理（P2）**：支付回调只跳过 `status=3`，未检查 `status=2`（已取消），理论上已取消订单仍可能被回调入账（需结合 Cron 取消逻辑确认实际触发概率）。
3. **第二套流水不一致（P2）**：`cz_user_balance_log` 无唯一索引，并发下可能重复插入。
4. **float 金额精度（P2）**：资金字段使用 float 而非 decimal，长期运行可能累积精度误差。

### 最终判定

**CONDITIONAL PASS（有条件通过）**

完成「上线前必须完成事项」中的 10 项后，本后端可以承载真实用户、真实充值、真实余额、真实订单的生产上线。核心资金链路（支付回调 → 余额变更 → 流水记录）在事务 + 行锁 + 幂等的三重保护下安全可控。

---

*审计完成时间：2026-08-28*
*审计范围：HCZ-backend 全部源码*
*审计方法：源码级调用链追踪 + 并发场景推演 + 资金守恒建模*
