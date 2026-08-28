# HCZ P5-P1 二次安全复查报告

**复查基准：** main @ 9e7cfdc（未 commit，AdminApi.php 已修改）  
**复查日期：** 2026-08-20  
**复查范围：** P1-001、P1-002 修复内容 + 全仓库管理员攻击面  
**复查方式：** 静态代码分析 + 攻击路径走查 + 全仓库入口枚举  

---

## 1. 总体结论

**结论：PASS WITH P1**

P1-001（管理员删除）已彻底修复，无可绕过路径。  
P1-002（管理员权限提升）的 power 修改、超级管理员修改、管理员创建均已修复，但**发现另一条管理员接管路径**：普通管理员仍可通过 `add_modify` 修改其他普通管理员的密码，从而接管账号并获得更高权限。

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 | 0 | 无 |
| **P1** | **1（新发现）** | 普通管理员修改其他管理员密码 → 账号接管 → 权限提升 |
| **P2** | **1（新发现）** | `admin_post/info` 无权限校验，泄露管理员密码哈希/salt/2FA密钥 |
| P3 | 0 | 无 |
| P4 | 0 | 无 |

---

## 2. P1-001 复查结果：管理员删除接口

**判定：FIXED**

### 逐项验证

| 防护层 | 方法 | 复查结果 | 说明 |
|--------|------|---------|------|
| A. 权限校验 | `directHasAdminPermission('管理员列表')` | **PASS** | 未登录被 AdminAuth 中间件拦截；无权限返回 403；ID=1 超级管理员自动通过 |
| B. 路径校验 | `directRequestPathMatches('admin_post/del')` | **PASS** | 基于 pathinfo 精确匹配/后缀匹配/包含匹配，大小写不敏感；query 参数不影响 pathinfo；自动路由路径同样被匹配 |
| C. CSRF 校验 | `directValidateRequiredCsrfToken()` | **PASS** | 检查 POST `_csrf_token`/`__token__` 或 Header `X-CSRF-Token`，`hash_equals` 常量时间比较；空 token 返回 false；全局 CsrfCheck 中间件双重保护 |
| D. 超级管理员保护 | `$targetId === 1` | **PASS** | `(int)` 强制转换：`"1"`→1, `"01"`→1, `"1.0"`→1, `"+1"`→1, `" 1 "`→1, 数组→1（非空数组转 int=1，被超级管理员检查拦截）；所有变体均被阻断 |
| E. 自删除保护 | `$targetId === $currentAdminId` | **PASS** | `$currentAdminId` 来自 Session，不可被客户端直接修改；AdminAuth 中间件每次回源验证 |
| F. 敏感操作验证 | `directValidateSensitiveOperation($post_info, 'admin_delete')` | **PASS** | 发生在删除之前；启用 2FA 则校验 TOTP，未启用则校验当前管理员密码 `admin_password`；空密码/错误密码/错误 2FA 均返回失败 |
| G. 目标存在性 | `AdminModel::find($targetId)` + null 检查 | **PASS** | 不存在返回「管理员不存在」，不会返回成功；`destroy($targetId)` 使用已验证的整型 ID |
| H. 操作日志 | `directWriteAdminOperationLog()` | **PASS** | 记录目标 ID、账号、名称；操作者由日志服务自动从 Session 获取 |

### 绕过尝试分析

| 攻击向量 | 结果 | 原因 |
|---------|------|------|
| `id[]=1`（数组参数） | 被拦截 | `(int)['1']` = 1 → 触发「禁止删除超级管理员」 |
| `id=01` / `id=1.0` / `id=+1` | 被拦截 | `(int)` 均转为 1 |
| `id= 1 `（前后空格） | 被拦截 | `(int)" 1 "` = 1 |
| `id=null` / `id=false` | 被拦截 | `(int)null` = 0 → `$targetId <= 0` 返回参数错误 |
| 缺少 CSRF token | 被拦截 | `directValidateRequiredCsrfToken` 返回 false → 403 |
| 错误 CSRF token | 被拦截 | `hash_equals` 返回 false → 403 |
| GET 请求 | 被拦截 | 路由定义为 `Route::post`，GET 不匹配 |
| Session 伪造 admin ID=1 | 不可行 | Session 数据服务端存储，客户端仅有 Session ID；AdminAuth 每次回源 DB 验证 |
| 删除不存在的 ID | 返回错误 | `find()` 返回 null → 「管理员不存在」 |

