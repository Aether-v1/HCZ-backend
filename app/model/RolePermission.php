<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * RBAC 角色-权限关联模型
 *
 * 对应表：cz_role_permission
 * 职责：仅数据实体定义，不包含业务逻辑
 *
 * @mixin Model
 * @property int    $id            关联ID
 * @property int    $role_id       角色ID
 * @property int    $permission_id 权限ID
 * @property string $create_time   创建时间
 */
class RolePermission extends Model
{
    // 关联表无 update_time，关闭自动时间戳
    protected $autoWriteTimestamp = false;

    /**
     * 关联角色
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    /**
     * 关联权限
     */
    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'id');
    }
}
