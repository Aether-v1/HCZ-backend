# HCZ P5 全面安全审计报告

**审计基准：** main @ 9e7cfdc  
**审计日期：** 2026-08-20  
**审计范围：** HCZ-backend 全量代码（ThinkPHP 8）  
**审计方式：** 静态代码分析 + 攻击面映射 + 逻辑验证  
**前置阶段：** P3（已完成）、P4（已完成）  

---

## 1. 总体结论

**结论：PASS WITH P1**

代码整体安全水位较高，P3/P4 修复的资金安全、并发竞态、CORS、CSRF 等核心问题均已正确落地且未被破坏。订单、支付、余额、C2C 库存等资金链路全部采用「事务 + 行锁 + 幂等 + 服务端重算金额」四重防护，未发现可直接利用的资金漏洞。

**但存在 2 个 P1 级管理员权限提升漏洞**，低权限管理员可删除超级管理员或修改任意管理员权限，必须修复后才能发布。

---

## 2. 漏洞统计

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 (Critical) | 0 | 无服务器接管/数据库全泄露/大规模资金损失 |
| **P1 (High)** | **2** | 管理员权限提升 + 管理员无校验删除 |
| P2 (Medium) | 2 | Legacy IDOR（已被中间件阻断）+ 异常信息泄露 |
| P3 (Low) | 4 | 模型批量赋值防护缺失、安全Header、登录限流粒度、依赖审计未执行 |
| P4 (Info) | 2 | 代码规范类建议 |

---

## 3. P0/P1 问题详情

### [P1-001] 管理员删除接口无权限校验 — 任意管理员可删除超级管理员

**位置：**
- 文件：`app/controller/AdminApi.php`
- 行号：4143 (`admin_post` 方法) → `case 'del'` 分支（约 4225 行）

**漏洞类型：** 权限提升 / 越权操作 / 管理员接管

**攻击条件：**
- 拥有任意一个管理员账号（即使是最低权限管理员）
- 知道目标管理员 ID（可通过 `admin_post/info` 或管理员列表获取）

**攻击路径：**
```
POST /{backstage_entrance}/admin_post/del
Content-Type: application/x-www-form-urlencoded
_csrf_token=<valid_token>
id=1
```

**当前代码逻辑：**
```php
case 'del':
    $deleteAdmin = AdminModel::find($post_info['id']);
    AdminModel::destroy($post_info['id']);
    if ($deleteAdmin) {
        $this->directWriteAdminOperationLog('删除管理员', ...);
    }
    return show(200, 'success', '删除成功');
```

**为什么存在漏洞：**
1. `case 'del'` 分支**完全没有**调用 `directHasAdminPermission()` 进行权限校验
2. 没有校验当前管理员是否为超级管理员（ID=1）
3. 没有禁止删除超级管理员（ID=1）
4. 没有敏感操作密码二次验证（`directValidateSensitiveOperation`）
5. 对比同方法的 `case 'add_modify'` 有完整的权限校验 + 路径校验 + CSRF 校验，`del` 分支明显遗漏

**实际影响：**
- 低权限管理员可删除超级管理员（ID=1），导致系统无最高权限管理员
- 可删除其他所有管理员，实现管理员权限清洗
- 删除后无恢复机制（物理删除，非软删除）

**复现步骤（代码级）：**
1. 以非超级管理员身份登录后台
2. 获取管理员列表（若有权限）或通过 `admin_post/info` 探测 ID
3. 构造 POST 请求到 `admin_post/del`，参数 `id=1`
4. 超级管理员被删除，返回「删除成功」

