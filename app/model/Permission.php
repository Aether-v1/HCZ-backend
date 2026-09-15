<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * RBAC 权限模型
 *
 * 对应表：cz_permission
 * 职责：仅数据实体定义 + 基础关系访问，不包含业务权限判断
 *
 * @mixin Model
 * @property int    $id          权限ID
 * @property string $name        权限名称（中文，如 用户列表）
 * @property string $code        权限编码（resource.action，如 admin.user.view）
 * @property string $description 权限描述
 * @property int    $status      状态：1启用 0禁用
 * @property string $create_time 创建时间
 * @property string $update_time 更新时间
 */
class Permission extends Model
{
    /**
     * 权限与角色的多对多关系
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, RolePermission::class, 'role_id', 'permission_id');
    }
}
