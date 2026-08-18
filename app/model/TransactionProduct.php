<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class TransactionProduct extends Model
{
    // 设置json类型字段
    protected $json = ['bank_card_info'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;
}
