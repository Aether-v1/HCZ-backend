<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\middleware\AdminAuth;
use app\service\AdminRecoveryApplicationService;
use app\service\AdminSensitiveOperationGuard;
use app\service\AuthorizationService;
use think\facade\Log;

/**
 * AdminRecoveryController — Pre-R1 Batch A5.2a
 *
 * 充值人工恢复 Admin API 控制器。
 *
 * 本轮（A5.2a）只实现非资金入口：
 *   GET  /admin/recovery/list
 *   GET  /admin/recovery/detail
 *   POST /admin/recovery/create
 *   POST /admin/recovery/approve
 *   POST /admin/recovery/reject
 *
 * settle endpoint 在 A5.2b 才允许加入。
 *
 * 安全边界：
 *   - AdminAuth 中间件（显式声明）
 *   - RBAC 权限检查（authorize()）
 *   - CSRF（全局 CsrfCheck 中间件自动覆盖 POST）
 *   - Controller 不直接操作 Model，全部通过 AdminRecoveryApplicationService
 *   - 不引用 RechargeSettlementService / UserFundLedgerService
 *   - 不产生任何资金变化
 */
class Recovery extends BaseController
{
    // AdminAuth 非全局中间件，必须显式声明；缺失 = 新增 admin 路由绕过鉴权（P0）
    protected array $middleware = [AdminAuth::class];

    private AdminRecoveryApplicationService $appService;

    protected function initialize()
    {
        $this->appService = new AdminRecoveryApplicationService();
    }

    // ==================== GET /admin/recovery/list ====================

    /**
     * 恢复案件列表
     * Permission: recharge_recovery.view
     */
    public function list()
    {
        if (!$this->authorize('recharge_recovery.view')) {
            return $this->deny('recharge_recovery.view');
        }

        $page = (int)$this->request->get('page', 1);
        $pageSize = (int)$this->request->get('page_size', 20);

        $filters = [];
        $allowed = ['status', 'provider', 'uid', 'recharge_status'];
        foreach ($allowed as $field) {
            $val = $this->request->get($field, '');
            if ($val !== '') {
                $filters[$field] = $val;
            }
        }

        $result = $this->appService->list($filters, $page, $pageSize);
        return show(200, 'success', 'ok', $result);
    }

    // ==================== GET /admin/recovery/detail ====================

    /**
     * 恢复案件详情
     * Permission: recharge_recovery.view
     */
    public function detail()
    {
        if (!$this->authorize('recharge_recovery.view')) {
            return $this->deny('recharge_recovery.view');
        }

        $caseId = (int)$this->request->get('id', 0);
        if ($caseId <= 0) {
            return show(400, 'error', '参数错误: id 无效');
        }

        $detail = $this->appService->detail($caseId);
        if ($detail === null) {
            return show(404, 'error', '恢复案件不存在');
        }

        return show(200, 'success', 'ok', $detail);
    }

    // ==================== POST /admin/recovery/create ====================

    /**
     * 创建恢复案件
     * Permission: recharge_recovery.create
     *
     * 请求字段（白名单）:
     *   recharge_id (required)
     *   evidence_note (optional)
     *   external_reference (optional)
     *
     * 禁止客户端传: uid, amount, credit_amount, request_no, wallet_type, balance, status
     */
    public function create()
    {
        if (!$this->authorize('recharge_recovery.create')) {
            return $this->deny('recharge_recovery.create');
        }

        $rechargeId = (int)$this->request->post('recharge_id', 0);
        $evidenceNote = trim((string)$this->request->post('evidence_note', ''));
        $externalReference = trim((string)$this->request->post('external_reference', ''));

        if ($rechargeId <= 0) {
            return show(400, 'error', '参数错误: recharge_id 无效');
        }

        $adminId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        if ($adminId <= 0) {
            return show(401, 'error', '未登录');
        }

        $result = $this->appService->create($rechargeId, $adminId, $evidenceNote, $externalReference);

        if (!$result['ok']) {
            $httpCode = $this->mapErrorCodeToHttp($result['error_code'] ?? '');
            return show($httpCode, 'error', $result['message']);
        }

        return show(200, 'success', $result['message'], $result['case']);
    }

    // ==================== POST /admin/recovery/approve ====================

    /**
     * 审核批准恢复案件
     * Permission: recharge_recovery.review
     *
     * 注意: approve 不产生任何资金变化。settle 在 A5.2b 实现。
     */
    public function approve()
    {
        if (!$this->authorize('recharge_recovery.review')) {
            return $this->deny('recharge_recovery.review');
        }

        $caseId = (int)$this->request->post('id', 0);
        $approvalNote = trim((string)$this->request->post('approval_note', ''));

        if ($caseId <= 0) {
            return show(400, 'error', '参数错误: id 无效');
        }

        $adminId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        if ($adminId <= 0) {
            return show(401, 'error', '未登录');
        }

        $result = $this->appService->approve($caseId, $adminId, $approvalNote);

        if (!$result['ok']) {
            $httpCode = $this->mapErrorCodeToHttp($result['error_code'] ?? '');
            return show($httpCode, 'error', $result['message']);
        }

        return show(200, 'success', $result['message'], $result['case']);
    }

    // ==================== POST /admin/recovery/reject ====================

