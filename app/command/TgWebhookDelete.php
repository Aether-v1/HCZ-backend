<?php
declare(strict_types=1);

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class TgWebhookDelete extends Command
{
    protected function configure(): void
    {
        $this->setName('tg:webhook:delete')
            ->addOption('drop-pending', null, Option::VALUE_NONE, '删除时丢弃待处理更新')
            ->setDescription('删除 Telegram Webhook');
    }

    protected function execute(Input $input, Output $output): void
    {
        $service = new TelegramService();
        $dropPending = (bool) $input->getOption('drop-pending');
        $result = $service->deleteWebhook($dropPending);

        if (($result['code'] ?? 0) !== 1) {
            $output->writeln('删除失败: ' . ($result['msg'] ?? '未知错误'));
            return;
        }

        $output->writeln('Webhook 已删除');
        $output->writeln('drop_pending_updates: ' . ($dropPending ? 'true' : 'false'));
    }
}