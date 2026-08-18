<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class AdminOperationLog extends Model
{
    protected $name = 'admin_operation_log';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = false;
}