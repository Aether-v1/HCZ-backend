<?php
namespace app\model;

use think\Model;

/**
 * HCZ INFO-022-A: 积分返还意图模型（Outbox / Crash Recovery）
 *
 * 对应表 cz_refund_intent。
 * DB 是唯一权威：一旦 intent 被持久化，即使 Redis 不可用或 PHP crash，
 * Worker 也能通过 processPendingIntents() 恢复执行。
 */
class RefundIntent extends Model
{
    protected $table = 'cz_refund_intent';
    protected $autoWriteTimestamp = false;

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
}
