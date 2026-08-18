<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

class UserFundLog extends Model
{
    protected $table = 'cz_user_fund_log';

    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'uid' => 'integer',
        'amount' => 'float',
        'before_amount' => 'float',
        'after_amount' => 'float',
    ];
}
