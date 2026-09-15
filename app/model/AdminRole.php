<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * RBAC 管理员-角色关联模型
 *
 * 对应表：cz_admin_role
 * 职责：仅数据实体定义，不包含业务逻辑
 *
 * @mixin Model
 * @property int    $id          关联ID
 * @property int    $admin_id    管理员ID
 * @property int    $role_id     角色ID
 * @property string $create_time 创建时间
 */
class AdminRole extends Model
{
    // 关联表无 update_time，关闭自动时间戳
    protected $autoWriteTimestamp = false;

    /**
     * 关联管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 关联角色
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }
}
