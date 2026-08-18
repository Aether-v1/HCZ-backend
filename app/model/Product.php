<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Product extends Model
{
    // 设置json类型字段
    protected $json = ['order_info', 'par_value', 'discount'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;
}
