<?php
declare(strict_types=1);

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class TgWebhookInfo extends Command
{
    protected function configure(): void
    {
        $this->setName('tg:webhook:info')
            ->setDescription('查看 Telegram Webhook 信息');
    }

    protected function execute(Input $input, Output $output): void
    {
        $service = new TelegramService();
        $result = $service->getWebhookInfo();

        if (($result['code'] ?? 0) !== 1) {
            $output->writeln('查询失败: ' . ($result['msg'] ?? '未知错误'));
            return;
        }

        $data = (array) ($result['data'] ?? []);
        $output->writeln('url: ' . (string) ($data['url'] ?? ''));
        $output->writeln('pending_update_count: ' . (string) ($data['pending_update_count'] ?? 0));
        $output->writeln('last_error_message: ' . (string) ($data['last_error_message'] ?? ''));
        $output->writeln('max_connections: ' . (string) ($data['max_connections'] ?? 0));
        $output->writeln('allowed_updates: ' . json_encode($data['allowed_updates'] ?? [], JSON_UNESCAPED_UNICODE));
    }
}