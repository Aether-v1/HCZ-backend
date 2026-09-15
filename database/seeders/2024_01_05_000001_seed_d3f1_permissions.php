<?php
/**
 * HCZ Batch 2-D3-F1 RBAC Permission Seeder
 *
 * 功能：幂等初始化 D3-F1 资金接口所需的细粒度权限
 *
 * 设计原则：
 * - 幂等：可重复执行，已存在的权限跳过
 * - 不删除：不 DELETE/TRUNCATE/DROP 任何现有数据
 * - 不覆盖：已存在的权限保留原样
 * - 最小化：只创建 D3-F1 实际需要的 10 个权限
 *
 * 权限清单：
 * - admin.withdrawal.approve / .reject / .delete
 * - admin.recharge.approve / .reject / .delete
 * - admin.user.balance.add / .subtract
 * - admin.order.recharge.complete / .cancel
 *
 * 用法：
 *   php database/seeders/2024_01_05_000001_seed_d3f1_permissions.php
 *   php database/seeders/2024_01_05_000001_seed_d3f1_permissions.php status
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
     * D3-F1 资金接口所需的 10 个细粒度权限
     */
    private array $permissions = [
        // 提现
        ['name' => '提现审核通过', 'code' => 'admin.withdrawal.approve', 'description' => '提现审核通过（扣减冻结余额+平台手续费）'],
        ['name' => '提现审核拒绝', 'code' => 'admin.withdrawal.reject',  'description' => '提现审核拒绝（冻结余额退回）'],
        ['name' => '提现记录删除', 'code' => 'admin.withdrawal.delete',  'description' => '提现记录删除/批量删除'],
        // 充值
        ['name' => '充值审核通过', 'code' => 'admin.recharge.approve',   'description' => '充值审核通过（余额增加）'],
        ['name' => '充值审核拒绝', 'code' => 'admin.recharge.reject',    'description' => '充值审核拒绝（仅标记状态）'],
        ['name' => '充值记录删除', 'code' => 'admin.recharge.delete',    'description' => '充值记录删除/批量删除（禁止删待审核）'],
        // 用户余额
        ['name' => '用户余额加款', 'code' => 'admin.user.balance.add',     'description' => '后台人工加款（增加用户余额）'],
        ['name' => '用户余额扣款', 'code' => 'admin.user.balance.subtract', 'description' => '后台人工扣款（减少用户余额）'],
        // 订单审核
        ['name' => '充值订单完成', 'code' => 'admin.order.recharge.complete', 'description' => '充值订单完成（结算+退款+返佣+分站结算）'],
        ['name' => '充值订单取消', 'code' => 'admin.order.recharge.cancel',   'description' => '充值订单取消（冻结余额退回）'],
    ];

    public function seed(): void
    {
        echo "=== HCZ Batch 2-D3-F1 RBAC Permission Seeder ===\n\n";

        $this->seedPermissions();
        $this->syncSuperAdminPermissions();

        echo "\n=== Seeder 完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n--- D3-F1 权限状态 ---\n";

        $total = Db::name('permission')->count();
        echo sprintf("  权限总数: %d\n", $total);

        echo "\n  D3-F1 新增权限:\n";
        foreach ($this->permissions as $perm) {
            $exists = Db::name('permission')->where('code', $perm['code'])->find();
            $status = $exists ? '✓ 已存在' : '✗ 缺失';
            echo sprintf("    %-40s %s\n", $perm['code'], $status);
        }
    }

    private function seedPermissions(): void
    {
        echo "--- 初始化 D3-F1 细粒度权限 ---\n";
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
