# HCZ P5-P1 第三轮安全复查报告

**复查基准：** main @ 9e7cfdc（AdminApi.php 已修改，未 commit/push）  
**复查日期：** 2026-08-20  
**复查方式：** 静态代码分析 + 全仓库攻击面枚举 + 调用链分析 + 参数边界分析  
**复查范围：** P1-001、P1-002、P1-003、P2-001 修复验证 + 全仓库管理员攻击面  

---

## 1. 总体结论

**结论：PASS WITH P2**

P1-001、P1-002、P1-003、P2-001 四项修复均已闭环，无绕过路径。全仓库管理员攻击面枚举确认仅 `AdminApi.php` 的 `admin_post` 为管理员创建/修改/删除入口，`account_post` 和 `twofa_post` 仅操作当前登录管理员自己。

但在全仓库信息泄露审计中发现一个**新的 P2**：`AdminList/admin_list` 端点无权限校验且返回完整 AdminModel（含 password 哈希、salt、加密的 2FA 密钥）。此为 pre-existing 问题，非本次修复引入，但属于管理员攻击面，必须报告。

| 等级 | 数量 | 说明 |
|------|------|------|
| P0 | 0 | 无 |
| P1 | 0 | P1-001/002/003 全部修复，无新 P1 |
| **P2** | **1（新发现）** | admin_list 端点泄露管理员密码哈希/salt/2FA 密钥 |
| P3 | 0 | 无（account_post 自助改密无旧密码验证属设计选择，非漏洞） |
| P4 | 0 | 无 |

---

## 2. P1-001 复查：管理员删除接口

**结论：FIXED**

### 防护层逐项验证

| 防护层 | 代码位置 | 验证结果 |
|--------|---------|---------|
| 权限校验 | `directHasAdminPermission('管理员列表')` | PASS — 无权限返回 403，ID=1 自动通过 |
| 路径校验 | `directRequestPathMatches('admin_post/del')` | PASS — pathinfo 匹配，大小写不敏感 |
| CSRF 校验 | `directValidateRequiredCsrfToken()` | PASS — POST token / Header 双重检查，hash_equals |
| 禁止删超级管理员 | `$targetId === 1` | PASS — `(int)` 转换处理 "01"/"1.0"/数组等变体 |
| 禁止自删 | `$targetId === $currentAdminId` | PASS — currentAdminId 来自 Session，不可客户端伪造 |
| 敏感操作验证 | `directValidateSensitiveOperation($post_info, 'admin_delete')` | PASS — 2FA 或管理员密码，绑定当前操作者 |
| 目标存在性 | `AdminModel::find($targetId)` + null 检查 | PASS — 不存在返回「管理员不存在」 |
| 操作日志 | `directWriteAdminOperationLog()` | PASS — 记录目标 ID/账号/名称 |

### 绕过尝试

| 攻击向量 | 结果 |
|---------|------|
| `id[]=1`（数组） | `(int)['1']` = 1 → 触发「禁止删除超级管理员」 |
| `id=01` / `id=1.0` / `id=+1` | `(int)` 均为 1 → 拦截 |
| 缺少/错误 CSRF | 403 拦截 |
| GET 请求 | 路由定义 POST，不匹配 |
| Session 伪造 admin ID=1 | 不可行 — Session 服务端存储，AdminAuth 每次回源 DB |

### 替代删除入口

全仓库搜索 `AdminModel::destroy` / `->delete()` / `where()->delete()`：
- **仅 `AdminApi.php:4382`**（admin_post/del）一处可删除管理员
- 其他 `->delete()` 均操作 User/Order/Product 等非管理员模型

**结论：P1-001 彻底修复，无绕过路径。**

---

## 3. P1-002 复查：管理员权限修改

**结论：FIXED**

### 防护层逐项验证

| 防护点 | 验证结果 |
|--------|---------|
| 操作者边界识别 | `$isSuperAdmin = directIsCurrentAdminSuperAdmin()` — ID=1 判断 |
| 非超管禁止修改 ID=1 | `!$isSuperAdmin && $targetId === 1` → 「无权修改超级管理员」，任何字段都不能改 |
| power 白名单校验 | `directValidateAdminPowerValue()` — 16 项白名单，中英文逗号，array_diff 精确匹配 |
| 仅超管写入 power | `if ($isSuperAdmin) { $AdminModel->power = $cleanedPower; }` — 非超管提交的 power 被校验但不写入 |
| 非超管禁止创建 | 创建分支前置 `!$isSuperAdmin` → 「仅超级管理员可创建管理员」 |
| 创建时 power 白名单 | 创建分支同样调用 `directValidateAdminPowerValue()` |
| CSRF | `directValidateRequiredCsrfToken()` |

