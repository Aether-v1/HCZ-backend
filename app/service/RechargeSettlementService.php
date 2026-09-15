<?php
declare (strict_types=1);

namespace app\service;

use app\model\Recharge;
use app\model\RecoveryCase;
use app\model\User as UserModel;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * RechargeSettlementService — Pre-R1 Batch A
 *
 * 唯一充值结算边界。所有资金入账路径（EPay callback / BEpusdt callback /
 * 未来 reconciliation / manual recovery）必须通过本 Service，禁止绕过
 * UserFundLedgerService 直接修改余额。
 *
 * 核心职责：
 *   1. 定位并锁定 Recharge row
 *   2. 判断本地订单状态（PENDING / EXPIRED 允许 settlement；PAID 幂等；其他拒绝）
 *   3. 验证本地金额 > 0（Provider 金额验证由调用方负责）
 *   4. 锁 User row
 *   5. UserFundLedgerService credit（统一 request_no = recharge_paid:{order_number}）
 *   6. 将 Recharge 标记 PAID + 写 paid_time / complete_time / provider metadata
 *   7. 同一 DB transaction commit
 *
 * 不承担：
 *   - signature verification（留在 Provider/controller 边界）
 *   - HTTP parsing / response
 *   - Provider network request
 *   - Telegram notification（调用方在事务外发送）
 *   - Cron scheduling
 *
 * 锁顺序：Recharge → User → Ledger（与现有 callback 一致，禁止交叉）
 *
 * PAID 是单调状态，不允许回退。
 */
class RechargeSettlementService
{
    // ===== Settlement Result Codes =====
    public const RESULT_SETTLED = 'settled';
    public const RESULT_ALREADY_PAID = 'already_paid';
    public const RESULT_NOT_SETTLEABLE = 'not_settleable';
    public const RESULT_INVALID_AMOUNT = 'invalid_amount';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_CASE_NOT_APPROVED = 'case_not_approved';
    public const RESULT_CASE_NOT_FOUND = 'case_not_found';

    /**
     * 按订单号结算（自己管理事务 + 行锁）
     *
     * 适用于：测试、未来 reconciliation / manual recovery
     * Callback 路径应使用 settleLocked()（在已有事务中执行）。
     *
     * @param string $orderNumber 商户订单号
     * @param string $source 来源标识: notify_epay / notify_bepusdt / reconciliation / admin_manual
     * @param array $providerMetadata Provider 元数据（gateway_trade_id, gateway_status 等）
     * @return array{result: string, message: string, ledger?: array, recharge?: array}
     * @throws Exception 系统异常（DB错误等，调用方应 rollback 并返回 fail）
     */
    public function settleByOrderNumber(string $orderNumber, string $source, array $providerMetadata = []): array
    {
        return Db::transaction(function () use ($orderNumber, $source, $providerMetadata) {
            $recharge = Recharge::where('order_number', $orderNumber)->lock(true)->find();
            if (!$recharge) {
                return [
                    'result' => self::RESULT_NOT_FOUND,
                    'message' => 'Recharge not found: ' . $orderNumber,
                ];
            }
            return $this->settleLocked($recharge, $source, $providerMetadata);
        });
    }

    /**
     * 在已有事务中结算已锁定的 Recharge
     *
     * 调用方必须：
     *   - 已在 DB transaction 中
     *   - 已对 recharge 行加锁 (lock(true))
     *   - 已完成 Provider signature verification + amount validation
     *
     * 本方法只负责：状态判断 + 账本入账 + 标记 PAID
     *
     * @param Recharge $recharge 已锁定的充值订单
     * @param string $source 来源标识
     * @param array $providerMetadata Provider 元数据
     * @return array{result: string, message: string, ledger?: array, recharge?: array}
     * @throws Exception 系统异常
     */
    public function settleLocked(Recharge $recharge, string $source, array $providerMetadata = []): array
    {
        // Callback 路径：遵守 isAutoSettleable()（user-cancel 不自动结算）
        return $this->doSettleLocked($recharge, $source, $providerMetadata, false);
    }