**结论：P1-001 已彻底修复，无绕过路径。**

---

## 3. P1-002 复查结果：管理员修改接口

**判定：PARTIALLY FIXED — 发现新 P1**

### 已修复项

| 防护点 | 复查结果 | 说明 |
|--------|---------|------|
| 权限校验（管理员列表） | **PASS** | 同 P1-001 |
| 路径校验 | **PASS** | `admin_post/add_modify` |
| CSRF 校验 | **PASS** | 同 P1-001 |
| 非超级管理员修改 ID=1 | **PASS** | `!$isSuperAdmin && $targetId === 1` → 「无权修改超级管理员」，任何字段都不能改 |
| 自我提权（修改自己 power） | **PASS** | `if ($isSuperAdmin)` 为 false 时跳过 `$AdminModel->power = $cleanedPower`，power 字段不被写入 |
| 横向提权（修改他人 power） | **PASS** | 同上，非超级管理员不写入 power |
| power 白名单校验 | **PASS** | 16 项白名单，中英文逗号分隔，`array_diff` 精确匹配；`*`/`all`/`admin`/`superadmin` 均被拒绝；Unicode 同形字符因字节不同被拒绝 |
| 普通管理员创建管理员 | **PASS** | `!$isSuperAdmin` → 「仅超级管理员可创建管理员」 |
| 超级管理员创建时 power 白名单 | **PASS** | 创建分支同样校验 |
| 修改密码敏感验证 | **PASS** | `directValidateSensitiveOperation` 在密码写入前执行 |
| 密码哈希算法 | **PASS** | 继续使用 `password_hash($password . $salt, PASSWORD_BCRYPT)` + 随机 salt |

### 未修复项（新发现 P1-003）

见第 4 节。

---

## 4. 新发现漏洞

### [P1-003] 普通管理员通过修改其他管理员密码实现账号接管与权限提升

**位置：**
- 文件：`app/controller/AdminApi.php`
- 方法：`admin_post()` → `case 'add_modify'`
- 行号：约 4256-4263（密码修改逻辑）

**漏洞类型：** 管理员权限提升 / 账号接管 / 水平越权

**攻击条件：**
- 拥有一个普通管理员账号，且该账号具有「管理员列表」权限
- 系统中存在另一个权限更高的普通管理员（如具有「系统设置管理」权限）
- 攻击者知道自己的登录密码（或已启用 2FA 并知道当前验证码）

**攻击路径：**
```
1. 攻击者以普通管理员 A（仅「管理员列表」权限）登录后台
2. 访问管理员列表，发现管理员 B（具有「系统设置管理」权限）
3. 构造 POST 请求到 /{backstage_entrance}/admin_post/add_modify
   参数：
     id=<B的ID>
     account=<B的账号>
     name=<B的名称>
     password=attacker_chosen_password
     admin_password=<攻击者A自己的密码>    ← 用于通过敏感操作验证
     _csrf_token=<合法CSRF token>
4. 服务端流程：
   - 权限校验：A 有「管理员列表」→ PASS
   - 路径/CSRF 校验 → PASS
   - $isSuperAdmin = false
   - $AdminModel = find(B的ID) → 找到
   - $targetId = B的ID（≠1）→ 超级管理员检查 PASS
   - power 白名单校验 → PASS（提交的 power 被校验但不写入）
   - account/name 更新
   - if ($isSuperAdmin) → false → power 不更新 ✓
   - if (!empty(password)) → true
     - directValidateSensitiveOperation → 校验 A 的密码 → PASS
     - B 的密码被更新为 attacker_chosen_password
   - save() 成功
5. 攻击者退出 A 账号，使用 B 的账号 + 新密码登录
6. 攻击者现在拥有 B 的全部权限（包括「系统设置管理」）
```

