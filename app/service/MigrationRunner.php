<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * HCZ R1.5a — MigrationRunner (Remediated)
 *
 * Migration Governance Foundation：
 * - scan / validate / sort migration files
 * - SHA-256 checksum tracking
 * - cz_migration tracking table (auto-bootstrap ONLY on state-changing ops)
 * - GET_LOCK global execution lock
 * - plan / status / run / rollback / baseline
 * - explicit funds_related / destructive metadata registry
 * - safe default: unknown migration ≠ auto safe
 * - post-apply verification
 * - orphaned tracking detection
 * - rollback audit history (ROLLED_BACK, no DELETE)
 *
 * R1.5a Remediation fixes (Independent Regression P1/P2):
 * - P1-01: status/plan/dry-run are READ-ONLY, never bootstrap tracking table
 * - P1-02: baseline uses heuristicCheckApplied + exact STATUS:APPLIED, no str_contains
 * - P2-01: funds/destructive/unknown gates are GLOBAL PREFLIGHT (before any execution)
 * - P2-02/P2-05: orphaned tracking detection (COMPLETED/FAILED/RUNNING)
 * - P2-03: stale RUNNING blocks run()
 * - P2-04: post-apply verification after migration execution
 * - P2-06: rollback preserves audit history (status=ROLLED_BACK, no DELETE)
 * - baseline funds gate
 *
 * 执行模型：每个 migration 在独立子进程中执行（php file.php migrate），
 * 避免 include 时触发文件底部的 match dispatch 和重复 App 初始化。
 *
 * 本类不执行任何 business logic，不修改 Funds Core，不触碰 R1.1-R1.4 封板文件。
 */
class MigrationRunner
{
    public const TRACKING_TABLE = 'cz_migration';
    public const LOCK_NAME = 'hcz_migration_lock';
    public const LOCK_TIMEOUT = 10;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /**
     * 显式 migration metadata registry。
     * 第一版使用 central registry，不为 metadata 修改历史 migration 文件。
     *
     * funds_related: 是否触碰资金域 schema/data（order/recharge/refund/points/wallet/ledger 等）
     * destructive: 是否包含破坏性操作（DROP TABLE / DROP COLUMN / RENAME 等）
     *
     * 未在 registry 中的 migration → unknown risk，plan 时 WARN，production run fail closed。
     */
    private const MIGRATION_METADATA = [
        '2024_01_01_000001_create_rbac_tables' => ['funds_related' => false, 'destructive' => false],
        '2024_01_03_000001_migrate_admin_super' => ['funds_related' => false, 'destructive' => false],
        '2024_01_04_000001_alter_cz_order_add_missing_fields' => ['funds_related' => true, 'destructive' => false],
        '2024_01_06_000001_alter_points_record_add_refund_key' => ['funds_related' => true, 'destructive' => false],
        '2024_01_07_000001_create_refund_intent_table' => ['funds_related' => true, 'destructive' => false],
        '2024_01_08_000001_create_points_exchange_order_table' => ['funds_related' => true, 'destructive' => false],
        '2024_01_09_000001_alter_cz_recharge_add_fields' => ['funds_related' => true, 'destructive' => false],
        '2024_01_10_000001_create_recharge_recovery_case_table' => ['funds_related' => true, 'destructive' => false],
        '2024_01_11_000001_seed_recharge_recovery_permissions' => ['funds_related' => true, 'destructive' => false],
        '2024_01_12_000001_create_idempotency_record_table' => ['funds_related' => true, 'destructive' => false],
        // R1.5b: cz_points_task_claim 正式 Schema Owner（从 PointsActions runtime DDL 迁出）
        '2024_01_13_000001_create_points_task_claim_table' => ['funds_related' => true, 'destructive' => false],
    ];

    /** migration 文件名格式：YYYY_MM_DD_NNNNNN_description.php */
    private const FILENAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/';

    private string $migrationsPath;
    private string $phpBinary;

    public function __construct(?string $migrationsPath = null, ?string $phpBinary = null)
    {
        $this->migrationsPath = $migrationsPath ?? (root_path() . 'database' . DIRECTORY_SEPARATOR . 'migrations');
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
    }

    // ==================== Scan / Validate / Sort ====================