**修复建议：**
```php
case 'del':
    // 1. 权限校验
    if (!$this->directHasAdminPermission('管理员列表')) {
        return $this->directDenyAdminPermission('管理员列表');
    }
    // 2. 路径校验
    if (!$this->directRequestPathMatches('admin_post/del')) {
        return show(403, 'error', '管理员请求路径错误');
    }
    // 3. CSRF 校验
    if (!$this->directValidateRequiredCsrfToken()) {
        return show(403, 'error', '管理员请求校验失败');
    }
    // 4. 禁止删除超级管理员
    $targetId = (int)($post_info['id'] ?? 0);
    if ($targetId <= 0) {
        return show(500, 'error', '参数错误');
    }
    if ($targetId === 1) {
        return show(500, 'error', '禁止删除超级管理员');
    }
    // 5. 禁止删除自己
    if ($targetId === (int)($this->admin_info['id'] ?? 0)) {
        return show(500, 'error', '禁止删除当前登录账号');
    }
    // 6. 敏感操作密码验证
    $sensitiveError = $this->directValidateSensitiveOperation($post_info);
    if ($sensitiveError) {
        return $sensitiveError;
    }
    $deleteAdmin = AdminModel::find($targetId);
    if (!$deleteAdmin) {
        return show(500, 'error', '管理员不存在');
    }
    AdminModel::destroy($targetId);
    // ... 日志 ...
```

**修复优先级：** 立即修复（发布阻断项）

---

### [P1-002] 低权限管理员可修改任意管理员权限 — 权限提升

**位置：**
- 文件：`app/controller/AdminApi.php`
- 行号：4143 (`admin_post` 方法) → `case 'add_modify'` 分支（约 4148-4210 行）

**漏洞类型：** 权限提升 / 越权修改 / 管理员接管

**攻击条件：**
- 拥有一个具有「管理员列表」权限的管理员账号（非超级管理员）
- 知道目标管理员 ID 或账号名

**攻击路径：**
```
# 攻击方式 A：修改自己的 power 字段，授予全部权限
POST /{backstage_entrance}/admin_post/add_modify
id=<自己的ID>
account=<自己的账号>
name=<自己的名称>
power=用户列表,支付管理,充值业务-产品列表,...,管理员列表,系统设置管理
password=（留空则不改密码）

# 攻击方式 B：修改超级管理员密码，接管最高权限
POST /{backstage_entrance}/admin_post/add_modify
id=1
account=admin
name=admin
power=（任意）
password=hacker123
```

**当前代码逻辑：**
```php
case 'add_modify':
    if (!$this->directHasAdminPermission('管理员列表')) {
        return $this->directDenyAdminPermission('管理员列表');
    }
    // ... 路径校验、CSRF 校验 ...
    $AdminModel = AdminModel::find($post_info['id']);
    // ...
    if ($AdminModel) {
        // 修改已有管理员
        $AdminModel->account = $post_info['account'];
        if (!empty($post_info['password'])) {
            $AdminModel->password = password_hash(...);
            $AdminModel->salt = $salt;
        }
        $AdminModel->name = $post_info['name'];
        $AdminModel->power = $post_info['power'];  // ← 直接覆盖权限
        $AdminModel->save();
    }
```

**为什么存在漏洞：**
1. 仅校验当前管理员有「管理员列表」权限，但**未限制可操作的目标管理员范围**
2. 非超级管理员可以修改 ID=1（超级管理员）的账号、密码、权限
3. 非超级管理员可以修改自己的 `power` 字段，授予自己任意权限（包括「系统设置管理」「管理员列表」等）
4. 修改其他管理员权限时，没有校验目标管理员的等级是否高于当前管理员
5. `power` 字段直接从 POST 参数赋值，无白名单校验
6. 没有敏感操作密码二次验证

**实际影响：**
- 任意具有「管理员列表」权限的普通管理员可将自己提升为全权限管理员
- 可修改超级管理员密码，直接接管系统最高权限
- 可修改其他管理员权限，实现权限操控

**复现步骤（代码级）：**
1. 以具有「管理员列表」权限的普通管理员身份登录
2. 构造 POST 请求到 `admin_post/add_modify`
3. 参数 `id=<自己ID>`，`power` 设为包含所有权限名称的逗号分隔字符串
4. 保存成功后，该管理员拥有全部后台权限