**当前代码逻辑：**
```php
// P5-P1-002: 仅超级管理员可修改 power 字段 ← 已修复
if ($isSuperAdmin) {
    $AdminModel->power = $cleanedPower;
}
// P5-P1-002: 修改密码需敏感操作二次验证 ← 仅验证了操作者身份，未限制操作对象
if(!empty($post_info['password'])){
    $sensitiveResult = $this->directValidateSensitiveOperation($post_info, 'admin_password_change');
    if (empty($sensitiveResult['ok'])) {
        return show(500, 'error', ...);
    }
    $AdminModel->password = password_hash(($post_info['password'] . $salt), PASSWORD_BCRYPT);
    $AdminModel->salt = $salt;
}
```

**为什么存在漏洞：**
1. P1-002 修复仅限制了 `power` 字段的写入（仅超级管理员），但**未限制 `password` 字段的写入对象**
2. `directValidateSensitiveOperation` 仅验证**操作者**（当前管理员）的密码/2FA，不验证**操作对象**的权限等级
3. 普通管理员 A 提供自己的密码即可通过验证，然后修改管理员 B 的密码
4. 这是与「修改 power」平行的另一条管理员接管路径

**实际影响：**
- 低权限管理员可接管任何非超级管理员账号
- 如果存在具有「系统设置管理」权限的管理员，攻击者可修改支付配置、站点密钥等，导致资金损失
- 接管后可进一步操作（如修改余额、处理提现等，取决于被接管账号的权限）
- 被接管管理员无法察觉（密码被静默修改，无通知机制）

**为什么判定为 P1：**
- 符合 P1 定义中的「管理员权限提升」
- 符合用户明确标准：「原来的 P1 虽然堵住了，但存在另一条管理员接管路径，必须继续判定为 P1」
- 可导致完整的权限提升和潜在资金损失

**修复建议（仅报告，本轮不修改）：**
- 方案 A（推荐）：非超级管理员禁止修改任何其他管理员的密码，仅超级管理员可重置管理员密码
- 方案 B：非超级管理员只能修改权限等级严格低于自己的管理员的密码（需引入权限等级概念，当前系统无此设计）
- 方案 C：修改其他管理员密码时，除操作者密码验证外，还需超级管理员二次授权

**是否阻断发布：是**

---

### [P2-001] `admin_post/info` 无权限校验，泄露管理员密码哈希、salt、2FA 密钥

**位置：**
- 文件：`app/controller/AdminApi.php`
- 方法：`admin_post()` → `case 'info'`
- 行号：约 4309-4340

**漏洞类型：** 敏感信息泄露 / 越权读取

**攻击条件：**
- 任意已登录管理员账号（无需任何特定权限）

**攻击路径：**
```
POST /{backstage_entrance}/admin_post/info
Content-Type: application/x-www-form-urlencoded

id=1
```

**当前代码逻辑：**
```php
case 'info':
    $id = (int)($post_info['id'] ?? 0);
    if ($id <= 0) {
        return show(500, 'error', '参数错误');
    }
    $res = AdminModel::find($id);
    if (!$res) {
        return show(500, 'error', '管理员不存在');
    }
    // ... 构建 power_selected ...
    $data = $res->getData();  // ← 返回所有字段，包括 password, salt, twofa_secret
    $data['power_selected'] = $power_selected;
    $data['twofa_status'] = [...];
    return show(200, 'success', '获取信息成功', $data);
```

