<?php
declare(strict_types=1);

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class TgCommandsSync extends Command
{
    protected function configure(): void
    {
        $this->setName('tg:commands:sync')
            ->setDescription('同步 Telegram 机器人命令菜单');
    }

    protected function execute(Input $input, Output $output): void
    {
        $service = new TelegramService();
        $result = $service->setBotCommands();

        if ($result !== true) {
            $output->writeln('同步失败: setMyCommands 请求未成功');
            return;
        }

        $output->writeln('机器人命令菜单已同步');
    }
}