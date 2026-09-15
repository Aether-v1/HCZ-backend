<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * RBAC 授权服务
 *
 * 职责：
 * - 查询管理员的所有权限 code 列表
 * - 判断管理员是否拥有指定权限
 * - 权限缓存（Cache，TTL 300s）
 * - 缓存不可用时降级到 DB 查询
 * - 超级管理员：role.code = 'super_admin' → 全部权限
 *
 * 不负责：
 * - Authentication（登录、Session、密码）→ AdminAuth 中间件负责
 * - 业务逻辑
 * - HTTP Request / Session 操作
 *
 * 安全要求：
 * - 权限匹配使用严格 ===，禁止 strpos/contains/startsWith 模糊匹配
 * - 超级管理员严格由 role.code === 'super_admin' 确定
 * - disabled role / disabled permission 不计入权限
 */
class AuthorizationService
{
    /** 缓存 key 前缀 */
    private const CACHE_PREFIX = 'rbac_admin_permissions_';

    /** 缓存 TTL（秒） */
    private const CACHE_TTL = 300;

    /** 超级管理员角色 code */
    private const SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * 判断管理员是否拥有指定权限
     *
     * @param int    $adminId       管理员ID
     * @param string $permissionCode 权限 code（如 admin.user.view）
     * @return bool
     */
    public function can(int $adminId, string $permissionCode): bool
    {
        if ($adminId <= 0 || trim($permissionCode) === '') {
            return false;
        }

        $permissions = $this->getAdminPermissions($adminId);

        // 超级管理员标记
        if (in_array('*', $permissions, true)) {
            return true;
        }

        // 严格匹配，禁止模糊匹配
        return in_array($permissionCode, $permissions, true);
    }

    /**
     * 判断管理员是否为超级管理员
     *
     * @param int $adminId
     * @return bool
     */
    public function isSuperAdmin(int $adminId): bool
    {
        if ($adminId <= 0) {
            return false;
        }

        try {
            $roleIds = Db::name('admin_role')
                ->where('admin_id', $adminId)
                ->column('role_id');

            if (empty($roleIds)) {
                return false;
            }

            $superAdminRole = Db::name('role')
                ->where('id', 'in', $roleIds)
                ->where('code', self::SUPER_ADMIN_ROLE)
                ->where('status', 1)
                ->find();

            return !empty($superAdminRole);
        } catch (\Throwable $e) {
            Log::error('AuthorizationService isSuperAdmin 查询失败', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 获取管理员的所有权限 code 列表
     *
     * 超级管理员返回 ['*']
     *
     * @param int $adminId
     * @return array<string>
     */
    public function getAdminPermissions(int $adminId): array
    {
        if ($adminId <= 0) {
            return [];
        }

        // 尝试从缓存获取
        $cached = $this->getFromCache($adminId);
        if ($cached !== null) {
            return $cached;
        }

        // 缓存未命中，查 DB
        $permissions = $this->loadPermissionsFromDb($adminId);

        // 写入缓存（失败不影响主流程）
        $this->setCache($adminId, $permissions);

        return $permissions;
    }

    /**
     * 使指定管理员的权限缓存失效
     *
     * @param int $adminId
     * @return void
     */
    public function invalidateAdminPermissions(int $adminId): void
    {
        if ($adminId <= 0) {
            return;
        }
        try {
            Cache::delete(self::CACHE_PREFIX . $adminId);
        } catch (\Throwable $e) {
            Log::warning('AuthorizationService 缓存删除失败', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 使拥有指定角色的所有管理员缓存失效
     *
     * @param int $roleId
     * @return void
     */
    public function invalidateRolePermissions(int $roleId): void
    {
        if ($roleId <= 0) {
            return;
        }
        try {
            $adminIds = Db::name('admin_role')
                ->where('role_id', $roleId)
                ->column('admin_id');

            foreach ($adminIds as $adminId) {
                $this->invalidateAdminPermissions((int)$adminId);
            }
        } catch (\Throwable $e) {
            Log::warning('AuthorizationService 角色缓存失效失败', [
                'role_id' => $roleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 从 DB 加载管理员权限
     *
     * @param int $adminId
     * @return array<string>
     */
    private function loadPermissionsFromDb(int $adminId): array
    {
        try {
            // 1. 查询管理员的所有启用角色
            $roles = Db::name('admin_role')
                ->alias('ar')
                ->join('role r', 'ar.role_id = r.id')
                ->where('ar.admin_id', $adminId)
                ->where('r.status', 1)
                ->field('r.id, r.code')
                ->select()
                ->toArray();

            if (empty($roles)) {
                return [];
            }

            // 2. 检查是否为超级管理员
            foreach ($roles as $role) {
                if ($role['code'] === self::SUPER_ADMIN_ROLE) {
                    return ['*'];
                }
            }

            // 3. 查询所有角色的启用权限
            $roleIds = array_column($roles, 'id');
            $permissions = Db::name('role_permission')
                ->alias('rp')
                ->join('permission p', 'rp.permission_id = p.id')
                ->where('rp.role_id', 'in', $roleIds)
                ->where('p.status', 1)
                ->column('p.code');

            return array_values(array_unique($permissions));
        } catch (\Throwable $e) {
            Log::error('AuthorizationService 权限加载失败', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            // DB 查询失败时安全降级：返回空权限（不授予任何权限）
            return [];
        }
    }

    /**
     * 从缓存获取权限
     *
     * @param int $adminId
     * @return array|null null 表示缓存未命中或缓存不可用
     */
    private function getFromCache(int $adminId): ?array
    {
        try {
            $value = Cache::get(self::CACHE_PREFIX . $adminId);
            if ($value === null) {
                return null;
            }
            if (is_array($value)) {
                return $value;
            }
            // 缓存数据格式异常，视为未命中
            return null;
        } catch (\Throwable $e) {
            // 缓存不可用时降级到 DB 查询
            Log::warning('AuthorizationService 缓存读取失败，降级到 DB', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 写入缓存
     *
     * @param int   $adminId
     * @param array $permissions
     * @return void
     */
    private function setCache(int $adminId, array $permissions): void
    {
        try {
            Cache::set(self::CACHE_PREFIX . $adminId, $permissions, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // 缓存写入失败不影响主流程
            Log::warning('AuthorizationService 缓存写入失败', [
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
