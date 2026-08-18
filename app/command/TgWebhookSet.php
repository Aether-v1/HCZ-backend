<?php
declare(strict_types=1);

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Config;

class TgWebhookSet extends Command
{
    protected function configure(): void
    {
        $this->setName('tg:webhook:set')
            ->setDescription('设置 Telegram Webhook');
    }

    protected function execute(Input $input, Output $output): void
    {
        $service = new TelegramService();
        $result = $service->setWebhookFromConfig();

        if (($result['code'] ?? 0) !== 1) {
            $output->writeln('设置失败: ' . ($result['msg'] ?? '未知错误'));
            return;
        }

        $output->writeln('Webhook 已设置');
        $output->writeln('url: ' . (string) Config::get('telegram.webhook_url', ''));
        $output->writeln('max_connections: ' . (string) Config::get('telegram.webhook_max_connections', 40));

        $allowedUpdates = Config::get('telegram.webhook_allowed_updates', []);
        $output->writeln('allowed_updates: ' . json_encode($allowedUpdates, JSON_UNESCAPED_UNICODE));
    }
}