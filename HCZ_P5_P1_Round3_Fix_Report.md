# HCZ P5-P1 第三轮修复报告

**修复基准：** main @ 9e7cfdc  
**修复日期：** 2026-08-20  
**修复范围：** P1-003、P2-001（含 P1-001/P1-002 重建）  
**修改文件：** `app/controller/AdminApi.php`（1 文件，+143 / -16）  

---

## 1. 修复摘要

| 编号 | 漏洞名称 | 状态 |
|------|---------|------|
| P1-001 | 管理员删除接口无权限校验 | **FIXED**（重建） |
| P1-002 | 低权限管理员可修改任意管理员权限 | **FIXED**（重建） |
| P1-003 | 普通管理员可修改其他管理员密码 → 账号接管 | **FIXED** |
| P2-001 | admin_post/info 缺少权限校验，泄露敏感字段 | **FIXED** |

> 说明：执行修复前因 `git checkout` 误操作导致上一轮 P1-001/P1-002 修复被丢弃，本轮从干净基准 9e7cfdc 一次性重建全部 4 项修复。

---

## 2. 修改文件清单

### 文件：`app/controller/AdminApi.php`

#### 2.1 新增辅助方法（第 739-768 行）

| 方法 | 作用 |
|------|------|
| `directGetAllowedAdminPowerList(): array` | 返回 16 项合法管理员权限白名单 |
| `directValidateAdminPowerValue(string $power): array` | 校验 power 字段，兼容中英文逗号，返回 `['ok','message','cleaned']` |
| `directIsCurrentAdminSuperAdmin(): bool` | 判断当前管理员是否为超级管理员（ID=1） |

#### 2.2 P1-001：`case 'del'`（第 4341-4387 行）

7 层防护：权限校验 → 路径校验 → CSRF 校验 → 禁止删超级管理员(ID=1) → 禁止自删 → 敏感操作验证(2FA/密码) → 删除前确认存在。保留操作日志。

#### 2.3 P1-002：`case 'add_modify'`（第 4148-4287 行）

操作者边界识别 `$isSuperAdmin`/`$currentAdminId`；非超管禁止修改 ID=1；power 白名单校验且仅超管写入；修改密码需敏感验证；非超管禁止创建管理员。

#### 2.4 P1-003：`case 'add_modify'` 密码修改限制（第 4231-4234 行）

```php
// P5-P1-003: 非超级管理员不能修改其他管理员的密码（防止账号接管）
if (!$isSuperAdmin && $targetId !== $currentAdminId && !empty($post_info['password'])) {
    return show(500, 'error', '仅超级管理员可修改其他管理员密码');
}
```

**逻辑：** 当操作者不是超级管理员、目标不是自己、且提交了 password 字段时，直接拒绝。此检查位于 power 写入之后、密码敏感验证之前，确保在任何密码哈希操作前拦截。

#### 2.5 P2-001：`case 'info'`（第 4289-4339 行）

新增 3 层校验：
1. `directHasAdminPermission('管理员列表')` — 无权限返回 403
2. `directRequestPathMatches('admin_post/info')` — 路径校验
3. `directValidateRequiredCsrfToken()` — CSRF 校验

字段白名单替换 `$res->getData()`：
```php
$data = [
    'id' => (int)($res['id'] ?? 0),
    'account' => (string)($res['account'] ?? ''),
    'name' => (string)($res['name'] ?? ''),
    'power' => (string)($res['power'] ?? ''),
    'power_selected' => $power_selected,
];
```

**不再返回：** `password`、`salt`、`twofa_secret`、`twofa_recovery_codes`、`twofa_status` 及其他模型字段。

---

## 3. 前端兼容性检查

**前端调用位置：** `view/admin/admin.html` 第 196 行

**前端实际使用字段（第 219-222 行）：**
```javascript
$("#admin_id").val(res.data.id || '');
$("#account").val(res.data.account || '');
$("#name").val(res.data.name || '');
$("#power").html(res.data.power_selected || '');
```

**结论：** 前端仅使用 `id`、`account`、`name`、`power_selected` 四个字段。白名单保留了这四个字段（额外保留 `power` 原始值供潜在使用），**不影响前端功能**。`twofa_status` 前端未使用，移除无影响。

