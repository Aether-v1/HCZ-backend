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
        $rawBody = (string)$this->request->getContent();
        $payload = json_decode($rawBody, true);
        $service = new BepusdtService();

        if (!is_array($payload)) {
            Log::error('bepusdt notify invalid json');
            return response('fail', 400);
        }

        if (!$service->verifyNotify($payload)) {
            Log::error('bepusdt notify verify failed', [
                'order_number' => (string)($payload['order_id'] ?? ''),
                'status' => (string)($payload['status'] ?? ''),
            ]);
            return response('fail', 400);
        }

        $orderNumber = (string)($payload['order_id'] ?? '');
        $status = (int)($payload['status'] ?? 0);
        $paidAmount = (float)($payload['actual_amount'] ?? ($payload['amount'] ?? 0));

        if ($orderNumber === '') {
            Log::error('bepusdt notify missing order_id', [
                'status' => (string)($payload['status'] ?? ''),
            ]);
            return response('fail', 400);
        }

        Db::startTrans();
        try {
            $recharge = Recharge::where('order_number', $orderNumber)->lock(true)->find();
            if (!$recharge) {
                throw new Exception('充值单不存在');
            }

            $localStatus = (int)($recharge['status'] ?? 0);


            if ($localStatus === 3) {


                Db::commit();


                return response('ok', 200);


            }



            if ($localStatus === 2) {


                Log::warning('bepusdt notify rejected: recharge already cancelled, no fund change allowed', [


                    'order_number' => $orderNumber,


                    'gateway_status' => $status,


                    'uid' => (int)($recharge['uid'] ?? 0),


                    'amount' => (float)($recharge['amount'] ?? 0),


                    'paid_amount' => $paidAmount,


                ]);


                Db::commit();


                return response('ok', 200);


            }

            if ($status === 1) {
                $recharge->gateway = 'bepusdt';
                $recharge->gateway_status = '1';
                $recharge->gateway_trade_id = $this->getGatewayTradeId($payload);
                $recharge->gateway_token = $this->hashGatewayToken($payload['token'] ?? '');
                $recharge->gateway_txid = $this->maskGatewayTxid($payload['block_transaction_id'] ?? '');
                $recharge->gateway_notify_payload = $this->buildGatewayNotifyPayload($payload);
                $recharge->save();

                Db::commit();
                return response('ok', 200);
            }

            if ($status === 3) {
                $recharge->gateway = 'bepusdt';
                $recharge->gateway_status = '3';
                $recharge->gateway_trade_id = $this->getGatewayTradeId($payload);
                $recharge->gateway_token = $this->hashGatewayToken($payload['token'] ?? '');
                $recharge->gateway_txid = $this->maskGatewayTxid($payload['block_transaction_id'] ?? '');
                $recharge->gateway_notify_payload = $this->buildGatewayNotifyPayload($payload);
                if ((int)($recharge['status'] ?? 0) === 0) {
                    $recharge->status = 2;
                    $recharge->cancel_time = date('Y-m-d H:i:s');
                }
                $recharge->save();

                Db::commit();
                return response('ok', 200);
            }

            if ($status !== 2) {


                throw new Exception('未知回调状态: ' . $status);


            }



            if ($localStatus !== 0) {


                Log::warning('bepusdt notify rejected: payment success but local recharge status is not pending', [


                    'order_number' => $orderNumber,


                    'local_status' => $localStatus,


                    'uid' => (int)($recharge['uid'] ?? 0),


                    'amount' => (float)($recharge['amount'] ?? 0),


                ]);


                Db::commit();


                return response('ok', 200);


            }



            $amount = round((float)($recharge['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new Exception('充值金额异常');
            }

            if ($paidAmount < $amount) {
                throw new Exception('回调金额不足');
            }

            $user = $this->directLockUser((int)($recharge['uid'] ?? 0));
            if (!$user) {
                throw new Exception('用户不存在');
            }

            $balanceBefore = (float)($user['balance'] ?? 0);

            $recharge->gateway = 'bepusdt';
            $recharge->status = 3;
            $recharge->submit_time = $recharge['submit_time'] ?: date('Y-m-d H:i:s');
            $recharge->paid_time = date('Y-m-d H:i:s');
            $recharge->complete_time = date('Y-m-d H:i:s');
            $recharge->gateway_trade_id = $this->getGatewayTradeId($payload);
            $recharge->gateway_token = $this->hashGatewayToken($payload['token'] ?? '');
            $recharge->gateway_status = (string)$status;
            $recharge->gateway_actual_amount = $paidAmount;
            $recharge->gateway_txid = $this->maskGatewayTxid($payload['block_transaction_id'] ?? '');
            $recharge->gateway_notify_payload = $this->buildGatewayNotifyPayload($payload);
            $recharge->save();

            $ledgerResult = (new UserFundLedgerService())->changeLockedUserWallet(
                $user,
                UserFundLedgerService::WALLET_BALANCE,
                $amount,
                [
                    'biz_type' => 'recharge',
                    'biz_id' => (int)($recharge['id'] ?? 0),
                    'biz_no' => (string)($recharge['order_number'] ?? ''),
                    'order_number' => (string)($recharge['order_number'] ?? ''),
                    'change_type' => 'recharge_paid',
                    'operator_type' => 'system',
                    'operator_id' => 0,
                    'status' => 'done',
                    'request_no' => 'recharge_paid:' . (string)($recharge['order_number'] ?? ''),
                    'remark' => '链上回调充值到账',
                    'idempotent' => true,
                    'extra' => [
                        'source' => 'notify_bepusdt_paid',
                        'gateway' => 'bepusdt',
                    ],
                ]
            );
            $walletSnapshot = (array)($ledgerResult['wallet_snapshot'] ?? []);
            $balanceAfter = array_key_exists('balance', $walletSnapshot)
                ? round((float)($walletSnapshot['balance'] ?? 0), 2)
                : round((float)($user['balance'] ?? ($balanceBefore + $amount)), 2);
            $this->directWriteBalanceLog([
                'uid' => (int)($user['id'] ?? 0),
                'scene' => 'recharge_paid',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'biz_id' => (int)($recharge['id'] ?? 0),
                'order_number' => (string)($recharge['order_number'] ?? ''),
                'remark' => '链上回调充值到账',
            ]);

            $rechargeSnapshot = $recharge->toArray();
            Db::commit();
            try {
                (new OrderTelegramNotifier())->notifyWalletRechargePaid($rechargeSnapshot);
            } catch (\Throwable $notifyException) {
                Log::error('wallet recharge notify failed', [
                    'recharge_id' => (int)($rechargeSnapshot['id'] ?? 0),
                    'order_no' => (string)($rechargeSnapshot['order_number'] ?? ''),
                    'uid' => (int)($rechargeSnapshot['uid'] ?? 0),
                    'action' => 'wallet_recharge_paid_notify',
                    'error_message' => $notifyException->getMessage(),
                ]);
            }
            return response('ok', 200);
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('bepusdt notify error: ' . $e->getMessage(), [
                'order_number' => $orderNumber,
                'status' => (string)($payload['status'] ?? ''),
            ]);
            return response('fail', 500);
        }
    }
}
