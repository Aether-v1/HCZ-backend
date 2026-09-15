<?php
/**
 * HCZ Batch 2-D3-F2 RBAC Permission Seeder
 *
 * 功能：幂等初始化 D3-F2 资金安全修复所需的细粒度权限
 *
 * 权限清单：
 * - admin.order.recharge.delete
 * - admin.order.query.delete
 * - admin.rebate.delete
 *
 * 用法：
 *   php database/seeders/2024_01_06_000001_seed_d3f2_permissions.php
 *   php database/seeders/2024_01_06_000001_seed_d3f2_permissions.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'seed';

$seeder = new class {
    private array $permissions = [
        ['name' => '充值订单删除', 'code' => 'admin.order.recharge.delete', 'description' => '充值业务订单删除/批量删除'],
        ['name' => '查询订单删除', 'code' => 'admin.order.query.delete',    'description' => '查询业务订单删除/批量删除'],
        ['name' => '返佣记录删除', 'code' => 'admin.rebate.delete',          'description' => '返佣记录删除/批量删除'],
    ];

    public function seed(): void
    {
        echo "=== HCZ Batch 2-D3-F2 RBAC Permission Seeder ===\n\n";
        $this->seedPermissions();
        $this->syncSuperAdminPermissions();
        echo "\n=== Seeder 完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n--- D3-F2 权限状态 ---\n";
        $total = Db::name('permission')->count();
        echo sprintf("  权限总数: %d\n", $total);
        echo "\n  D3-F2 新增权限:\n";
        foreach ($this->permissions as $perm) {
            $exists = Db::name('permission')->where('code', $perm['code'])->find();
            $status = $exists ? '✓ 已存在' : '✗ 缺失';
            echo sprintf("    %-40s %s\n", $perm['code'], $status);
        }
    }

    private function seedPermissions(): void
    {
        echo "--- 初始化 D3-F2 细粒度权限 ---\n";
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
