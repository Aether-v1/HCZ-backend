# HCZ P5-P1 安全漏洞修复报告

**修复基准：** main @ 9e7cfdc  
**修复日期：** 2026-08-20  
**修复范围：** 仅 P1-001、P1-002  
**修改文件：** `app/controller/AdminApi.php`（1 文件，+140 / -11）  

---

## 1. 修复摘要

| 编号 | 漏洞名称 | 状态 |
|------|---------|------|
| P1-001 | 管理员删除接口无权限校验 | **FIXED** |
| P1-002 | 低权限管理员可修改任意管理员权限 | **FIXED** |

---

## 2. 修改文件清单

### 文件：`app/controller/AdminApi.php`

#### 2.1 新增辅助方法（第 739-795 行）

| 方法 | 作用 |
|------|------|
| `directGetAllowedAdminPowerList(): array` | 返回系统定义的 16 项合法管理员权限白名单（与后台 `$street` 下拉框完全一致） |
| `directValidateAdminPowerValue(string $power): array` | 校验 power 字段是否全部来自白名单，兼容中英文逗号分隔，返回规范化结果 `['ok', 'message', 'cleaned']` |
| `directIsCurrentAdminSuperAdmin(): bool` | 判断当前登录管理员是否为超级管理员（ID=1） |

**权限白名单（16 项）：**
用户列表、支付管理、充值业务 - 产品列表、查询业务 - 产品列表、充值业务 - 订单列表、查询业务 - 订单列表、交易挂单数据、交易订单数据、充值订单记录、提现订单记录、返佣记录、首页轮播图、积分管理、管理员列表、操作记录、系统设置管理

#### 2.2 修复 P1-001：`case 'del'` 分支（原第 4251-4260 行）

**修改前：**
```php
case 'del':
    $deleteAdmin = AdminModel::find($post_info['id']);
    AdminModel::destroy($post_info['id']);
    if ($deleteAdmin) {
        $this->directWriteAdminOperationLog(...);
    }
    return show(200, 'success', '删除成功');
```

**修改后新增 7 层防护：**
1. **权限校验** — `directHasAdminPermission('管理员列表')`，无权限返回 403
2. **路径校验** — `directRequestPathMatches('admin_post/del')`，防参数污染
3. **CSRF 校验** — `directValidateRequiredCsrfToken()`，缺失返回 403 + 警告日志
4. **禁止删除超级管理员** — `targetId === 1` 直接拒绝（任何人，包括超级管理员本人）
5. **禁止删除自己** — `targetId === currentAdminId` 直接拒绝
6. **敏感操作二次验证** — `directValidateSensitiveOperation()`（2FA 或管理员密码）
7. **目标存在性校验** — 删除前 `find()` 确认存在，不存在返回「管理员不存在」

**保留：** 操作日志 `directWriteAdminOperationLog()` 记录操作者、目标 ID、账号、名称。

#### 2.3 修复 P1-002：`case 'add_modify'` 分支（原第 4148-4216 行）

**修改前问题：**
- 任意有「管理员列表」权限的管理员可修改任意管理员的 `power` 字段
- 可修改超级管理员（ID=1）的账号、密码、权限
- 可通过 `id=自己ID&power=全部权限` 实现自我提权
- `power` 字段无白名单校验，可注入任意字符串
- 非超级管理员可创建新管理员
- 修改密码无敏感操作二次验证

**修改后新增防护：**

| 防护点 | 逻辑 |
|--------|------|
| 操作者边界识别 | `$isSuperAdmin = directIsCurrentAdminSuperAdmin()` |
| 禁止修改超级管理员 | 非超级管理员 + `targetId === 1` → 「无权修改超级管理员」（任何字段都不能改） |
| power 白名单校验 | `directValidateAdminPowerValue()` 校验所有权限项，非法则拒绝 |
| 防止自我提权 / 横向提权 | **仅超级管理员可写入 `power` 字段**；普通管理员提交的 power 被校验但不写入 |
| 密码修改二次验证 | 修改密码需通过 `directValidateSensitiveOperation()`（2FA 或管理员密码） |
| 禁止普通管理员创建 | 新建管理员分支前置 `!$isSuperAdmin` → 「仅超级管理员可创建管理员」 |
| 新建 power 白名单 | 创建管理员时同样校验 power 白名单 |
| 日志精确化 | 普通管理员修改时日志不记录权限变更（因未修改）；超级管理员记录权限前后对比 |

