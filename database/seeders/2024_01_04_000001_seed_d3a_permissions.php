<?php
/**
 * HCZ Batch 2-D3-A RBAC Permission Seeder
 *
 * 功能：幂等初始化 D3-A 非资金接口所需的细粒度权限
 *
 * 设计原则：
 * - 幂等：可重复执行，已存在的权限跳过
 * - 不删除：不 DELETE/TRUNCATE/DROP 任何现有数据
 * - 不覆盖：已存在的权限保留原样
 * - 最小化：只创建实际需要的权限，不为了"完整"创建无用权限
 *
 * 用法：
 *   php database/seeders/2024_01_04_000001_seed_d3a_permissions.php
 *   php database/seeders/2024_01_04_000001_seed_d3a_permissions.php status
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
     * D3-A 非资金接口所需的 20 个细粒度权限
     */
    private array $permissions = [
        // C2C 挂单
        ['name' => 'C2C挂单管理',   'code' => 'admin.transaction.pending.manage', 'description' => 'C2C挂单上架/下架/修改'],
        ['name' => 'C2C挂单删除',   'code' => 'admin.transaction.pending.delete', 'description' => 'C2C挂单删除/批量删除'],
        // C2C 订单
        ['name' => 'C2C订单删除',   'code' => 'admin.transaction.order.delete',   'description' => 'C2C订单删除/批量删除'],
        // 产品
        ['name' => '产品管理',       'code' => 'admin.product.manage',             'description' => '产品新增/修改/状态切换/排序'],
        ['name' => '产品删除',       'code' => 'admin.product.delete',             'description' => '产品删除/批量删除'],
        ['name' => '产品查看',       'code' => 'admin.product.view',               'description' => '产品详情查看'],
        // 积分
        ['name' => '积分查看',       'code' => 'admin.points.view',                'description' => '积分记录/兑换订单列表查看'],
        // 管理员
        ['name' => '管理员管理',     'code' => 'admin.admin.manage',               'description' => '管理员新增/修改'],
        ['name' => '管理员删除',     'code' => 'admin.admin.delete',               'description' => '管理员删除'],
        // 个人账户
        ['name' => '个人账户',       'code' => 'admin.account.self',               'description' => '个人资料/头像修改（仅本人）'],
        // 文件上传
        ['name' => '文件上传',       'code' => 'admin.upload',                     'description' => '通用文件上传'],
        // 站内消息
        ['name' => '消息发送',       'code' => 'admin.message.send',               'description' => '发送站内消息'],
        ['name' => '消息查看',       'code' => 'admin.message.view',               'description' => '消息详情查看'],
        ['name' => '消息管理',       'code' => 'admin.message.manage',             'description' => '消息置顶等管理操作'],
        ['name' => '消息删除',       'code' => 'admin.message.delete',             'description' => '消息删除'],
        // 用户
        ['name' => '用户管理',       'code' => 'admin.user.manage',                'description' => '用户状态切换/2FA解绑'],
        ['name' => '用户删除',       'code' => 'admin.user.delete',                'description' => '用户删除/批量删除'],
        ['name' => '用户密码重置',   'code' => 'admin.user.password.reset',        'description' => '重置用户密码'],
        ['name' => '用户权限设置',   'code' => 'admin.user.rights',                'description' => '设置用户权限'],
    ];

    public function seed(): void
    {
        echo "=== HCZ Batch 2-D3-A RBAC Permission Seeder ===\n\n";

        $this->seedPermissions();
        $this->syncSuperAdminPermissions();

        echo "\n=== Seeder 完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n--- D3-A 权限状态 ---\n";

        $total = Db::name('permission')->count();
        echo sprintf("  权限总数: %d\n", $total);

        echo "\n  D3-A 新增权限:\n";
        foreach ($this->permissions as $perm) {
            $exists = Db::name('permission')->where('code', $perm['code'])->find();
            $status = $exists ? '✓ 已存在' : '✗ 缺失';
            echo sprintf("    %-40s %s\n", $perm['code'], $status);
        }
    }

    private function seedPermissions(): void
    {
        echo "--- 初始化 D3-A 细粒度权限 ---\n";
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

    private function syncSuperAdminPermissions(): void
    {
        echo "\n--- 同步 super_admin 角色权限 ---\n";

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
