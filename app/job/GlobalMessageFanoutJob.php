<?php
namespace app\job;

use app\service\UserMessageService;
use think\facade\Log;
use think\queue\Job;

class GlobalMessageFanoutJob
{
    public function fire(Job $job, $data)
    {
        $templateId = (int)($data['template_id'] ?? 0);
        if ($templateId <= 0) {
            $job->delete();
            return;
        }

        try {
            $affected = UserMessageService::syncGlobalMessageTemplateToAllUsers($templateId);
            Log::info('global message fanout finished', [
                'template_id' => $templateId,
                'affected' => $affected,
                'job_id' => $job->getJobId(),
            ]);
            $job->delete();
        } catch (\Throwable $e) {
            Log::error('global message fanout failed', [
                'template_id' => $templateId,
                'job_id' => $job->getJobId(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'attempts' => $job->attempts(),
            ]);

            if ($job->attempts() < 3) {
                $job->release(30);
                return;
            }

            $job->delete();
        }
    }
}
