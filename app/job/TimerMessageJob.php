<?php
namespace app\job;

use think\queue\Job;
use app\service\TelegramService;
use think\facade\Log;

class TimerMessageJob
{
    /**
     * 执行任务
     */
    public function fire(Job $job, $data)
    {
        try {
            $telegramService = new TelegramService();
            $result = $telegramService->processTimers();
            
            if ($result) {
                Log::info('定时消息处理成功');
                $job->delete();
                return true;
            }
            
            Log::warning('定时消息处理失败');
            
            // 重试3次
            if ($job->attempts() < 3) {
                $job->release(60); // 1分钟后重试
            } else {
                $job->delete();
            }
            
        } catch (\Exception $e) {
            Log::error('定时消息任务执行失败', [
                'error' => $e->getMessage(),
                'attempts' => $job->attempts()
            ]);
            
            if ($job->attempts() < 3) {
                $job->release(60);
            } else {
                $job->delete();
            }
        }
    }
}