    /**
     * 扫描 migration 目录，验证文件名格式，按字典序排序，验证唯一性。
     *
     * @return array{id:string, filename:string, filepath:string, checksum:string, metadata:array, warnings:array[]}
     * @throws \RuntimeException 如果发现 duplicate ID 或文件名格式无效
     */
    public function scanMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new \RuntimeException("Migrations directory not found: {$this->migrationsPath}");
        }

        $files = glob($this->migrationsPath . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return [];
        }

        $migrations = [];
        $seenIds = [];

        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $id = $this->getMigrationId($filename);

            // 验证文件名格式
            if (!$this->validateFilename($filename)) {
                throw new \RuntimeException(
                    "Invalid migration filename format: {$filename}. "
                    . "Expected YYYY_MM_DD_NNNNNN_description.php"
                );
            }

            // 验证唯一性
            if (isset($seenIds[$id])) {
                throw new \RuntimeException("Duplicate migration ID: {$id} (files: {$seenIds[$id]} and {$filename})");
            }
            $seenIds[$id] = $filename;

            $checksum = $this->computeChecksum($filepath);
            $metadata = $this->getMetadata($id);
            $warnings = [];

            if ($metadata['unknown']) {
                $warnings[] = "Migration '{$id}' has no metadata in registry — risk classification UNKNOWN";
            }
            if ($metadata['funds_related']) {
                $warnings[] = "Migration '{$id}' is FUNDS-RELATED — requires explicit authorization in production";
            }
            if ($metadata['destructive']) {
                $warnings[] = "Migration '{$id}' is DESTRUCTIVE — requires explicit --allow-destructive";
            }

            $migrations[] = [
                'id' => $id,
                'filename' => $filename,
                'filepath' => $filepath,
                'checksum' => $checksum,
                'metadata' => $metadata,
                'warnings' => $warnings,
            ];
        }

        // 按 migration ID 字典序排序（不依赖 filesystem glob 返回顺序）
        usort($migrations, fn($a, $b) => strcmp($a['id'], $b['id']));

        return $migrations;
    }

    public function getMigrationId(string $filename): string
    {
        return preg_replace('/\.php$/', '', $filename);
    }

    public function computeChecksum(string $filepath): string
    {
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read migration file: {$filepath}");
        }
        return hash('sha256', $content);
    }

    public function validateFilename(string $filename): bool
    {
        return (bool) preg_match(self::FILENAME_PATTERN, $filename);
    }

    /**
     * 获取 migration metadata。未在 registry 中的标记为 unknown。
     *
     * @return array{funds_related:bool, destructive:bool, unknown:bool}
     */
    public function getMetadata(string $migrationId): array
    {
        if (isset(self::MIGRATION_METADATA[$migrationId])) {
            $meta = self::MIGRATION_METADATA[$migrationId];
            return [
                'funds_related' => (bool) ($meta['funds_related'] ?? false),
                'destructive' => (bool) ($meta['destructive'] ?? false),
                'unknown' => false,
            ];
        }

        // Safe default: unknown migration ≠ auto safe
        return [
            'funds_related' => false,
            'destructive' => false,
            'unknown' => true,
        ];
    }

    // ==================== Tracking Table Bootstrap ====================

    /**
     * 检查 tracking table 是否存在（READ-ONLY，不创建）。
     */
    public function trackingTableExists(): bool
    {
        $table = self::TRACKING_TABLE;
        $tables = Db::query("SHOW TABLES LIKE '{$table}'");
        return !empty($tables);
    }

    /**
     * 自举 tracking table（不作为普通 migration）。
     * CREATE TABLE IF NOT EXISTS + schema 验证。
     * ONLY call this from state-changing operations (run/baseline/rollback),
     * NEVER from read-only operations (plan/status/dry-run).
     *
     * @throws \RuntimeException 如果表已存在但 schema 不符合
     */
    public function bootstrapTracking(): void
    {
        $table = self::TRACKING_TABLE;

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(128) NOT NULL COMMENT 'migration filename (immutable id)',
  `checksum` char(64) NOT NULL COMMENT 'SHA-256 of file content at execution time',
  `status` varchar(16) NOT NULL DEFAULT 'running' COMMENT 'running/completed/failed/rolled_back',
  `funds_related` tinyint(1) NOT NULL DEFAULT 0,
  `database_name` varchar(64) NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `baseline` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=baseline adoption, 0=actual execution',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Migration execution tracking'
SQL;

        Db::execute($sql);

        // 验证 schema 符合预期
        if (!$this->verifyTrackingSchema()) {
            throw new \RuntimeException(
                "Tracking table '{$table}' exists but schema does not match expected. "
                . "Please inspect and fix manually."
            );
        }
    }

    /**
     * 验证 tracking table 有所有 required columns 和 unique index。
     */
    public function verifyTrackingSchema(): bool
    {
        $table = self::TRACKING_TABLE;

        // 检查表存在（SHOW TABLES 不支持参数绑定，表名来自代码内部常量）
        $tables = Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            return false;
        }

        // 检查 required columns
        $requiredColumns = [
            'id', 'migration', 'checksum', 'status', 'funds_related',
            'database_name', 'started_at', 'completed_at', 'error_message', 'baseline',
        ];
        $columns = Db::query("SHOW COLUMNS FROM `{$table}`");
        $existingColumns = array_column($columns, 'Field');

        foreach ($requiredColumns as $col) {
            if (!in_array($col, $existingColumns, true)) {
                return false;
            }
        }

        // 检查 unique index on migration
        $indexes = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = 'uk_migration'");
        if (empty($indexes)) {
            return false;
        }

        return true;
    }

    public function getCurrentDatabase(): string
    {
        $result = Db::query('SELECT DATABASE() AS db');
        return $result[0]['db'] ?? 'unknown';
    }

    // ==================== Lock ====================

    public function acquireLock(): bool
    {
        $result = Db::query('SELECT GET_LOCK(?, ?) AS acquired', [self::LOCK_NAME, self::LOCK_TIMEOUT]);
        return isset($result[0]['acquired']) && (int) $result[0]['acquired'] === 1;
    }

    public function releaseLock(): void
    {
        try {
            Db::execute('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (\Throwable $e) {
            // 释放锁失败不阻塞
        }
    }

    // ==================== Tracking Record Operations ====================

    public function getTrackingRecord(string $migrationId): ?array
    {
        $record = Db::name('migration')
            ->where('migration', $migrationId)
            ->find();

        return $record ?: null;
    }

    public function getAllTrackingRecords(): array
    {
        return Db::name('migration')
            ->order('migration', 'asc')
            ->select()
            ->toArray();
    }

    private function markRunning(string $migrationId, string $checksum, bool $fundsRelated, string $databaseName): void
    {
        // 删除可能存在的 FAILED 或 ROLLED_BACK 记录（允许重试/重新执行）
        Db::name('migration')
            ->where('migration', $migrationId)
            ->whereIn('status', [self::STATUS_FAILED, self::STATUS_ROLLED_BACK])
            ->delete();

        Db::name('migration')->insert([
            'migration' => $migrationId,
            'checksum' => $checksum,
            'status' => self::STATUS_RUNNING,
            'funds_related' => $fundsRelated ? 1 : 0,
            'database_name' => $databaseName,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function markCompleted(string $migrationId): void
    {
        Db::name('migration')
            ->where('migration', $migrationId)
            ->update([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);
    }

    private function markFailed(string $migrationId, string $errorMessage): void
    {
        Db::name('migration')
            ->where('migration', $migrationId)
            ->update([
                'status' => self::STATUS_FAILED,
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => mb_substr($errorMessage, 0, 65535),
            ]);
    }

    private function markRolledBack(string $migrationId): void
    {
        Db::name('migration')
            ->where('migration', $migrationId)
            ->update([
                'status' => self::STATUS_ROLLED_BACK,
                'completed_at' => date('Y-m-d H:i:s'),
                'error_message' => null,
            ]);
    }

    // ==================== Plan / Status (READ-ONLY) ====================

    /**
     * 生成执行计划（READ-ONLY，不创建 tracking table，不执行 migration）。
     *
     * 如果 tracking table 不存在，所有 migration 视为 pending，并输出 warning。
     *
     * @return array{pending:array, completed:array, failed:array, running:array, rolled_back:array, checksum_drifts:array, orphaned:array, warnings:array, tracking_exists:bool}
     */
    public function plan(): array
    {
        // P1-01 FIX: plan() is READ-ONLY — never bootstrap tracking table
        $trackingExists = $this->trackingTableExists();

        $migrations = $this->scanMigrations();
        $migrationIds = array_column($migrations, 'id');

        $records = [];
        $recordMap = [];
        if ($trackingExists) {
            $records = $this->getAllTrackingRecords();
            foreach ($records as $r) {
                $recordMap[$r['migration']] = $r;
            }
        }

        $pending = [];
        $completed = [];
        $failed = [];
        $running = [];
        $rolledBack = [];
        $checksumDrifts = [];
        $orphaned = [];
        $warnings = [];

        if (!$trackingExists) {
            $warnings[] = "[INFO] Tracking table '" . self::TRACKING_TABLE . "' does not exist. All discovered migrations are treated as pending.";
        }

        foreach ($migrations as $m) {
            $id = $m['id'];
            $record = $recordMap[$id] ?? null;

            if ($record === null) {
                // Untracked or ROLLED_BACK → pending
                $pending[] = $m;
                foreach ($m['warnings'] as $w) {
                    $warnings[] = "[PENDING] {$w}";
                }
            } elseif ($record['status'] === self::STATUS_COMPLETED) {
                $completed[] = $m;
                // checksum drift 检测
                if ($record['checksum'] !== $m['checksum']) {
                    $checksumDrifts[] = [
                        'id' => $id,
                        'stored_checksum' => $record['checksum'],
                        'current_checksum' => $m['checksum'],
                    ];
                    $warnings[] = "[CHECKSUM DRIFT] Migration '{$id}' file changed after execution. Stored hash differs from current file hash.";
                }
            } elseif ($record['status'] === self::STATUS_FAILED) {
                $failed[] = $m;
                $warnings[] = "[FAILED] Migration '{$id}' previously failed: {$record['error_message']}";
            } elseif ($record['status'] === self::STATUS_RUNNING) {
                // P2-03: stale RUNNING — may be from previous crash
                $running[] = $m;
                $warnings[] = "[STALE RUNNING] Migration '{$id}' is in RUNNING state — may be stale from previous crash. Manual recovery required.";
            } elseif ($record['status'] === self::STATUS_ROLLED_BACK) {
                // P2-06: ROLLED_BACK → treat as pending (re-runnable)
                $rolledBack[] = $m;
                $pending[] = $m;
                $warnings[] = "[ROLLED BACK] Migration '{$id}' was previously rolled back. Will be re-executed on next migrate:run.";
            } else {
                $warnings[] = "[UNKNOWN STATUS] Migration '{$id}' has unknown tracking status: '{$record['status']}'";
            }
        }

        // P2-02/P2-05: Detect orphaned tracking records (in tracking but not on filesystem)
        if ($trackingExists) {
            foreach ($records as $r) {
                if (!in_array($r['migration'], $migrationIds, true)) {
                    $orphaned[] = $r;
                    $status = $r['status'];
                    $warnings[] = "[ORPHANED {$status}] Tracking record '{$r['migration']}' exists but migration file is missing from filesystem.";
                }
            }
        }

        return [
            'pending' => $pending,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'rolled_back' => $rolledBack,
            'checksum_drifts' => $checksumDrifts,
            'orphaned' => $orphaned,
            'warnings' => $warnings,
            'tracking_exists' => $trackingExists,
        ];
    }

    /**
     * 输出所有 migration 状态（READ-ONLY）。
     */
    public function status(): array
    {
        return $this->plan();
    }

    // ==================== Run ====================

    /**
     * 执行所有 pending migration。
     *
     * @param array $options {
     *   @var bool $allow_funds 允许执行 funds_related migration
     *   @var bool $allow_destructive 允许执行 destructive migration
     *   @var bool $allow_unknown 允许执行 unknown metadata migration
     *   @var bool $production 生产模式（unknown/funds migration fail closed）
     *   @var bool $dry_run 只输出计划不执行（READ-ONLY, no bootstrap）
     * }
     * @return array{executed:array, skipped:array, failed:array, warnings:array}
     * @throws \RuntimeException 如果获取锁失败或存在 FAILED/RUNNING migration
     */
    public function run(array $options = []): array
    {
        $allowFunds = $options['allow_funds'] ?? false;
        $allowDestructive = $options['allow_destructive'] ?? false;
        $allowUnknown = $options['allow_unknown'] ?? false;
        $production = $options['production'] ?? false;
        $dryRun = $options['dry_run'] ?? false;

        // P1-01 FIX: plan() is READ-ONLY, no bootstrap
        $plan = $this->plan();

        // P2-03: stale RUNNING blocks run()
        if (!empty($plan['running'])) {
            $runningIds = array_column($plan['running'], 'id');
            throw new \RuntimeException(
                "Cannot run migrations: STALE RUNNING records exist: " . implode(', ', $runningIds) .
                ". These may be from a previous crash. Manual recovery is required before execution can continue."
            );
        }

        // P2-02/P2-05: orphaned FAILED or RUNNING blocks run()
        $orphanedBlocking = array_filter($plan['orphaned'], fn($r) =>
            in_array($r['status'], [self::STATUS_FAILED, self::STATUS_RUNNING], true)
        );
        if (!empty($orphanedBlocking)) {
            $orphanIds = array_column($orphanedBlocking, 'migration');
            throw new \RuntimeException(
                "Cannot run migrations: ORPHANED " . implode('/', array_unique(array_column($orphanedBlocking, 'status'))) .
                " tracking records exist for missing files: " . implode(', ', $orphanIds) .
                ". Manual cleanup required."
            );
        }

        // 如果有 FAILED migration，阻止执行
        if (!empty($plan['failed'])) {
            $failedIds = array_column($plan['failed'], 'id');
            throw new \RuntimeException(
                "Cannot run migrations: previously FAILED migrations exist: " . implode(', ', $failedIds) .
                ". Fix or reset them before continuing."
            );
        }

        // P1-01 FIX: dry-run is READ-ONLY — return before bootstrap
        if ($dryRun) {
            return [
                'executed' => [],
                'skipped' => $plan['pending'],
                'failed' => [],
                'warnings' => array_merge($plan['warnings'], ['[DRY RUN] No migrations executed. Tracking table not modified.']),
            ];
        }

        if (empty($plan['pending'])) {
            // Bootstrap tracking even if no pending (for status tracking consistency)
            $this->bootstrapTracking();
            return [
                'executed' => [],
                'skipped' => [],
                'failed' => [],
                'warnings' => array_merge($plan['warnings'], ['No pending migrations.']),
            ];
        }

        // P2-01 FIX: GLOBAL PREFLIGHT — check ALL pending migrations before executing ANY
        $preflightResult = $this->preflightGates($plan['pending'], $allowFunds, $allowDestructive, $allowUnknown, $production);
        if ($preflightResult['blocked']) {
            throw new \RuntimeException($preflightResult['message']);
        }

        // Only bootstrap tracking after all preflight checks pass
        $this->bootstrapTracking();

        // 获取全局锁
        if (!$this->acquireLock()) {
            throw new \RuntimeException("Cannot acquire migration lock. Another migration runner may be active.");
        }

        $databaseName = $this->getCurrentDatabase();
        $executed = [];
        $skipped = [];
        $failed = [];
        $warnings = $plan['warnings'];

        try {
            foreach ($plan['pending'] as $m) {
                $id = $m['id'];
                $meta = $m['metadata'];

                // Gates already passed in preflight, but re-check for safety (non-production skip logic)
                if ($meta['unknown'] && !$allowUnknown) {
                    $skipped[] = $m;
                    $warnings[] = "[SKIPPED] Migration '{$id}' has unknown metadata. Pass --allow-unknown to execute.";
                    continue;
                }

                if ($meta['funds_related'] && !$allowFunds) {
                    $skipped[] = $m;
                    $warnings[] = "[SKIPPED] Migration '{$id}' is funds-related. Pass --allow-funds to execute.";
                    continue;
                }

                if ($meta['destructive'] && !$allowDestructive) {
                    $skipped[] = $m;
                    $warnings[] = "[SKIPPED] Migration '{$id}' is destructive. Pass --allow-destructive to execute.";
                    continue;
                }

                // 标记 RUNNING
                $this->markRunning($id, $m['checksum'], $meta['funds_related'], $databaseName);

                // 子进程执行
                $result = $this->executeMigration($m['filepath'], 'migrate');

                if ($result['success']) {
                    // P2-04 FIX: Post-apply verification
                    $verified = $this->verifyMigrationApplied($id, $m);
                    if (!$verified) {
                        $errorMsg = "Migration '{$id}' exited with code 0 but post-apply verification FAILED. Schema/data does not match expected state.";
                        $this->markFailed($id, $errorMsg);
                        $failed[] = $m;
                        $warnings[] = "[FAILED] {$errorMsg}";
                        break;
                    }

                    $this->markCompleted($id);
                    $executed[] = $m;
                    $warnings[] = "[OK] Migration '{$id}' completed successfully (post-apply verification passed).";
                } else {
                    $this->markFailed($id, $result['error']);
                    $failed[] = $m;
                    $warnings[] = "[FAILED] Migration '{$id}' failed: {$result['error']}";
                    // 第一个失败后停止（不继续执行后续 migration）
                    break;
                }
            }
        } finally {
            $this->releaseLock();
        }

        return [
            'executed' => $executed,
            'skipped' => $skipped,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * P2-01 FIX: Global preflight gates — check ALL pending migrations before executing ANY.
     *
     * @return array{blocked:bool, message:string}
     */
    private function preflightGates(array $pending, bool $allowFunds, bool $allowDestructive, bool $allowUnknown, bool $production): array
    {
        $fundsPending = [];
        $destructivePending = [];
        $unknownPending = [];

        foreach ($pending as $m) {
            $meta = $m['metadata'];
            if ($meta['funds_related']) $fundsPending[] = $m['id'];
            if ($meta['destructive']) $destructivePending[] = $m['id'];
            if ($meta['unknown']) $unknownPending[] = $m['id'];
        }

        if ($production) {
            if (!empty($unknownPending) && !$allowUnknown) {
                return [
                    'blocked' => true,
                    'message' => "Production mode preflight BLOCKED: " . count($unknownPending) .
                        " migration(s) have UNKNOWN metadata: " . implode(', ', $unknownPending) .
                        ". Add to MIGRATION_METADATA or pass --allow-unknown. No migrations executed.",
                ];
            }
            if (!empty($fundsPending) && !$allowFunds) {
                return [
                    'blocked' => true,
                    'message' => "Production mode preflight BLOCKED: " . count($fundsPending) .
                        " migration(s) are FUNDS-RELATED: " . implode(', ', $fundsPending) .
                        ". Pass --allow-funds with explicit authorization. No migrations executed.",
                ];
            }
        }

        // Non-production: destructive always requires explicit flag (even in test)
        if (!empty($destructivePending) && !$allowDestructive) {
            return [
                'blocked' => true,
                'message' => "Preflight BLOCKED: " . count($destructivePending) .
                    " migration(s) are DESTRUCTIVE: " . implode(', ', $destructivePending) .
                    ". Pass --allow-destructive to confirm. No migrations executed.",
            ];
        }

        return ['blocked' => false, 'message' => ''];
    }

    // ==================== Rollback ====================

    /**
     * 回滚指定 migration。
     * 注意：rollback 是高风险操作，生产环境默认禁止。
     *
     * P2-06 FIX: rollback preserves audit history (status=ROLLED_BACK, no DELETE).
     *
     * @throws \RuntimeException 如果 migration 未执行或回滚失败
     */
    public function rollback(string $migrationId, array $options = []): array
    {
        $allowDestructive = $options['allow_destructive'] ?? false;
        $production = $options['production'] ?? false;

        if ($production) {
            throw new \RuntimeException("Rollback is blocked in production mode. Use manual recovery.");
        }

        $this->bootstrapTracking();
        $record = $this->getTrackingRecord($migrationId);

        if ($record === null) {
            throw new \RuntimeException("Migration '{$migrationId}' not found in tracking table.");
        }
        if ($record['status'] !== self::STATUS_COMPLETED) {
            throw new \RuntimeException("Migration '{$migrationId}' is not in COMPLETED status (current: {$record['status']}).");
        }

        $migrations = $this->scanMigrations();
        $migration = null;
        foreach ($migrations as $m) {
            if ($m['id'] === $migrationId) {
                $migration = $m;
                break;
            }
        }

        if ($migration === null) {
            throw new \RuntimeException("Migration file for '{$migrationId}' not found on disk.");
        }

        if ($migration['metadata']['destructive'] && !$allowDestructive) {
            throw new \RuntimeException(
                "Migration '{$migrationId}' rollback is destructive. Pass --allow-destructive to confirm."
            );
        }

        // 子进程执行 rollback
        $result = $this->executeMigration($migration['filepath'], 'rollback');

        if (!$result['success']) {
            // P2-06 FIX: rollback failure — tracking remains COMPLETED (audit history preserved)
            throw new \RuntimeException("Rollback of '{$migrationId}' failed: {$result['error']}. Tracking record remains in COMPLETED state.");
        }

        // P2-06 FIX: rollback success — mark ROLLED_BACK, preserve audit history (NO DELETE)
        $this->markRolledBack($migrationId);

        return [
            'id' => $migrationId,
            'status' => self::STATUS_ROLLED_BACK,
            'output' => $result['output'],
        ];
    }

    // ==================== Baseline ====================

    /**
     * Baseline adoption：标记已存在的 migration 为 COMPLETED（不执行）。
     * 用于将现有环境纳入新 tracking 系统。
     *
     * P1-02 FIX: baseline uses heuristicCheckApplied() + exact STATUS:APPLIED contract,
     * NO str_contains fuzzy matching. Fail closed if no reliable proof.
     *
     * Baseline funds gate: funds_related migration requires --allow-funds.
     *
     * @param array $options {
     *   @var bool $allow_funds 允许 baseline funds_related migration
     *   @var bool $production 生产模式
     * }
     * @return array{baselined:array, skipped:array, warnings:array}
     */
    public function baseline(array $options = []): array
    {
        $allowFunds = $options['allow_funds'] ?? false;
        $production = $options['production'] ?? false;

        $this->bootstrapTracking();
        $migrations = $this->scanMigrations();
        $databaseName = $this->getCurrentDatabase();

        $baselined = [];
        $skipped = [];
        $warnings = [];

        foreach ($migrations as $m) {
            $id = $m['id'];
            $record = $this->getTrackingRecord($id);

            // 已在 tracking 中，跳过
            if ($record !== null) {
                $skipped[] = $m;
                $warnings[] = "[SKIP] Migration '{$id}' already in tracking table (status: {$record['status']}).";
                continue;
            }

            // Baseline funds gate
            if ($m['metadata']['funds_related'] && !$allowFunds) {
                if ($production) {
                    throw new \RuntimeException(
                        "Production mode: baseline of funds-related migration '{$id}' requires --allow-funds."
                    );
                }
                $skipped[] = $m;
                $warnings[] = "[SKIPPED] Migration '{$id}' is funds-related. Pass --allow-funds to baseline.";
                continue;
            }

            // P1-02 FIX: Use heuristicCheckApplied as primary proof (exact schema/data verification)
            $heuristicApplied = $this->heuristicCheckApplied($id);

            // Secondary proof: exact STATUS:APPLIED contract from migration's status() output
            // Migration must output a line exactly matching: STATUS: APPLIED (case-sensitive, whole line)
            $statusApplied = false;
            if (!$heuristicApplied) {
                $result = $this->executeMigration($m['filepath'], 'status');
                if ($result['success']) {
                    foreach ($result['output'] as $line) {
                        $trimmed = trim($line);
                        if ($trimmed === 'STATUS: APPLIED') {
                            $statusApplied = true;
                            break;
                        }
                    }
                }
            }

            $applied = $heuristicApplied || $statusApplied;

            if ($applied) {
                Db::name('migration')->insert([
                    'migration' => $id,
                    'checksum' => $m['checksum'],
                    'status' => self::STATUS_COMPLETED,
                    'funds_related' => $m['metadata']['funds_related'] ? 1 : 0,
                    'database_name' => $databaseName,
                    'started_at' => date('Y-m-d H:i:s'),
                    'completed_at' => date('Y-m-d H:i:s'),
                    'baseline' => 1,
                ]);
                $baselined[] = $m;
                $proof = $heuristicApplied ? 'heuristic schema verification' : 'exact STATUS:APPLIED contract';
                $warnings[] = "[BASELINE] Migration '{$id}' marked as COMPLETED (baseline adoption, proof: {$proof}).";
            } else {
                $skipped[] = $m;
                $warnings[] = "[PENDING] Migration '{$id}' does not have reliable proof of being applied. Will be executed on next migrate:run.";
            }
        }

        return [
            'baselined' => $baselined,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }

    // ==================== Post-apply Verification ====================

    /**
     * P2-04 FIX: Verify migration actually applied after execution.
     * Uses heuristicCheckApplied for known migrations.
     * For migrations without heuristic check, verifies exit code was 0 (already checked by caller).
     *
     * @return bool true if verification passed, false if migration did not apply correctly
     */
    public function verifyMigrationApplied(string $migrationId, array $migration): bool
    {
        // Primary: heuristic schema/data verification
        if ($this->hasHeuristicCheck($migrationId)) {
            return $this->heuristicCheckApplied($migrationId);
        }

        // For migrations without heuristic check, we rely on exit code (already verified)
        // This is a known limitation — future migrations should add heuristic checks
        return true;
    }

    /**
     * Check if a migration has a heuristic verification check registered.
     */
    public function hasHeuristicCheck(string $migrationId): bool
    {
        $checks = $this->getHeuristicChecks();
        return isset($checks[$migrationId]);
    }

    // ==================== Heuristic Verification ====================

    /**
     * 启发式检查 migration 是否已应用（用于无 status() 或 status 输出不明确的情况）。
     * P1-02 FIX: This is the PRIMARY proof for baseline, replacing str_contains fuzzy matching.
     */
    private function heuristicCheckApplied(string $migrationId): bool
    {
        $checks = $this->getHeuristicChecks();

        if (isset($checks[$migrationId])) {
            try {
                return (bool) $checks[$migrationId]();
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get all heuristic verification checks (extracted for testability).
     */
    private function getHeuristicChecks(): array
    {
        return [
            '2024_01_01_000001_create_rbac_tables' => fn() => $this->tableExists('cz_role'),
            '2024_01_03_000001_migrate_admin_super' => fn() => $this->tableExists('cz_admin'),
            '2024_01_04_000001_alter_cz_order_add_missing_fields' => fn() => $this->columnExists('cz_order', 'gateway'),
            '2024_01_06_000001_alter_points_record_add_refund_key' => fn() => $this->columnExists('cz_points_record', 'refund_key'),
            '2024_01_07_000001_create_refund_intent_table' => fn() => $this->tableExists('cz_refund_intent'),
            '2024_01_08_000001_create_points_exchange_order_table' => fn() => $this->tableExists('cz_points_exchange_order'),
            '2024_01_09_000001_alter_cz_recharge_add_fields' => fn() => $this->columnExists('cz_recharge', 'gateway'),
            '2024_01_10_000001_create_recharge_recovery_case_table' => fn() => $this->tableExists('cz_recharge_recovery_case'),
            '2024_01_11_000001_seed_recharge_recovery_permissions' => fn() => $this->permissionExists('recharge_recovery.view'),
            '2024_01_12_000001_create_idempotency_record_table' => fn() => $this->tableExists('cz_idempotency_record'),
            // R1.5b: cz_points_task_claim 完整 schema 验证（不能只验证 table exists）
            '2024_01_13_000001_create_points_task_claim_table' => fn() => $this->verifyPointsTaskClaimSchema(),
        ];
    }

    /**
     * R1.5b: 完整验证 cz_points_task_claim schema。
     * 必须验证：table exists + 10 columns + 4 indexes（含列顺序和 unique 属性）。
     * 禁止只验证 table exists。
     */
    private function verifyPointsTaskClaimSchema(): bool
    {
        $table = 'cz_points_task_claim';

        // 1. Table exists
        if (!$this->tableExists($table)) {
            return false;
        }

        // 2. All 10 columns exist
        $requiredColumns = [
            'id', 'uid', 'task_key', 'claim_key', 'task_type',
            'task_date', 'points', 'status', 'create_time', 'update_time',
        ];
        foreach ($requiredColumns as $col) {
            if (!$this->columnExists($table, $col)) {
                return false;
            }
        }

        // 3. Indexes with correct column order and unique property
        // PRIMARY KEY (id)
        if (!$this->indexExists($table, 'PRIMARY', ['id'], true)) {
            return false;
        }
        // UNIQUE KEY uniq_uid_claim_key (uid, claim_key)
        if (!$this->indexExists($table, 'uniq_uid_claim_key', ['uid', 'claim_key'], true)) {
            return false;
        }
        // KEY idx_uid_task (uid, task_key)
        if (!$this->indexExists($table, 'idx_uid_task', ['uid', 'task_key'], false)) {
            return false;
        }
        // KEY idx_task_date (task_key, task_date)
        if (!$this->indexExists($table, 'idx_task_date', ['task_key', 'task_date'], false)) {
            return false;
        }

        return true;
    }

    /**
     * 验证索引存在、列顺序正确、unique 属性匹配。
     * R1.5b: 用于完整 schema 验证，不能只检查 index name。
     *
     * @param string $table 表名
     * @param string $indexName 索引名（PRIMARY 用 'PRIMARY'）
     * @param array $expectedColumns 期望的列顺序数组
     * @param bool $expectedUnique 期望是否唯一索引
     */
    private function indexExists(string $table, string $indexName, array $expectedColumns, bool $expectedUnique): bool
    {
        try {
            // SHOW INDEX 不支持参数绑定，表名/索引名来自代码内部
            $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        } catch (\Throwable $e) {
            return false;
        }

        if (empty($rows)) {
            return false;
        }

        // 按 Seq_in_index 排序，验证列顺序
        usort($rows, fn($a, $b) => ($a['Seq_in_index'] ?? 0) <=> ($b['Seq_in_index'] ?? 0));

        $actualColumns = array_map(fn($r) => $r['Column_name'] ?? '', $rows);
        if ($actualColumns !== $expectedColumns) {
            return false;
        }

        // 验证 unique 属性：Non_unique = 0 表示唯一
        $actualUnique = ($rows[0]['Non_unique'] ?? 1) == 0;
        if ($actualUnique !== $expectedUnique) {
            return false;
        }

        return true;
    }

    private function tableExists(string $table): bool
    {
        // SHOW TABLES 不支持参数绑定，表名来自代码内部
        $result = Db::query("SHOW TABLES LIKE '{$table}'");
        return !empty($result);
    }

    private function columnExists(string $table, string $column): bool
    {
        // SHOW COLUMNS LIKE 不支持参数绑定，表名/列名来自代码内部
        $result = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return !empty($result);
    }

    private function permissionExists(string $code): bool
    {
        if (!$this->tableExists('cz_permission')) {
            return false;
        }
        $result = Db::name('permission')->where('code', $code)->find();
        return !empty($result);
    }

    // ==================== Subprocess Execution ====================

    /**
     * 在独立子进程中执行 migration 文件。
     * 避免 include 时触发底部 match dispatch 和重复 App 初始化。
     *
     * P2-04: Success = exit code 0 AND no exception markers.
     * Post-apply verification is done separately by verifyMigrationApplied().
     *
     * @return array{success:bool, output:array, error:string, exit_code:int}
     */
    private function executeMigration(string $filepath, string $action): array
    {
        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'output' => [],
                'error' => "Migration file not found: {$filepath}",
                'exit_code' => -1,
            ];
        }

        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($this->phpBinary),
            escapeshellarg($filepath),
            escapeshellarg($action)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $success = $exitCode === 0;

        // ThinkPHP exception handler 可能捕获异常并返回 exit code 0
        // 检查输出中是否包含 ThinkPHP CLI 格式化的异常/错误标记（如 [RuntimeException]）
        if ($success) {
            $outputStr = implode("\n", $output);
            if (preg_match('/\[\w+(Exception|Error)\]/', $outputStr)) {
                $success = false;
            }
        }

        $error = $success ? '' : implode("\n", array_slice($output, -20));

        return [
            'success' => $success,
            'output' => $output,
            'error' => $error,
            'exit_code' => $exitCode,
        ];
    }
}
