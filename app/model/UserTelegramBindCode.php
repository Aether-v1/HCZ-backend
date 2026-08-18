<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

class UserTelegramBindCode extends Model
{
    protected $name = 'user_tg_bind_code';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer',
        'telegram_user_id' => 'integer',
        'telegram_chat_id' => 'integer',
    ];
}