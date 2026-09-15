<?php
declare(strict_types=1);

namespace app\command;

use app\service\MigrationRunner;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;

/**
 * HCZ R1.5a — Migrate CLI Command
 *
 * 用法：
 *   php think migrate status              显示所有 migration 状态
 *   php think migrate plan                显示待执行计划（不执行）
 *   php think migrate run                 执行所有 pending migration
 *   php think migrate run --dry-run       只显示计划不执行
 *   php think migrate run --allow-funds   允许执行 funds_related migration
 *   php think migrate rollback <id>       回滚指定 migration
 *   php think migrate baseline            标记已存在的 migration 为 COMPLETED
 *
 * 安全规则：
 * - 生产模式（--production）下，unknown/funds migration fail closed
 * - rollback 在生产模式下默认禁止
 * - 执行前自动 bootstrap tracking table + acquire global lock
 */
class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate')
            ->setDescription('HCZ Migration Governance Runner')
            ->addArgument('action', Argument::OPTIONAL, 'status|plan|run|rollback|baseline', 'status')
            ->addOption('migration', null, Option::VALUE_OPTIONAL, 'Migration ID (for rollback)')
            ->addOption('allow-funds', null, Option::VALUE_NONE, 'Allow funds-related migrations')
            ->addOption('allow-destructive', null, Option::VALUE_NONE, 'Allow destructive migrations')
            ->addOption('allow-unknown', null, Option::VALUE_NONE, 'Allow unknown metadata migrations')
            ->addOption('production', null, Option::VALUE_NONE, 'Production mode (fail closed)')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Dry run (plan only, no execution)')
            ->addOption('yes', 'y', Option::VALUE_NONE, 'Skip interactive confirmation');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = $input->getArgument('action');
        $runner = new MigrationRunner();

        try {
            switch ($action) {
                case 'status':
                    return $this->actionStatus($runner, $output);
                case 'plan':
                    return $this->actionPlan($runner, $output);
                case 'run':
                    return $this->actionRun($runner, $input, $output);
                case 'rollback':
                    return $this->actionRollback($runner, $input, $output);
                case 'baseline':
                    return $this->actionBaseline($runner, $input, $output);
                default:
                    $output->writeln("<error>Unknown action: {$action}</error>");
                    $output->writeln("Valid actions: status, plan, run, rollback, baseline");
                    return 1;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>Migration error: {$e->getMessage()}</error>");
            return 1;
        }
    }

    private function actionStatus(MigrationRunner $runner, Output $output): int
    {
        $output->writeln('<info>=== HCZ Migration Status ===</info>');

        $dbName = $runner->getCurrentDatabase();
        $output->writeln("Database: <comment>{$dbName}</comment>");

        $plan = $runner->status();

        $output->writeln('');
        $output->writeln(sprintf('Pending:   <comment>%d</comment>', count($plan['pending'])));
        $output->writeln(sprintf('Completed: <info>%d</info>', count($plan['completed'])));
        $output->writeln(sprintf('Failed:    <error>%d</error>', count($plan['failed'])));
        $output->writeln(sprintf('Checksum drifts: <question>%d</question>', count($plan['checksum_drifts'])));

        if (!empty($plan['pending'])) {
            $output->writeln('');
            $output->writeln('<comment>--- Pending Migrations ---</comment>');
            foreach ($plan['pending'] as $m) {
                $flags = [];
                if ($m['metadata']['funds_related']) $flags[] = 'FUNDS';
                if ($m['metadata']['destructive']) $flags[] = 'DESTRUCTIVE';
                if ($m['metadata']['unknown']) $flags[] = 'UNKNOWN';
                $flagStr = $flags ? ' [' . implode(',', $flags) . ']' : '';
                $output->writeln("  {$m['id']}{$flagStr}");
            }
        }

        if (!empty($plan['warnings'])) {
            $output->writeln('');
            $output->writeln('<question>--- Warnings ---</question>');
            foreach ($plan['warnings'] as $w) {
                $output->writeln("  {$w}");
            }
        }

        return 0;
    }

    private function actionPlan(MigrationRunner $runner, Output $output): int
    {
        $output->writeln('<info>=== HCZ Migration Plan (dry-run) ===</info>');

        $dbName = $runner->getCurrentDatabase();
        $output->writeln("Database: <comment>{$dbName}</comment>");

        $plan = $runner->plan();

        $output->writeln('');
        $output->writeln(sprintf('Pending migrations to execute: <comment>%d</comment>', count($plan['pending'])));

        if (!empty($plan['pending'])) {
            $output->writeln('');
            $output->writeln('<comment>--- Execution Order ---</comment>');
            $i = 1;
            foreach ($plan['pending'] as $m) {
                $flags = [];
                if ($m['metadata']['funds_related']) $flags[] = 'FUNDS';
                if ($m['metadata']['destructive']) $flags[] = 'DESTRUCTIVE';
                if ($m['metadata']['unknown']) $flags[] = 'UNKNOWN';
                $flagStr = $flags ? ' [' . implode(',', $flags) . ']' : '';
                $output->writeln(sprintf('  %d. %s%s', $i++, $m['id'], $flagStr));
            }
        }

        if (!empty($plan['warnings'])) {
            $output->writeln('');
            $output->writeln('<question>--- Warnings ---</question>');
            foreach ($plan['warnings'] as $w) {
                $output->writeln("  {$w}");
            }
        }

        $output->writeln('');
        $output->writeln('<info>No migrations executed (plan mode).</info>');

        return 0;
    }

    private function actionRun(MigrationRunner $runner, Input $input, Output $output): int
    {
        $output->writeln('<info>=== HCZ Migration Run ===</info>');

        $dbName = $runner->getCurrentDatabase();
        $output->writeln("Database: <comment>{$dbName}</comment>");

        $options = [
            'allow_funds' => $input->getOption('allow-funds'),
            'allow_destructive' => $input->getOption('allow-destructive'),
            'allow_unknown' => $input->getOption('allow-unknown'),
            'production' => $input->getOption('production'),
            'dry_run' => $input->getOption('dry-run'),
        ];

        if ($options['production']) {
            $output->writeln('<question>*** PRODUCTION MODE ***</question>');
            if (!$input->getOption('yes')) {
                $output->writeln('<error>Production mode requires --yes flag for explicit confirmation.</error>');
                return 1;
            }
        }

        $result = $runner->run($options);

        $output->writeln('');
        $output->writeln(sprintf('Executed: <info>%d</info>', count($result['executed'])));
        $output->writeln(sprintf('Skipped:  <comment>%d</comment>', count($result['skipped'])));
        $output->writeln(sprintf('Failed:   <error>%d</error>', count($result['failed'])));

        if (!empty($result['executed'])) {
            $output->writeln('');
            $output->writeln('<info>--- Executed ---</info>');
            foreach ($result['executed'] as $m) {
                $output->writeln("  [OK] {$m['id']}");
            }
        }

        if (!empty($result['skipped'])) {
            $output->writeln('');
            $output->writeln('<comment>--- Skipped ---</comment>');
            foreach ($result['skipped'] as $m) {
                $output->writeln("  [SKIP] {$m['id']}");
            }
        }

        if (!empty($result['failed'])) {
            $output->writeln('');
            $output->writeln('<error>--- Failed ---</error>');
            foreach ($result['failed'] as $m) {
                $output->writeln("  [FAIL] {$m['id']}");
            }
        }

        if (!empty($result['warnings'])) {
            $output->writeln('');
            $output->writeln('<question>--- Warnings ---</question>');
            foreach ($result['warnings'] as $w) {
                $output->writeln("  {$w}");
            }
        }

        return !empty($result['failed']) ? 1 : 0;
    }

    private function actionRollback(MigrationRunner $runner, Input $input, Output $output): int
    {
        $migrationId = $input->getOption('migration');
        if (empty($migrationId)) {
            $output->writeln('<error>Rollback requires --migration <id></error>');
            return 1;
        }

        $output->writeln('<info>=== HCZ Migration Rollback ===</info>');
        $output->writeln("Migration: <comment>{$migrationId}</comment>");

        if (!$input->getOption('yes')) {
            $output->writeln('<question>Rollback is destructive. Pass --yes to confirm.</question>');
            return 1;
        }

        $result = $runner->rollback($migrationId, [
            'allow_destructive' => $input->getOption('allow-destructive'),
            'production' => $input->getOption('production'),
        ]);

        $output->writeln("<info>Rollback completed: {$result['id']} → {$result['status']}</info>");

        return 0;
    }

    private function actionBaseline(MigrationRunner $runner, Input $input, Output $output): int
    {
        $output->writeln('<info>=== HCZ Migration Baseline Adoption ===</info>');

        $dbName = $runner->getCurrentDatabase();
        $output->writeln("Database: <comment>{$dbName}</comment>");
        $output->writeln('<comment>Marking already-applied migrations as COMPLETED (no execution).</comment>');

        $result = $runner->baseline();

        $output->writeln('');
        $output->writeln(sprintf('Baselined: <info>%d</info>', count($result['baselined'])));
        $output->writeln(sprintf('Skipped:    <comment>%d</comment>', count($result['skipped'])));

        if (!empty($result['baselined'])) {
            $output->writeln('');
            $output->writeln('<info>--- Baselined ---</info>');
            foreach ($result['baselined'] as $m) {
                $output->writeln("  [BASELINE] {$m['id']}");
            }
        }

        if (!empty($result['warnings'])) {
            $output->writeln('');
            $output->writeln('<question>--- Warnings ---</question>');
            foreach ($result['warnings'] as $w) {
                $output->writeln("  {$w}");
            }
        }

        return 0;
    }
}
