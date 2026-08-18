<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Cache extends Model
{
    // 设置字段信息
    protected $schema = [
        'k'      => 'string',
        'v'      => 'string',
        'expire' => 'int'
    ];
    // 关闭自动写入create_time,update_time字段
    protected $createTime = false;
    protected $updateTime = false;
    // 设置主键名
    protected $pk = 'k';
}
