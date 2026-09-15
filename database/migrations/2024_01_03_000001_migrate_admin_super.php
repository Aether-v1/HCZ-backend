<?php
/**
 * HCZ Batch 2-D1: admin.id=1 → super_admin 角色关联迁移
 *
 * 幂等、可回滚、安全检查：
 * 1. 检查 cz_admin 表是否存在
 * 2. 检查 admin.id=1 是否存在
 * 3. 检查 admin.id=1 是否启用（status=1）
 * 4. 检查 super_admin 角色是否存在
 * 5. 检查 admin_role 是否已关联
 * 6. 已关联 → SKIP；未关联 → INSERT
 *
 * 用法：
 *   php database/migrations/2024_01_03_000001_migrate_admin_super.php migrate
 *   php database/migrations/2024_01_03_000001_migrate_admin_super.php rollback
 *   php database/migrations/2024_01_03_000001_migrate_admin_super.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\facade\Db;

$action = $argv[1] ?? 'status';

$migration = new class {
    private const SUPER_ADMIN_ROLE_CODE = 'super_admin';
    private const ADMIN_ID = 1;

    public function migrate(): void
    {
        echo "=== HCZ Batch 2-D1: admin.id=1 → super_admin 迁移 ===\n\n";

        // 1. 检查 cz_admin 表
        if (!$this->tableExists('cz_admin')) {
            echo "[SKIP] cz_admin 表不存在（当前环境可能是测试数据库，无管理员表）\n";
            echo "       在生产环境执行此脚本即可完成迁移。\n";
            return;
        }
        echo "[OK] cz_admin 表存在\n";

        // 2. 检查 admin.id=1 是否存在
        $admin = Db::name('admin')->where('id', self::ADMIN_ID)->find();
        if (!$admin) {
            echo "[BLOCKED] admin.id=1 不存在，禁止创建虚假管理员\n";
            echo "          请确认生产数据库中是否存在 id=1 的管理员记录。\n";
            return;
        }
        $username = $admin['username'] ?? 'unknown';
        echo "[OK] admin.id=1 存在 (username: {$username})\n";

        // 3. 检查 admin.id=1 是否启用
        $status = (int)($admin['status'] ?? 1);
        if ($status !== 1) {
            echo "[BLOCKED] admin.id=1 状态异常 (status={$status})，不自动启用\n";
            echo "          请人工确认该管理员是否应为超级管理员。\n";
            return;
        }
        echo "[OK] admin.id=1 状态正常 (status=1)\n";

        // 4. 检查 super_admin 角色
        $role = Db::name('role')->where('code', self::SUPER_ADMIN_ROLE_CODE)->find();
        if (!$role) {
            echo "[BLOCKED] super_admin 角色不存在，请先执行 Batch 2-C Seeder\n";
            return;
        }
        $roleId = (int)$role['id'];
        echo "[OK] super_admin 角色存在 (role_id={$roleId})\n";

        // 5. 检查是否已关联
        $exists = Db::name('admin_role')
            ->where('admin_id', self::ADMIN_ID)
            ->where('role_id', $roleId)
            ->find();
        if ($exists) {
            echo "[SKIP] admin.id=1 已关联 super_admin 角色，无需重复插入\n";
            $this->status();
            return;
        }

        // 6. 插入关联
        Db::name('admin_role')->insert([
            'admin_id' => self::ADMIN_ID,
            'role_id' => $roleId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        echo "[OK] 已插入 admin_role 关联 (admin_id=1, role_id={$roleId})\n";

        // 7. 验证
        $verify = Db::name('admin_role')
            ->where('admin_id', self::ADMIN_ID)
            ->where('role_id', $roleId)
            ->find();
        if ($verify) {
            echo "[OK] 验证通过：关联已存在\n";
        } else {
            echo "[FAIL] 验证失败：关联未找到\n";
            return;
        }

        echo "\n=== 迁移完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ Batch 2-D1: admin.id=1 → super_admin 回滚 ===\n\n";

        if (!$this->tableExists('cz_admin')) {
            echo "[SKIP] cz_admin 表不存在，无需回滚\n";
            return;
        }

        $role = Db::name('role')->where('code', self::SUPER_ADMIN_ROLE_CODE)->find();
        if (!$role) {
            echo "[SKIP] super_admin 角色不存在，无需回滚\n";
            return;
        }
        $roleId = (int)$role['id'];

        $exists = Db::name('admin_role')
            ->where('admin_id', self::ADMIN_ID)
            ->where('role_id', $roleId)
            ->find();
        if (!$exists) {
            echo "[SKIP] 关联不存在，无需回滚\n";
            return;
        }

        Db::name('admin_role')
            ->where('admin_id', self::ADMIN_ID)
            ->where('role_id', $roleId)
            ->delete();
        echo "[OK] 已删除 admin_role 关联 (admin_id=1, role_id={$roleId})\n";

        echo "\n=== 回滚完成 ===\n";
        $this->status();
    }

    public function status(): void
    {
        echo "\n--- admin.id=1 → super_admin 关联状态 ---\n";

        if (!$this->tableExists('cz_admin')) {
            echo "  cz_admin 表: 不存在（测试环境）\n";
            echo "  关联状态: 无法检查（请在生产环境执行）\n";
            return;
        }

        $admin = Db::name('admin')->where('id', self::ADMIN_ID)->find();
        echo sprintf("  admin.id=1: %s\n", $admin ? '存在' : '不存在');
        if ($admin) {
            echo sprintf("  username: %s\n", $admin['username'] ?? 'unknown');
            echo sprintf("  status: %d\n", (int)($admin['status'] ?? 0));
        }

        $role = Db::name('role')->where('code', self::SUPER_ADMIN_ROLE_CODE)->find();
        echo sprintf("  super_admin 角色: %s\n", $role ? '存在' : '不存在');

        if ($admin && $role) {
            $exists = Db::name('admin_role')
                ->where('admin_id', self::ADMIN_ID)
                ->where('role_id', (int)$role['id'])
                ->find();
            echo sprintf("  admin_role 关联: %s\n", $exists ? '已关联' : '未关联');
        }

        $adminRoleCount = Db::name('admin_role')->count();
        echo sprintf("  cz_admin_role 总记录数: %d\n", $adminRoleCount);
    }

    private function tableExists(string $table): bool
    {
        try {
            $result = Db::query("SHOW TABLES LIKE '{$table}'");
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
