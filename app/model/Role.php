<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * RBAC 角色模型
 *
 * 对应表：cz_role
 * 职责：仅数据实体定义 + 基础关系访问，不包含业务权限判断
 *
 * @mixin Model
 * @property int    $id          角色ID
 * @property string $name        角色名称
 * @property string $code        角色编码（机器可读，如 super_admin）
 * @property string $description 角色描述
 * @property int    $status      状态：1启用 0禁用
 * @property string $create_time 创建时间
 * @property string $update_time 更新时间
 */
class Role extends Model
{
    /**
     * 角色与权限的多对多关系
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, RolePermission::class, 'permission_id', 'role_id');
    }

    /**
     * 角色与管理员的多对多关系
     */
    public function admins()
    {
        return $this->belongsToMany(Admin::class, AdminRole::class, 'admin_id', 'role_id');
    }
}
