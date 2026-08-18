<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Phone extends Model
{
    // 设置字段信息
    protected $schema = [
        'prefix'    => 'int',
        'phone'     => 'int',
        'province'  => 'string',
        'city'      => 'string',
        'isp'       => 'string',
        'post_code' => 'int',
        'city_code' => 'int',
        'area_code' => 'int'
    ];
    // 设置主键名
    protected $pk = 'phone';
    // 关闭自动写入create_time,update_time字段
    protected $createTime = false;
    protected $updateTime = false;
    // 只读字段
    // protected $readonly = ['prefix', 'phone', 'province', 'city', 'isp', 'post_code', 'city_code', 'area_code'];
}
