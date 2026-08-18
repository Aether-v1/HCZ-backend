<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class Config extends Model
{
    // 设置字段信息
    protected $schema = [
        'k'           => 'string',
        'v'           => 'string',
        'create_time' => 'datetime',
        'update_time' => 'datetime'
    ];
    // 设置主键名
    protected $pk = 'k';
}