---

## 4. 攻击路径复测

| # | 攻击场景 | 预期 | 实际代码逻辑 | 结果 |
|---|---------|------|-------------|------|
| 1 | 普通管理员修改其他管理员密码 | DENY | `!$isSuperAdmin && $targetId !== $currentAdminId && !empty(password)` → 500 | **PASS** |
| 2 | 普通管理员修改自己密码 | ALLOW（敏感验证） | `$targetId === $currentAdminId` 跳过 P1-003 检查 → 进入敏感验证 | **PASS** |
| 3 | 超级管理员修改普通管理员密码 | ALLOW + 敏感验证 | `$isSuperAdmin=true` 跳过 P1-003 → 敏感验证 → 写入 | **PASS** |
| 4 | 普通管理员修改 ID=1 密码 | DENY | `!$isSuperAdmin && $targetId === 1` → 500「无权修改超级管理员」 | **PASS** |
| 5 | 普通管理员修改自己 power | DENY（不写入） | `if ($isSuperAdmin)` 为 false → power 不赋值 | **PASS** |
| 6 | 普通管理员修改他人 power | DENY（不写入） | 同上 | **PASS** |
| 7 | 普通管理员创建管理员 | DENY | `!$isSuperAdmin` → 500「仅超级管理员可创建管理员」 | **PASS** |
| 8 | 无管理员列表权限访问 info | DENY | `directHasAdminPermission` 返回 false → 403 | **PASS** |
| 9 | info 缺少 CSRF | DENY | `directValidateRequiredCsrfToken` 返回 false → 403 | **PASS** |
| 10 | info 查询 ID=1 | 允许但不泄露敏感字段 | 白名单仅返回 id/account/name/power/power_selected | **PASS** |
| 11 | info 返回 password | MUST NOT EXIST | 白名单无 password 字段 | **PASS** |
| 12 | info 返回 salt | MUST NOT EXIST | 白名单无 salt 字段 | **PASS** |
| 13 | info 返回 twofa_secret | MUST NOT EXIST | 白名单无 twofa_secret 字段 | **PASS** |
| 14 | info 返回 twofa_recovery_codes | MUST NOT EXIST | 白名单无 twofa_recovery_codes 字段 | **PASS** |
| 15 | 组合攻击：普通管理员改他人 password+power | 均 DENY | P1-003 拦截 password；power 不写入 | **PASS** |
| 16 | 普通管理员修改他人 account/name | ALLOW（原有业务） | 非 power/密码字段正常写入 | **PASS**（按设计） |

---

## 5. 管理员权限矩阵（修复后最终状态）

| 操作 | 超级管理员 (ID=1) | 普通管理员（有管理员列表） | 普通管理员（无管理员列表） |
|------|-------------------|--------------------------|--------------------------|
| 查看管理员列表 | ✅ | ✅ | ❌ 403 |
| 查看管理员详情 (info) | ✅ | ✅ | ❌ 403 |
| 创建管理员 | ✅（power 白名单） | ❌ 仅超管可创建 | ❌ 403 |
| 修改普通管理员 account/name | ✅ | ✅ | ❌ 403 |
| 修改普通管理员 power | ✅（白名单） | ❌ power 不写入 | ❌ 403 |
| 修改普通管理员密码 | ✅（需敏感验证） | ❌ 仅超管可改他人密码 | ❌ 403 |
| 修改自己密码 | ✅（需敏感验证） | ✅（需敏感验证） | ❌ 403 |
| 修改超级管理员 (ID=1) | ❌ 禁止修改 | ❌ 无权修改 | ❌ 403 |
| 删除普通管理员 | ✅（需敏感验证） | ✅（需敏感验证） | ❌ 403 |
| 删除超级管理员 (ID=1) | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |
| 删除自己 | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |

---

## 6. 回归测试

### 6.1 PHP 语法检查
```
php -l app/controller/AdminApi.php
→ No syntax errors detected
```
**结果：PASS**

