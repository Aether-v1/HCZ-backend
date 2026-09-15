<?php
declare(strict_types=1);

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class TimerProcess extends Command
{
    protected function configure(): void
    {
        $this->setName('timer:process')
            ->setDescription('处理到期的 Telegram 定时消息（B03-T：per-timer Redis claim，AT-LEAST-ONCE）');
    }

    protected function execute(Input $input, Output $output): void
    {
        try {
            $service = new TelegramService();
            $result = $service->processTimers();

            if ($result) {
                $output->writeln('定时消息处理完成');
            } else {
                $output->writeln('定时消息处理失败（详见日志）');
            }
        } catch (\Throwable $e) {
            Log::error('timer:process 执行异常', ['error' => $e->getMessage()]);
            $output->writeln('timer:process 执行异常：' . $e->getMessage());
        }
    }
}
