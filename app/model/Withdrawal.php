<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Withdrawal extends Model
{
    // ===== Canonical Withdrawal Status (R1.6a) =====
    // 0 = pending review, 1 = approved/success, 2 = rejected/failed
    // Numeric values FROZEN - do not renumber.
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

}