**为什么存在漏洞：**
1. `case 'info'` 分支**完全没有** `directHasAdminPermission` 权限校验
2. 没有路径校验、没有 CSRF 校验
3. `$res->getData()` 返回模型**所有字段**，包括：
   - `password`（bcrypt 哈希）
   - `salt`
   - `twofa_secret`（2FA 密钥）
   - `twofa_recovery_codes`（恢复码）
   - `power`（权限列表）
4. 任意登录管理员可获取超级管理员（ID=1）的密码哈希和 2FA 密钥

**实际影响：**
- 攻击者可离线暴力破解管理员密码哈希（bcrypt 强度较高，但非不可破解）
- 若 `twofa_secret` 未加密存储，攻击者可直接生成合法 2FA 验证码，配合密码哈希破解实现完整登录
- 泄露其他管理员的权限配置，辅助定向攻击

**为什么判定为 P2：**
- 需要已认证管理员访问（非远程未授权）
- bcrypt 哈希提供一定保护
- 2FA 密钥可能加密存储（需进一步确认），降低直接利用风险
- 属于信息泄露类，不直接导致权限提升

**修复建议（仅报告）：**
- 添加 `directHasAdminPermission('管理员列表')` 校验
- 使用字段白名单返回，排除 `password`、`salt`、`twofa_secret`、`twofa_recovery_codes`
- 添加 CSRF 校验

**是否阻断发布：否**（建议发布后尽快修复）

---

## 5. 管理员权限矩阵（修复后实际状态）

| 操作 | 超级管理员 (ID=1) | 普通管理员（有管理员列表） | 普通管理员（无管理员列表） |
|------|-------------------|--------------------------|--------------------------|
| 查看管理员列表 | ✅ | ✅ | ❌ 403 |
| 查看管理员详情 (info) | ✅ | ✅ **（无权限校验，P2-001）** | ✅ **（无权限校验，P2-001）** |
| 创建管理员 | ✅ | ❌ 仅超级管理员可创建 | ❌ 403 |
| 修改普通管理员 account/name | ✅ | ✅ | ❌ 403 |
| 修改普通管理员 power | ✅ | ❌ power 不写入 | ❌ 403 |
| 修改普通管理员密码 | ✅ | ⚠️ **可以（P1-003）** | ❌ 403 |
| 修改超级管理员 (ID=1) | ❌ 禁止修改 | ❌ 无权修改 | ❌ 403 |
| 删除普通管理员 | ✅（需密码/2FA） | ✅（需密码/2FA） | ❌ 403 |
| 删除超级管理员 (ID=1) | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |
| 删除自己 | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |

---

## 6. 全仓库管理员攻击面清单

### 管理员创建入口

| 文件 | 方法 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| AdminApi.php | `admin_post/add_modify`（新建分支） | 超级管理员 | ✅ 安全（非超级管理员被拦截） |

### 管理员修改入口

| 文件 | 方法 | 修改对象 | 权限要求 | 是否安全 |
|------|------|---------|---------|---------|
| AdminApi.php | `admin_post/add_modify` | 任意管理员 | 管理员列表 + 超级管理员限制 | ⚠️ P1-003（密码可改） |
| AdminApi.php | `account_post/account` | 当前登录管理员自己 | 已登录 + CSRF | ✅ 安全（仅改自己） |
| AdminApi.php | `twofa_post/*` | 当前登录管理员自己 | 已登录 + CSRF + ID校验 | ✅ 安全（仅改自己） |

### 管理员删除入口

| 文件 | 方法 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| AdminApi.php | `admin_post/del` | 管理员列表 + 7层防护 | ✅ 安全（P1-001 已修复） |

### 管理员权限(power)修改入口

| 文件 | 方法 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| AdminApi.php | `admin_post/add_modify` | 超级管理员 | ✅ 安全（非超级管理员不写入 power） |

### 管理员密码修改入口

