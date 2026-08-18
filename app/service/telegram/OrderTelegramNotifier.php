<?php
declare (strict_types=1);

namespace app\service\telegram;

use app\model\Order;
use app\model\Product;
use app\model\TransactionOrder;
use app\model\User as UserModel;
use app\service\TelegramService;
use think\Model;
use think\facade\Config;
use think\facade\Log;

class OrderTelegramNotifier
{
    private TelegramService $telegramService;

    public function __construct(?TelegramService $telegramService = null)
    {
        $this->telegramService = $telegramService ?: new TelegramService();
    }

    public function notifyProductOrderCreated($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $this->sendToUser(
            (int)($orderData['uid'] ?? 0),
            $this->buildProductOrderMessage($orderData, '订单已创建，等待平台处理。'),
            [],
            ['scene' => 'product_created', 'order_id' => (int)($orderData['id'] ?? 0)]
        );
    }

    public function notifyProductOrderProcessing($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $this->sendToUser(
            (int)($orderData['uid'] ?? 0),
            $this->buildProductOrderMessage($orderData, '订单已进入处理中，请耐心等待。'),
            [],
            ['scene' => 'product_processing', 'order_id' => (int)($orderData['id'] ?? 0)]
        );
    }

    public function notifyProductOrderCompleted($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $inlineKeyboard = [];
        if ($this->shouldShowProductReceiptActions($orderData)) {
            $inlineKeyboard = [[
                ['text' => '已收到', 'callback_data' => $this->buildProductReceiptCallbackData($orderData, 'ok')],
                ['text' => '未收到', 'callback_data' => $this->buildProductReceiptCallbackData($orderData, 'miss')],
            ]];
        }

        $suffix = $this->shouldShowProductReceiptActions($orderData)
            ? '订单已完成，请直接在当前消息确认是否已收到。'
            : '订单已完成。';

        $this->sendToUser(
            (int)($orderData['uid'] ?? 0),
            $this->buildProductOrderMessage($orderData, $suffix),
            $inlineKeyboard,
            ['scene' => 'product_completed', 'order_id' => (int)($orderData['id'] ?? 0)]
        );
    }

    public function notifyProductOrderCancelled($order, string $reason = ''): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $suffix = '订单已取消';
        if ($reason !== '') {
            $suffix .= '，原因：' . $reason;
        }
        $suffix .= '。';

