<?php
declare (strict_types = 1);

namespace app\service;

use app\model\RecoveryCase;
use app\model\Recharge;
use app\service\RechargeSettlementService;
use think\facade\Db;
use think\facade\Log;

/**
 * AdminRecoveryApplicationService — Pre-R1 Batch A5.2a
 *
 * Admin Recovery 唯一应用层入口。Controller 只调用本 Service，
 * 不直接操作 RecoveryCase Model 或 RecoveryCaseService。
 *
 * 本轮（A5.2a）只实现非资金入口：
 *   list / detail / create / approve / reject
 *
 * settle() 在 A5.2b 才允许加入。
 *
 * 安全边界：
 *   - 不引用 RechargeSettlementService
 *   - 不引用 UserFundLedgerService
 *   - 不产生任何资金变化
 *   - uid/amount/recharge 关系从服务端可信数据派生，不信任调用者
 */
class AdminRecoveryApplicationService
{
    private RecoveryCaseService $caseService;
    private AdminOperationLogService $auditLog;

    public function __construct()
    {
        $this->caseService = new RecoveryCaseService();
        $this->auditLog = new AdminOperationLogService();
    }

    /**
     * 测试注入：替换审计日志 Service（仅用于测试模拟审计失败）
     * 生产代码不应调用此方法。
     */
    public function setAuditLog(AdminOperationLogService $auditLog): void
    {
        $this->auditLog = $auditLog;
    }

    // ==================== List ====================

    /**
     * 分页查询恢复案件列表
     *
     * @param array $filters 过滤条件（白名单字段）
     * @param int $page
     * @param int $pageSize
     * @return array{items: array, page: int, page_size: int, total: int}
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $query = RecoveryCase::order('id', 'desc');

        // 白名单过滤字段
        $allowedFilters = ['status', 'provider', 'uid'];
        foreach ($allowedFilters as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        // recharge_status 通过关联查询（简化：子查询）
        if (!empty($filters['recharge_status'])) {
            $rechargeIds = Recharge::where('status', (int)$filters['recharge_status'])->column('id');
            if (empty($rechargeIds)) {
                return ['items' => [], 'page' => $page, 'page_size' => $pageSize, 'total' => 0];
            }
            $query->whereIn('recharge_id', $rechargeIds);
        }

        $total = $query->count();
        $cases = $query->page($page, $pageSize)->select();

        $items = [];
        foreach ($cases as $case) {
            $items[] = $this->toListDto($case);
        }

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    // ==================== Detail ====================

    /**
     * 查询恢复案件详情
     *
     * @param int $caseId
     * @return array|null
     */
    public function detail(int $caseId): ?array
    {
        $case = RecoveryCase::where('id', $caseId)->find();
        if (!$case) {
            return null;
        }
        return $this->toDetailDto($case);
    }

    // ==================== Create ====================