**修复建议：**
```php
case 'add_modify':
    // ... 现有权限/路径/CSRF 校验 ...
    $targetId = (int)($post_info['id'] ?? 0);
    $currentAdminId = (int)($this->admin_info['id'] ?? 0);
    $isSuperAdmin = $currentAdminId === 1;

    if ($AdminModel) {
        // 1. 非超级管理员禁止修改超级管理员
        if (!$isSuperAdmin && (int)$AdminModel['id'] === 1) {
            return show(500, 'error', '无权修改超级管理员');
        }
        // 2. 非超级管理员禁止修改自己的 power 字段（防自我提权）
        if (!$isSuperAdmin && (int)$AdminModel['id'] === $currentAdminId) {
            // 只允许修改账号和名称，不允许修改 power
            $AdminModel->account = $post_info['account'];
            $AdminModel->name = $post_info['name'];
            // 不设置 power
        } else {
            // 超级管理员或修改他人：校验 power 白名单
            $allowedPowers = $this->getAllowedAdminPowerList();
            $requestedPowers = array_filter(array_map('trim', explode(',', $post_info['power'] ?? '')));
            $invalidPowers = array_diff($requestedPowers, $allowedPowers);
            if (!empty($invalidPowers)) {
                return show(500, 'error', '包含无效权限项');
            }
            $AdminModel->account = $post_info['account'];
            $AdminModel->name = $post_info['name'];
            $AdminModel->power = implode(',', $requestedPowers);
        }
        // 3. 修改密码需要敏感操作验证
        if (!empty($post_info['password'])) {
            $sensitiveError = $this->directValidateSensitiveOperation($post_info);
            if ($sensitiveError) {
                return $sensitiveError;
            }
            $AdminModel->password = password_hash(...);
            $AdminModel->salt = $salt;
        }
        $AdminModel->save();
    }
    // 新增管理员时，非超级管理员禁止创建
    if (!$isSuperAdmin) {
        return show(500, 'error', '仅超级管理员可创建管理员');
    }
```

**修复优先级：** 立即修复（发布阻断项）

---

## 4. P2 问题详情

### [P2-001] Legacy 订单删除接口存在 IDOR — 当前被中间件阻断但属潜在风险

**位置：**
- 文件：`app/controller/indexapi/OrderActions.php`
- 行号：`handleOrderPost` 方法 → `case 'order_del'` 分支

**漏洞类型：** 越权删除（IDOR）/ 潜在漏洞

**攻击条件：**
- `LegacyUserFrontendDisabled` 中间件被禁用或配置错误
- 或该方法被新的 API 路由直接映射

**当前代码逻辑：**
```php
case 'order_del':
    Order::destroy($post_info['del_id']);
    return show(200, 'success', '删除成功');
```

**为什么存在漏洞：**
- `Order::destroy($post_info['del_id'])` 直接按 ID 删除，**没有校验订单归属**（`uid` 是否等于当前用户）
- 对比同方法的 `order_on_line_status` 分支使用了 `where('uid', $user_info['id'])` 过滤
- 对比 API 端点 `api_order_delete` 使用了 `Order::userVisibleQuery($uid)` 过滤

**当前缓解措施：**
- `LegacyUserFrontendDisabled` 中间件全局阻断 `indexapi` 控制器的非 API 路径
- 该方法仅可通过自动路由 `indexapi/order_post/order_del` 访问，已被中间件返回 503
- 新 API 端点 `api_order_delete` 已正确使用 `userVisibleQuery`

**实际影响：**
- 当前生产环境下不可直接利用（中间件阻断）
- 若中间件被误关或路由被显式映射，任意登录用户可删除任意订单