    /**
     * 内部核心结算方法（Callback 和 Manual Recovery 共用）
     *
     * @param Recharge $recharge 已锁定的充值订单
     * @param string $source 来源标识
     * @param array $providerMetadata Provider 元数据
     * @param bool $manualApproved 是否为人工批准恢复（true 时绕过 isAutoSettleable 的 cancel_source 检查）
     * @return array
     * @throws Exception
     */
    private function doSettleLocked(Recharge $recharge, string $source, array $providerMetadata, bool $manualApproved): array
    {
        $localStatus = (int)($recharge->status ?? 0);
        $orderNumber = (string)($recharge->order_number ?? '');

        // ===== 1. PAID: 幂等，已入账 =====
        if ($localStatus === Recharge::STATUS_PAID) {
            Log::info('recharge settlement: already paid (idempotent)', [
                'order_number' => $orderNumber,
                'source' => $source,
                'manual_approved' => $manualApproved,
            ]);
            return [
                'result' => self::RESULT_ALREADY_PAID,
                'message' => 'Recharge already paid',
                'recharge' => $recharge->toArray(),
            ];
        }

        // ===== 2. 验证是否允许 settlement =====
        if (!$manualApproved && !$recharge->isAutoSettleable()) {
            // Callback 路径：遵守 isAutoSettleable()
            Log::warning('recharge settlement: not settleable', [
                'order_number' => $orderNumber,
                'source' => $source,
                'local_status' => $localStatus,
                'cancel_source' => (string)($recharge->cancel_source ?? ''),
            ]);
            return [
                'result' => self::RESULT_NOT_SETTLEABLE,
                'message' => 'Recharge status not settleable: status=' . $localStatus
                    . ', cancel_source=' . (string)($recharge->cancel_source ?? ''),
                'recharge' => $recharge->toArray(),
            ];
        }
        // Manual Recovery 路径：有显式 Admin 授权，绕过 isAutoSettleable 的 cancel_source 检查
        // （但仍保留 PAID 幂等、金额检查、事务原子性、账本唯一约束）

        // ===== 3. 验证本地金额 =====
        $localAmount = round((float)($recharge->amount ?? 0), 2);
        if ($localAmount <= 0) {
            Log::error('recharge settlement: invalid local amount', [
                'order_number' => $orderNumber,
                'source' => $source,
                'local_amount' => $localAmount,
            ]);
            return [
                'result' => self::RESULT_INVALID_AMOUNT,
                'message' => 'Invalid local recharge amount: ' . $localAmount,
                'recharge' => $recharge->toArray(),
            ];
        }

        // ===== 4. 锁 User row（锁顺序：Recharge → User → Ledger）=====
        $uid = (int)($recharge->uid ?? 0);
        $user = UserModel::where('id', $uid)->lock(true)->find();
        if (!$user) {
            throw new Exception('User not found for recharge: ' . $orderNumber . ', uid=' . $uid);
        }

        // ===== 5. UserFundLedgerService credit（统一幂等键）=====
        $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
            $user,
            UserFundLedgerService::WALLET_BALANCE,
            $localAmount,
            [
                'biz_type' => 'recharge',
                'biz_id' => (int)($recharge->id ?? 0),
                'biz_no' => $orderNumber,
                'order_number' => $orderNumber,
                'change_type' => 'recharge_paid',
                'operator_type' => $manualApproved ? 'admin' : 'system',
                'operator_id' => $manualApproved ? ($providerMetadata['admin_id'] ?? 0) : 0,
                'status' => 'done',
                // 统一幂等键：EPay / BEpusdt / reconciliation / manual recovery 共用
                'request_no' => 'recharge_paid:' . $orderNumber,
                'remark' => '充值到账 (' . $source . ')',
                'idempotent' => true,
                'extra' => [
                    'source' => $source,
                    'gateway' => (string)($recharge->gateway ?? ''),
                    'manual_approved' => $manualApproved,
                ],
            ]
        );

