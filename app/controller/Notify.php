<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Recharge;
use app\model\UserBalanceLog;
use app\model\User as UserModel;
use app\service\BepusdtService;
use app\service\UserFundLedgerService;
use app\service\telegram\OrderTelegramNotifier;
use Exception;
use think\App;
use think\facade\Db;
use think\facade\Log;
use think\Request;

class Notify
{
    protected Request $request;
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
    }

    private function directLockUser(int $uid)
    {
        if ($uid <= 0) {
            return null;
        }

        return UserModel::where('id', $uid)->lock(true)->find();
    }

    private function directWriteBalanceLog(array $data): void
    {
        $scene = (string)($data['scene'] ?? '');
        $orderNumber = (string)($data['order_number'] ?? '');
        if ($scene === '' || $orderNumber === '') {
            throw new Exception('余额流水参数异常');
        }

        if (UserBalanceLog::where('scene', $scene)->where('order_number', $orderNumber)->find()) {
            return;
        }

        UserBalanceLog::create([
            'uid' => (int)($data['uid'] ?? 0),
            'scene' => $scene,
            'change_type' => 1,
            'currency' => 'USDT',
            'amount' => round((float)($data['amount'] ?? 0), 2),
            'balance_before' => round((float)($data['balance_before'] ?? 0), 2),
            'balance_after' => round((float)($data['balance_after'] ?? 0), 2),
            'biz_type' => 'cz_order',
            'biz_id' => (int)($data['biz_id'] ?? 0),
            'order_number' => $orderNumber,
            'remark' => (string)($data['remark'] ?? ''),
            'operator_id' => (int)($data['operator_id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function getGatewayTradeId(array $payload): string
    {
        return (string)($payload['trade_id'] ?? ($payload['id'] ?? ''));
    }

    private function hashGatewayToken($token): string
    {
        $token = trim((string)$token);
        if ($token === '') {
            return '';
        }

        return substr(hash('sha256', $token), 0, 12);
    }

    private function maskGatewayTxid($txid): string
    {
        $txid = trim((string)$txid);
        $length = strlen($txid);
        if ($txid === '') {
            return '';
        }

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        if ($length <= 12) {
            return substr($txid, 0, 3) . str_repeat('*', $length - 6) . substr($txid, -3);
        }

        return substr($txid, 0, 6) . str_repeat('*', $length - 12) . substr($txid, -6);
    }

    private function buildGatewayNotifyPayload(array $payload): string
    {
        return json_encode([
            'order_id' => (string)($payload['order_id'] ?? ''),
            'status' => (string)($payload['status'] ?? ''),
            'amount' => $payload['amount'] ?? null,
            'actual_amount' => $payload['actual_amount'] ?? null,
            'trade_id' => $this->getGatewayTradeId($payload),
            'txid' => $this->maskGatewayTxid($payload['block_transaction_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function api_callback_bepusdt()
    {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            Log::warning('bepusdt callback invalid json', ['raw' => substr($rawBody, 0, 500)]);
            return response('fail', 400);
        }

        $orderId = trim((string) ($payload['order_id'] ?? ''));
        $providerStatus = (int) ($payload['status'] ?? 0);
        $paidAmount = (float) ($payload['actual_amount'] ?? ($payload['amount'] ?? 0));

        Log::info('bepusdt callback received', [
            'order_id' => $orderId,
            'provider_status' => $providerStatus,
            'paid_amount' => $paidAmount,
            'payload_keys' => implode(',', array_keys($payload)),
        ]);

        if ($orderId === '') {
            Log::warning('bepusdt callback missing order_id');
            return response('fail', 400);
        }

        // 签名验证（保留原有校验，不弱化）
        try {
            $verifyResult = (new BepusdtService())->verifyNotify($payload);
            if ($verifyResult !== true) {
                Log::warning('bepusdt callback signature invalid', [
                    'order_id' => $orderId,
                    'verify_result' => is_string($verifyResult) ? $verifyResult : 'unknown',
                ]);
                return response('fail', 400);
            }
        } catch (\Throwable $e) {
            Log::warning('bepusdt callback signature verify exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return response('fail', 400);
        }

        $settlementResult = null;
        try {
            Db::startTrans();

            $recharge = Recharge::where('order_number', $orderId)->lock(true)->find();
            if (!$recharge) {
                Log::warning('bepusdt callback recharge not found', ['order_id' => $orderId]);
                Db::rollback();
                return response('fail', 500);
            }

            $localStatus = (int) ($recharge['status'] ?? 0);

            // ===== Provider status=1: 待支付 =====
            // 更新 gateway 信息，不入账（正确，用户尚未付款）
            if ($providerStatus === 1) {
                $recharge->gateway = 'bepusdt';
                $recharge->gateway_trade_id = $this->getGatewayTradeId($payload);
                $recharge->gateway_token = $this->hashGatewayToken($payload['token'] ?? '');
                $recharge->gateway_status = '1';
                $recharge->gateway_txid = $this->maskGatewayTxid($payload['transaction_id'] ?? ($payload['txid'] ?? ''));
                $recharge->gateway_notify_payload = $this->buildGatewayNotifyPayload($payload);
                $recharge->save();
                Db::commit();
                Log::info('bepusdt callback pending updated', ['order_id' => $orderId]);
                return response('ok', 200);
            }

            // ===== Provider status=3: 已过期 =====
            // Provider 端订单过期，若本地仍 PENDING 则标记 EXPIRED
            if ($providerStatus === 3) {
                $recharge->gateway = 'bepusdt';
                $recharge->gateway_trade_id = $this->getGatewayTradeId($payload);
                $recharge->gateway_token = $this->hashGatewayToken($payload['token'] ?? '');
                $recharge->gateway_status = '3';
                $recharge->gateway_txid = $this->maskGatewayTxid($payload['transaction_id'] ?? ($payload['txid'] ?? ''));
                $recharge->gateway_notify_payload = $this->buildGatewayNotifyPayload($payload);
                if ($localStatus === Recharge::STATUS_PENDING) {
                    $recharge->status = Recharge::STATUS_EXPIRED;
                    $recharge->cancel_time = date('Y-m-d H:i:s');
                    $recharge->cancel_source = Recharge::CANCEL_SOURCE_PROVIDER_EXPIRED;
                    Log::info('bepusdt callback provider expired, marked local expired', [
                        'order_id' => $orderId,
                    ]);
                }
                $recharge->save();
                Db::commit();
                return response('ok', 200);
            }

            // ===== Provider status=2: 支付成功（核心入账路径）=====
            if ($providerStatus !== 2) {
                Log::warning('bepusdt callback unknown provider status', [
                    'order_id' => $orderId,
                    'provider_status' => $providerStatus,
                ]);
                Db::rollback();
                return response('fail', 500);
            }

            // BEpusdt 金额验证：USDT 计价，paidAmount >= amount（少付拒绝）
            $amount = round((float) ($recharge['amount'] ?? 0), 2);
            if ($amount <= 0) {
                Log::error('bepusdt callback invalid local amount', [
                    'order_id' => $orderId,
                    'amount' => $amount,
                ]);
                Db::rollback();
                return response('fail', 500);
            }
            if ($paidAmount < $amount) {
                Log::error('bepusdt callback amount insufficient', [
                    'order_id' => $orderId,
                    'paid_amount' => $paidAmount,
                    'local_amount' => $amount,
                ]);
                Db::rollback();
                return response('fail', 500);
            }

            // 调用统一 Settlement Boundary
            // PENDING(0) 和 EXPIRED(2, cancel_source 允许自动恢复) 都会被 settle
            // PAID(3) → ALREADY_PAID 幂等
            // 其他状态 → NOT_SETTLEABLE（rollback + 返回 fail，不静默丢单）
            $settlement = (new \app\service\RechargeSettlementService())->settleLocked(
                $recharge,
                'notify_bepusdt',
                [
                    'gateway' => 'bepusdt',
                    'gateway_trade_id' => $this->getGatewayTradeId($payload),
                    'gateway_status' => '2',
                    'gateway_actual_amount' => $paidAmount > 0 ? $paidAmount : null,
                    'gateway_txid' => $this->maskGatewayTxid($payload['transaction_id'] ?? ($payload['txid'] ?? '')),
                    'gateway_notify_payload' => $this->buildGatewayNotifyPayload($payload),
                ]
            );

            $settlementResult = $settlement;

            // ACK 语义与业务处理分离
            if (!(new \app\service\RechargeSettlementService())->shouldAckSuccess($settlement['result'])) {
                Log::warning('bepusdt callback settlement not ackable', [
                    'order_id' => $orderId,
                    'settlement_result' => $settlement['result'],
                    'settlement_message' => $settlement['message'] ?? '',
                    'local_status' => $localStatus,
                    'cancel_source' => (string) ($recharge['cancel_source'] ?? ''),
                ]);
                Db::rollback();
                return response('fail', 500);
            }

            // 仅在新入账成功时写展示用 balance_log
            if ($settlement['result'] === \app\service\RechargeSettlementService::RESULT_SETTLED) {
                $this->directWriteBalanceLog([
                    'uid' => (int) ($recharge['uid'] ?? 0),
                    'scene' => 'recharge_paid',
                    'amount' => $amount,
                    'balance_before' => (float) ($settlement['balance_before'] ?? 0),
                    'balance_after' => (float) ($settlement['balance_after'] ?? 0),
                    'biz_id' => (int) ($recharge['id'] ?? 0),
                    'order_number' => (string) ($recharge['order_number'] ?? ''),
                    'remark' => 'bepusdt recharge paid',
                    'operator_id' => 0,
                ]);
            }

            Db::commit();

            // Telegram 通知在事务外发送（网络调用不应阻塞 DB 事务）
            if ($settlement['result'] === \app\service\RechargeSettlementService::RESULT_SETTLED) {
                try {
                    (new \app\service\telegram\OrderTelegramNotifier())->notifyWalletRechargePaid(
                        (int) ($recharge['uid'] ?? 0),
                        (string) ($recharge['order_number'] ?? ''),
                        $amount
                    );
                } catch (\Throwable $e) {
                    Log::warning('bepusdt callback telegram notify failed', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('bepusdt callback processed', [
                'order_id' => $orderId,
                'settlement_result' => $settlement['result'],
                'amount' => $amount,
            ]);

            return response('ok', 200);

        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('bepusdt callback exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('fail', 500);
        }
    }
}
