<?php
declare (strict_types=1);

namespace app\service;

use app\model\Recharge;
use app\model\RecoveryCase;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * RecoveryCaseService — Pre-R1 Batch A5.1
 *
 * 充值人工恢复案件领域服务。
 *
 * 职责：
 *   1. 创建 RecoveryCase（验证 Recharge 存在、未 PAID、无冲突 case）
 *   2. 审核批准（approve）
 *   3. 审核拒绝（reject）
 *   4. 关闭（close）
 *   5. 重新打开（reopen，被拒绝后有新证据）
 *
 * 不承担：
 *   - 资金结算（由 RechargeSettlementService::settleManualApproved 负责）
 *   - HTTP 路由 / Controller（A5.2 实现）
 *   - RBAC / 2FA（A5.2 实现，本 Service 只接受 admin_id 参数）
 *   - 文件上传（A5 后续批次）
 *
 * 核心 invariant：
 *   - 一个 recharge 终身只允许一个 recovery case（uk_recharge_id DB 唯一约束兜底）
 *   - 创建时 amount 必须来自 Recharge 自身，不信任调用方输入
 *   - APPROVED ≠ SETTLED
 *   - 状态迁移必须符合状态机
 */
class RecoveryCaseService
{
    // ===== 创建结果码 =====
    public const CREATE_OK = 'created';
    public const CREATE_ALREADY_EXISTS = 'already_exists';
    public const CREATE_RECHARGE_NOT_FOUND = 'recharge_not_found';
    public const CREATE_RECHARGE_PAID = 'recharge_paid';

    // ===== 审核结果码 =====
    public const APPROVE_OK = 'approved';
    public const APPROVE_INVALID_STATE = 'invalid_state';
    public const APPROVE_NOT_FOUND = 'not_found';

    public const REJECT_OK = 'rejected';
    public const REJECT_INVALID_STATE = 'invalid_state';
    public const REJECT_NOT_FOUND = 'not_found';

    /**
     * 创建 RecoveryCase
     *
     * @param int $rechargeId 充值订单 ID
     * @param int $adminId 创建者管理员 ID
     * @param string $provider 支付渠道 epay/bepusdt
     * @param string $evidenceNote 证据备注
     * @param string $externalReference 外部交易引用（trade_no/tx_hash）
     * @return array{result: string, message: string, case?: RecoveryCase}
     * @throws Exception DB 异常
     */
    public function createCase(int $rechargeId, int $adminId, string $provider = '', string $evidenceNote = '', string $externalReference = ''): array
    {
        return Db::transaction(function () use ($rechargeId, $adminId, $provider, $evidenceNote, $externalReference) {
            // 1. 锁定 Recharge row，验证存在且未 PAID
            $recharge = Recharge::where('id', $rechargeId)->lock(true)->find();
            if (!$recharge) {
                return [
                    'result' => self::CREATE_RECHARGE_NOT_FOUND,
                    'message' => 'Recharge not found: id=' . $rechargeId,
                ];
            }

            if ((int)$recharge->status === Recharge::STATUS_PAID) {
                return [
                    'result' => self::CREATE_RECHARGE_PAID,
                    'message' => 'Recharge already paid: id=' . $rechargeId,
                ];
            }

            // 2. 检查是否已有 case（uk_recharge_id 唯一约束兜底）
            $existing = RecoveryCase::where('recharge_id', $rechargeId)->lock(true)->find();
            if ($existing) {
                return [
                    'result' => self::CREATE_ALREADY_EXISTS,
                    'message' => 'RecoveryCase already exists for recharge: id=' . $rechargeId . ', case_id=' . $existing->id,
                    'case' => $existing,
                ];
            }

            // 3. 创建 case，amount 来自 Recharge 自身（不信任调用方输入）
            $case = new RecoveryCase();
            $case->recharge_id = $rechargeId;
            $case->uid = (int)$recharge->uid;
            $case->provider = $provider !== '' ? $provider : (string)$recharge->gateway;
            $case->status = RecoveryCase::STATUS_OPEN;
            $case->claimed_amount = (float)$recharge->amount; // 仅记录，不作为结算金额
            $case->external_reference = $externalReference;
            $case->evidence_note = $evidenceNote;
            $case->created_by_admin_id = $adminId;
            $case->save();

            Log::info('recovery case created', [
                'case_id' => $case->id,
                'recharge_id' => $rechargeId,
                'order_number' => (string)$recharge->order_number,
                'uid' => (int)$recharge->uid,
                'admin_id' => $adminId,
                'provider' => $case->provider,
            ]);

            return [
                'result' => self::CREATE_OK,
                'message' => 'RecoveryCase created: id=' . $case->id,
                'case' => $case,
            ];
        });
    }