        // ===== 6. Mark recharge PAID + provider metadata =====
        $recharge->status = Recharge::STATUS_PAID;
        if (empty($recharge->submit_time)) {
            $recharge->submit_time = date('Y-m-d H:i:s');
        }
        $recharge->paid_time = date('Y-m-d H:i:s');
        $recharge->complete_time = date('Y-m-d H:i:s');

        // Provider metadata（仅在提供时更新，保留已有值）
        if (!empty($providerMetadata['gateway'])) {
            $recharge->gateway = (string)$providerMetadata['gateway'];
        }
        if (!empty($providerMetadata['gateway_trade_id'])) {
            $recharge->gateway_trade_id = (string)$providerMetadata['gateway_trade_id'];
        }
        if (!empty($providerMetadata['gateway_status'])) {
            $recharge->gateway_status = (string)$providerMetadata['gateway_status'];
        }
        if (array_key_exists('gateway_actual_amount', $providerMetadata) && $providerMetadata['gateway_actual_amount'] !== null) {
            $recharge->gateway_actual_amount = (float)$providerMetadata['gateway_actual_amount'];
        }
        if (!empty($providerMetadata['gateway_txid'])) {
            $recharge->gateway_txid = (string)$providerMetadata['gateway_txid'];
        }
        if (!empty($providerMetadata['gateway_notify_payload'])) {
            $recharge->gateway_notify_payload = (string)$providerMetadata['gateway_notify_payload'];
        }

        $recharge->save();

        Log::info('recharge settlement: settled', [
            'order_number' => $orderNumber,
            'source' => $source,
            'amount' => $localAmount,
            'uid' => $uid,
            'manual_approved' => $manualApproved,
            'ledger_duplicated' => !empty($ledgerResult['duplicated']),
        ]);

