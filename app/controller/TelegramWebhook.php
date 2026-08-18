<?php
namespace app\controller;

use app\BaseController;
use app\common\library\TelegramHelper;
use app\service\TelegramService;
use think\Response;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Log;

class TelegramWebhook extends BaseController
{
    protected $telegramService;

    protected function initialize()
    {
        $this->telegramService = new TelegramService();
    }

    public function index(): Response
    {
        $startTime = microtime(true);
        $rawBody = (string) file_get_contents('php://input');

        try {
            Log::debug('secret_token validation start', [
                'path' => (string) $this->request->pathinfo(),
                'has_secret_header' => $this->request->header('X-Telegram-Bot-Api-Secret-Token', '') !== '',
            ]);

            TelegramHelper::validateTelegramRequest();

            $update = $rawBody === '' ? [] : json_decode($rawBody, true);
            if ($rawBody !== '' && !is_array($update)) {
                Log::warning('telegram webhook payload decode failed', [
                    'json_error' => json_last_error_msg(),
                ]);

                return json(['ok' => false], 400);
            }

            if (!$this->shouldProcessUpdate($update)) {
                return json(['ok' => true], 200);
            }

            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                try {
                    $this->telegramService->handleCallbackQuery($update['callback_query']);
                } catch (\Throwable $e) {
                    Log::error('handleCallbackQuery异常', $this->buildThrowableLogContext($e, [
                        'callback_query_id' => $update['callback_query']['id'] ?? '',
                        'from_id' => $this->hashLogIdentifier($update['callback_query']['from']['id'] ?? null),
                    ]));
                }
            } elseif (isset($update['message']) && is_array($update['message'])) {
                Log::debug('enter processMessage');

                try {
                    $this->processMessage($update['message']);
                } catch (\Throwable $e) {
                    Log::error('processMessage异常', $this->buildThrowableLogContext($e, [
                        'chat_id' => $this->hashLogIdentifier($update['message']['chat']['id'] ?? null),
                        'message_id' => $this->hashLogIdentifier($update['message']['message_id'] ?? null),
                    ]));
                }
            } else {
                Log::info('telegram webhook ignored update', [
                    'keys' => array_keys($update),
                ]);
            }

            return json(['ok' => true], 200);
        } catch (\Throwable $e) {
            $isSecretTokenError = $this->isSecretTokenException($e);
            $statusCode = $isSecretTokenError ? 403 : 500;

            Log::error('telegram webhook failed', [
                'status_code' => $statusCode,
                'is_secret_token_error' => $isSecretTokenError,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return json(['ok' => false], $statusCode);
        } finally {
            $this->logProcessingTime($startTime, 'index');
        }
    }

    public function queryPhoneBalance($phoneNumber)
    {
        return TelegramHelper::queryPhoneBalance($phoneNumber);
    }

    public function maskPhoneNumber($phoneNumber)
    {
        return TelegramHelper::maskPhoneNumber((string) $phoneNumber);
    }

    private function processMessage(array $message): void
    {
        try {
            if (isset($message['chat']['type']) && in_array($message['chat']['type'], ['supergroup', 'group'], true)) {
                $this->telegramService->handleGroupMessage($message);
                return;
            }

            $this->telegramService->handlePrivateMessage($message);
        } catch (\Throwable $e) {
            Log::error('processMessage异常', $this->buildThrowableLogContext($e, [
                'chat_id' => $this->hashLogIdentifier($message['chat']['id'] ?? null),
                'message_id' => $this->hashLogIdentifier($message['message_id'] ?? null),
            ]));
        }
    }

    private function shouldProcessUpdate(array $update): bool
    {
        $updateId = $update['update_id'] ?? null;

        if (!is_numeric($updateId)) {
            Log::warning('telegram webhook update_id missing', [
                'keys' => array_keys($update),
            ]);
            return false;
        }

        return $this->reserveUpdateProcessing((int) $updateId);
    }

    private function reserveUpdateProcessing(int $updateId): bool
    {
        $ttl = (int) Config::get('telegram.bot_constants.webhook_update_dedupe_ttl', 172800);
        $key = "tg:update:processed:{$updateId}";

        try {
            $reserved = Cache::store('redis')->handler()->set($key, (string) time(), ['nx', 'ex' => $ttl]);
            if ($reserved) {
                return true;
            }

            Log::info('telegram webhook duplicate update ignored', [
                'update_id' => $updateId,
                'dedupe_key' => $key,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('telegram webhook update dedupe failed', $this->buildThrowableLogContext($e, [
                'update_id' => $updateId,
                'dedupe_key' => $key,
                'error_summary' => $e->getMessage(),
            ]));
            return true;
        }
    }

    private function logProcessingTime(float $startTime, string $step): void
    {
        $processTime = (microtime(true) - $startTime) * 1000;
        $threshold = (float) Config::get('telegram.bot_constants.process_time_warning_threshold', 1500);

        if ($processTime > $threshold) {
            Log::warning('webhook processing slow', [
                'total_time_ms' => round($processTime, 2),
                'step' => $step,
                'threshold_ms' => $threshold,
            ]);
            return;
        }

        Log::debug('Webhook????', [
            'total_time_ms' => round($processTime, 2),
            'step' => $step,
        ]);
    }

    private function isSecretTokenException(\Throwable $e): bool
    {
        return in_array($e->getMessage(), [
            'Invalid request token',
            'secret_token configuration missing',
        ], true);
    }

    private function hashLogIdentifier($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', (string) $value), 0, 12);
    }

    private function buildThrowableLogContext(\Throwable $e, array $context = []): array
    {
        $logContext = array_merge([
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], $context);

        if ((bool) Config::get('app.app_debug', false)) {
            $logContext['trace'] = $e->getTraceAsString();
        }

        return $logContext;
    }
}