    /**
     * 创建恢复案件
     *
     * @param int $rechargeId
     * @param int $adminId
     * @param string $evidenceNote
     * @param string $externalReference
     * @return array{ok: bool, error_code?: string, message: string, case?: array}
     */
    public function create(int $rechargeId, int $adminId, string $evidenceNote = '', string $externalReference = ''): array
    {
        if ($rechargeId <= 0) {
            return ['ok' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'recharge_id 无效'];
        }

        $result = $this->caseService->createCase($rechargeId, $adminId, '', $evidenceNote, $externalReference);

        switch ($result['result']) {
            case RecoveryCaseService::CREATE_OK:
                $caseDto = $this->toDetailDto($result['case']);
                $this->auditLog->record(
                    'create_recovery_case',
                    'recharge_recovery',
                    '创建充值恢复案件: case_id=' . $caseDto['id'] . ', recharge_id=' . $rechargeId,
                    ['target_id' => $caseDto['id'], 'target_type' => 'recovery_case', 'admin_id' => $adminId]
                );
                return ['ok' => true, 'message' => '恢复案件创建成功', 'case' => $caseDto];

            case RecoveryCaseService::CREATE_ALREADY_EXISTS:
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_ALREADY_EXISTS', 'message' => '该充值订单已存在恢复案件'];

            case RecoveryCaseService::CREATE_RECHARGE_NOT_FOUND:
                return ['ok' => false, 'error_code' => 'RECHARGE_NOT_FOUND', 'message' => '充值订单不存在'];

            case RecoveryCaseService::CREATE_RECHARGE_PAID:
                return ['ok' => false, 'error_code' => 'RECHARGE_ALREADY_PAID', 'message' => '充值订单已支付，无需恢复'];

            default:
                return ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => '创建失败: ' . ($result['message'] ?? '未知错误')];
        }
    }

    // ==================== Approve ====================

    /**
     * 审核批准恢复案件
     *
     * @param int $caseId
     * @param int $adminId
     * @param string $approvalNote
     * @return array{ok: bool, error_code?: string, message: string, case?: array}
     */
    public function approve(int $caseId, int $adminId, string $approvalNote): array
    {
        $note = trim($approvalNote);
        if ($note === '') {
            return ['ok' => false, 'error_code' => 'RECOVERY_APPROVAL_NOTE_REQUIRED', 'message' => '审核备注不能为空'];
        }
        if (mb_strlen($note) > 500) {
            return ['ok' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => '审核备注过长（最多500字）'];
        }

        $result = $this->caseService->approve($caseId, $adminId, $note);

        switch ($result['result']) {
            case RecoveryCaseService::APPROVE_OK:
                $caseDto = $this->toDetailDto($result['case']);
                $this->auditLog->record(
                    'approve_recovery_case',
                    'recharge_recovery',
                    '批准充值恢复案件: case_id=' . $caseDto['id'] . ', recharge_id=' . $caseDto['recharge_id'] . ', 备注: ' . mb_substr($note, 0, 100),
                    ['target_id' => $caseDto['id'], 'target_type' => 'recovery_case', 'admin_id' => $adminId]
                );
                return ['ok' => true, 'message' => '恢复案件已批准', 'case' => $caseDto];

            case RecoveryCaseService::APPROVE_INVALID_STATE:
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_INVALID_STATE', 'message' => '案件状态不允许批准（当前状态: ' . ($result['case']->status ?? 'unknown') . '）'];

            case RecoveryCaseService::APPROVE_NOT_FOUND:
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_NOT_FOUND', 'message' => '恢复案件不存在'];

            default:
                return ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => '批准失败: ' . ($result['message'] ?? '未知错误')];
        }
    }

    // ==================== Reject ====================

    /**
     * 审核拒绝恢复案件
     *
     * @param int $caseId
     * @param int $adminId
     * @param string $reason
     * @return array{ok: bool, error_code?: string, message: string, case?: array}
     */
    public function reject(int $caseId, int $adminId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error_code' => 'RECOVERY_APPROVAL_NOTE_REQUIRED', 'message' => '拒绝原因不能为空'];
        }
        if (mb_strlen($reason) > 500) {
            return ['ok' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => '拒绝原因过长（最多500字）'];
        }

        $result = $this->caseService->reject($caseId, $adminId, $reason);

        switch ($result['result']) {
            case RecoveryCaseService::REJECT_OK:
                $caseDto = $this->toDetailDto($result['case']);
                $this->auditLog->record(
                    'reject_recovery_case',
                    'recharge_recovery',
                    '拒绝充值恢复案件: case_id=' . $caseDto['id'] . ', recharge_id=' . $caseDto['recharge_id'] . ', 原因: ' . mb_substr($reason, 0, 100),
                    ['target_id' => $caseDto['id'], 'target_type' => 'recovery_case', 'admin_id' => $adminId]
                );
                return ['ok' => true, 'message' => '恢复案件已拒绝', 'case' => $caseDto];

            case RecoveryCaseService::REJECT_INVALID_STATE:
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_INVALID_STATE', 'message' => '案件状态不允许拒绝'];

            case RecoveryCaseService::REJECT_NOT_FOUND:
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_NOT_FOUND', 'message' => '恢复案件不存在'];

            default:
                return ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => '拒绝失败: ' . ($result['message'] ?? '未知错误')];
        }
    }

    // ==================== Settle (A5.2b) ====================

    /**
     * 执行已批准恢复案件的资金结算
     *
     * 安全链：Controller 已完成 AdminAuth + CSRF + RBAC(settle, DB authoritative) + SensitiveOperationGuard
     * 本方法负责：事务内 case 锁 + 状态校验 + RechargeSettlementService + 审计
     *
     * 事务边界：RecoveryCase 锁 + settleManualApproved（资金+Recharge+Case状态）+ 审计日志 → 同一事务 commit
     * 禁止：资金成功但审计缺失 / Case 状态与资金不一致
     *
     * @param int $caseId 恢复案件 ID
     * @param int $adminId 执行管理员 ID（来自 Session，不信任客户端）
     * @return array{ok: bool, error_code?: string, message: string, result?: string, case?: array, amount?: float}
     */
    public function settle(int $caseId, int $adminId): array
    {
        if ($caseId <= 0) {
            return ['ok' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'case_id 无效'];
        }
        if ($adminId <= 0) {
            return ['ok' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => '管理员身份无效'];
        }

        // 资金事务：case 锁 + settleManualApproved + 审计 → 同一事务
        // settleManualApproved 内部已有 Db::transaction，嵌套时使用保存点，外层 commit 才真正提交
        return Db::transaction(function () use ($caseId, $adminId) {
            // 1. 锁定 RecoveryCase，服务端重新读取（不信任客户端状态）
            $case = RecoveryCase::where('id', $caseId)->lock(true)->find();
            if (!$case) {
                return ['ok' => false, 'error_code' => 'RECOVERY_CASE_NOT_FOUND', 'message' => '恢复案件不存在'];
            }

            // 2. 严格状态校验
            if ($case->status === 'settled') {
                // 已 settled：幂等返回成功（不重复入账）
                return [
                    'ok' => true,
                    'message' => '案件已结算（幂等），资金已到账',
                    'result' => RechargeSettlementService::RESULT_ALREADY_PAID,
                    'amount' => (float)($case->claimed_amount ?? 0),
                    'case' => $this->toDetailDto($case),
                ];
            }
            if (!$case->isSettleable()) {
                return [
                    'ok' => false,
                    'error_code' => 'RECOVERY_CASE_INVALID_STATE',
                    'message' => '案件状态不允许结算（当前状态: ' . $case->status . '），仅 approved 可结算',
                ];
            }

            // 3. 调用统一结算边界（唯一资金入口，禁止绕过）
            //    amount/uid/order_number 全部由 SettlementService 从 Recharge 服务端派生
            //    统一幂等键 recharge_paid:{order_number}，与 Callback 共享
            $settlementService = new RechargeSettlementService();
            $result = $settlementService->settleManualApproved($case, [
                'admin_id' => $adminId,
                'gateway' => (string)$case->provider,
            ]);

            $settleResult = $result['result'] ?? '';

            // 4. 关键审计日志（在同一事务内，资金成功 ↔ 审计存在 原子一致）
            //    使用 recordCritical()：审计失败时抛出异常，触发外层事务 rollback
            //    敏感字段（password/TOTP/secret）绝不进入 audit payload
            if (in_array($settleResult, [
                RechargeSettlementService::RESULT_SETTLED,
                RechargeSettlementService::RESULT_ALREADY_PAID,
            ], true)) {
                $amount = $result['amount'] ?? 0;
                $rechargeId = $case->recharge_id;
                $orderNumber = $result['recharge']['order_number'] ?? '';
                $auditContent = sprintf(
                    '人工恢复结算: case_id=%d, recharge_id=%d, order_number=%s, amount=%s, result=%s',
                    $caseId,
                    $rechargeId,
                    $orderNumber,
                    $amount,
                    $settleResult
                );
                $this->auditLog->recordCritical(
                    'settle_recovery_case',
                    'recharge_recovery',
                    $auditContent,
                    [
                        'target_id' => $caseId,
                        'target_type' => 'recovery_case',
                        'admin_id' => $adminId,
                    ]
                );

                Log::info('recovery case settled via admin API', [
                    'case_id' => $caseId,
                    'recharge_id' => $rechargeId,
                    'order_number' => $orderNumber,
                    'amount' => $amount,
                    'result' => $settleResult,
                    'admin_id' => $adminId,
                    'settlement_result' => $result['case']['settlement_result'] ?? '',
                ]);

                return [
                    'ok' => true,
                    'message' => $settleResult === RechargeSettlementService::RESULT_SETTLED
                        ? '恢复结算成功，资金已到账'
                        : '订单已支付（幂等收敛），资金已到账',
                    'result' => $settleResult,
                    'amount' => (float)($result['amount'] ?? 0),
                    'balance_before' => (float)($result['balance_before'] ?? 0),
                    'balance_after' => (float)($result['balance_after'] ?? 0),
                    'case' => $this->toDetailDto($case->refresh()),
                ];
            }

            // 5. 结算失败（金额无效等），case 保持 approved，可重试或退回
            Log::warning('recovery case settlement failed via admin API', [
                'case_id' => $caseId,
                'admin_id' => $adminId,
                'settle_result' => $settleResult,
                'message' => $result['message'] ?? '',
            ]);

            $errorMap = [
                RechargeSettlementService::RESULT_NOT_SETTLEABLE => 'RECOVERY_CASE_NOT_SETTLEABLE',
                RechargeSettlementService::RESULT_INVALID_AMOUNT => 'RECOVERY_INVALID_AMOUNT',
                RechargeSettlementService::RESULT_NOT_FOUND => 'RECHARGE_NOT_FOUND',
                RechargeSettlementService::RESULT_CASE_NOT_APPROVED => 'RECOVERY_CASE_INVALID_STATE',
                RechargeSettlementService::RESULT_CASE_NOT_FOUND => 'RECOVERY_CASE_NOT_FOUND',
            ];

            return [
                'ok' => false,
                'error_code' => $errorMap[$settleResult] ?? 'INTERNAL_ERROR',
                'message' => '结算失败: ' . ($result['message'] ?? '未知错误'),
                'result' => $settleResult,
            ];
        });
    }

    // ==================== DTO ====================

    /**
     * 列表 DTO（安全字段，不暴露敏感信息）
     */
    private function toListDto(RecoveryCase $case): array
    {
        $recharge = Recharge::where('id', $case->recharge_id)->find();
        return [
            'id' => (int)$case->id,
            'recharge_id' => (int)$case->recharge_id,
            'order_number' => $recharge ? (string)$recharge->order_number : '',
            'uid' => (int)$case->uid,
            'provider' => (string)$case->provider,
            'amount' => (string)$case->claimed_amount,
            'recharge_status' => $recharge ? (int)$recharge->status : null,
            'cancel_source' => $recharge ? (string)$recharge->cancel_source : '',
            'legacy_ambiguous' => $recharge && (int)$recharge->status === Recharge::STATUS_EXPIRED && (string)$recharge->cancel_source === '',
            'case_status' => (string)$case->status,
            'created_at' => (string)$case->created_at,
            'approved_at' => $case->approved_at ? (string)$case->approved_at : null,
            'settled_at' => $case->settled_at ? (string)$case->settled_at : null,
            'settlement_result' => (string)$case->settlement_result,
        ];
    }

    /**
     * 详情 DTO
     */
    private function toDetailDto(RecoveryCase $case): array
    {
        $list = $this->toListDto($case);
        $list['external_reference'] = (string)$case->external_reference;
        $list['evidence_note'] = (string)$case->evidence_note;
        $list['approval_note'] = $case->approval_note ? (string)$case->approval_note : null;
        $list['created_by_admin_id'] = (int)$case->created_by_admin_id;
        $list['approved_by_admin_id'] = (int)$case->approved_by_admin_id;
        $list['ledger_request_no'] = (string)$case->ledger_request_no;
        $list['closed_at'] = $case->closed_at ? (string)$case->closed_at : null;
        return $list;
    }
}