        return [
            'result' => self::RESULT_SETTLED,
            'message' => 'Recharge settled successfully',
            'ledger' => $ledgerResult,
            'recharge' => $recharge->toArray(),
            'amount' => $localAmount,
            'balance_before' => (float)($ledgerResult['before_amount'] ?? 0),
            'balance_after' => (float)($ledgerResult['after_amount'] ?? 0),
        ];
    }

    /**
     * 判断结算结果是否应该返回 Provider success ACK
     *
     * ACK 语义与业务处理分离：
     *   - SETTLED / ALREADY_PAID → Provider success ACK（业务已处理或幂等）
     *   - NOT_SETTLEABLE / INVALID_AMOUNT / NOT_FOUND → 不应返回 success ACK
     *     （让 Provider 重试或告警，避免静默丢单）
     *
     * @param string $result
     * @return bool
     */
    public function shouldAckSuccess(string $result): bool
    {
        return in_array($result, [
            self::RESULT_SETTLED,
            self::RESULT_ALREADY_PAID,
        ], true);
    }

    /**
     * 人工批准恢复结算（Pre-R1 Batch A5.3）
     *
     * 必须传入一个 status=APPROVED 的 RecoveryCase，不能只传 recharge_id。
     * 这确保任何人工补款都经过 Admin Review + RBAC + 2FA（A5.2 实现）。
     *
     * 锁顺序：RecoveryCase → Recharge → User → Ledger
     * （Callback 路径不锁 RecoveryCase，无交叉死锁风险）
     *
     * 事务边界：RecoveryCase SETTLED + Recharge PAID + ledger credit 在同一 DB transaction。
     *
     * 与 Callback 并发：统一 request_no = recharge_paid:{order_number} + 账本唯一约束 + PAID 幂等，
     * 无论谁先执行，最终只到账一次。
     *
     * @param RecoveryCase $case 已批准的恢复案件（必须 status=APPROVED）
     * @param array $providerMetadata Provider 元数据（gateway_trade_id, gateway_txid 等）
     * @return array{result: string, message: string, ledger?: array, recharge?: array, case?: array}
     * @throws Exception 系统异常
     */
    public function settleManualApproved(RecoveryCase $case, array $providerMetadata = []): array
    {
        return Db::transaction(function () use ($case, $providerMetadata) {
            // ===== 1. 锁定 RecoveryCase，验证状态 =====
            $lockedCase = RecoveryCase::where('id', $case->id)->lock(true)->find();
            if (!$lockedCase) {
                return [
                    'result' => self::RESULT_CASE_NOT_FOUND,
                    'message' => 'RecoveryCase not found: id=' . $case->id,
                ];
            }

            if (!$lockedCase->isSettleable()) {
                return [
                    'result' => self::RESULT_CASE_NOT_APPROVED,
                    'message' => 'RecoveryCase not approved (status=' . $lockedCase->status . '), cannot settle',
                    'case' => $lockedCase->toArray(),
                ];
            }

            // ===== 2. 锁定 Recharge =====
            $recharge = Recharge::where('id', $lockedCase->recharge_id)->lock(true)->find();
            if (!$recharge) {
                return [
                    'result' => self::RESULT_NOT_FOUND,
                    'message' => 'Recharge not found for recovery case: id=' . $lockedCase->recharge_id,
                ];
            }

            // ===== 3. 执行核心结算（manualApproved=true，绕过 isAutoSettleable 的 cancel_source 检查）=====
            $source = 'admin_manual';
            $settleResult = $this->doSettleLocked($recharge, $source, $providerMetadata, true);

            // ===== 4. 更新 RecoveryCase 状态 =====
            if ($settleResult['result'] === self::RESULT_SETTLED) {
                $lockedCase->status = RecoveryCase::STATUS_SETTLED;
                $lockedCase->settlement_result = RecoveryCase::SETTLEMENT_MANUAL;
                $lockedCase->settled_at = date('Y-m-d H:i:s');
                $lockedCase->ledger_request_no = 'recharge_paid:' . (string)$recharge->order_number;
                $lockedCase->save();

                Log::info('recovery case settled (manual)', [
                    'case_id' => $lockedCase->id,
                    'recharge_id' => $recharge->id,
                    'order_number' => (string)$recharge->order_number,
                    'amount' => $settleResult['amount'] ?? 0,
                    'approved_by' => $lockedCase->approved_by_admin_id,
                ]);
            } elseif ($settleResult['result'] === self::RESULT_ALREADY_PAID) {
                // Callback 已先完成，幂等收敛
                $lockedCase->status = RecoveryCase::STATUS_SETTLED;
                $lockedCase->settlement_result = RecoveryCase::SETTLEMENT_ALREADY_PAID;
                $lockedCase->settled_at = date('Y-m-d H:i:s');
                $lockedCase->ledger_request_no = 'recharge_paid:' . (string)$recharge->order_number;
                $lockedCase->save();

                Log::info('recovery case settled by callback first (idempotent)', [
                    'case_id' => $lockedCase->id,
                    'recharge_id' => $recharge->id,
                    'order_number' => (string)$recharge->order_number,
                ]);
            } else {
                // 结算失败（金额无效等），case 保持 APPROVED，可重试或退回
                $lockedCase->settlement_result = RecoveryCase::SETTLEMENT_FAILED;
                $lockedCase->save();

                Log::warning('recovery case settlement failed', [
                    'case_id' => $lockedCase->id,
                    'recharge_id' => $recharge->id,
                    'settle_result' => $settleResult['result'],
                    'message' => $settleResult['message'] ?? '',
                ]);
            }

            $settleResult['case'] = $lockedCase->toArray();
            return $settleResult;
        });
    }
}
