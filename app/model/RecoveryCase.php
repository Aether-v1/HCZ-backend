<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 *
 * RecoveryCase — Pre-R1 Batch A5.1
 *
 * 充值人工恢复案件。独立于 Recharge 的审核实体。
 *
 * Recharge 表达支付业务事实（PENDING/EXPIRED/PAID）。
 * RecoveryCase 表达人工恢复审核事实（open/approved/rejected/settled/closed）。
 *
 * 核心约束：
 *   - 一个 recharge 终身只允许一个 recovery case（uk_recharge_id 唯一约束）
 *   - APPROVED ≠ SETTLED：审核通过只表示获得授权，资金到账需 SettlementService 成功
 *   - 结算金额必须取自 Recharge.amount，不能从 claimed_amount 或调用方输入
 *   - 统一幂等键 recharge_paid:{order_number}，与 callback 共用
 *
 * 状态机（最小化）：
 *   open      → 新建，待审核
 *   approved  → 审核通过，可结算
 *   rejected  → 审核拒绝
 *   settled   → 已结算（资金已到账）
 *   closed    → 已关闭（终态）
 */
class RecoveryCase extends Model
{
    // ===== 表名 =====
    protected $table = 'cz_recharge_recovery_case';

    // ===== 主键 =====
    protected $pk = 'id';

    // ===== 自动时间戳 =====
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // ===== 状态常量 (Single Source of Truth) =====
    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CLOSED = 'closed';

    // ===== 结算结果常量 =====
    public const SETTLEMENT_MANUAL = 'settled_manual';      // 人工结算成功（真正造成资金入账）
    public const SETTLEMENT_ALREADY_PAID = 'already_paid';  // callback 已先完成（幂等收敛）
    public const SETTLEMENT_FAILED = 'failed';               // 结算失败

    // ===== 允许的状态迁移 =====
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CLOSED],
        self::STATUS_APPROVED => [self::STATUS_SETTLED, self::STATUS_OPEN, self::STATUS_CLOSED],
        self::STATUS_REJECTED => [self::STATUS_OPEN, self::STATUS_CLOSED],
        self::STATUS_SETTLED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [], // 终态
    ];

    /**
     * 判断是否可以迁移到目标状态
     */
    public function canTransitionTo(string $targetStatus): bool
    {
        $current = (string)($this->status ?? self::STATUS_OPEN);
        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
        return in_array($targetStatus, $allowed, true);
    }

    /**
     * 是否为活跃状态（可审核/可结算）
     */
    public function isActive(): bool
    {
        return in_array((string)($this->status ?? ''), [
            self::STATUS_OPEN,
            self::STATUS_APPROVED,
        ], true);
    }

    /**
     * 是否已结算（资金已到账）
     */
    public function isSettled(): bool
    {
        return (string)($this->status ?? '') === self::STATUS_SETTLED;
    }

    /**
     * 是否可结算（状态为 APPROVED）
     */
    public function isSettleable(): bool
    {
        return (string)($this->status ?? '') === self::STATUS_APPROVED;
    }

    /**
     * 关联 Recharge
     */
    public function recharge()
    {
        return $this->belongsTo(Recharge::class, 'recharge_id', 'id');
    }

    /**
     * 关联 User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }
}
