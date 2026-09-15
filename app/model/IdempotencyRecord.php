<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * R1.3b: HTTP Idempotency Record ORM Model
 *
 * 职责：表映射、时间戳映射、状态常量。
 * 禁止：事务管理、acquire、并发、fingerprint 比较、replay 决策、expiry reset、business callback（全部归 IdempotencyService）。
 */
class IdempotencyRecord extends Model
{
    protected $table = 'cz_idempotency_record';

    // 状态常量
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED_RETRYABLE = 'failed_retryable';

    // 第一版允许的 principal_type
    public const PRINCIPAL_USER = 'user';
    public const PRINCIPAL_ADMIN = 'admin';
}