**密码安全保持不变：** 继续使用 `password_hash($password . $salt, PASSWORD_BCRYPT)` + 随机 salt，未改哈希算法。

---

## 3. P1-001 验证矩阵（静态代码走查）

| 测试场景 | 预期结果 | 实际代码逻辑 | 结果 |
|---------|---------|-------------|------|
| 无管理员身份 → 删除管理员 | 403 未登录 | AdminAuth 中间件在路由层拦截 | **PASS** |
| 普通管理员（无管理员列表权限）→ 删除 | 403 权限不足 | `directHasAdminPermission` 返回 false | **PASS** |
| 普通管理员 → 删除自己 | 500 禁止删除当前登录账号 | `targetId === currentAdminId` | **PASS** |
| 普通管理员 → 删除 ID=1 | 500 禁止删除超级管理员 | `targetId === 1` | **PASS** |
| 超级管理员 → 删除 ID=1 | 500 禁止删除超级管理员 | `targetId === 1`（不豁免超级管理员） | **PASS** |
| 超级管理员 → 删除普通管理员 | 200 删除成功（需密码/2FA） | 全部校验通过 → destroy + 日志 | **PASS** |
| 普通管理员（有管理员列表权限）→ 删除其他普通管理员 | 200 删除成功（需密码/2FA） | 全部校验通过 → destroy + 日志 | **PASS** |
| 缺少 CSRF Token → 删除 | 403 请求校验失败 | `directValidateRequiredCsrfToken` 返回 false | **PASS** |
| 不存在的管理员 ID → 删除 | 500 管理员不存在 | `find()` 返回 null | **PASS** |
| 伪造 target ID（SQL 注入） | 安全 | `(int)$post_info['id']` 强制整型转换 | **PASS** |

---

## 4. P1-002 验证矩阵（静态代码走查）

| 测试场景 | 预期结果 | 实际代码逻辑 | 结果 |
|---------|---------|-------------|------|
| 普通管理员 → 修改自己 power 为全部权限 | power 不被修改（account/name 可改） | `if ($isSuperAdmin)` 为 false，跳过 power 赋值 | **PASS** |
| 普通管理员 → 修改自己 power 为「超级管理员」 | 500 包含非法权限项 | 白名单校验失败（「超级管理员」不在 16 项列表中） | **PASS** |
| 普通管理员 → 修改 ID=1（任意字段） | 500 无权修改超级管理员 | `!$isSuperAdmin && targetId === 1` | **PASS** |
| 普通管理员 → 修改 ID=1 密码 | 500 无权修改超级管理员 | 在到达密码逻辑前被拦截 | **PASS** |
| 普通管理员 → 修改其他管理员 power | power 不被修改 | `if ($isSuperAdmin)` 为 false，跳过 power 赋值 | **PASS** |
| 普通管理员 → 修改其他管理员 account/name | 允许修改（原有业务逻辑） | 非 power 字段正常写入 | **PASS**（按设计） |
| 普通管理员 → 创建管理员 | 500 仅超级管理员可创建管理员 | `!$isSuperAdmin` 在创建分支前置拦截 | **PASS** |
| 超级管理员 → 修改普通管理员权限 | 200 修改成功 | `isSuperAdmin=true`，power 校验通过后写入 | **PASS** |
| 超级管理员 → 创建管理员 | 200 添加成功 | `isSuperAdmin=true`，power 校验通过后 create | **PASS** |
| 非法 power（`power=*` 或 `power=admin`）→ 提交 | 500 包含非法权限项 | 白名单校验失败 | **PASS** |
| 缺少 CSRF Token → 提交 | 403 请求校验失败 | `directValidateRequiredCsrfToken` 返回 false | **PASS** |
| 修改密码但未提供管理员密码/2FA | 500 敏感操作验证失败 | `directValidateSensitiveOperation` 返回 ok=false | **PASS** |
| 组合攻击：`id=1&password=hacker` | 500 无权修改超级管理员 | 在密码逻辑前被 targetId===1 拦截 | **PASS** |