**修复建议：**
- 即使 legacy 路径已停用，也应修复该分支，添加所有权校验：
```php
case 'order_del':
    $order = Order::where('uid', $user_info['id'])->find($post_info['del_id']);
    if (!$order) {
        return show(500, 'error', '订单不存在');
    }
    $order->markUserDeleted();  // 软删除而非物理删除
    return show(200, 'success', '删除成功');
```

**修复优先级：** 发布后处理（非阻断项，但建议尽快修复）

---

### [P2-002] API 异常响应直接返回异常消息 — 潜在信息泄露

**位置：**
- 文件：`app/ExceptionHandle.php`
- 行号：`render` 方法（约 55-70 行）

**漏洞类型：** 信息泄露

**当前代码逻辑：**
```php
public function render($request, Throwable $e): Response
{
    if ($request->isAjax() || $request->isJson() || str_starts_with($request->pathinfo(), "api/")) {
        return json([
            "code" => $httpCode,
            "message" => $e->getMessage() ?: "系统异常，请稍后重试",
            // ...
        ], $httpCode);
    }
    return parent::render($request, $e);
}
```

**为什么存在漏洞：**
- `$e->getMessage()` 直接返回给客户端
- 若异常为数据库异常（`PDOException`）、文件操作异常等，消息中可能包含：
  - SQL 语句片段
  - 数据库表名/字段名
  - 文件绝对路径
  - 内部类名/方法名
- 虽然 `app_debug=false` 会抑制 ThinkPHP 默认的详细错误页，但自定义 `render` 绕过了这一保护

**实际影响：**
- 攻击者可通过构造异常请求探测数据库结构、文件路径等内部信息
- 辅助其他攻击（如 SQL 注入、路径穿越）

**修复建议：**
```php
public function render($request, Throwable $e): Response
{
    if ($request->isAjax() || $request->isJson() || str_starts_with($request->pathinfo(), "api/")) {
        // 生产环境下，对非业务异常返回通用消息
        $safeMessage = "系统异常，请稍后重试";
        if ($e instanceof ValidateException || $e instanceof HttpException) {
            $safeMessage = $e->getMessage() ?: $safeMessage;
        }
        // 业务自定义异常（继承自 think\Exception）可返回消息
        if ($e instanceof \think\Exception) {
            $safeMessage = $e->getMessage() ?: $safeMessage;
        }
        return json([
            "code" => $httpCode,
            "message" => $safeMessage,
            // ...
        ], $httpCode);
    }
    return parent::render($request, $e);
}
```

**修复优先级：** 发布后处理

---

## 5. P3 问题汇总

### [P3-001] User / Admin 模型未定义 $guarded / $fillable

**位置：** `app/model/User.php`、`app/model/Admin.php`

**说明：** ThinkPHP 默认所有字段可批量赋值。虽然当前所有 `create()` / `update()` 调用均使用显式字段数组（未发现 `$request->all()` 批量赋值），但模型层面缺乏防护纵深。若未来新增代码使用 `$model->save($request->post())` 模式，将导致 Mass Assignment。

**建议：** 在 User 模型中设置 `$guarded = ['id', 'password', 'salt', 'balance', 'frozen_amount', 'agent_wallet', 'agent_status', 'twofa_secret', 'twofa_enabled', 'invite_code', 'reg_time', 'reg_ip']`；Admin 模型设置 `$guarded = ['id', 'password', 'salt']`。

---

### [P3-002] 安全 Header 未在应用层设置

**位置：** 应用层无安全 Header 中间件

**说明：** 未发现 `Content-Security-Policy`、`X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy`、`Strict-Transport-Security` 等 Header 的应用层设置。这些通常由 Nginx / Cloudflare 层负责。

