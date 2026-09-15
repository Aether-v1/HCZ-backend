<?php
/**
 * HCZ Batch 2-C RBAC Permission Seeder
 *
 * 功能：幂等初始化 RBAC 基础数据
 * - 16 个标准权限（从 docs/rbac-permission-map.md 映射）
 * - super_admin 角色
 * - super_admin 与全部 16 个权限的关联
 *
 * 设计原则：
 * - 幂等：使用 INSERT IGNORE / ON DUPLICATE KEY UPDATE，可重复执行
 * - 不删除：不 DELETE/TRUNCATE/DROP 任何现有数据
 * - 不覆盖：已存在的权限/角色保留原样，不更新 name/description
 * - 安全：仅操作 cz_role/cz_permission/cz_role_permission，不触碰 cz_admin
 *
 * 用法：
 *   php database/seeders/2024_01_02_000001_seed_rbac_permissions.php
 *   php database/seeders/2024_01_02_000001_seed_rbac_permissions.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'seed';

$seeder = new class {
    /**
     * 19 个标准权限定义（与 docs/rbac-permission-map.md 保持一致，Batch 2-D1 新增 3 个分站权限）
     */
    private array $permissions = [
        ['name' => '用户列表',           'code' => 'admin.user.view',              'description' => '查看用户列表、用户详情'],
        ['name' => '支付管理',           'code' => 'admin.payment.manage',         'description' => '支付配置、支付方式管理'],
        ['name' => '充值业务-产品列表',  'code' => 'admin.product.recharge.view',  'description' => '充值类产品管理'],
        ['name' => '查询业务-产品列表',  'code' => 'admin.product.query.view',     'description' => '查询类产品管理'],
        ['name' => '充值业务-订单列表',  'code' => 'admin.order.recharge.view',    'description' => '充值类订单查询'],
        ['name' => '查询业务-订单列表',  'code' => 'admin.order.query.view',       'description' => '查询类订单查询'],
        ['name' => '交易挂单数据',       'code' => 'admin.transaction.pending.view','description' => '交易挂单（C2C 挂单）'],
        ['name' => '交易订单数据',       'code' => 'admin.transaction.order.view',  'description' => '交易订单（C2C 订单）'],
        ['name' => '充值订单记录',       'code' => 'admin.recharge.view',          'description' => '充值记录查询'],
        ['name' => '提现订单记录',       'code' => 'admin.withdrawal.view',        'description' => '提现记录查询、审核'],
        ['name' => '返佣记录',           'code' => 'admin.rebate.view',            'description' => '返佣记录查询'],
        ['name' => '首页轮播图',         'code' => 'admin.banner.manage',          'description' => '轮播图增删改'],
        ['name' => '积分管理',           'code' => 'admin.points.manage',          'description' => '积分配置、积分记录、积分兑换'],
        ['name' => '管理员列表',         'code' => 'admin.admin.view',             'description' => '管理员列表、增删改'],
        ['name' => '操作记录',           'code' => 'admin.log.view',               'description' => '管理员操作日志查询'],
        ['name' => '系统设置管理',       'code' => 'admin.setting.manage',         'description' => '系统配置修改'],
        // Batch 2-D1 新增：分站管理权限（修复 SubstationAdminApi 无授权 P0 问题）
        ['name' => '分站查看',           'code' => 'substation.view',              'description' => '查看分站列表、申请、订单、收入日志'],
        ['name' => '分站审核',           'code' => 'substation.audit',             'description' => '审核分站开通申请、资料修改'],
        ['name' => '分站管理',           'code' => 'substation.manage',            'description' => '管理分站配置、冻结/恢复、钱包余额调整'],
    ];

    public function seed(): void
    {
        echo "=== HCZ Batch 2-C RBAC Permission Seeder ===\n\n";

        $this->seedPermissions();
        $this->seedSuperAdminRole();
        $this->seedSuperAdminPermissions();

        echo "\n=== Seeder 完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n--- RBAC 数据状态 ---\n";

        $roleCount = Db::name('role')->count();
        $permCount = Db::name('permission')->count();
        $adminRoleCount = Db::name('admin_role')->count();
        $rolePermCount = Db::name('role_permission')->count();

        echo sprintf("  cz_role:              %d 条\n", $roleCount);
        echo sprintf("  cz_permission:        %d 条\n", $permCount);
        echo sprintf("  cz_admin_role:        %d 条\n", $adminRoleCount);
        echo sprintf("  cz_role_permission:   %d 条\n", $rolePermCount);

        // 列出所有权限
        echo "\n  权限列表:\n";
        $perms = Db::name('permission')->order('id')->select()->toArray();
        foreach ($perms as $p) {
            echo sprintf("    %-35s %s\n", $p['code'], $p['name']);
        }

        // 列出角色及其权限数
        echo "\n  角色列表:\n";
        $roles = Db::name('role')->order('id')->select()->toArray();
        foreach ($roles as $r) {
            $permCount = Db::name('role_permission')->where('role_id', $r['id'])->count();
            echo sprintf("    %-20s %-15s 权限数: %d\n", $r['code'], $r['name'], $permCount);
        }
    }

    private function seedPermissions(): void
    {
        echo "--- 初始化 16 个标准权限 ---\n";
        $inserted = 0;
        $skipped = 0;

        foreach ($this->permissions as $perm) {
            $exists = Db::name('permission')->where('code', $perm['code'])->find();
            if ($exists) {
                $skipped++;
                continue;
            }
            Db::name('permission')->insert([
                'name' => $perm['name'],
                'code' => $perm['code'],
                'description' => $perm['description'],
                'status' => 1,
            ]);
            $inserted++;
            echo sprintf("  + %s\n", $perm['code']);
        }

        echo sprintf("  完成：新增 %d，跳过 %d（已存在）\n", $inserted, $skipped);
    }

    private function seedSuperAdminRole(): void
    {
        echo "\n--- 初始化 super_admin 角色 ---\n";

        $exists = Db::name('role')->where('code', 'super_admin')->find();
        if ($exists) {
            echo "  super_admin 角色已存在，跳过\n";
            return;
        }

        Db::name('role')->insert([
            'name' => '超级管理员',
            'code' => 'super_admin',
            'description' => '拥有全部权限的超级管理员角色',
            'status' => 1,
        ]);
        echo "  + super_admin 角色创建成功\n";
    }

    private function seedSuperAdminPermissions(): void
    {
        echo "\n--- 关联 super_admin 与全部权限 ---\n";

        $role = Db::name('role')->where('code', 'super_admin')->find();
        if (!$role) {
            echo "  super_admin 角色不存在，跳过\n";
            return;
        }

        $permissions = Db::name('permission')->where('status', 1)->select()->toArray();
        $inserted = 0;
        $skipped = 0;

        foreach ($permissions as $perm) {
            $exists = Db::name('role_permission')
                ->where('role_id', $role['id'])
                ->where('permission_id', $perm['id'])
                ->find();
            if ($exists) {
                $skipped++;
                continue;
            }
            Db::name('role_permission')->insert([
                'role_id' => $role['id'],
                'permission_id' => $perm['id'],
            ]);
            $inserted++;
        }

        echo sprintf("  完成：新增关联 %d，跳过 %d（已存在）\n", $inserted, $skipped);
    }
};

match ($action) {
    'seed' => $seeder->seed(),
    'status' => $seeder->status(),
    default => die("用法: php {$argv[0]} [seed|status]\n"),
};