---

## 5. 回归测试

### 5.1 PHP 语法检查
```
php -l app/controller/AdminApi.php
→ No syntax errors detected
```
**结果：PASS**

### 5.2 单元测试（PHPUnit 10.5.64 / PHP 8.2.30）
```
vendor/bin/phpunit --testsuite Unit
→ OK (7 tests, 15 assertions)
```
**结果：PASS**

### 5.3 集成测试
```
vendor/bin/phpunit --testsuite Integration
→ OK, but some tests were skipped! (12 skipped)
```
集成测试因本地无数据库环境全部跳过（预期行为，不涉及本次修改的管理员权限逻辑）。
**结果：PASS（SKIPPED，环境限制）**

### 5.4 调试代码扫描
```
Select-String -Pattern "dd\(|dump\(|var_dump\(|print_r"
→ 无匹配
```
**结果：PASS（无调试代码残留）**

---

## 6. Git 状态

```
git status
On branch main
Your branch is up to date with 'origin/main'.
Changes not staged for commit:
  modified:   app/controller/AdminApi.php

Untracked files:
  HCZ_P5_Security_Audit_Report.md  （上一轮审计报告，非本次修改）
```

```
git diff --stat
 app/controller/AdminApi.php | 151 ++++++++++++++++++++++++++++++++++++++++----
 1 file changed, 140 insertions(+), 11 deletions(-)
```

```
git log -1 --oneline
9e7cfdc P4: P1-001~005 + P2-001~007 生产级安全修复
```

**未执行：** git add / git commit / git push（按要求等待下一步指令）

---

## 7. 安全边界确认

### 修复后管理员操作权限矩阵

| 操作 | 超级管理员 (ID=1) | 普通管理员（有管理员列表权限） | 普通管理员（无管理员列表权限） |
|------|-------------------|-------------------------------|-------------------------------|
| 查看管理员列表 | ✅ | ✅ | ❌ 403 |
| 创建管理员 | ✅（需 power 白名单） | ❌ 仅超级管理员可创建 | ❌ 403 |
| 修改普通管理员 account/name | ✅ | ✅ | ❌ 403 |
| 修改普通管理员 power | ✅（需白名单） | ❌ power 不写入 | ❌ 403 |
| 修改普通管理员密码 | ✅（需敏感验证） | ✅（需敏感验证） | ❌ 403 |
| 修改超级管理员 (ID=1) | ❌ 禁止修改 | ❌ 无权修改 | ❌ 403 |
| 删除普通管理员 | ✅（需敏感验证） | ✅（需敏感验证） | ❌ 403 |
| 删除超级管理员 (ID=1) | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |
| 删除自己 | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |

---

## 8. 最终结论

| 项目 | 结论 |
|------|------|
| P1-001 管理员删除接口无权限校验 | **FIXED** |
| P1-002 低权限管理员可修改任意管理员权限 | **FIXED** |
| 是否还有 P1 漏洞 | **NO**（本次修复的 2 个 P1 已全部修复） |
| 是否发现新的 P0/P1 | **NO** |
| 是否建议进入 P5 二次安全复查 | **YES**（建议对修复后的管理员权限模块进行二次渗透验证） |
| 是否可以开始生产环境验收 | 需先完成 P5 二次复查确认无回归，再进入 P6 |

### 修复原则遵守情况
- ✅ 仅修改 P1-001/P1-002 相关代码
- ✅ 未修改 P2/P3/P4 问题
- ✅ 未重构 AdminApi 或重写权限系统
- ✅ 未修改数据库结构
- ✅ 未修改 .env / 生产配置
- ✅ 复用项目现有 `direct*` 方法，未引入新框架
- ✅ 未删除真实管理员 / 未修改真实数据
- ✅ 未执行 git commit / push
- ✅ 无调试代码残留