    /**
     * 审核批准
     *
     * @param int $caseId 案件 ID
     * @param int $adminId 审核者管理员 ID
     * @param string $note 审核备注
     * @return array{result: string, message: string, case?: RecoveryCase}
     * @throws Exception
     */
    public function approve(int $caseId, int $adminId, string $note = ''): array
    {
        return Db::transaction(function () use ($caseId, $adminId, $note) {
            $case = RecoveryCase::where('id', $caseId)->lock(true)->find();
            if (!$case) {
                return ['result' => self::APPROVE_NOT_FOUND, 'message' => 'RecoveryCase not found: id=' . $caseId];
            }

            if (!$case->canTransitionTo(RecoveryCase::STATUS_APPROVED)) {
                return [
                    'result' => self::APPROVE_INVALID_STATE,
                    'message' => 'Cannot approve from status: ' . $case->status,
                    'case' => $case,
                ];
            }

            $case->status = RecoveryCase::STATUS_APPROVED;
            $case->approved_by_admin_id = $adminId;
            $case->approved_at = date('Y-m-d H:i:s');
            $case->approval_note = $note;
            $case->save();

            Log::info('recovery case approved', [
                'case_id' => $caseId,
                'recharge_id' => $case->recharge_id,
                'admin_id' => $adminId,
            ]);

            return [
                'result' => self::APPROVE_OK,
                'message' => 'RecoveryCase approved: id=' . $caseId,
                'case' => $case,
            ];
        });
    }

    /**
     * 审核拒绝
     */
    public function reject(int $caseId, int $adminId, string $note = ''): array
    {
        return Db::transaction(function () use ($caseId, $adminId, $note) {
            $case = RecoveryCase::where('id', $caseId)->lock(true)->find();
            if (!$case) {
                return ['result' => self::REJECT_NOT_FOUND, 'message' => 'RecoveryCase not found: id=' . $caseId];
            }

            if (!$case->canTransitionTo(RecoveryCase::STATUS_REJECTED)) {
                return [
                    'result' => self::REJECT_INVALID_STATE,
                    'message' => 'Cannot reject from status: ' . $case->status,
                    'case' => $case,
                ];
            }

            $case->status = RecoveryCase::STATUS_REJECTED;
            $case->approved_by_admin_id = $adminId;
            $case->approved_at = date('Y-m-d H:i:s');
            $case->approval_note = $note;
            $case->save();

            Log::info('recovery case rejected', [
                'case_id' => $caseId,
                'recharge_id' => $case->recharge_id,
                'admin_id' => $adminId,
            ]);

            return [
                'result' => self::REJECT_OK,
                'message' => 'RecoveryCase rejected: id=' . $caseId,
                'case' => $case,
            ];
        });
    }

    /**
     * 关闭 case（终态）
     */
    public function close(int $caseId, int $adminId, string $note = ''): array
    {
        return Db::transaction(function () use ($caseId, $adminId, $note) {
            $case = RecoveryCase::where('id', $caseId)->lock(true)->find();
            if (!$case) {
                return ['result' => 'not_found', 'message' => 'RecoveryCase not found: id=' . $caseId];
            }

            if (!$case->canTransitionTo(RecoveryCase::STATUS_CLOSED)) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Cannot close from status: ' . $case->status,
                    'case' => $case,
                ];
            }

            $case->status = RecoveryCase::STATUS_CLOSED;
            $case->closed_at = date('Y-m-d H:i:s');
            if ($note !== '' && $case->approval_note === null) {
                $case->approval_note = $note;
            }
            $case->save();

            Log::info('recovery case closed', [
                'case_id' => $caseId,
                'recharge_id' => $case->recharge_id,
                'admin_id' => $adminId,
            ]);

            return ['result' => 'closed', 'message' => 'RecoveryCase closed: id=' . $caseId, 'case' => $case];
        });
    }

    /**
     * 重新打开（被拒绝后有新证据）
     */
    public function reopen(int $caseId, int $adminId, string $note = ''): array
    {
        return Db::transaction(function () use ($caseId, $adminId, $note) {
            $case = RecoveryCase::where('id', $caseId)->lock(true)->find();
            if (!$case) {
                return ['result' => 'not_found', 'message' => 'RecoveryCase not found: id=' . $caseId];
            }

            // rejected → open
            if ((string)$case->status !== RecoveryCase::STATUS_REJECTED) {
                return [
                    'result' => 'invalid_state',
                    'message' => 'Cannot reopen from status: ' . $case->status . ' (only rejected can be reopened)',
                    'case' => $case,
                ];
            }

            $case->status = RecoveryCase::STATUS_OPEN;
            $case->approved_by_admin_id = 0;
            $case->approved_at = null;
            if ($note !== '') {
                $case->evidence_note = ($case->evidence_note ? $case->evidence_note . "\n" : '') . '[reopen] ' . $note;
            }
            $case->save();

            Log::info('recovery case reopened', [
                'case_id' => $caseId,
                'recharge_id' => $case->recharge_id,
                'admin_id' => $adminId,
            ]);

            return ['result' => 'reopened', 'message' => 'RecoveryCase reopened: id=' . $caseId, 'case' => $case];
        });
    }
}
