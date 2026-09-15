<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Product extends Model
{
    // ===== Canonical Product Status (R1.6a) =====
    // 0 = disabled, 1 = enabled
    // Numeric values FROZEN - do not renumber.
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    // 设置json类型字段
    protected $json = ['order_info', 'par_value', 'discount'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;
}
