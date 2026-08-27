<?php
declare(strict_types=1);

namespace app\command;

use app\model\UserFundLog;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * F15 修复：补齐资金账本幂等唯一索引 uk_uid_wallet_direction_reqno
 *
 * 背景：UserFundLedgerService::createLogWithIdempotentFallback() 依赖该唯一索引
 *       拦截 (uid, wallet_type, direction, request_no) 的重复写入，作为支付回调 /
 *       提现 / 返佣 / 佣金转移等资金操作的幂等兜底。
 *
 * 用法：
 *   php think fund-log:index --check   # 只检查（索引是否存在 + 重复数据预检），不修改数据库
 *   php think fund-log:index           # 预检通过后创建唯一索引（幂等，可重复执行）
 *
 * 安全约定：创建唯一索引之前必做重复数据预检；
 * 若发现重复记录，立即停止且不修改任何数据，等待人工确认。
 */
class FundLogIndex extends Command
{
    protected function configure(): void
    {
        $this->setName('fund-log:index')
            ->addOption('check', null, Option::VALUE_NONE, '仅检查（索引状态 + 重复数据预检），不修改数据库')
            ->setDescription('检查并创建 cz_user_fund_log 幂等唯一索引 uk_uid_wallet_direction_reqno');
    }

    protected function execute(Input $input, Output $output): void
    {
        $checkOnly = (bool) $input->getOption('check');
        $table = UserFundLog::getTable();
        $indexName = 'uk_uid_wallet_direction_reqno';
        $columns = ['uid', 'wallet_type', 'direction', 'request_no'];

        try {
            // 1. 索引是否已存在（幂等）
            if ($this->indexExists($table, $indexName)) {
                $output->writeln("索引 {$indexName} 已存在，无需处理。");
                return;
            }

            // 2. 创建唯一索引前的重复数据预检（只读）
            $duplicates = $this->findDuplicates($table, $columns);
            if (count($duplicates) > 0) {
                $output->writeln('发现历史重复数据，已停止。未做任何数据库修改。');
                $output->writeln('重复组数量: ' . count($duplicates));
                foreach ($duplicates as $dup) {
                    $output->writeln(sprintf(
                        "  uid=%s wallet_type=%s direction=%s request_no=%s 出现 %d 次",
                        (string) ($dup['uid'] ?? ''),
                        (string) ($dup['wallet_type'] ?? ''),
                        (string) ($dup['direction'] ?? ''),
                        (string) ($dup['request_no'] ?? ''),
                        (int) ($dup['cnt'] ?? 0)
                    ));
                }
                $output->writeln('请先人工确认并处理重复数据（禁止擅自 DELETE/UPDATE/合并流水），处理完成后再执行本命令。');
                return;
            }

            if ($checkOnly) {
                $output->writeln('检查完成：索引不存在，无重复数据，可安全创建。');
                return;
            }

            // 3. 创建唯一索引
            $sql = sprintf(
                'ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (`%s`)',
                $table,
                $indexName,
                implode('`,`', $columns)
            );
            Db::execute($sql);

            // 4. 复验
            $created = $this->indexExists($table, $indexName);
            $output->writeln($created
                ? "唯一索引 {$indexName} 创建成功并已复验。"
                : '唯一索引创建后复验失败，请人工核查。');
        } catch (\Throwable $e) {
            $output->writeln('执行失败: ' . $e->getMessage());
            $output->writeln('索引未创建（如有必要请人工核查是否部分执行）。');
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = Db::query(
            'SELECT COUNT(1) AS c FROM information_schema.STATISTICS '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return (int) ($rows[0]['c'] ?? 0) > 0;
    }

    private function findDuplicates(string $table, array $columns): array
    {
        $cols = implode(', ', array_map(static function (string $c): string {
            return '`' . $c . '`';
        }, $columns));

        return Db::query(
            "SELECT {$cols}, COUNT(1) AS cnt FROM `{$table}` "
            . "GROUP BY {$cols} HAVING cnt > 1 ORDER BY cnt DESC LIMIT 100"
        );
    }
}
