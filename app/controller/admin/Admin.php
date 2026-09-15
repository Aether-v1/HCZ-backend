<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Admin as AdminModel;
use app\service\AdminOperationLogService;
use app\service\AdminSensitiveOperationGuard;
use app\service\AuthorizationService;
use think\db\exception\DbException;
use think\facade\Log;

/**
 * 管理员管理控制器（B09 迁移）
 *
 * 承载 AdminApi::admin_post 迁移出的 3 个非资金 action：
 *   add_modify / info / del
 *
 * 职责链：HTTP → AdminAuth → CSRF(handler) → RBAC → Sensitive Guard(需要时)
 *         → AdminModel CRUD → Operation Log → Response
 *
 * 行为等价约束（与 OLD AdminApi::admin_post 完全一致）：
 * - RBAC：add_modify=admin.admin.delete / info=admin.admin.manage / del=admin.admin.manage（既有 P3，保持不修改）
 * - 无事务 / 无锁
 * - info 无操作日志
 * - 双路径兼容：admin_post/{action} 与 admin/admin/{action}
 * - 复用 AdminSensitiveOperationGuard / AuthorizationService / AdminOperationLogService（均不改）
 */
class Admin extends \app\BaseController
{
    protected array $middleware = [
        AdminAuth::class,
    ];

    public function admin_post(string $action)
    {
        $postInfo = $this->request->post();
        try {
            switch ($action) {
                case 'add_modify':
                    return $this->addModify($postInfo);
                case 'info':
                    return $this->info($postInfo);
                case 'del':
                    return $this->deleteOne($postInfo);
                default:
                    return show(500, 'error', '你不对劲');
            }
        } catch (DbException $e) {
            return show(500, 'error', $e->getMessage());
        }
    }

    /** 双路径兼容：OLD admin_post/{action} 与 NEW admin/admin/{action} */
    private function pathAllowed(string $action): bool
    {
        return $this->directRequestPathMatches('admin_post/' . $action)
            || $this->directRequestPathMatches('admin/admin/' . $action);
    }

    /** 复用 B08 Guard（Frozen，不修改） */
    private function sensitiveGuard(): AdminSensitiveOperationGuard
    {
        return app(AdminSensitiveOperationGuard::class);
    }

    /** 当前登录管理员 ID（Session 来源，与 AdminAuth 同源） */
    private function currentAdminId(): int
    {
        return (int)($this->currentAdminIdentity()['id'] ?? 0);
    }

    /** 是否超级管理员：复用 AuthorizationService（不复制实现；fail-closed） */
    private function isCurrentAdminSuperAdmin(): bool
    {
        return (new AuthorizationService())->isSuperAdmin($this->currentAdminId());
    }

    /** 16 项中文权限白名单（与 OLD directGetAllowedAdminPowerList 逐字一致） */
    private function powerList(): array
    {
        return [
            "用户列表", "支付管理", "充值业务 - 产品列表", "查询业务 - 产品列表",
            "充值业务 - 订单列表", "查询业务 - 订单列表", "交易挂单数据", "交易订单数据",
            "充值订单记录", "提现订单记录", "返佣记录", "首页轮播图",
            "积分管理", "管理员列表", "操作记录", "系统设置管理"
        ];
    }

    /** power 白名单校验（与 OLD directValidateAdminPowerValue 等价） */
    private function validatePowerValue(string $power): array
    {
        $allowed = $this->powerList();
        if (trim($power) === '') {
            return ['ok' => true, 'message' => '', 'cleaned' => ''];
        }
        $items = preg_split('/[,，]/', $power);
        $items = array_map('trim', $items);
        $items = array_filter($items, function ($v) { return $v !== ''; });
        $items = array_values($items);
        $invalid = array_diff($items, $allowed);
        if (!empty($invalid)) {
            return ['ok' => false, 'message' => '包含非法权限项：' . implode('、', $invalid), 'cleaned' => ''];
        }
        return ['ok' => true, 'message' => '', 'cleaned' => implode(',', $items)];
    }

    /** 操作日志：复用 AdminOperationLogService（不复制实现） */
    private function writeAdminLog(string $action, string $module, string $content, array $options = []): void
    {
        app(AdminOperationLogService::class)->record($action, $module, $content, array_merge([
            'admin' => $this->currentAdminIdentity(),
        ], $options));
    }

