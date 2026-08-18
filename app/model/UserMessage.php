<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * @mixin Model
 */
class UserMessage extends Model
{
    protected $table = 'cz_user_message';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'biz_id' => 'integer',
        'is_pinned' => 'integer',
        'is_read' => 'integer',
        'is_deleted' => 'integer',
        'sender_admin_id' => 'integer',
    ];
}
