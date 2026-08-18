<?php
declare (strict_types=1);

namespace app\service;

use app\model\User as UserModel;
use app\model\UserFundLog;
use Exception;
use think\facade\Db;
use think\facade\Log;

class UserFundLedgerService
{
    public const WALLET_BALANCE = 'balance';
    public const WALLET_FROZEN = 'frozen_amount';
    public const WALLET_AGENT = 'agent_wallet';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    private const WALLET_FIELD_MAP = [
        self::WALLET_BALANCE => 'balance',
        self::WALLET_FROZEN => 'frozen_amount',
        self::WALLET_AGENT => 'agent_wallet',
    ];

    public function supportedWalletTypes(): array
    {
        return array_keys(self::WALLET_FIELD_MAP);
    }

    public function supportsWalletType(string $walletType): bool
    {
        return isset(self::WALLET_FIELD_MAP[strtolower(trim($walletType))]);
    }

    public function lockUser(int $uid): UserModel
    {
        if ($uid <= 0) {
            throw new Exception('User id is required');
        }

        $user = UserModel::where('id', $uid)->lock(true)->find();
        if (!$user) {
            throw new Exception('User not found');
        }

        return $user;
    }

    public function changeUserWallet(int $uid, string $walletType, float $delta, array $options = []): array
    {
        Db::startTrans();
        try {
            $user = $this->lockUser($uid);
            $result = $this->changeLockedUserWallet($user, $walletType, $delta, $options);
            Db::commit();

            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::warning('user fund ledger change failed', [
                'uid' => $uid,
                'wallet_type' => $walletType,
                'delta' => $delta,
                'biz_type' => (string)($options['biz_type'] ?? ''),
                'biz_no' => (string)($options['biz_no'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function changeLockedUserWallet(UserModel $user, string $walletType, float $delta, array $options = []): array
    {
        $walletType = $this->normalizeWalletType($walletType);
        $amount = $this->normalizeAmount(abs($delta));
        if ($amount <= 0) {
            throw new Exception('Ledger amount must be greater than zero');
        }

        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            throw new Exception('User model is missing id');
        }

        $bizType = $this->requireBizType($options);
        $bizNo = $this->requireBizNo($options);
        $direction = $delta >= 0 ? self::DIRECTION_IN : self::DIRECTION_OUT;
        $field = self::WALLET_FIELD_MAP[$walletType];

        $beforeAmount = $this->normalizeAmount((float)($user[$field] ?? 0));
        $afterAmount = $direction === self::DIRECTION_IN
            ? $this->normalizeAmount($beforeAmount + $amount)
            : $this->normalizeAmount($beforeAmount - $amount);

        if (empty($options['allow_negative']) && $afterAmount < -0.000001) {
            throw new Exception($this->buildInsufficientMessage($walletType));
        }
        if ($afterAmount < 0) {
            $afterAmount = 0.0;
        }

        if (!empty($options['idempotent'])) {
            $existing = $this->findExistingLogByRequest($uid, $walletType, $bizType, $bizNo, $direction, $options);
            if ($existing) {
                $this->assertExistingLogMatches($existing, $amount);
                return $this->buildChangeResult($user, $walletType, $existing, true);
            }
        }

        $user->{$field} = $afterAmount;
        if ($user->save() === false) {
            throw new Exception('User wallet update failed');
        }

        $logResult = $this->createLogWithIdempotentFallback([
            'uid' => $uid,
            'wallet_type' => $walletType,
            'change_type' => $this->resolveChangeType($options, $bizType),
            'direction' => $direction,
            'amount' => $amount,
            'before_amount' => $beforeAmount,
            'after_amount' => $afterAmount,
            'biz_type' => $bizType,
            'biz_id' => (int)($options['biz_id'] ?? 0),
            'biz_no' => $bizNo,
            'order_number' => trim((string)($options['order_number'] ?? $bizNo)),
            'operator_type' => trim((string)($options['operator_type'] ?? 'system')),
            'operator_id' => (int)($options['operator_id'] ?? 0),
            'status' => trim((string)($options['status'] ?? 'done')),
            'request_no' => trim((string)($options['request_no'] ?? '')),
            'remark' => trim((string)($options['remark'] ?? '')),
            'extra_json' => $this->buildExtraJson($options),
            'create_time' => $this->resolveCreateTime($options),
        ]);

        return $this->buildChangeResult(
            $user,
            $walletType,
            $logResult['log'],
            $logResult['duplicated']
        );
    }

    public function transferUserWallet(
        int $uid,
        string $fromWalletType,
        string $toWalletType,
        float $amount,
        array $options = []
    ): array {
        Db::startTrans();
        try {
            $user = $this->lockUser($uid);
            $result = $this->transferLockedUserWallet($user, $fromWalletType, $toWalletType, $amount, $options);
            Db::commit();

            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::warning('user fund ledger transfer failed', [
                'uid' => $uid,
                'from_wallet_type' => $fromWalletType,
                'to_wallet_type' => $toWalletType,
                'amount' => $amount,
                'biz_type' => (string)($options['biz_type'] ?? ''),
                'biz_no' => (string)($options['biz_no'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function transferLockedUserWallet(
        UserModel $user,
        string $fromWalletType,
        string $toWalletType,
        float $amount,
        array $options = []
    ): array {
        $fromWalletType = $this->normalizeWalletType($fromWalletType);
        $toWalletType = $this->normalizeWalletType($toWalletType);
        if ($fromWalletType === $toWalletType) {
            throw new Exception('Transfer wallets must be different');
        }

        $amount = $this->normalizeAmount($amount);
        if ($amount <= 0) {
            throw new Exception('Transfer amount must be greater than zero');
        }

        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            throw new Exception('User model is missing id');
        }

        $bizType = trim((string)($options['biz_type'] ?? 'wallet_transfer'));
        $bizNo = $this->requireBizNo($options);
        $createTime = $this->resolveCreateTime($options);

        if (!empty($options['idempotent'])) {
            $outExisting = $this->findExistingLogByRequest($uid, $fromWalletType, $bizType, $bizNo, self::DIRECTION_OUT, $options);
            $inExisting = $this->findExistingLogByRequest($uid, $toWalletType, $bizType, $bizNo, self::DIRECTION_IN, $options);
            if ($outExisting || $inExisting) {
                if (!$outExisting || !$inExisting) {
                    throw new Exception('Partial wallet transfer ledger already exists');
                }

                $this->assertExistingLogMatches($outExisting, $amount);
                $this->assertExistingLogMatches($inExisting, $amount);

                return [
                    'uid' => $uid,
                    'amount' => $amount,
                    'biz_type' => $bizType,
                    'biz_no' => $bizNo,
                    'duplicated' => true,
                    'from' => $this->buildChangeResult($user, $fromWalletType, $outExisting, true),
                    'to' => $this->buildChangeResult($user, $toWalletType, $inExisting, true),
                    'wallet_snapshot' => $this->buildWalletSnapshot($user),
                ];
            }
        }

        $shared = [
            'biz_type' => $bizType,
            'biz_id' => (int)($options['biz_id'] ?? 0),
            'biz_no' => $bizNo,
            'order_number' => trim((string)($options['order_number'] ?? $bizNo)),
            'operator_type' => trim((string)($options['operator_type'] ?? 'system')),
            'operator_id' => $options['operator_id'] ?? null,
            'status' => trim((string)($options['status'] ?? 'done')),
            'request_no' => trim((string)($options['request_no'] ?? '')),
            'create_time' => $createTime,
            'idempotent' => false,
        ];

        $fromResult = $this->changeLockedUserWallet($user, $fromWalletType, -1 * $amount, array_merge($shared, [
            'change_type' => (string)($options['out_change_type'] ?? $options['change_type'] ?? $bizType),
            'remark' => (string)($options['out_remark'] ?? $options['remark'] ?? ''),
            'allow_negative' => !empty($options['allow_negative']),
            'extra' => $this->mergeExtraPayload(
                $options['extra'] ?? null,
                $options['out_extra'] ?? null,
                [
                    'transfer_peer_wallet' => $toWalletType,
                    'transfer_direction' => self::DIRECTION_OUT,
                ]
            ),
            'operator_id' => $options['operator_id'] ?? null,
        ]));

        $toResult = $this->changeLockedUserWallet($user, $toWalletType, $amount, array_merge($shared, [
            'change_type' => (string)($options['in_change_type'] ?? $options['change_type'] ?? $bizType),
            'remark' => (string)($options['in_remark'] ?? $options['remark'] ?? ''),
            'extra' => $this->mergeExtraPayload(
                $options['extra'] ?? null,
                $options['in_extra'] ?? null,
                [
                    'transfer_peer_wallet' => $fromWalletType,
                    'transfer_direction' => self::DIRECTION_IN,
                ]
            ),
            'operator_id' => $options['operator_id'] ?? null,
        ]));

        return [
            'uid' => $uid,
            'amount' => $amount,
            'biz_type' => $bizType,
            'biz_no' => $bizNo,
            'duplicated' => false,
            'from' => $fromResult,
            'to' => $toResult,
            'wallet_snapshot' => $this->buildWalletSnapshot($user),
        ];
    }

    private function normalizeWalletType(string $walletType): string
    {
        $walletType = strtolower(trim($walletType));
        if (!isset(self::WALLET_FIELD_MAP[$walletType])) {
            throw new Exception('Unsupported wallet type: ' . $walletType);
        }

        return $walletType;
    }

    private function normalizeAmount(float $amount): float
    {
        return round($amount, 2);
    }

    private function requireBizType(array $options): string
    {
        $bizType = trim((string)($options['biz_type'] ?? ''));
        if ($bizType === '') {
            throw new Exception('Ledger biz_type is required');
        }

        return $bizType;
    }

    private function requireBizNo(array $options): string
    {
        $bizNo = trim((string)($options['biz_no'] ?? ''));
        if ($bizNo === '') {
            throw new Exception('Ledger biz_no is required');
        }

        return $bizNo;
    }

    private function resolveChangeType(array $options, string $default): string
    {
        $changeType = trim((string)($options['change_type'] ?? $options['scene'] ?? ''));
        return $changeType !== '' ? $changeType : $default;
    }

    private function resolveCreateTime(array $options): string
    {
        $createTime = trim((string)($options['create_time'] ?? ''));
        return $createTime !== '' ? $createTime : date('Y-m-d H:i:s');
    }

    private function buildExtraJson(array $options): ?string
    {
        if (isset($options['extra_json']) && trim((string)$options['extra_json']) !== '') {
            return trim((string)$options['extra_json']);
        }

        $payload = [];
        foreach (['extra', 'meta'] as $key) {
            if (!isset($options[$key])) {
                continue;
            }

            $value = $options[$key];
            if (is_array($value)) {
                $payload = array_merge($payload, $value);
                continue;
            }

            if (is_object($value)) {
                $payload = array_merge($payload, json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: []);
            }
        }

        if (array_key_exists('operator_id', $options) && $options['operator_id'] !== null && $options['operator_id'] !== '') {
            $payload['operator_id'] = (int)$options['operator_id'];
        }

        if ($payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function mergeExtraPayload($base, $override, array $append = []): array
    {
        $payload = [];
        foreach ([$base, $override] as $candidate) {
            if (is_array($candidate)) {
                $payload = array_merge($payload, $candidate);
                continue;
            }

            if (is_object($candidate)) {
                $payload = array_merge($payload, json_decode(json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: []);
            }
        }

        return array_merge($payload, $append);
    }

    private function findExistingLog(int $uid, string $walletType, string $bizType, string $bizNo, string $direction): ?UserFundLog
    {
        return UserFundLog::where('uid', $uid)
            ->where('wallet_type', $walletType)
            ->where('biz_type', $bizType)
            ->where('biz_no', $bizNo)
            ->where('direction', $direction)
            ->order('id', 'desc')
            ->find();
    }

    private function findExistingLogByRequest(
        int $uid,
        string $walletType,
        string $bizType,
        string $bizNo,
        string $direction,
        array $options = []
    ): ?UserFundLog {
        $requestNo = trim((string)($options['request_no'] ?? ''));
        if ($requestNo !== '') {
            return UserFundLog::where('uid', $uid)
                ->where('wallet_type', $walletType)
                ->where('direction', $direction)
                ->where('request_no', $requestNo)
                ->order('id', 'desc')
                ->find();
        }

        return $this->findExistingLog($uid, $walletType, $bizType, $bizNo, $direction);
    }

    private function assertExistingLogMatches(UserFundLog $log, float $expectedAmount): void
    {
        $storedAmount = $this->normalizeAmount((float)($log['amount'] ?? 0));
        if (abs($storedAmount - $expectedAmount) > 0.000001) {
            throw new Exception('Existing ledger amount does not match current request');
        }
    }

    private function createLogWithIdempotentFallback(array $payload): array
    {
        try {
            $log = UserFundLog::create($payload);
            if (!$log) {
                throw new Exception('User fund log create failed');
            }

            return [
                'log' => $log,
                'duplicated' => false,
            ];
        } catch (\Throwable $e) {
            if (!$this->isRequestNoUniqueConflict($e, $payload)) {
                throw $e;
            }

            $existing = $this->findExistingLogByRequest(
                (int)($payload['uid'] ?? 0),
                (string)($payload['wallet_type'] ?? ''),
                (string)($payload['biz_type'] ?? ''),
                (string)($payload['biz_no'] ?? ''),
                (string)($payload['direction'] ?? ''),
                [
                    'request_no' => (string)($payload['request_no'] ?? ''),
                ]
            );

            if (!$existing) {
                throw $e;
            }

            $this->assertExistingLogMatches($existing, $this->normalizeAmount((float)($payload['amount'] ?? 0)));

            return [
                'log' => $existing,
                'duplicated' => true,
            ];
        }
    }

    private function isRequestNoUniqueConflict(\Throwable $e, array $payload): bool
    {
        $requestNo = trim((string)($payload['request_no'] ?? ''));
        if ($requestNo === '') {
            return false;
        }

        $message = strtolower($e->getMessage());
        if (strpos($message, 'uk_uid_wallet_direction_reqno') === false) {
            return false;
        }

        return strpos($message, 'duplicate entry') !== false
            || strpos($message, 'integrity constraint violation') !== false
            || (string)$e->getCode() === '1062';
    }

    private function buildChangeResult(UserModel $user, string $walletType, UserFundLog $log, bool $duplicated): array
    {
        $field = self::WALLET_FIELD_MAP[$walletType];
        return [
            'uid' => (int)($log['uid'] ?? $user['id'] ?? 0),
            'wallet_type' => $walletType,
            'field' => $field,
            'direction' => (string)($log['direction'] ?? ''),
            'delta' => ((string)($log['direction'] ?? '') === self::DIRECTION_OUT ? -1 : 1) * $this->normalizeAmount((float)($log['amount'] ?? 0)),
            'amount' => $this->normalizeAmount((float)($log['amount'] ?? 0)),
            'before_amount' => $this->normalizeAmount((float)($log['before_amount'] ?? 0)),
            'after_amount' => $this->normalizeAmount((float)($log['after_amount'] ?? 0)),
            'wallet_value' => $this->normalizeAmount((float)($user[$field] ?? 0)),
            'biz_type' => (string)($log['biz_type'] ?? ''),
            'biz_no' => (string)($log['biz_no'] ?? ''),
            'change_type' => (string)($log['change_type'] ?? ''),
            'remark' => (string)($log['remark'] ?? ''),
            'log_id' => (int)($log['id'] ?? 0),
            'duplicated' => $duplicated,
            'wallet_snapshot' => $this->buildWalletSnapshot($user),
            'log' => $log->toArray(),
        ];
    }

    private function buildWalletSnapshot(UserModel $user): array
    {
        return [
            self::WALLET_BALANCE => $this->normalizeAmount((float)($user['balance'] ?? 0)),
            self::WALLET_FROZEN => $this->normalizeAmount((float)($user['frozen_amount'] ?? 0)),
            self::WALLET_AGENT => $this->normalizeAmount((float)($user['agent_wallet'] ?? 0)),
        ];
    }

    private function buildInsufficientMessage(string $walletType): string
    {
        switch ($walletType) {
            case self::WALLET_FROZEN:
                return 'Frozen amount is insufficient';
            case self::WALLET_AGENT:
                return 'Agent wallet balance is insufficient';
            default:
                return 'Balance is insufficient';
        }
    }
}
