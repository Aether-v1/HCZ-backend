<?php
/**
 * HCZ Batch 2-B RBAC Database Migration
 *
 * 功能：创建 RBAC 所需的 4 张表
 * - cz_role              角色表
 * - cz_permission        权限表
 * - cz_admin_role        管理员-角色关联表
 * - cz_role_permission   角色-权限关联表
 *
 * 设计原则：
 * - 幂等：CREATE TABLE IF NOT EXISTS，可重复执行
 * - 可回滚：提供 rollback() 方法
 * - 遵循项目现有规范：int unsigned 主键、create_time/update_time、uk_/idx_ 索引、utf8mb4、无外键
 * - 不预置数据：只建表结构，不插入角色/权限数据（数据迁移在后续批次）
 *
 * 用法：
 *   php database/migrations/2024_01_01_000001_create_rbac_tables.php migrate
 *   php database/migrations/2024_01_01_000001_create_rbac_tables.php rollback
 *   php database/migrations/2024_01_01_000001_create_rbac_tables.php status
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use think\App;
use think\facade\Db;

$app = new App(dirname(__DIR__, 2));
$app->initialize();

$action = $argv[1] ?? 'migrate';

$migration = new class {
    private array $tables = ['cz_role', 'cz_permission', 'cz_admin_role', 'cz_role_permission'];

    public function migrate(): void
    {
        echo "=== HCZ Batch 2-B RBAC Migration ===\n\n";

        $this->createRoleTable();
        $this->createPermissionTable();
        $this->createAdminRoleTable();
        $this->createRolePermissionTable();

        echo "\n=== Migration 完成 ===\n";
        $this->status();
    }

    public function rollback(): void
    {
        echo "=== HCZ Batch 2-B RBAC Rollback ===\n\n";

        // 按依赖逆序删除（关联表先删）
        $dropOrder = ['cz_role_permission', 'cz_admin_role', 'cz_permission', 'cz_role'];
        foreach ($dropOrder as $table) {
            Db::execute("DROP TABLE IF EXISTS `{$table}`");
            echo "  DROP TABLE {$table}: OK\n";
        }

        echo "\n=== Rollback 完成 ===\n";
    }

    public function status(): void
    {
        echo "\n=== 表状态 ===\n";
        foreach ($this->tables as $table) {
            try {
                $result = Db::query("SHOW TABLES LIKE '{$table}'");
                $exists = !empty($result);
                echo sprintf("  %-25s %s\n", $table, $exists ? 'EXISTS' : 'MISSING');

                if ($exists) {
                    $indexes = Db::query("SHOW INDEX FROM `{$table}`");
                    $indexNames = array_unique(array_column($indexes, 'Key_name'));
                    echo sprintf("    索引: %s\n", implode(', ', $indexNames));
                }
            } catch (\Throwable $e) {
                echo sprintf("  %-25s ERROR: %s\n", $table, $e->getMessage());
            }
        }
    }

    private function createRoleTable(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `cz_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '角色ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '角色名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '角色编码（机器可读，如 super_admin）',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '角色描述',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC 角色表'
SQL;
        Db::execute($sql);
        echo "  CREATE TABLE cz_role: OK\n";
    }

    private function createPermissionTable(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `cz_permission` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '权限ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '权限名称（中文，如 用户列表）',
  `code` varchar(100) NOT NULL DEFAULT '' COMMENT '权限编码（resource.action，如 admin.user.view）',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '权限描述',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC 权限表'
SQL;
        Db::execute($sql);
        echo "  CREATE TABLE cz_permission: OK\n";
    }

    private function createAdminRoleTable(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `cz_admin_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '关联ID',
  `admin_id` int unsigned NOT NULL DEFAULT '0' COMMENT '管理员ID',
  `role_id` int unsigned NOT NULL DEFAULT '0' COMMENT '角色ID',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_role` (`admin_id`, `role_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC 管理员-角色关联表'
SQL;
        Db::execute($sql);
        echo "  CREATE TABLE cz_admin_role: OK\n";
    }

    private function createRolePermissionTable(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `cz_role_permission` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '关联ID',
  `role_id` int unsigned NOT NULL DEFAULT '0' COMMENT '角色ID',
  `permission_id` int unsigned NOT NULL DEFAULT '0' COMMENT '权限ID',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC 角色-权限关联表'
SQL;
        Db::execute($sql);
        echo "  CREATE TABLE cz_role_permission: OK\n";
    }
};

match ($action) {
    'migrate' => $migration->migrate(),
    'rollback' => $migration->rollback(),
    'status' => $migration->status(),
    default => die("用法: php {$argv[0]} [migrate|rollback|status]\n"),
};
