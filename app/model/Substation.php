<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

class Substation extends Model
{
    // ===== Canonical Substation Status (R1.6a.2-B) =====
    // 0 = pending/new, 1 = submitted/awaiting audit, 2 = approved/audit passed,
    // 3 = rejected, 4 = suspended/frozen, 5 = activated/paid
    // Numeric values FROZEN - do not renumber. DB/API contract depends on these.
    public const STATUS_PENDING = 0;
    public const STATUS_SUBMITTED = 1;
    public const STATUS_APPROVED = 2;
    public const STATUS_REJECTED = 3;
    public const STATUS_SUSPENDED = 4;
    public const STATUS_ACTIVATED = 5;

}
