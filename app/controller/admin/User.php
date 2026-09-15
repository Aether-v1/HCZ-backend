<?php
// HCZ B08: Admin 域控制器。user_post 非资金 action 迁移（password/status_switch/twofa_unbind/rights/del/dels）。
// balance 保持 Frozen 留在 AdminApi（本控制器无 balance case，default 拒绝，不得新增）。
// 安全原语走 AdminSensitiveOperationGuard（不复制 AdminApi 私有实现）；
// 用户行锁走 UserService::lockById（SSOT，不复制 directLockUser）；
// 删除前检查走 UserService::assertNoPendingBusiness（只读风险闸，顺序与 OLD 一致）；
// 操作日志统一走 AdminOperationLogService::record()（不复制 AdminApi 私有实现）。
namespace app\controller\admin;

use app\middleware\AdminAuth;
use app\model\Substation;
use app\model\User as UserModel;
use app\service\AdminOperationLogService;
use app\service\AdminSensitiveOperationGuard;
use app\service\UserService;
use think\facade\Db;
use think\facade\Log;
use Exception;

class User extends \app\BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）。
    protected array $middleware = [AdminAuth::class];

    public function user_post(string $action)
    {
        $post_info = $this->request->post();
        switch ($action) {
            case 'password':
                return $this->password($post_info);
            case 'status_switch':
                return $this->statusSwitch($post_info);
            case 'twofa_unbind':
                return $this->twofaUnbind($post_info);
            case 'rights':
                return $this->rights($post_info);
            case 'dels':
                return $this->batchDelete($post_info);
            case 'del':
                return $this->deleteOne($post_info);
            default:
                return show(500, 'error', '你不对劲');
        }
    }

    /**
     * OLD AdminApi::handleUserPassword 行为等价。
     * 权限码 admin.user.password.reset；CSRF；sensitive scene=user_password_reset；事务+行锁；password_hash；commit 后日志。
     */
    private function password(array $post_info)
    {
        if (!$this->authorize('admin.user.password.reset')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post password invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '密码重置请求校验失败');
        }
        $sensitiveValidation = $this->sensitiveGuard()->verifySensitiveOperation((array)$post_info, 'user_password_reset');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        $uid = (int)($post_info['uid'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }
        $newPassword = (string)($post_info['password'] ?? '');
        if ($newPassword === '') {
            return show(500, 'error', '新密码不能为空');
        }

        try {
            Db::startTrans();
            $user_info = $this->userService()->lockById($uid);
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $salt = randomkeys(4);
            $user_info->password = password_hash(($newPassword . $salt), PASSWORD_BCRYPT);
            $user_info->salt = $salt;
            $user_info->save();
            Db::commit();

            $this->writeAdminLog('重置用户密码', '用户管理', '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，已由管理员重置登录密码', [
                'target_id' => (int)$user_info['id'],
                'target_type' => 'user',
            ]);

            return show(200, 'success', '修改成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post password error: ' . $e->getMessage(), [
                'uid' => $uid,
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            ]);
            return show(500, 'error', '修改失败');
        }
    }

    /**
     * OLD AdminApi::handleUserStatusSwitch 行为等价。
     * 权限码 admin.user.manage；CSRF；事务+行锁；0↔1 翻转；commit 后日志。不新增 sensitive verification。
     */
    private function statusSwitch(array $post_info)
    {
        if (!$this->authorize('admin.user.manage')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post status_switch invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '状态切换请求校验失败');
        }

        $uid = (int)($post_info['uid'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        try {
            Db::startTrans();
            $res = $this->userService()->lockById($uid);
            if (!$res) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            $oldStatus = (int)$res['status'];
            $newStatus = ($oldStatus === 0) ? 1 : 0;
            $res->status = $newStatus;
            $res->save();
            Db::commit();

            $this->writeAdminLog('切换用户状态', '用户管理', '用户UID：' . (int)$res['id'] . '，账号：' . (string)($res['mobile'] ?? '') . '，状态：' . ($oldStatus === 0 ? '禁用' : '启用') . ' -> ' . ($newStatus === 0 ? '禁用' : '启用'), [
                'target_id' => (int)$res['id'],
                'target_type' => 'user',
            ]);

            return show(200, 'success', '状态更新成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post status_switch error: ' . $e->getMessage(), [
                'uid' => $uid,
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            ]);
            return show(500, 'error', '状态更新失败');
        }
    }

    /**
     * OLD AdminApi::handleUserTwofaUnbind 行为等价。
     * 权限码 admin.user.manage；CSRF；path 双路径（user_post/admin/user）；sensitive scene=user_twofa_unbind；
     * 事务+行锁；清空 3 个 2FA 字段；commit 后日志。
     */
    private function twofaUnbind(array $post_info)
    {
        if (!$this->authorize('admin.user.manage')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post twofa_unbind invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '解绑请求校验失败');
        }
        if (!$this->pathAllowed('twofa_unbind')) {
            Log::warning('admin user_post twofa_unbind invalid path blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }

        $sensitiveValidation = $this->sensitiveGuard()->verifySensitiveOperation((array)$post_info, 'user_twofa_unbind');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        try {
            Db::startTrans();
            $user_info = $this->userService()->lockById((int)($post_info['uid'] ?? 0));
            if (!$user_info) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }

            if (empty($user_info['twofa_enabled']) && empty($user_info['twofa_secret']) && empty($user_info['twofa_recovery_codes'])) {
                Db::rollback();
                return show(500, 'error', '该用户未启用2FA');
            }

            $user_info->twofa_enabled = 0;
            $user_info->twofa_secret = null;
            $user_info->twofa_recovery_codes = null;
            $user_info->save();

            Db::commit();

            $this->writeAdminLog(
                '解绑用户2FA',
                '用户管理',
                '用户UID：' . (int)$user_info['id'] . '，账号：' . (string)($user_info['mobile'] ?? '') . '，已由管理员强制清空2FA绑定',
                [
                    'target_id' => (int)$user_info['id'],
                    'target_type' => 'user',
                ]
            );

            return show(200, 'success', '用户2FA解绑成功');
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('admin user_post twofa_unbind error: ' . $e->getMessage(), [
                'uid' => (int)($post_info['uid'] ?? 0),
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            ]);
            return show(500, 'error', '解绑失败');
        }
    }

    /**
     * OLD AdminApi::handleUserRights 行为等价。
     * 权限码 admin.user.rights；CSRF；path 双路径；sensitive scene=user_rights；
     * Db::transaction 闭包；锁序 user→substation；Substation 创建时钱包字段全 0（无资金副作用）；无操作日志（OLD 事实，保持）。
     */
    private function rights(array $post_info)
    {
        if (!$this->authorize('admin.user.rights')) {
            return $this->directDenyAdminPermission('用户列表');
        }

        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post rights invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '权益请求校验失败');
        }

        if (!$this->pathAllowed('rights')) {
            Log::warning('admin user_post rights invalid path blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['uid'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '请求路径错误');
        }

        $sensitiveValidation = $this->sensitiveGuard()->verifySensitiveOperation((array)$post_info, 'user_rights');
        if (empty($sensitiveValidation['ok'])) {
            return show(403, 'error', (string)($sensitiveValidation['message'] ?? '安全验证失败'));
        }

        $uid = (int)($post_info['uid'] ?? 0);
        $rightsAction = trim((string)($post_info['rights_action'] ?? ''));
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        $allowedActions = ['vip_open', 'vip_close', 'svip_open', 'svip_close'];
        if (!in_array($rightsAction, $allowedActions, true)) {
            return show(500, 'error', '权益操作类型错误');
        }

        try {
            $message = Db::transaction(function () use ($uid, $rightsAction) {
                $user = $this->userService()->lockById($uid);
                if (!$user) {
                    throw new Exception('用户不存在');
                }

                if ($rightsAction === 'vip_open') {
                    $user->agent_status = 1;
                    $user->save();
                    return 'VIP 已开通';
                }

                if ($rightsAction === 'vip_close') {
                    $user->agent_status = 0;
                    $user->save();
                    return 'VIP 已关闭';
                }

                $substation = Substation::where('uid', $uid)->lock(true)->find();
                if (!$substation) {
                    $substation = Substation::create([
                        'uid' => $uid,
                        'status' => 0,
                        'wallet_balance' => 0,
                        'wallet_total_income' => 0,
                        'wallet_total_transferred' => 0,
                        'income_balance' => 0,
                        'create_time' => date('Y-m-d H:i:s'),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                    $substation = Substation::where('id', (int)$substation['id'])->lock(true)->find();
                }

                if ($rightsAction === 'svip_open') {
                    $substation->status = Substation::STATUS_APPROVED;
                    if (trim((string)($substation['open_time'] ?? '')) === '') {
                        $substation->open_time = date('Y-m-d H:i:s');
                    }
                    $substation->reject_reason = null;
                    $substation->update_time = date('Y-m-d H:i:s');
                    $substation->save();

                    // 业务规则：SVIP 包含 VIP。
                    $user->agent_status = 1;
                    $user->save();
                    return 'SVIP 已开通（已同步开通 VIP）';
                }

                $substation->status = Substation::STATUS_PENDING;
                $substation->reject_reason = null;
                $substation->update_time = date('Y-m-d H:i:s');
                $substation->save();
                return 'SVIP 已关闭';
            });

            return show(200, 'success', $message);
        } catch (\Throwable $e) {
            Log::error('admin user_post rights error: ' . $e->getMessage(), [
                'uid' => $uid,
                'rights_action' => $rightsAction,
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            ]);
            return show(500, 'error', $e->getMessage() ?: '权益操作失败');
        }
    }

    /**
     * OLD AdminApi::handleUserDeleteBatch 行为等价。
     * 权限码 admin.user.delete；CSRF；ids 归一化；先全量 pending 检查（任一失败整批拒绝）→ 逐条删除+逐条日志；无事务（OLD 事实，保持）。
     */
    private function batchDelete(array $post_info)
    {
        if (!$this->authorize('admin.user.delete')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post dels invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'ids' => $post_info['ids'] ?? [],
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '删除请求校验失败');
        }

        $ids = is_array($post_info['ids'] ?? null) ? $post_info['ids'] : [];
        if (empty($ids)) {
            return show(500, 'error', '请选择要删除的用户');
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (empty($ids)) {
            return show(500, 'error', '用户参数错误');
        }

        // 先全部检查通过，再执行删除（避免删了一半才失败）
        $failedChecks = [];
        foreach ($ids as $uid) {
            $pendingCheck = $this->userService()->assertNoPendingBusiness($uid);
            if (empty($pendingCheck['ok'])) {
                $failedChecks[] = 'UID#' . $uid . '：' . $pendingCheck['message'];
                continue;
            }
            $historyCheck = $this->userService()->assertNoFinancialHistory($uid);
            if (empty($historyCheck['ok'])) {
                $failedChecks[] = 'UID#' . $uid . '：' . $historyCheck['message'];
            }
        }
        if (!empty($failedChecks)) {
            return show(500, 'error', '以下用户无法删除：' . implode('；', $failedChecks));
        }

        // 全部通过后执行删除（R1.6e: 每个用户独立事务+行锁+锁内重检）
        $deletedCount = 0;
        foreach ($ids as $uid) {
            Db::startTrans();
            try {
                $user = $this->userService()->lockById($uid);
                if (!$user) {
                    Db::rollback();
                    continue;
                }
                // 锁内重新检查
                $pendingCheck = $this->userService()->assertNoPendingBusiness($uid);
                if (empty($pendingCheck['ok'])) {
                    Db::rollback();
                    continue;
                }
                $historyCheck = $this->userService()->assertNoFinancialHistory($uid);
                if (empty($historyCheck['ok'])) {
                    Db::rollback();
                    continue;
                }
                $mobile = (string)($user['mobile'] ?? '');
                UserModel::destroy($uid);
                Db::commit();
                $deletedCount++;
                $this->writeAdminLog('删除用户', '用户管理', '用户UID：' . $uid . '，账号：' . $mobile, [
                    'target_id' => $uid,
                    'target_type' => 'user',
                ]);
            } catch (\Exception $e) {
                Db::rollback();
                Log::error('admin user batch delete error: ' . $e->getMessage(), ['uid' => $uid]);
            }
        }

        return show(200, 'success', '删除成功（' . $deletedCount . '个）');
    }

    /**
     * R1.6e: 单个用户删除。
     * 权限码 admin.user.delete；CSRF；id 参数；pending + financial history 双检查；
     * 事务+行锁内重检后 destroy（消除 TOCTOU）；操作日志。
     */
    private function deleteOne(array $post_info)
    {
        if (!$this->authorize('admin.user.delete')) {
            return $this->directDenyAdminPermission('用户列表');
        }
        if (!$this->directValidateRequiredCsrfToken()) {
            Log::warning('admin user_post del invalid csrf blocked', [
                'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
                'uid' => (int)($post_info['id'] ?? 0),
                'ip' => (string)$this->request->ip(),
                'path' => $this->directCurrentRequestPath(),
            ]);
            return show(403, 'error', '删除请求校验失败');
        }

        $uid = (int)($post_info['id'] ?? 0);
        if ($uid <= 0) {
            return show(500, 'error', '用户参数错误');
        }

        // 快速预检（无锁，提前拒绝）
        $pendingCheck = $this->userService()->assertNoPendingBusiness($uid);
        if (empty($pendingCheck['ok'])) {
            return show(500, 'error', $pendingCheck['message'] . '，无法删除');
        }
        $historyCheck = $this->userService()->assertNoFinancialHistory($uid);
        if (empty($historyCheck['ok'])) {
            return show(500, 'error', $historyCheck['message'] . '，无法删除');
        }

        // R1.6e: 事务+行锁内重新检查并删除，消除 TOCTOU
        Db::startTrans();
        try {
            $user = $this->userService()->lockById($uid);
            if (!$user) {
                Db::rollback();
                return show(500, 'error', '用户不存在');
            }
            // 锁内重新检查（防止预检后并发产生新记录）
            $pendingCheck = $this->userService()->assertNoPendingBusiness($uid);
            if (empty($pendingCheck['ok'])) {
                Db::rollback();
                return show(500, 'error', $pendingCheck['message'] . '，无法删除');
            }
            $historyCheck = $this->userService()->assertNoFinancialHistory($uid);
            if (empty($historyCheck['ok'])) {
                Db::rollback();
                return show(500, 'error', $historyCheck['message'] . '，无法删除');
            }
            $mobile = (string)($user['mobile'] ?? '');
            UserModel::destroy($uid);
            Db::commit();

            $this->writeAdminLog('删除用户', '用户管理', '用户UID：' . $uid . '，账号：' . $mobile, [
                'target_id' => $uid,
                'target_type' => 'user',
            ]);

            return show(200, 'success', '删除成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('admin user delete error: ' . $e->getMessage(), ['uid' => $uid]);
            return show(500, 'error', '删除失败');
        }
    }

    /**
     * 双路径兼容：OLD 入口 user_post/{action} 与 NEW 入口 admin/user/{action} 均放行，其余路径拒绝。
     * 仅 twofa_unbind / rights 存在 OLD path 检查，本方法只在这两个 action 使用。
     */
    private function pathAllowed(string $action): bool
    {
        return $this->directRequestPathMatches('user_post/' . $action)
            || $this->directRequestPathMatches('admin/user/' . $action);
    }

    /**
     * 敏感操作二次验证 Guard（身份由当前 Session 代码强制确定）。
     */
    private function sensitiveGuard(): AdminSensitiveOperationGuard
    {
        return app(AdminSensitiveOperationGuard::class);
    }

    /**
     * 用户域服务（行锁 SSOT / 删除前检查）。
     */
    private function userService(): UserService
    {
        return app(UserService::class);
    }

    /**
     * 操作日志统一入口（B08 迁移到 AdminOperationLogService::record()，不复制 AdminApi 私有实现）。
     */
    private function writeAdminLog(string $action, string $module, string $content, array $options = []): void
    {
        app(AdminOperationLogService::class)->record($action, $module, $content, array_merge([
            'admin' => $this->currentAdminIdentity(),
        ], $options));
    }
}