**建议：** 确认 Nginx / Cloudflare 配置中已包含以下 Header：
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY` 或 `SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`（HTTPS 环境）

---

### [P3-003] 登录限流基于 IP+账号组合，无纯账号级锁定

**位置：** `app/service/LoginRateLimiter.php`

**说明：** 登录失败计数使用 `IP:账号` 组合键。攻击者可通过轮换 IP（代理池、TOR）对同一账号进行无限次密码尝试。虽然有 2FA 作为第二道防线，但纯密码登录场景下仍存在暴力破解风险。

**建议：** 增加纯账号维度的失败计数（如单账号 10 分钟内失败 20 次则临时锁定 30 分钟），与 IP 维度并行。

---

### [P3-004] composer audit 未执行（本地环境无 composer）

**位置：** 依赖审计

**说明：** 当前审计环境未安装 composer，无法运行 `composer audit` 和 `composer outdated`。无法确认第三方依赖是否存在已知 CVE。

**建议：** 在 CI/CD 或生产服务器执行 `composer audit --no-dev`，并将结果纳入发布检查。

---

## 6. P4 问题汇总

### [P4-001] Legacy Index 控制器使用 $_REQUEST 直接取参

**位置：** `app/controller/Index.php`（多处）

**说明：** 该控制器为旧版前端控制器，已被 `LegacyUserFrontendDisabled` 中间件全局阻断。使用 `$_REQUEST` 而非 `$this->request->param()` 属于代码规范问题，不构成当前安全风险。

**建议：** 若确认 legacy 前端永久下线，可考虑删除该控制器；若保留，应统一使用 Request 对象取参。

---

### [P4-002] admin_post/info 接口返回管理员完整数据（含 password 字段）

**位置：** `app/controller/AdminApi.php` → `case 'info'`

**说明：** `$res->getData()` 返回模型所有字段，包括 `password`、`salt`、`twofa_secret` 等。虽然该接口仅管理员可访问且密码为 bcrypt 哈希，但仍属于过度返回。

**建议：** 使用字段白名单返回，排除 `password`、`salt`、`twofa_secret`、`twofa_recovery_codes`。

---

## 7. 已验证安全项目

| 审计项 | 状态 | 说明 |
|--------|------|------|
| **身份认证** | **PASS** | bcrypt+salt 密码存储；登录限流（IP+账号，5次/15分钟）；2FA TOTP；登录成功 session_regenerate_id；登出销毁 session；改密后 `destroyUserSession()` 使旧 session 失效；管理员独立 session |
| **授权 / RBAC** | **PASS** | UserAuth 中间件校验登录态；所有 API 资源操作均校验 `uid` 归属；订单使用 `userVisibleQuery($uid)` 范围查询；银行卡/交易挂单均 `where('uid', $uid)` 过滤 |
| **订单安全** | **PASS** | 金额服务端重算（`SubstationPriceService`），不信任客户端 price；取消订单校验所有权+状态(仅status=0)+事务+行锁；删除订单使用软删除(`markUserDeleted`)+所有权过滤 |
| **支付安全** | **PASS** | Epay 回调校验签名+金额+商户号+订单号+支付状态；幂等性校验(已支付订单直接返回 success)；事务+行锁；Bepusdt 回调同样校验签名+金额+幂等 |
| **余额 / 钱包** | **PASS** | 统一通过 `UserFundLedgerService` 操作；所有变更在事务内+行锁(`lock(true)`)；`idempotent=true` 防重复；负数金额校验；冻结余额与可用余额分离 |
| **C2C / 库存** | **PASS** | 购买使用 `SELECT FOR UPDATE` 行锁挂单；可用量原子校验 `available_amount >= quantity`；释放挂单校验所有权+密码/2FA；订单取消恢复库存；P4 修复未被破坏 |
| **管理员** | **FAIL** | 见 P1-001、P1-002 |
| **SQL 注入** | **PASS** | 所有 `whereRaw` 均为硬编码字符串，无用户输入拼接；查询全部使用 ThinkPHP 参数绑定；未发现 `DB::raw($userInput)` 模式 |
| **XSS** | **PASS** | 用户输入通过 `normalizeProfileText()` 截断+清理；API 返回 JSON（前端渲染）；未发现富文本存储；昵称/资料字段有长度限制 |
| **CSRF** | **PASS** | 全局 `CsrfCheck` 中间件；例外仅登录/注册/回调/webhook；管理员敏感操作额外校验 CSRF token；Session cookie `SameSite=None` + `Secure` |
| **CORS** | **PASS** | `CorsMiddleware` 白名单机制（从配置读取允许域名）；非白名单 Origin 不返回 CORS Header；凭证请求校验 Origin 匹配 |
| **Rate Limit** | **PASS** | 登录：5次/15分钟(IP+账号)；注册：3次/IP/天 + 手机号限流；提现：密码+2FA + 频率限制；划转：5次/60秒；钱包地址修改：5分钟冷却 |
| **文件上传** | **PASS** | `UploadService` MIME 双检(finfo+getimagesize)；扩展名白名单(jpg/png/gif/webp)；目录穿越防护(拒绝 `..`)；文件名随机化；凭证图存私有目录(`runtime/private`)；chmod 0644 |
| **SSRF** | **N/A** | 未发现用户可控 URL 的外部 HTTP 请求；`getTelecomOperator` 使用固定第三方 API URL |
| **命令执行** | **PASS** | 未发现 `exec()`/`shell_exec()`/`system()`/`passthru()`/`proc_open()` 等危险函数；`curl_exec` 为 HTTP 客户端正常使用 |
| **路径穿越** | **PASS** | 文件下载/查看通过 `proofFileResponse` 校验所有权后读取固定存储路径；`UploadService::normalizeDirectory` 拒绝 `..`；未发现用户可控路径的文件操作 |
| **依赖安全** | **BLOCKED** | 本地无 composer，无法执行 `composer audit`；需在 CI/生产环境补充 |
| **Secret 泄露** | **PASS** | `.env` 在 `.gitignore`；`APP_KEY`/`CRON_SECRET` 要求外部文件配置（`resolveRequiredSecretConfigValue`），禁止位于项目目录内；`rsa_public_key.pem` 为公钥非敏感 |
| **Git 历史** | **PASS** | 初始提交标注 "sanitized"；`.env` 无 git 历史；未发现历史提交中的密码/Token/API Key；`secure-keys/` 目录仅含 SQL 索引文件和公钥 |
| **配置安全** | **PASS** | `app_debug=false`；`show_error_msg=false`；Session cookie `Secure`+`HttpOnly`+`SameSite=None`；CSRF 启用；管理员 IP 白名单默认 127.0.0.1 |
| **日志安全** | **PASS** | 异常日志对 token/secret/key/sign 字段掩码(`sanitizeExceptionLogValue`)；手机号/钱包地址脱敏；未发现密码明文日志 |
| **Mass Assignment** | **PASS（实践层）** | 所有 `create()`/`update()` 均使用显式字段数组；未发现 `$request->all()` 批量赋值；模型层防护缺失见 P3-001 |
| **API 数据泄露** | **PASS** | User 模型 `$hidden` 排除 password/salt/twofa_secret；API 响应使用显式字段构建；管理员 info 接口过度返回见 P4-002 |

---

## 8. P3/P4 修复回归检查

| 之前修复项 | 当前状态 | 验证结果 |
|-----------|---------|---------|
| P3: C2C 超卖 (SELECT FOR UPDATE) | 代码完整保留 | `TransactionActions::buy` 使用 `lock(true)` + `available_amount >= quantity` 原子校验 |
| P3: 提现资金安全 (事务+行锁+幂等) | 代码完整保留 | `FinanceActions::withdrawal` 使用 `Db::transaction` + `lock(true)` + 重复检测 |
| P3: 管理员删除防护 | **未覆盖 admin_post/del** | P4 修复了用户删除/订单删除，但 `AdminApi::admin_post 'del'` 仍无校验 → P1-001 |
| P3: 平台手续费流水 | 代码完整保留 | 提现/交易均写入 `UserFundLedgerService` 流水 |
| P3: 双异常处理器 | 代码完整保留 | `ExceptionHandle` 区分 API/非 API 响应 |
| P3: 登录限流 | 代码完整保留 | `LoginRateLimiter` 正常工作 |
| P4: CORS 白名单 | 代码完整保留 | `CorsMiddleware` 从配置读取白名单，非白名单不返回 Header |
| P4: 旧版前端停用 | 代码完整保留 | `LegacyUserFrontendDisabled` 全局阻断 index/indexapi/indexlist 控制器 |
| P4: Session 安全 cookie | 配置完整保留 | `cookie_secure=true`, `cookie_httponly=true`, `cookie_samesite=None` |

**回归结论：** P3/P4 修复项均未被破坏，仅管理员删除接口的权限校验存在遗漏（P1-001）。

---

## 9. 发布阻断项

### 必须修复后才能发布（BLOCKER）

| 编号 | 问题 | 原因 |
|------|------|------|
| **P1-001** | 管理员删除无权限校验 | 任意管理员可删除超级管理员，系统最高权限可被清除 |
| **P1-002** | 低权限管理员可修改任意管理员权限 | 可自我提权或修改超级管理员密码，接管系统 |

### 可以发布后再处理（NON-BLOCKER）

| 编号 | 问题 | 建议处理时间 |
|------|------|-------------|
| P2-001 | Legacy order_del IDOR | 发布后 1 周内（当前被中间件阻断） |
| P2-002 | 异常信息泄露 | 发布后 1 周内 |
| P3-001 | 模型 $guarded 缺失 | 下个迭代 |
| P3-002 | 安全 Header | 确认 Nginx/Cloudflare 配置即可 |
| P3-003 | 登录限流粒度 | 下个迭代 |
| P3-004 | 依赖审计 | CI 中补充 `composer audit` |
| P4-001/002 | 代码规范 | 按需处理 |

---

## 10. 最终建议

### 是否建议进入 P6？

**不建议直接进入 P6。** 必须先完成 P1-001 和 P1-002 的修复并通过回归验证后，方可进入 P6（生产环境验收）。

### 是否存在必须先修复的 P1/P2？

- **P1-001、P1-002：必须先修复**（发布阻断）
- P2-001、P2-002：可发布后修复，但建议在 P6 之前完成

### 是否可以开始生产环境验收？

**暂不可以。** 两个 P1 漏洞涉及管理员权限体系完整性，在修复并验证前不应部署到生产环境。

### 修复优先级排序

1. **P1-001** → admin_post/del 添加权限校验 + 禁止删除超级管理员 + 敏感操作验证
2. **P1-002** → admin_post/add_modify 限制非超级管理员修改权限字段 + 禁止修改超级管理员
3. P2-002 → 异常处理消息白名单
4. P2-001 → Legacy order_del 所有权校验
5. P3 系列 → 按迭代计划处理

---

## 附录：审计方法论

本次审计覆盖以下攻击面：
- 控制器：IndexApi（2224行，含8个Trait）、AdminApi（4341行）、Notify、SubstationApi、Index
- 模型：User、Admin、Order、Recharge、TransactionOrder、TransactionProduct、BankCard 等
- 中间件：UserAuth、AdminAuth、CorsMiddleware、CsrfCheck、LegacyUserFrontendDisabled、SessionInit
- 服务：UserFundLedgerService、UploadService、LoginRateLimiter、ActionRateLimiter、SubstationPriceService 等
- 配置：app.php、middleware.php、cache.php、session.php、.env.example
- Git 历史：全分支提交记录 + .env 历史追踪
- 危险模式扫描：DB::raw、exec/system、$_REQUEST、$request->all()、forceFill 等

**审计工具：** 静态代码分析（Grep/Read）、攻击面映射、逻辑走查、并发竞态分析