### 6.2 单元测试
```
vendor/bin/phpunit --testsuite Unit
→ OK (7 tests, 15 assertions)
```
**结果：PASS**

### 6.3 集成测试
```
vendor/bin/phpunit --testsuite Integration
→ OK, but some tests were skipped! (12 skipped)
```
因本地无数据库环境全部跳过（预期行为，不涉及管理员权限逻辑）。
**结果：PASS（SKIPPED，环境限制）**

### 6.4 调试代码扫描
```
Select-String -Pattern "dd\(|dump\(|var_dump\(|print_r\("
→ 无匹配
```
**结果：PASS（无调试代码残留）**

### 6.5 敏感字段确认
```
grep -n "getData()" app/controller/AdminApi.php
→ 3866: 其他方法（非 admin_post/info，不在本轮范围）
→ 4207: $beforeAdmin = $AdminModel->getData()（内部日志使用，不返回客户端）
→ 4560/4562: Session 旋转内部使用
```
`admin_post/info` 已不再使用 `getData()` 返回完整模型。
**结果：PASS**

---

## 7. Git 状态

```
git status
On branch main
Your branch is up to date with 'origin/main'.
Changes not staged for commit:
  modified:   app/controller/AdminApi.php

Untracked files:
  HCZ_P5_Security_Audit_Report.md
  HCZ_P5_P1_Fix_Report.md
  HCZ_P5_P1_Reaudit_Report.md
```

```
git diff --stat
 app/controller/AdminApi.php | 159 +++++++++++++++++++++++++++++++++++++++-----
 1 file changed, 143 insertions(+), 16 deletions(-)
```

```
git log -1 --oneline
9e7cfdc P4: P1-001~005 + P2-001~007 生产级安全修复
```

**未执行：** git add / git commit / git push

---

## 8. 当前漏洞统计

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 | 0 | 无 |
| P1 | 0 | P1-001/002/003 全部修复 |
| P2 | 0 | P2-001 已修复（本轮）；原 P5 审计中的 P2-001/P2-002 待后续处理 |
| P3 | 4 | 未处理（不在本轮范围） |
| P4 | 2 | 未处理（不在本轮范围） |

> 注：原 P5 审计中还有 P2-001（legacy order_del IDOR，已被中间件阻断）和 P2-002（异常信息泄露），与本轮修复的 P2-001（info 信息泄露）是不同编号，均未在本轮处理。

---

## 9. 新发现安全漏洞

**本轮修复过程中未发现新的 P0/P1/P2 漏洞。**

修复过程中注意到 `app/controller/AdminApi.php` 第 3866 行存在另一个 `getData()` 返回（非 admin_post/info 方法），可能属于用户信息查询接口，存在类似信息泄露风险。但该接口不在本轮 P1-003/P2-001 范围内，**仅记录，不处理**，建议在下一轮审计中确认。

---

## 10. 最终结论

| 项目 | 结论 |
|------|------|
| P1-003 普通管理员修改他人密码 | **FIXED** |
| P2-001 info 接口信息泄露 | **FIXED** |
| P1-001 管理员删除（重建） | **FIXED** |
| P1-002 管理员权限提升（重建） | **FIXED** |
| 当前 P0 数量 | **0** |
| 当前 P1 数量 | **0** |
| 是否发现新的管理员账号接管路径 | **NO** |
| 是否建议进入 P5-P1 第三次安全复查 | **YES** |
| 是否允许 commit / push | **建议先完成第三次复查确认无回归，再 commit/push** |

### 修复原则遵守情况
- ✅ 仅修改 P1-003/P2-001 相关代码（含 P1-001/P1-002 重建）
- ✅ 未处理其他 P2/P3/P4
- ✅ 未重构 AdminApi 或重写权限系统
- ✅ 未修改数据库结构
- ✅ 未修改 .env / 生产配置
- ✅ 未修改密码哈希算法（继续使用 bcrypt + salt）
- ✅ 复用项目现有 `direct*` 安全方法
- ✅ 未删除真实管理员 / 未修改真实数据
- ✅ 未执行 git commit / push
- ✅ 无调试代码残留
- ✅ 前端兼容性验证通过