    // ============ add_modify ============
    private function addModify(array $postInfo)
    {
        // OLD: authorize('admin.admin.delete')
        if (!$this->authorize('admin.admin.delete')) {
            return $this->directDenyAdminPermission('admin.admin.delete');
        }
        if (!$this->pathAllowed('add_modify')) {
            return show(403, 'error', '管理员请求路径错误');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin add_modify invalid csrf blocked', [
                'admin_id' => $this->currentAdminId(),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '管理员请求校验失败');
        }
        // P5-P1-002: 操作者权限边界
        $isSuperAdmin = $this->isCurrentAdminSuperAdmin();
        $currentAdminId = $this->currentAdminId();
        $AdminModel = AdminModel::find($postInfo['id']);
        if (empty($postInfo['account'])) {
            return show(500, 'error', '请输入登录账号');
        }
        if (empty($postInfo['name'])) {
            return show(500, 'error', '请输入管理员名称');
        }
        $salt = randomkeys(4);
        if ($AdminModel) {
            $beforeAdmin = $AdminModel->getData();
            $targetId = (int)$AdminModel['id'];
            // P5-P1-002: 非超级管理员禁止修改超级管理员
            if (!$isSuperAdmin && $targetId === 1) {
                return show(500, 'error', '无权修改超级管理员');
            }
            // P5-P1-002: power 白名单校验
            $powerResult = $this->validatePowerValue((string)($postInfo['power'] ?? ''));
            if (empty($powerResult['ok'])) {
                return show(500, 'error', (string)($powerResult['message'] ?? '权限校验失败'));
            }
            $cleanedPower = (string)($powerResult['cleaned'] ?? '');
            if ($postInfo['account'] != $AdminModel['account']) {
                $AdminModels = AdminModel::where('account', $postInfo['account'])->find();
                if ($AdminModels) {
                    return show(500, 'error', '登录账号已存在，请修改');
                }
            }
            $AdminModel->account = $postInfo['account'];
            $AdminModel->name = $postInfo['name'];
            // P5-P1-002: 仅超级管理员可修改 power 字段，防止自我提权和横向提权
            if ($isSuperAdmin) {
                $AdminModel->power = $cleanedPower;
            }
            // P5-P1-003: 非超级管理员不能修改其他管理员的密码（防止账号接管）
            if (!$isSuperAdmin && $targetId !== $currentAdminId && !empty($postInfo['password'])) {
                return show(500, 'error', '仅超级管理员可修改其他管理员密码');
            }
            // P5-P1-002: 修改密码需敏感操作二次验证
            if (!empty($postInfo['password'])) {
                $sensitiveResult = $this->sensitiveGuard()->verifySensitiveOperation($postInfo, 'admin_password_change');
                if (empty($sensitiveResult['ok'])) {
                    return show(500, 'error', (string)($sensitiveResult['message'] ?? '敏感操作验证失败'));
                }
                $AdminModel->password = password_hash(($postInfo['password'] . $salt), PASSWORD_BCRYPT);
                $AdminModel->salt = $salt;
            }
            $AdminModel->save();
            $powerChangedText = $isSuperAdmin
                ? ('，权限：' . (string)($beforeAdmin['power'] ?? '无') . ' -> ' . $cleanedPower)
                : '';
            $this->writeAdminLog('修改管理员', '管理员管理', '管理员ID：' . (int)($AdminModel['id'] ?? 0) . '，账号：' . (string)($beforeAdmin['account'] ?? '') . ' -> ' . (string)$postInfo['account'] . '，名称：' . (string)($beforeAdmin['name'] ?? '') . ' -> ' . (string)$postInfo['name'] . $powerChangedText . (!empty($postInfo['password']) ? '，密码：已重置' : ''), [
                'target_id' => (int)($AdminModel['id'] ?? 0),
                'target_type' => 'admin',
            ]);
            return show(200, 'success', '修改成功');
        }
        // P5-P1-002: 仅超级管理员可创建新管理员
        if (!$isSuperAdmin) {
            return show(500, 'error', '仅超级管理员可创建管理员');
        }
        if (empty($postInfo['password'])) {
            return show(500, 'error', '请输入登录密码');
        }
        $AdminModel = AdminModel::where('account', $postInfo['account'])->find();
        if ($AdminModel) {
            return show(500, 'error', '登录账号已存在，请修改');
        }
        // P5-P1-002: 新建管理员 power 白名单校验
        $powerResult = $this->validatePowerValue((string)($postInfo['power'] ?? ''));
        if (empty($powerResult['ok'])) {
            return show(500, 'error', (string)($powerResult['message'] ?? '权限校验失败'));
        }
        $cleanedPower = (string)($powerResult['cleaned'] ?? '');
        AdminModel::create([
            'account' => $postInfo['account'],
            'password' => password_hash(($postInfo['password'] . $salt), PASSWORD_BCRYPT),
            'salt' => $salt,
            'name' => $postInfo['name'],
            'power' => $cleanedPower,
            // 新增：默认禁用2FA
            'twofa_enabled' => 0,
            'twofa_secret' => null,
            'twofa_recovery_codes' => null
        ]);
        $newAdmin = AdminModel::where('account', $postInfo['account'])->find();
        $this->writeAdminLog('新增管理员', '管理员管理', '新增管理员账号：' . (string)$postInfo['account'] . '，名称：' . (string)$postInfo['name'] . '，权限：' . $cleanedPower, [
            'target_id' => (int)($newAdmin['id'] ?? 0),
            'target_type' => 'admin',
        ]);
        return show(200, 'success', '添加成功');
    }

    // ============ info ============
    private function info(array $postInfo)
    {
        // P2-001: 权限校验
        if (!$this->authorize('admin.admin.manage')) {
            return $this->directDenyAdminPermission('管理员列表');
        }
        // P2-001: 路径校验
        if (!$this->pathAllowed('info')) {
            return show(403, 'error', '管理员请求路径错误');
        }
        // P2-001: CSRF 校验
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin info invalid csrf blocked', [
                'admin_id' => $this->currentAdminId(),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '管理员请求校验失败');
        }
        $id = (int)($postInfo['id'] ?? 0);
        if ($id <= 0) {
            return show(500, 'error', '参数错误');
        }

        $res = AdminModel::find($id);
        if (!$res) {
            return show(500, 'error', '管理员不存在');
        }

        $street = $this->powerList();

        $powerValue = (string)($res['power'] ?? '');
        $power_selected = '';
        foreach ($street as $name) {
            $selected = (strpos($powerValue, $name) !== false) ? 'selected' : '';
            $power_selected .= "<option value=\"{$name}\" {$selected}>{$name}</option>";
        }

        // P2-001: 字段白名单，禁止返回 password/salt/twofa_secret/twofa_recovery_codes 等敏感字段
        $data = [
            'id' => (int)($res['id'] ?? 0),
            'account' => (string)($res['account'] ?? ''),
            'name' => (string)($res['name'] ?? ''),
            'power' => (string)($res['power'] ?? ''),
            'power_selected' => $power_selected,
        ];

        return show(200, 'success', '获取信息成功', $data);
    }

    // ============ del ============
    private function deleteOne(array $postInfo)
    {
        // P5-P1-001: 权限校验
        if (!$this->authorize('admin.admin.manage')) {
            return $this->directDenyAdminPermission('管理员列表');
        }
        // P5-P1-001: 路径校验
        if (!$this->pathAllowed('del')) {
            return show(403, 'error', '管理员请求路径错误');
        }
        // P5-P1-001: CSRF 校验
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin del invalid csrf blocked', [
                'admin_id' => $this->currentAdminId(),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '管理员请求校验失败');
        }
        $targetId = (int)($postInfo['id'] ?? 0);
        $currentAdminId = $this->currentAdminId();
        // P5-P1-001: 禁止删除超级管理员
        if ($targetId === 1) {
            return show(500, 'error', '禁止删除超级管理员');
        }
        // P5-P1-001: 禁止删除自己
        if ($targetId === $currentAdminId) {
            return show(500, 'error', '禁止删除当前登录账号');
        }
        if ($targetId <= 0) {
            return show(500, 'error', '参数错误');
        }
        // P5-P1-001: 敏感操作二次验证
        $sensitiveResult = $this->sensitiveGuard()->verifySensitiveOperation($postInfo, 'admin_delete');
        if (empty($sensitiveResult['ok'])) {
            return show(500, 'error', (string)($sensitiveResult['message'] ?? '敏感操作验证失败'));
        }
        // P5-P1-001: 删除前确认目标存在
        $deleteAdmin = AdminModel::find($targetId);
        if (!$deleteAdmin) {
            return show(500, 'error', '管理员不存在');
        }
        AdminModel::destroy($targetId);
        $this->writeAdminLog('删除管理员', '管理员管理', '删除管理员ID：' . (int)($deleteAdmin['id'] ?? 0) . '，账号：' . (string)($deleteAdmin['account'] ?? '') . '，名称：' . (string)($deleteAdmin['name'] ?? ''), [
            'target_id' => (int)($deleteAdmin['id'] ?? 0),
            'target_type' => 'admin',
        ]);
        return show(200, 'success', '删除成功');
    }
}
