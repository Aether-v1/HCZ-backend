<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class TransactionOrder extends Model
{
    public const PENDING_TIMEOUT_SECONDS = 1200;

    // ===== Canonical TransactionOrder Status (R1.6a.2-A) =====
    // 0 = pending(待汇款), 1 = remitted(已汇款), 2 = cancelled(已取消), 3 = completed(已完成)
    // NOTE: 2/3 are SWAPPED vs Order (Order: 2=completed, 3=cancelled).
    // Do NOT reuse Order::STATUS_* — these are independent entities.
    // Numeric values FROZEN — do not renumber.
    public const STATUS_PENDING = 0;
    public const STATUS_REMITTED = 1;
    public const STATUS_CANCELLED = 2;
    public const STATUS_COMPLETED = 3;

    // 设置json类型字段
    protected $json = ['bank_card_info'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;

    public static function pendingTimeoutSeconds(): int
    {
        return self::PENDING_TIMEOUT_SECONDS;
    }

    public static function expirePendingOrders(?int $uid = null): int
    {
        return (new \app\service\TransactionOrderService())->expirePendingOrders($uid);
    }

    public static function remainingSeconds($order): int
    {
        $create = strtotime((string)($order['create_time'] ?? '')) ?: 0;
        if ($create <= 0) {
            return 0;
        }
        return max(0, ($create + self::pendingTimeoutSeconds()) - time());
    }

    public static function buildStatusMeta($order): array
    {
        $status = (int)($order['status'] ?? 0);
        $create = strtotime((string)($order['create_time'] ?? '')) ?: 0;
        $expireAt = $create > 0 ? ($create + self::pendingTimeoutSeconds()) : 0;
        $expired = $status === self::STATUS_PENDING && $expireAt > 0 && time() > $expireAt;
        $effective = $expired ? 9 : $status;
        $map = [
            self::STATUS_PENDING => '待汇款',
            self::STATUS_REMITTED => '已汇款',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_COMPLETED => '已完成',
            9 => '已超时',
        ];

        return [
            'status' => $status,
            'effective_status' => $effective,
            'status_text' => $map[$effective] ?? '交易订单',
            'expired' => $expired,
            'expire_time' => $expireAt > 0 ? date('Y-m-d H:i:s', $expireAt) : '',
            'remaining_seconds' => $status === self::STATUS_PENDING ? self::remainingSeconds($order) : 0,
        ];
    }
}