### 白名单绕过测试

| 输入 | 结果 |
|------|------|
| `power=*` | 白名单拒绝 — `*` 不在 16 项中 |
| `power=all` / `power=admin` / `power=superadmin` | 白名单拒绝 |
| `power=超级管理员` | 白名单拒绝 |
| `power=用户列表,支付管理` | 通过 — 均为合法权限 |
| `power=用户列表，支付管理`（中文逗号） | 通过 — preg_split 兼容中英文逗号 |
| `power=用户列表,,支付管理`（空项） | 通过 — array_filter 过滤空项 |
| Unicode 同形字符 | 拒绝 — 字节不同，array_diff 不匹配 |
| `power[]=用户列表`（数组参数） | `(string)$post_info['power']` → "Array" → 白名单拒绝 |

### 替代 power 修改入口

全仓库搜索 `->power =` / `['power'] =`：
- **仅 `AdminApi.php:4229`**（admin_post/add_modify，超管保护下）一处可修改管理员 power
- 其他 power 字段操作均为 User 模型或配置项

**结论：P1-002 彻底修复，无绕过路径。**

---

## 4. P1-003 复查：管理员密码修改

**结论：FIXED**

### 核心修复验证

```php
// P5-P1-003: 非超级管理员不能修改其他管理员的密码（防止账号接管）
if (!$isSuperAdmin && $targetId !== $currentAdminId && !empty($post_info['password'])) {
    return show(500, 'error', '仅超级管理员可修改其他管理员密码');
}
```

### 场景验证

| 场景 | 预期 | 实际逻辑 | 结果 |
|------|------|---------|------|
| 普通管理员改他人密码 | DENY | `!isSuperAdmin && targetId!=currentId && password非空` → 500 | **PASS** |
| 普通管理员改自己密码 | ALLOW（敏感验证） | `targetId===currentId` 跳过 P1-003 → 进入敏感验证 | **PASS** |
| 超级管理员改他人密码 | ALLOW（敏感验证） | `isSuperAdmin=true` 跳过 P1-003 → 敏感验证 → 写入 | **PASS** |
| 普通管理员改 ID=1 密码 | DENY | `!isSuperAdmin && targetId===1` 在 P1-002 层已拦截 | **PASS** |
| 普通管理员改他人 password+power | 均 DENY | P1-003 拦截 password；power 不写入 | **PASS** |
| `password=""`（空密码） | 不触发改密 | `!empty($post_info['password'])` 为 false → 跳过改密 | **PASS** |
| `password[]=xxx`（数组） | `!empty(['xxx'])` = true → 触发 P1-003 拦截（非超管改他人）；超管则 `password_hash(['xxx'].$salt)` 会报错但不构成安全漏洞 | **PASS** |

### 全仓库密码修改入口清单

| 文件 | 方法 | 修改对象 | 权限 | 是否安全 |
|------|------|---------|------|---------|
| AdminApi.php | `admin_post/add_modify` | 任意管理员 | 管理员列表 + P1-003 限制 | ✅ 安全 |
| AdminApi.php | `account_post/account` | 当前管理员自己 | 已登录 + CSRF | ✅ 安全（`where('id', $this->admin_info['id'])`） |
| AdminApi.php | `login_check` | 登录流程，非改密 | 公开 | ✅ N/A |

**不存在第二个修改其他管理员密码的入口。**

### account_post/account 说明

该接口仅修改当前登录管理员自己的账号和密码（`where('id', $this->admin_info['id'])`），有 CSRF 保护。改自己密码不要求旧密码验证，属自助服务设计，不构成越权。如需加强可增加旧密码验证，但这是 P3 增强建议，非漏洞。

**结论：P1-003 彻底修复，无替代密码修改入口。**

---

## 5. P2-001 复查：info 接口信息泄露

**结论：FIXED**

### 防护验证

| 防护点 | 验证结果 |
|--------|---------|
| 权限校验 | `directHasAdminPermission('管理员列表')` — 无权限 403 |
| 路径校验 | `directRequestPathMatches('admin_post/info')` |
| CSRF 校验 | `directValidateRequiredCsrfToken()` — 缺失 403 + 警告日志 |
| 字段白名单 | `$data = ['id','account','name','power','power_selected']` |
| 禁止返回敏感字段 | 无 `password`/`salt`/`twofa_secret`/`twofa_recovery_codes`/`twofa_status` |