| 文件 | 方法 | 修改对象 | 权限要求 | 是否安全 |
|------|------|---------|---------|---------|
| AdminApi.php | `admin_post/add_modify` | 任意管理员 | 管理员列表 + 敏感验证 | ⚠️ P1-003 |
| AdminApi.php | `account_post/account` | 当前管理员自己 | 已登录 + CSRF | ✅ 安全（仅改自己，无旧密码验证但属自助服务） |

### 管理员信息读取入口

| 文件 | 方法 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| AdminApi.php | `admin_post/info` | 无（仅需登录） | ⚠️ P2-001（泄露密码哈希等） |
| AdminList.php | 列表查询 | 管理员列表（页面级） | ✅ 安全（列表不返回密码） |

### 管理员 Session / Auth 入口

| 文件 | 机制 | 是否安全 |
|------|------|---------|
| AdminAuth.php | Session + 每次回源 DB 验证 | ✅ 安全（管理员被删除/禁用后 Session 立即失效） |
| AdminApi.php 构造函数 | `$this->admin_info = session('admin')` | ✅ 安全（Session 服务端存储，客户端不可直接修改） |

---

## 7. 回归测试结果

| 检查项 | 命令/方式 | 结果 |
|--------|----------|------|
| PHP 语法检查 | `php -l app/controller/AdminApi.php` | **PASS**（No syntax errors） |
| 单元测试 | `vendor/bin/phpunit --testsuite Unit` | **PASS**（7 tests, 15 assertions） |
| 集成测试 | `vendor/bin/phpunit --testsuite Integration` | **SKIPPED**（12 tests 全部跳过，本地无数据库环境） |
| 调试代码扫描 | `Select-String dd/dump/var_dump/print_r` | **PASS**（无匹配） |
| Git diff 范围 | `git diff --stat` | **PASS**（仅 AdminApi.php 1 文件，+140/-11） |
| Git 状态 | `git status` | **PASS**（未 commit，未 push） |

---

## 8. 最终发布建议

### 明确回答

| 问题 | 回答 |
|------|------|
| Q1：P1-001 是否彻底修复？ | **是** — 7 层防护全部有效，无绕过路径 |
| Q2：P1-002 是否彻底修复？ | **否** — power 修改和超级管理员修改已修复，但密码修改路径未封堵（P1-003） |
| Q3：是否还有 P0？ | **否** |
| Q4：是否还有 P1？ | **是** — 新发现 P1-003（普通管理员修改其他管理员密码 → 账号接管） |
| Q5：是否发现新的 P2？ | **是** — P2-001（admin_post/info 无权限校验，泄露敏感信息） |
| Q6：当前代码是否可以进入 P6？ | **否** — 存在 P1-003，必须修复后才能进入 P6 |
| Q7：当前是否可以 commit / push？ | **不建议** — 建议先修复 P1-003 再 commit；如必须 commit，应明确标注存在已知 P1-003 |

### 下一步建议

1. **必须修复 P1-003**：限制非超级管理员修改其他管理员密码（推荐方案：仅超级管理员可重置管理员密码）
2. **建议修复 P2-001**：为 `admin_post/info` 添加权限校验和字段白名单
3. 修复后进行 P5-P1 第三次复查
4. 全部 P1 清零后进入 P6 生产环境验收

---

## 9. 复查方法论说明

本次复查采用攻击者视角，针对修复代码进行了以下分析：
- 逐行走查 `case 'del'` 和 `case 'add_modify'` 的所有分支
- 类型转换边界测试（`(int)` 对各种输入的行为）
- 参数污染分析（数组参数、重复参数、GET+POST 混合）
- 全仓库 AdminModel 修改点枚举（create/save/destroy/delete/power=/password=）
- Session 安全性分析（AdminAuth 中间件回源验证机制）
- 替代攻击路径搜索（除 power 修改外的管理员接管方式）
- 权限矩阵交叉验证

**未执行：** 真实 HTTP 请求测试、生产环境操作、数据库修改、代码修改。
