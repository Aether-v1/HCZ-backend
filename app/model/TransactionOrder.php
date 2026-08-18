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
        $expired = $status === 0 && $expireAt > 0 && time() > $expireAt;
        $effective = $expired ? 9 : $status;
        $map = [
            0 => '待汇款',
            1 => '已汇款',
            2 => '已取消',
            3 => '已完成',
            9 => '已超时',
        ];

        return [
            'status' => $status,
            'effective_status' => $effective,
            'status_text' => $map[$effective] ?? '交易订单',
            'expired' => $expired,
            'expire_time' => $expireAt > 0 ? date('Y-m-d H:i:s', $expireAt) : '',
            'remaining_seconds' => $status === 0 ? self::remainingSeconds($order) : 0,
        ];
    }
}