### 前端兼容性

前端 `view/admin/admin.html` 第 219-222 行仅使用：`id`、`account`、`name`、`power_selected`。白名单全部覆盖，**不影响前端功能**。

### 第 3866 行 getData() 澄清

上一轮报告提到的 `AdminApi.php:3866` 的 `(array)$res->getData()`，经确认属于 `product_post/info` 分支，`$res` 是 **Product 模型**（产品信息），不是 AdminModel。返回产品的折扣配置等业务字段，**不包含管理员密码/salt/2FA**。此为上一轮的误报，已澄清。

### 其他 info 类接口

- `Admin.php` 控制器的各页面方法：均使用 `where('id', $this->admin_info['id'])` 查询自己，且返回 View 渲染（非 JSON），不泄露敏感字段
- `AdminList.php` 的各列表接口：见第 6 节新发现

**结论：P2-001（admin_post/info）彻底修复。**

---

## 6. 全仓库管理员攻击面

### 6.1 管理员创建入口

| 入口 | 文件 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| `admin_post/add_modify`（新建分支） | AdminApi.php:4256-4287 | 超级管理员 | ✅ 安全 |

### 6.2 管理员修改入口

| 入口 | 文件 | 修改对象 | 权限要求 | 是否安全 |
|------|------|---------|---------|---------|
| `admin_post/add_modify` | AdminApi.php:4180-4255 | 任意管理员 | 管理员列表 + 超管边界 + P1-003 | ✅ 安全 |
| `account_post/account` | AdminApi.php:4406-4418 | 当前管理员自己 | 已登录 + CSRF | ✅ 安全 |
| `account_post/avatar` | AdminApi.php:4419-4439 | 当前管理员自己 | 已登录 + CSRF | ✅ 安全 |
| `twofa_post/*` | AdminApi.php:3996-4145 | 当前管理员自己 | 已登录 + CSRF + ID校验 | ✅ 安全 |

### 6.3 管理员删除入口

| 入口 | 文件 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| `admin_post/del` | AdminApi.php:4341-4387 | 管理员列表 + 7层防护 | ✅ 安全 |

### 6.4 管理员权限(power)修改入口

| 入口 | 文件 | 权限要求 | 是否安全 |
|------|------|---------|---------|
| `admin_post/add_modify` | AdminApi.php:4227-4230 | 超级管理员 | ✅ 安全 |

### 6.5 管理员密码修改入口

| 入口 | 文件 | 修改对象 | 权限要求 | 是否安全 |
|------|------|---------|---------|---------|
| `admin_post/add_modify` | AdminApi.php:4236-4243 | 任意管理员 | 超管可改他人；普通仅改自己 + 敏感验证 | ✅ 安全 |
| `account_post/account` | AdminApi.php:4411-4414 | 当前管理员自己 | 已登录 + CSRF | ✅ 安全 |

### 6.6 管理员信息读取入口

| 入口 | 文件 | 权限要求 | 返回字段 | 是否安全 |
|------|------|---------|---------|---------|
| `admin_post/info` | AdminApi.php:4289-4339 | 管理员列表 + CSRF | 白名单 5 字段 | ✅ 安全（已修复） |
| **`admin_list`（DataTables AJAX）** | **AdminList.php:849-871** | **仅 AdminAuth（无特定权限）** | **完整 AdminModel 所有字段** | **⚠️ P2 泄露** |
| `Admin/admin`（页面） | Admin.php | AdminAuth | View 渲染，不直接返回 JSON | ✅ 安全 |

### 6.7 管理员 Session/Auth

| 机制 | 文件 | 是否安全 |
|------|------|---------|
| AdminAuth 中间件 | AdminAuth.php | ✅ 每次请求回源 DB 验证管理员存在且 status!=0，被删/禁用后 Session 立即失效 |
| Session 存储 | 服务端 | ✅ 客户端仅有 Session ID，不可直接修改 admin_info |
| 登录后 Session 旋转 | login_check | ✅ 调用 `rotateSessionForAdminLogin` |
| IP 变化 | AdminAuth | ⚠️ 仅日志记录，不强制登出（可接受设计） |

---

## 7. 新发现漏洞

### [P2-002] admin_list 端点泄露管理员密码哈希、salt、2FA 密钥

**位置：**
- 文件：`app/controller/AdminList.php`
- 方法：`admin_list()`
- 行号：第 849-871 行

