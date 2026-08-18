<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * @mixin Model
 */
class Admin extends Model
{
    // 设置json类型字段
    protected $json = ['power_street'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;
}
