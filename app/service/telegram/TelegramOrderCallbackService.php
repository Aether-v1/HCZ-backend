<?php
declare (strict_types=1);

namespace app\service\telegram;

use app\model\Order;
use app\service\ProductOrderService;
use app\service\TelegramService;
use think\facade\Cache;
use think\facade\Log;

class TelegramOrderCallbackService
{
    private TelegramService $telegramService;
    private ProductOrderService $productOrderService;
    private OrderTelegramNotifier $notifier;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
        $this->productOrderService = new ProductOrderService();
        $this->notifier = new OrderTelegramNotifier($telegramService);
    }

    public function handleProductReceiptCallback(array $callbackQuery): bool
    {
        $data = (string)($callbackQuery['data'] ?? '');
        if (!preg_match('/^po:(\d+):(ok|miss):([a-f0-9]{16})$/', $data, $matches)) {
            return false;
        }

        $callbackId = (string)($callbackQuery['id'] ?? '');
        $dedupeKey = $this->telegramService->getCachePrefix() . 'callback:product_receipt:' . $callbackId;
        if (Cache::has($dedupeKey)) {
            $this->telegramService->answerCallbackQuery($callbackId, '请求已处理，请勿重复点击');
            return true;
        }
        Cache::set($dedupeKey, 1, 120);

        $orderId = (int)$matches[1];
        $action = $matches[2];
        $signature = strtolower($matches[3]);
        $tgUserId = (int)($callbackQuery['from']['id'] ?? 0);
        $chatId = (int)($callbackQuery['message']['chat']['id'] ?? 0);
        $messageId = (int)($callbackQuery['message']['message_id'] ?? 0);

        $bindInfo = $this->telegramService->getUserBindInfo($tgUserId);
        if (!$bindInfo || empty($bindInfo['user_id'])) {
            $this->telegramService->answerCallbackQuery($callbackId, '当前TG未绑定平台账号', true);
            return true;
        }

        $order = Order::userVisibleQuery((int)$bindInfo['user_id'])->where('id', $orderId)->find();
        if (!$order) {
            $this->telegramService->answerCallbackQuery($callbackId, '订单不存在或无权操作', true);
            return true;
        }

        if (!$this->notifier->verifyProductReceiptCallback($orderId, (int)($order['uid'] ?? 0), $action, $signature)) {
            $this->telegramService->answerCallbackQuery($callbackId, '回调签名无效', true);
            return true;
        }

        $targetConfirmStatus = $action === 'ok' ? 2 : 3;
        $actionLabel = $action === 'ok' ? '已收到' : '未收到';

        try {
            $updatedOrder = $this->productOrderService->confirmReceiptByUser(
                (int)$bindInfo['user_id'],
                $orderId,
                $targetConfirmStatus,
                [
                    'source' => 'telegram_callback',
                    'tg_user_id' => $tgUserId,
                    'callback_id' => $callbackId,
                ]
            );

            try {
                $this->telegramService->answerCallbackQuery($callbackId, '已处理：' . $actionLabel);
            } catch (\Throwable $writebackException) {
                Log::warning('telegram product receipt callback writeback failed', [
                    'order_id' => (int)($updatedOrder['id'] ?? $orderId),
                    'order_no' => (string)($updatedOrder['order_number'] ?? ''),
                    'uid' => (int)($updatedOrder['uid'] ?? ($bindInfo['user_id'] ?? 0)),
                    'action' => 'product_receipt_callback_answer',
                    'error_message' => $writebackException->getMessage(),
                ]);
            }
            if ($chatId > 0 && $messageId > 0) {
                try {
                    $this->telegramService->editMessageText(
                        $chatId,
                        $messageId,
                        $this->notifier->buildProductReceiptFinalMessage($updatedOrder, $actionLabel),
                        []
                    );
                } catch (\Throwable $writebackException) {
                    Log::warning('telegram product receipt callback writeback failed', [
                        'order_id' => (int)($updatedOrder['id'] ?? $orderId),
                        'order_no' => (string)($updatedOrder['order_number'] ?? ''),
                        'uid' => (int)($updatedOrder['uid'] ?? ($bindInfo['user_id'] ?? 0)),
                        'action' => 'product_receipt_callback_edit_message',
                        'error_message' => $writebackException->getMessage(),
                    ]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            $freshOrder = Order::userVisibleQuery((int)$bindInfo['user_id'])->where('id', $orderId)->find();
            if ($freshOrder && in_array((int)($freshOrder['confirm_status'] ?? 0), [2, 3], true)) {
                $latestLabel = (int)($freshOrder['confirm_status'] ?? 0) === 2 ? '已收到' : '未收到';
                $freshOrderData = $freshOrder->toArray();
                try {
                    $this->telegramService->answerCallbackQuery($callbackId, '该订单已处理');
                } catch (\Throwable $writebackException) {
                    Log::warning('telegram product receipt callback writeback failed', [
                        'order_id' => (int)($freshOrderData['id'] ?? $orderId),
                        'order_no' => (string)($freshOrderData['order_number'] ?? ''),
                        'uid' => (int)($freshOrderData['uid'] ?? ($bindInfo['user_id'] ?? 0)),
                        'action' => 'product_receipt_callback_answer',
                        'error_message' => $writebackException->getMessage(),
                    ]);
                }
                if ($chatId > 0 && $messageId > 0) {
                    try {
                        $this->telegramService->editMessageText(
                            $chatId,
                            $messageId,
                            $this->notifier->buildProductReceiptFinalMessage($freshOrderData, $latestLabel),
                            []
                        );
                    } catch (\Throwable $writebackException) {
                        Log::warning('telegram product receipt callback writeback failed', [
                            'order_id' => (int)($freshOrderData['id'] ?? $orderId),
                            'order_no' => (string)($freshOrderData['order_number'] ?? ''),
                            'uid' => (int)($freshOrderData['uid'] ?? ($bindInfo['user_id'] ?? 0)),
                            'action' => 'product_receipt_callback_edit_message',
                            'error_message' => $writebackException->getMessage(),
                        ]);
                    }
                }
                return true;
            }

            Log::warning('telegram product receipt callback failed', [
                'order_id' => $orderId,
                'tg_user_id' => substr(hash('sha256', (string)$tgUserId), 0, 12),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            try {
                $this->telegramService->answerCallbackQuery($callbackId, $e->getMessage(), true);
            } catch (\Throwable $writebackException) {
                Log::warning('telegram product receipt callback writeback failed', [
                    'order_id' => $orderId,
                    'tg_user_id' => substr(hash('sha256', (string)$tgUserId), 0, 12),
                    'action' => 'product_receipt_callback_answer',
                    'error_message' => $writebackException->getMessage(),
                ]);
            }
            return true;
        }
    }
}