**漏洞类型：** 敏感信息泄露 / 越权读取

**攻击条件：**
- 任意已登录管理员账号（无需「管理员列表」权限）

**攻击路径：**
```
GET /{backstage_entrance}/admin_list?draw=1&start=0&length=100
```

**当前代码逻辑：**
```php
public function admin_list()
{
    $payload = $this->listPayload();
    // ... 排序/分页/搜索 ...
    $basePar[] = ['id', '<>', 1];  // 排除超级管理员
    $par = $basePar;
    $par[] = ['name|account', 'like', '%' . $search . '%'];
    $data = AdminModel::where($par)->order(...)->limit($start, $length)->select();
    foreach($data as $key => $vo) {
        // 空循环 — 无字段过滤！
    }
    $result = ['data' => $data];
    return $this->datatablesResponse($result, $payload);
}
```

`datatablesResponse()` 对 Collection 调用 `toArray()`，而 Admin 模型**没有 `$hidden` 属性**，导致所有字段序列化到 JSON：
- `id`, `account`, `name`, `power`, `status`
- **`password`**（bcrypt 哈希）
- **`salt`**
- **`twofa_secret`**（加密存储，但密文仍泄露）
- **`twofa_recovery_codes`**（哈希存储）
- `avatar`, `create_time`, `login_time`, `login_ip` 等

**为什么存在漏洞：**
1. `admin_list` 方法**没有** `directHasAdminPermission('管理员列表')` 校验
2. AdminList 控制器仅有 `AdminAuth` 中间件，无 per-method 权限控制
3. `AdminModel::select()` 返回完整模型，无 `field()` 限制
4. foreach 循环为空，未做字段过滤
5. Admin 模型无 `$hidden` 属性
6. `datatablesResponse()` 直接 `toArray()` 序列化

**实际影响：**
- 任意低权限管理员可获取所有其他管理员（除 ID=1）的密码哈希和 salt
- 可离线 GPU 暴力破解 bcrypt 哈希（成本较高但可行）
- 加密的 2FA 密钥密文泄露，若 APP_KEY 泄露可解密生成有效 2FA 码
- 辅助定向攻击（知道哪些管理员有哪些权限）

**为什么判定为 P2：**
- 需要已认证管理员（非远程未授权）
- bcrypt 哈希提供较强保护
- 2FA 密钥加密存储
- 不直接导致权限提升，需配合离线破解
- 超级管理员 ID=1 被排除

**修复建议（仅报告，本轮不修改）：**
1. 添加 `directHasAdminPermission('管理员列表')` 校验
2. 使用 `field('id','account','name','power','status','create_time')` 限制查询字段
3. 或在 Admin 模型添加 `$hidden = ['password','salt','twofa_secret','twofa_recovery_codes']`
4. 推荐方案：同时做权限校验 + field 限制（纵深防御）

**是否阻断发布：** 建议发布前修复，但不阻断进入 P6（P6 为生产环境验收流程，此为代码级问题可并行修复）。

---

## 8. 管理员权限最终矩阵

| 操作 | 超级管理员 (ID=1) | 普通管理员（有管理员列表） | 普通管理员（无管理员列表） |
|------|-------------------|--------------------------|--------------------------|
| 查看管理员列表 (admin_list) | ✅ | ✅ | ⚠️ **可访问（P2-002，无权限校验）** |
| 查看管理员详情 (info) | ✅ | ✅ | ❌ 403 |
| 创建管理员 | ✅（power 白名单） | ❌ 仅超管可创建 | ❌ 403 |
| 修改普通管理员 account/name | ✅ | ✅ | ❌ 403 |
| 修改普通管理员 power | ✅（白名单） | ❌ power 不写入 | ❌ 403 |
| 修改普通管理员密码 | ✅（需敏感验证） | ❌ 仅超管可改他人密码 | ❌ 403 |
| 修改自己密码 | ✅（需敏感验证） | ✅（需敏感验证） | ✅（account_post，需 CSRF） |
| 修改超级管理员 (ID=1) | ❌ 禁止修改 | ❌ 无权修改 | ❌ 403 |
| 删除普通管理员 | ✅（需敏感验证） | ✅（需敏感验证） | ❌ 403 |
| 删除超级管理员 (ID=1) | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |
| 删除自己 | ❌ 禁止删除 | ❌ 禁止删除 | ❌ 403 |
| 操作自己 2FA | ✅ | ✅ | ✅ |
| 操作他人 2FA | ❌ | ❌ | ❌ |