    /**
     * 审核拒绝恢复案件
     * Permission: recharge_recovery.review
     */
    public function reject()
    {
        if (!$this->authorize('recharge_recovery.review')) {
            return $this->deny('recharge_recovery.review');
        }

        $caseId = (int)$this->request->post('id', 0);
        $reason = trim((string)$this->request->post('reason', ''));

        if ($caseId <= 0) {
            return show(400, 'error', '参数错误: id 无效');
        }

        $adminId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        if ($adminId <= 0) {
            return show(401, 'error', '未登录');
        }

        $result = $this->appService->reject($caseId, $adminId, $reason);

        if (!$result['ok']) {
            $httpCode = $this->mapErrorCodeToHttp($result['error_code'] ?? '');
            return show($httpCode, 'error', $result['message']);
        }

        return show(200, 'success', $result['message'], $result['case']);
    }

    // ==================== POST /admin/recovery/settle (A5.2b) ====================

    /**
     * 执行已批准恢复案件的资金结算
     *
     * 安全链（严格顺序）：
     *   AdminAuth (中间件)
     *     ↓
     *   CsrfCheck (全局中间件, POST 强制)
     *     ↓
     *   RBAC authorize('recharge_recovery.settle') — DB authoritative check（先失效缓存）
     *     ↓
     *   SensitiveOperationGuard — TOTP 或 密码二次验证
     *     ↓
     *   RecoveryCase 状态校验（ApplicationService 事务内 lock + isSettleable）
     *     ↓
     *   RechargeSettlementService::settleManualApproved()
     *     ↓
     *   UserFundLedgerService credit（统一 request_no = recharge_paid:{order_number}）
     *     ↓
     *   审计日志（同一事务）
     *
     * 客户端只能提交 case_id；uid/amount/order_number 全部服务端派生。
     * 任何前置验证失败 → 资金 = 0 修改。
     */
    public function settle()
    {
        // ===== 1. RBAC: recharge_recovery.settle（DB authoritative，不依赖 300s 缓存）=====
        // 先主动失效当前管理员权限缓存，确保 authorize() 从 DB 重新加载
        $adminId = (int)($this->currentAdminIdentity()['id'] ?? 0);
        if ($adminId > 0) {
            try {
                (new AuthorizationService())->invalidateAdminPermissions($adminId);
            } catch (\Throwable $e) {
                Log::warning('settle: invalidate permission cache failed', [
                    'admin_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$this->authorize('recharge_recovery.settle')) {
            return $this->deny('recharge_recovery.settle');
        }

        // ===== 2. SensitiveOperationGuard：TOTP 或 密码二次验证（强制）=====
        $postInfo = $this->request->post();
        $guard = new AdminSensitiveOperationGuard($this->request);
        $guardResult = $guard->verifySensitiveOperation($postInfo, 'recharge_recovery_settle');
        if (empty($guardResult['ok'])) {
            Log::warning('admin recovery settle sensitive verification failed', [
                'admin_id' => $adminId,
                'ip' => (string)$this->request->ip(),
                'message' => $guardResult['message'] ?? '',
            ]);
            // 敏感验证失败 → 403，不触碰资金
            return show(403, 'error', '敏感操作验证失败: ' . ($guardResult['message'] ?? '验证未通过'));
        }

        // ===== 3. 提取 case_id（唯一可信客户端参数）=====
        $caseId = (int)$this->request->post('case_id', 0);
        if ($caseId <= 0) {
            return show(400, 'error', '参数错误: case_id 无效');
        }

        // 注意：客户端可能提交 uid/amount/order_number 等字段，全部忽略，
        // 由 ApplicationService 从 RecoveryCase → Recharge 服务端派生。

        // ===== 4. 调用 ApplicationService（事务内完成结算 + 审计）=====
        $result = $this->appService->settle($caseId, $adminId);

        if (!$result['ok']) {
            $httpCode = $this->mapErrorCodeToHttp($result['error_code'] ?? '');
            return show($httpCode, 'error', $result['message']);
        }

        // ===== 5. 成功响应 =====
        return show(200, 'success', $result['message'], [
            'result' => $result['result'] ?? '',
            'amount' => $result['amount'] ?? 0,
            'balance_before' => $result['balance_before'] ?? 0,
            'balance_after' => $result['balance_after'] ?? 0,
            'case' => $result['case'] ?? null,
        ]);
    }

    // ==================== Helpers ====================

    /**
     * 权限拒绝 + 日志
     */
    private function deny(string $permission)
    {
        Log::warning('admin recovery permission denied', [
            'admin_id' => (int)($this->currentAdminIdentity()['id'] ?? 0),
            'permission' => $permission,
            'ip' => (string)$this->request->ip(),
            'path' => $this->directCurrentRequestPath(),
        ]);
        return show(403, 'error', '权限不足');
    }

    /**
     * 业务错误码 → HTTP 状态码映射
     */
    private function mapErrorCodeToHttp(string $errorCode): int
    {
        $map = [
            'RECOVERY_CASE_NOT_FOUND' => 404,
            'RECHARGE_NOT_FOUND' => 404,
            'RECOVERY_CASE_ALREADY_EXISTS' => 409,
            'RECOVERY_CASE_INVALID_STATE' => 409,
            'RECHARGE_ALREADY_PAID' => 409,
            'RECOVERY_APPROVAL_NOTE_REQUIRED' => 422,
            'VALIDATION_ERROR' => 400,
            'PERMISSION_DENIED' => 403,
            'INTERNAL_ERROR' => 500,
        ];
        return $map[$errorCode] ?? 400;
    }
}
