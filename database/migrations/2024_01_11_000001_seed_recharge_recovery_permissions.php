<?php
/**
 * HCZ Pre-R1 Batch A5.2a
 * Seed recharge_recovery RBAC permissions.
 *
 * R1.5a 适配：统一 migration 接口（AppInit + class + migrate/rollback/status + dispatch）。
 * 业务语义不变：INSERT IGNORE 4 个 recharge_recovery permissions，不授予普通角色。
 *
 * 幂等：使用 INSERT IGNORE，重复执行安全。
 * rollback：安全默认——不删除权限（删除可能影响 admin 访问），输出警告。
 *
 * 用法：
 *   php database/migrations/2024_01_11_000001_seed_recharge_recovery_permissions.php migrate
 *   php database/migrations/2024_01_11_000001_seed_recharge_recovery_permissions.php rollback
 *   php database/migrations/2024_01_11_000001_seed_recharge_recovery_permissions.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private array $permissions = [
        ['code' => 'recharge_recovery.view',   'name' => '查看充值恢复案件', 'description' => '查看人工充值恢复案件列表和详情'],
        ['code' => 'recharge_recovery.create', 'name' => '创建充值恢复案件', 'description' => '为未支付充值订单创建人工恢复案件'],
        ['code' => 'recharge_recovery.review', 'name' => '审核充值恢复案件', 'description' => '批准或拒绝人工充值恢复案件'],
        ['code' => 'recharge_recovery.settle', 'name' => '执行充值恢复结算', 'description' => '执行已批准的人工充值恢复结算（最高权限，需配合敏感操作验证）'],
    ];

    public function migrate(): void
    {
        echo "=== Seed recharge_recovery RBAC permissions ===\n";

        if (!$this->tableExists('cz_permission')) {
            echo "  SKIP: cz_permission table does not exist.\n";
            echo "Migration completed (no-op).\n";
            return;
        }

        $count = 0;
        foreach ($this->permissions as $perm) {
            Db::execute(
                "INSERT IGNORE INTO cz_permission (name, code, description, status, create_time, update_time)
                 VALUES (:name, :code, :description, 1, NOW(), NOW())",
                $perm
            );
            $count++;
            echo "  INSERT IGNORE: {$perm['code']}\n";
        }

        echo "Seeded {$count} recharge_recovery permissions (idempotent).\n";
        echo "Migration completed.\n";
    }

    public function rollback(): void
    {
        echo "=== Rollback seed recharge_recovery permissions ===\n";
        echo "  WARNING: Seed migration rollback is intentionally a no-op.\n";
        echo "  Deleting RBAC permissions may break admin access to recovery features.\n";
        echo "  If you really need to remove these permissions, do it manually.\n";
        echo "Rollback completed (no data deleted).\n";
    }

    public function status(): void
    {
        echo "=== Status: seed recharge_recovery permissions ===\n";

        if (!$this->tableExists('cz_permission')) {
            echo "  cz_permission table: NOT EXISTS\n";
            echo "  Status: PENDING (table not found)\n";
            return;
        }

        $allExist = true;
        foreach ($this->permissions as $perm) {
            $found = Db::name('permission')->where('code', $perm['code'])->find();
            $exists = !empty($found);
            $mark = $exists ? 'EXISTS' : 'MISSING';
            echo "  {$perm['code']}: {$mark}\n";
            if (!$exists) {
                $allExist = false;
            }
        }

        echo "  Status: " . ($allExist ? 'COMPLETED (all permissions exist)' : 'PENDING (some permissions missing)') . "\n";
    }

    private function tableExists(string $table): bool
    {
        // SHOW TABLES 不支持参数绑定，表名来自代码内部常量
        $result = Db::query("SHOW TABLES LIKE '{$table}'");
        return !empty($result);
    }
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