---

## 9. 回归检查

| 检查项 | 命令/方式 | 结果 |
|--------|----------|------|
| PHP Syntax | `php -l app/controller/AdminApi.php` | **PASS** |
| Unit Tests | `vendor/bin/phpunit --testsuite Unit` | **PASS**（7 tests, 15 assertions） |
| Integration Tests | `vendor/bin/phpunit --testsuite Integration` | **SKIPPED**（12 tests，本地无数据库） |
| Route 枚举 | 读取 route/app.php | **PASS** — 管理员路由仅 admin_post/account_post/twofa_post/admin_list |
| AdminAuth 审查 | 读取 middleware/AdminAuth.php | **PASS** — 每次回源 DB 验证 |
| 敏感字段搜索 | grep password/salt/twofa_secret in AdminModel 返回 | **PASS** — info 已白名单；admin_list 仍泄露（P2-002） |
| getData/toArray 搜索 | 全仓库搜索 | **PASS** — admin_post/info 已移除；admin_list 仍使用（P2-002） |
| Debug Code | grep dd/dump/var_dump | **PASS**（0 匹配） |
| Git Diff | `git diff --stat` | **PASS** — 仅 AdminApi.php（+143/-16） |
| Git Status | `git status` | **PASS** — 未 commit/push |

---

## 10. 最终发布建议

### Q1-Q14 明确回答

| # | 问题 | 回答 |
|---|------|------|
| Q1 | P1-001 是否真正彻底修复？ | **是** — 7 层防护有效，无替代删除入口 |
| Q2 | P1-002 是否真正彻底修复？ | **是** — 仅超管可写 power，白名单严格，无替代入口 |
| Q3 | P1-003 是否真正彻底修复？ | **是** — 非超管不能改他人密码，account_post 仅改自己，无替代入口 |
| Q4 | P2-001 是否真正彻底修复？ | **是** — info 接口已加权限/CSRF/字段白名单 |
| Q5 | 是否存在其他管理员密码修改入口？ | **否** — 仅 admin_post/add_modify（受保护）和 account_post（仅自己） |
| Q6 | 是否存在其他管理员 power 修改入口？ | **否** — 仅 admin_post/add_modify（超管保护） |
| Q7 | 是否存在其他管理员删除入口？ | **否** — 仅 admin_post/del（7 层防护） |
| Q8 | 是否存在其他管理员创建入口？ | **否** — 仅 admin_post/add_modify（超管限制） |
| Q9 | AdminApi.php 约第 3866 行 getData() 是否泄露？ | **否** — 该 getData() 属于 Product 模型（product_post/info），非管理员数据 |
| Q10 | 是否存在新的 P0？ | **否** |
| Q11 | 是否存在新的 P1？ | **否** |
| Q12 | 是否存在新的 P2？ | **是** — P2-002：admin_list 端点泄露管理员密码哈希/salt/2FA 密钥 |
| Q13 | 是否可以进入 P6？ | **可以** — P0=0, P1=0, 无明显可利用的管理员接管路径。P2-002 为信息泄露，建议修复但不阻断 P6 流程 |
| Q14 | 是否可以 commit / push？ | **建议先修复 P2-002 再 commit**；如必须 commit，应明确标注存在已知 P2-002 |

### 发布建议

1. **P1 全部清零** — P1-001/002/003 修复闭环，无绕过路径
2. **P2-002 建议修复后发布** — admin_list 信息泄露，修复成本低（加权限校验 + field 限制）
3. **可以进入 P6** — 生产环境验收可与 P2-002 修复并行
4. **commit/push 时机** — 建议将 P2-002 一并修复后再 commit，避免已知漏洞进入版本历史

---

## 11. 审计方法论说明

本次复查采用攻击者视角，执行了以下分析：
- 全仓库 AdminModel 操作点枚举（create/save/destroy/delete/power=/password=）
- 路由表完整枚举（确认管理员相关路由）
- 替代入口搜索（Controller/Service/Model/Middleware/Command/Job）
- 参数类型边界分析（`(int)` 转换、数组参数、空值、类型混淆）
- Session/Auth 机制审查（AdminAuth 回源验证、Session 旋转）
- 敏感操作验证绑定分析（directValidateSensitiveOperation 绑定当前操作者）
- 信息泄露路径搜索（getData/toArray/field/模型 $hidden）
- 前端字段使用确认（admin.html 实际使用字段）

**未执行：** 真实 HTTP 请求测试、生产环境操作、数据库修改、代码修改。