        $this->sendToUser(
            (int)($orderData['uid'] ?? 0),
            $this->buildProductOrderMessage($orderData, $suffix),
            [],
            ['scene' => 'product_cancelled', 'order_id' => (int)($orderData['id'] ?? 0)]
        );
    }

    public function notifyUsdtOrderPendingSellerConfirm($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $buyerText = $this->buildUsdtOrderMessage($orderData, '买家已提交付款凭证，等待卖家验收。', 'buyer');
        $sellerText = $this->buildUsdtOrderMessage($orderData, '买家已提交付款凭证，请及时验收并放币。', 'seller');

        $this->sendToUser((int)($orderData['uid'] ?? 0), $buyerText, [], ['scene' => 'usdt_paid_buyer', 'order_id' => (int)($orderData['id'] ?? 0)]);
        $this->sendToUser((int)($orderData['sell_uid'] ?? 0), $sellerText, [], ['scene' => 'usdt_paid_seller', 'order_id' => (int)($orderData['id'] ?? 0)]);
    }

    public function notifyUsdtOrderReleased($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $buyerText = $this->buildUsdtOrderMessage($orderData, '卖家已放币，订单已完成。', 'buyer');
        $sellerText = $this->buildUsdtOrderMessage($orderData, '您已完成放币，订单已完成。', 'seller');

        $this->sendToUser((int)($orderData['uid'] ?? 0), $buyerText, [], ['scene' => 'usdt_release_buyer', 'order_id' => (int)($orderData['id'] ?? 0)]);
        $this->sendToUser((int)($orderData['sell_uid'] ?? 0), $sellerText, [], ['scene' => 'usdt_release_seller', 'order_id' => (int)($orderData['id'] ?? 0)]);
    }

    public function notifyUsdtOrderCancelled($order, string $reason = ''): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $reasonText = $reason !== '' ? '，原因：' . $reason : '';
        $buyerText = $this->buildUsdtOrderMessage($orderData, '交易订单已取消' . $reasonText . '。', 'buyer');
        $sellerText = $this->buildUsdtOrderMessage($orderData, '交易订单已取消' . $reasonText . '。', 'seller');

        $this->sendToUser((int)($orderData['uid'] ?? 0), $buyerText, [], ['scene' => 'usdt_cancel_buyer', 'order_id' => (int)($orderData['id'] ?? 0)]);
        $this->sendToUser((int)($orderData['sell_uid'] ?? 0), $sellerText, [], ['scene' => 'usdt_cancel_seller', 'order_id' => (int)($orderData['id'] ?? 0)]);
    }

    public function notifyWalletRechargePaid($recharge): void
    {
        $rechargeData = $this->normalizeRecord($recharge);
        if (empty($rechargeData)) {
            return;
        }

        $this->sendToUser(
            (int)($rechargeData['uid'] ?? 0),
            $this->buildWalletRechargeMessage($rechargeData),
            [],
            ['scene' => 'wallet_recharge_paid', 'recharge_id' => (int)($rechargeData['id'] ?? 0)]
        );
    }

    public function notifyWithdrawalSubmitted($withdrawal): void
    {
        $withdrawalData = $this->normalizeRecord($withdrawal);
        if (empty($withdrawalData)) {
            return;
        }

        $this->sendToUser(
            (int)($withdrawalData['uid'] ?? 0),
            $this->buildWithdrawalMessage($withdrawalData, '提现申请已提交，等待平台审核。'),
            [],
            ['scene' => 'withdrawal_submitted', 'withdrawal_id' => (int)($withdrawalData['id'] ?? 0)]
        );
    }

    public function notifyWithdrawalSucceeded($withdrawal): void
    {
        $withdrawalData = $this->normalizeRecord($withdrawal);
        if (empty($withdrawalData)) {
            return;
        }

        $this->sendToUser(
            (int)($withdrawalData['uid'] ?? 0),
            $this->buildWithdrawalMessage($withdrawalData, '提现已成功处理。'),
            [],
            ['scene' => 'withdrawal_succeeded', 'withdrawal_id' => (int)($withdrawalData['id'] ?? 0)]
        );
    }

    public function buildProductCompletionMessage($order): string
    {
        $orderData = $this->normalizeRecord($order);
        return $this->buildProductOrderMessage($orderData, $this->productReceiptStatusText($orderData));
    }

    public function buildProductReceiptFinalMessage($order, string $actionLabel): string
    {
        $orderData = $this->normalizeRecord($order);
        return $this->buildProductOrderMessage($orderData, '确认结果：' . $actionLabel . '。');
    }

    public function notifyProductReceiptResult($order): void
    {
        $orderData = $this->normalizeRecord($order);
        if (empty($orderData)) {
            return;
        }

        $confirmStatus = (int)($orderData['confirm_status'] ?? 0);
        if ($confirmStatus === 2) {
            $actionLabel = '已收到';
        } elseif ($confirmStatus === 3) {
            $actionLabel = '未收到';
        } else {
            $actionLabel = '状态已更新';
        }

        $this->sendToUser(
            (int)($orderData['uid'] ?? 0),
            $this->buildProductReceiptFinalMessage($orderData, $actionLabel),
            [],
            ['scene' => 'product_receipt_result', 'order_id' => (int)($orderData['id'] ?? 0)]
        );
    }

    public function buildProductReceiptCallbackData($order, string $action): string
    {
        $orderData = $this->normalizeRecord($order);
        $orderId = (int)($orderData['id'] ?? 0);
        $userId = (int)($orderData['uid'] ?? 0);
        $signature = substr(hash_hmac('sha256', $orderId . '|' . $action . '|' . $userId, $this->callbackSignKey()), 0, 16);
        return 'po:' . $orderId . ':' . $action . ':' . $signature;
    }

    public function verifyProductReceiptCallback(int $orderId, int $userId, string $action, string $signature): bool
    {
        $expected = substr(hash_hmac('sha256', $orderId . '|' . $action . '|' . $userId, $this->callbackSignKey()), 0, 16);
        return hash_equals($expected, strtolower($signature));
    }

    private function buildProductOrderMessage(array $order, string $statusLine): string
    {
        $productName = $this->resolveProductName($order);
        $amountMoney = round((float)($order['amount_money'] ?? 0), 2);
        $payAmount = round((float)($order['cny_amount'] ?? 0), 2);

        $lines = [
            '【商品订单通知】',
            '订单号：' . (string)($order['order_number'] ?? ''),
            '商品：' . $productName,
            '充值金额：' . number_format($amountMoney, 2, '.', ''),
            '支付金额：' . number_format($payAmount, 2, '.', '') . ' USDT',
            '状态：' . $statusLine,
        ];

        $createTime = (string)($order['create_time'] ?? '');
        if ($createTime !== '') {
            $lines[] = '时间：' . $createTime;
        }

        return implode("\n", $lines);
    }

    private function buildUsdtOrderMessage(array $order, string $statusLine, string $role): string
    {
        $counterpartyUid = $role === 'buyer' ? (int)($order['sell_uid'] ?? 0) : (int)($order['uid'] ?? 0);
        $counterparty = UserModel::field('id,nickname,mobile')->find($counterpartyUid);
        $counterpartyLabel = '用户#' . $counterpartyUid;
        if ($counterparty) {
            $nickname = trim((string)($counterparty['nickname'] ?? ''));
            $mobile = trim((string)($counterparty['mobile'] ?? ''));
            if ($nickname !== '') {
                $counterpartyLabel = $nickname;
            } elseif ($mobile !== '') {
                $counterpartyLabel = $mobile;
            }
        }

        $lines = [
            '【USDT交易通知】',
            '订单号：' . (string)($order['order_number'] ?? ''),
            ($role === 'buyer' ? '卖家' : '买家') . '：' . $counterpartyLabel,
            '数量：' . number_format((float)($order['pay_amount'] ?? 0), 2, '.', '') . ' USDT',
            '金额：' . number_format((float)($order['payment_amount'] ?? 0), 2, '.', '') . ' 元（人民币）',
            '到账：' . number_format((float)($order['usdt_amount'] ?? 0), 2, '.', '') . ' USDT',
            '状态：' . $statusLine,
        ];

        $createTime = (string)($order['create_time'] ?? '');
        if ($createTime !== '') {
            $lines[] = '时间：' . $createTime;
        }

        return implode("\n", $lines);
    }

    private function productReceiptStatusText(array $order): string
    {
        $confirmStatus = (int)($order['confirm_status'] ?? 0);
        if ($confirmStatus === 2) {
            return '订单已完成，用户已确认收到。';
        }
        if ($confirmStatus === 3) {
            return '订单已完成，用户反馈未收到。';
        }

        return '订单已完成，请确认是否已收到。';
    }

    private function buildWalletRechargeMessage(array $recharge): string
    {
        $lines = [
            '【钱包通知】',
            '类型：钱包充值成功',
            '单号：' . (string)($recharge['order_number'] ?? ''),
            '金额：' . number_format((float)($recharge['amount'] ?? 0), 2, '.', '') . ' USDT',
            '状态：充值已到账',
        ];

        $paidTime = (string)($recharge['paid_time'] ?? ($recharge['complete_time'] ?? ''));
        if ($paidTime !== '') {
            $lines[] = '时间：' . $paidTime;
        }

        return implode("\n", $lines);
    }

    private function buildWithdrawalMessage(array $withdrawal, string $statusLine): string
    {
        $lines = [
            '【钱包通知】',
            '类型：余额提现',
            '单号：' . (string)($withdrawal['order_number'] ?? ''),
            '提现金额：' . number_format((float)($withdrawal['amount'] ?? 0), 2, '.', '') . ' USDT',
            '提现地址：' . (string)($withdrawal['wallet_address'] ?? ''),
            '状态：' . $statusLine,
        ];

        $createTime = (string)($withdrawal['create_time'] ?? '');
        if ($createTime !== '') {
            $lines[] = '时间：' . $createTime;
        }

        return implode("\n", $lines);
    }

    private function shouldShowProductReceiptActions(array $order): bool
    {
        return (int)($order['status'] ?? 0) === 2 && in_array((int)($order['confirm_status'] ?? 0), [0, 1], true);
    }

    private function sendToUser(int $userId, string $text, array $inlineKeyboard = [], array $context = []): void
    {
        if ($userId <= 0 || $text === '') {
            return;
        }

        try {
            $user = UserModel::field('id,telegram_id,tg_is_bind')->find($userId);
            if (!$user || (int)($user['tg_is_bind'] ?? 0) !== 1 || empty($user['telegram_id'])) {
                return;
            }

            $success = empty($inlineKeyboard)
                ? $this->telegramService->sendBasicReply((int)$user['telegram_id'], $text)
                : $this->telegramService->sendInlineKeyboardReply((int)$user['telegram_id'], $text, $inlineKeyboard);

            if (!$success) {
                Log::warning('telegram notify send failed', array_merge($context, [
                    'user_id' => $userId,
                    'telegram_id_hash' => substr(hash('sha256', (string)$user['telegram_id']), 0, 12),
                ]));
            }
        } catch (\Throwable $e) {
            Log::error('telegram notify exception', array_merge($context, [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }
    }

    private function resolveProductName(array $order): string
    {
        $productInfo = $order['product_info'] ?? [];
        if ($productInfo instanceof Model) {
            $productInfo = $productInfo->toArray();
        }
        if (is_string($productInfo)) {
            $decoded = json_decode($productInfo, true);
            $productInfo = is_array($decoded) ? $decoded : [];
        }
        if (is_array($productInfo) && !empty($productInfo['name'])) {
            return (string)$productInfo['name'];
        }

        $productId = (int)($order['product_id'] ?? 0);
        if ($productId > 0) {
            $product = Product::field('id,name')->find($productId);
            if ($product) {
                return (string)($product['name'] ?? '未知商品');
            }
        }

        return '未知商品';
    }

    private function normalizeRecord($record): array
    {
        if ($record instanceof Model) {
            return $record->toArray();
        }
        if (is_array($record)) {
            return $record;
        }

        return [];
    }

    private function callbackSignKey(): string
    {
        return (string)Config::get('app.app_key', 'telegram-order-callback');
    }
}