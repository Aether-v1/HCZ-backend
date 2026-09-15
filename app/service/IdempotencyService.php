<?php
declare(strict_types=1);

namespace app\service;

use app\model\IdempotencyRecord;
use app\support\IdempotencyResult;
use think\facade\Db;

/**
 * R1.3b: MySQL-backed HTTP Idempotency Service（Storage Foundation）
 *
 * 核心模型：WAIT THEN REPLAY（SAME TRANSACTION / strong atomicity first）
 *
 * - IdempotencyService owns outer Db::transaction()
 * - 事务内：INSERT PROCESSING → business callback → serialize replay payload → UPDATE COMPLETED → COMMIT
 * - duplicate unique (1062)：首事务 commit 后第二请求得到 duplicate → SELECT existing → replay/conflict
 * - lock wait timeout (1205, 5s)：返回 IN_PROGRESS，客户端稍后重试
 * - deadlock (1213) / unknown DB error：FAIL CLOSED（business NOT executed）
 *
 * 禁止：precommit PROCESSING fast 409、catch all DB errors as duplicate、silent JSON truncation
 *
 * 本类仍是 Foundation，不接业务 consumer。R3 才接 order.create/withdraw.create。
 */
class IdempotencyService
{
    public const CURRENT_FINGERPRINT_VERSION = 1;

    public const DEFAULT_TTL = 86400;   // 24h
    public const MIN_TTL = 3600;         // 1h
    public const MAX_TTL = 604800;       // 7d

    public const MAX_REPLAY_PAYLOAD = 16384; // 16KB

    public const LOCK_WAIT_TIMEOUT = 5;   // seconds, session-only

    private const HASH_PATTERN = '/^[a-f0-9]{64}$/';
    private const OPERATION_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    /**
     * 执行幂等操作。
     *
     * @param string   $principalType  'user'|'admin'（第一版 whitelist）
     * @param int      $principalId    来自可信认证上下文，>0
     * @param string   $operation      稳定操作标识，1-64 chars [A-Za-z0-9._:-]
     * @param string   $keyHash        IdempotencyKey::hash() 输出，64 lowercase hex
     * @param string   $requestHash    CanonicalFingerprint::hash() 输出，64 lowercase hex
     * @param int      $ttlSeconds     TTL，[3600, 604800]
     * @param callable $business       业务回调，返回 ['http_status'=>int, 'body'=>?array, 'resource_type'=>?string, 'resource_id'=>?string]
     */
    public function execute(
        string $principalType,
        int $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $ttlSeconds,
        callable $business,
    ): IdempotencyResult {
        // 1. input validation（fail before DB/business）
        $this->validateInputs($principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds);

        // 2. set session lock wait timeout, restore in finally
        $originalTimeout = $this->getLockWaitTimeout();
        $this->setLockWaitTimeout(self::LOCK_WAIT_TIMEOUT);

        try {
            return Db::transaction(function () use (
                $principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds, $business
            ) {
                return $this->executeInTransaction(
                    $principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds, $business
                );
            });
        } catch (\Throwable $e) {
            // Only DB errors are handled here; business exceptions must propagate.
            $isDbError = ($e instanceof \PDOException)
                || ($e instanceof \think\db\exception\DbException)
                || ($e->getPrevious() instanceof \PDOException);
            if (!$isDbError) {
                throw $e;
            }

            $mysqlCode = $this->extractMysqlErrorCode($e);
            if ($mysqlCode === 1205) {
                // lock wait timeout → first request still processing
                return IdempotencyResult::inProgress();
            }
            if ($mysqlCode === 1213) {
                // deadlock → fail closed
                return IdempotencyResult::error('Deadlock detected, idempotency state uncertain: ' . $e->getMessage());
            }
            // any other DB/unknown error → fail closed (business NOT executed)
            return IdempotencyResult::error('Idempotency storage failure: ' . $e->getMessage());
        } finally {
            $this->setLockWaitTimeout($originalTimeout);
        }
    }

    /**
     * 事务内核心逻辑：尝试 INSERT，duplicate 则 handleExisting。
     */
    private function executeInTransaction(
        string $principalType,
        int $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $ttlSeconds,
        callable $business,
    ): IdempotencyResult {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        // 尝试 INSERT PROCESSING (use create() to trigger auto_timestamp)
        try {
            $record = IdempotencyRecord::create([
                'principal_type' => $principalType,
                'principal_id' => $principalId,
                'operation' => $operation,
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'fingerprint_version' => self::CURRENT_FINGERPRINT_VERSION,
                'status' => IdempotencyRecord::STATUS_PROCESSING,
                'expires_at' => $expiresAt,
            ]);
            $recordId = (int) $record->id;
        } catch (\Throwable $e) {
            $mysqlCode = $this->extractMysqlErrorCode($e);
            if ($mysqlCode === 1062) {
                // duplicate unique → first request committed (or still holding lock)
                return $this->handleExistingDuplicate(
                    $principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds, $business
                );
            }
            // other insert error → rethrow, outer catch handles fail-closed
            throw $e;
        }

        // INSERT 成功 → 执行业务 callback
        return $this->runBusinessAndComplete($recordId, $business);
    }

    /**
     * 处理 duplicate：SELECT existing FOR UPDATE，判断状态。
     */
    private function handleExistingDuplicate(
        string $principalType,
        int $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $ttlSeconds,
        callable $business,
    ): IdempotencyResult {
        $existing = IdempotencyRecord::where([
            ['principal_type', '=', $principalType],
            ['principal_id', '=', $principalId],
            ['operation', '=', $operation],
            ['idempotency_key_hash', '=', $keyHash],
        ])->lock(true)->find();

        if (!$existing) {
            // race: row was deleted (expired reset by another tx) → re-insert
            return $this->executeInTransaction(
                $principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds, $business
            );
        }

        // fingerprint version check → fail closed if unsupported
        if ((int) $existing->fingerprint_version !== self::CURRENT_FINGERPRINT_VERSION) {
            return IdempotencyResult::error(
                "Unsupported fingerprint_version: {$existing->fingerprint_version}, expected " . self::CURRENT_FINGERPRINT_VERSION
            );
        }

        // request_hash mismatch → conflict (before status/expiry)
        if ($existing->request_hash !== $requestHash) {
            return IdempotencyResult::conflict();
        }

        $status = (string) $existing->status;

        return match ($status) {
            IdempotencyRecord::STATUS_COMPLETED => $this->handleCompleted($existing, $principalType, $principalId, $operation, $keyHash, $requestHash, $ttlSeconds, $business),
            IdempotencyRecord::STATUS_PROCESSING => IdempotencyResult::inProgress(),
            IdempotencyRecord::STATUS_FAILED_RETRYABLE => IdempotencyResult::error('FAILED_RETRYABLE state not enabled in first batch, fail closed'),
            default => IdempotencyResult::error("Unknown idempotency status: {$status}"),
        };
    }

    /**
     * 处理 COMPLETED：未过期 → replay；过期 → DELETE + re-insert + business。
     */
    private function handleCompleted(
        $existing,
        string $principalType,
        int $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $ttlSeconds,
        callable $business,
    ): IdempotencyResult {
        $now = time();
        $expiresAt = strtotime((string) $existing->expires_at);

        if ($expiresAt !== false && $expiresAt > $now) {
            // not expired → replay (business callback MUST NOT execute)
            return $this->buildReplayResult($existing);
        }

        // expired → DELETE old row + re-insert PROCESSING + business + COMPLETED
        IdempotencyRecord::where('id', '=', (int) $existing->id)->delete();

        $newExpiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $newRecord = IdempotencyRecord::create([
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            'operation' => $operation,
            'idempotency_key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'fingerprint_version' => self::CURRENT_FINGERPRINT_VERSION,
            'status' => IdempotencyRecord::STATUS_PROCESSING,
            'expires_at' => $newExpiresAt,
        ]);
        $recordId = (int) $newRecord->id;

        return $this->runBusinessAndComplete($recordId, $business);
    }

    /**
     * 执行业务 callback + 校验结果 + 序列化 + UPDATE COMPLETED。
     */
    private function runBusinessAndComplete(int $recordId, callable $business): IdempotencyResult
    {
        $result = $business();

        // validate callback result
        if (!is_array($result)) {
            throw new \RuntimeException('Idempotency business callback must return array');
        }

        $httpStatus = (int) ($result['http_status'] ?? 0);
        if ($httpStatus < 200 || $httpStatus > 299) {
            throw new \RuntimeException("Idempotency business http_status must be 2xx, got {$httpStatus}");
        }

        $body = $result['body'] ?? null;
        if ($body !== null && !is_array($body)) {
            throw new \RuntimeException('Idempotency business body must be array or null');
        }

        // 204 No Content → body MUST be null
        if ($httpStatus === 204 && $body !== null) {
            throw new \RuntimeException('Idempotency business 204 response must have null body');
        }

        $resourceType = isset($result['resource_type']) ? (string) $result['resource_type'] : null;
        $resourceId = isset($result['resource_id']) ? (string) $result['resource_id'] : null;
        if ($resourceType !== null && strlen($resourceType) > 32) {
            throw new \RuntimeException('Idempotency resource_type must be <=32 chars');
        }
        if ($resourceId !== null && strlen($resourceId) > 64) {
            throw new \RuntimeException('Idempotency resource_id must be <=64 chars');
        }

        // serialize replay payload (fail-closed on JSON error)
        if ($body === null) {
            $responseJson = null;
        } else {
            $responseJson = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
            if (strlen($responseJson) > self::MAX_REPLAY_PAYLOAD) {
                throw new \RuntimeException(
                    'Idempotency replay payload exceeds ' . self::MAX_REPLAY_PAYLOAD . ' bytes, got ' . strlen($responseJson)
                );
            }
        }

        // UPDATE COMPLETED (same transaction)
        IdempotencyRecord::where('id', '=', $recordId)->update([
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'http_status' => $httpStatus,
            'response_body' => $responseJson,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);

        return IdempotencyResult::executed($httpStatus, $body, $recordId, $resourceType, $resourceId);
    }

    /**
     * 从已存储 COMPLETED record 构建 replay result。
     */
    private function buildReplayResult($existing): IdempotencyResult
    {
        $httpStatus = (int) $existing->http_status;
        $responseJson = $existing->response_body;

        if ($httpStatus === 204 || $responseJson === null || $responseJson === '') {
            $body = null;
        } else {
            try {
                $body = json_decode((string) $responseJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // corrupt replay data → fail closed (do NOT re-execute business)
                return IdempotencyResult::error('Corrupt idempotency response_body JSON: ' . $e->getMessage());
            }
            if (!is_array($body)) {
                return IdempotencyResult::error('Corrupt idempotency response_body: not an array');
            }
        }

        return IdempotencyResult::replay(
            $httpStatus,
            $body,
            (int) $existing->id,
            $existing->resource_type !== null ? (string) $existing->resource_type : null,
            $existing->resource_id !== null ? (string) $existing->resource_id : null,
        );
    }

    // ---------- input validation ----------

    private function validateInputs(
        string $principalType,
        int $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $ttlSeconds,
    ): void {
        if (!in_array($principalType, [IdempotencyRecord::PRINCIPAL_USER, IdempotencyRecord::PRINCIPAL_ADMIN], true)) {
            throw new \InvalidArgumentException("Idempotency principal_type must be 'user' or 'admin', got '{$principalType}'");
        }
        if ($principalId <= 0) {
            throw new \InvalidArgumentException("Idempotency principal_id must be >0, got {$principalId}");
        }
        if (preg_match(self::OPERATION_PATTERN, $operation) !== 1) {
            throw new \InvalidArgumentException("Idempotency operation must be 1-64 chars [A-Za-z0-9._:-], got '{$operation}'");
        }
        if (preg_match(self::HASH_PATTERN, $keyHash) !== 1) {
            throw new \InvalidArgumentException('Idempotency keyHash must be 64 lowercase hex chars');
        }
        if (preg_match(self::HASH_PATTERN, $requestHash) !== 1) {
            throw new \InvalidArgumentException('Idempotency requestHash must be 64 lowercase hex chars');
        }
        if ($ttlSeconds < self::MIN_TTL || $ttlSeconds > self::MAX_TTL) {
            throw new \InvalidArgumentException(
                "Idempotency ttlSeconds must be [" . self::MIN_TTL . ", " . self::MAX_TTL . "], got {$ttlSeconds}"
            );
        }
    }

    // ---------- lock wait timeout session management ----------

    private function getLockWaitTimeout(): int
    {
        $row = Db::query('SELECT @@session.innodb_lock_wait_timeout AS v');
        return (int) ($row[0]['v'] ?? 50);
    }

    private function setLockWaitTimeout(int $seconds): void
    {
        Db::execute("SET SESSION innodb_lock_wait_timeout = {$seconds}");
    }

    // ---------- MySQL error code extraction ----------

    private function extractMysqlErrorCode(\Throwable $e): int
    {
        // ThinkORM PDOException (wraps original \PDOException, stores errorInfo in getData)
        if ($e instanceof \think\db\exception\PDOException) {
            $data = $e->getData();
            if (is_array($data) && isset($data['PDO Error Info']['Driver Error Code'])) {
                return (int) $data['PDO Error Info']['Driver Error Code'];
            }
        }
        // Original \PDOException
        if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }
        // getPrevious chain
        $prev = $e->getPrevious();
        if ($prev instanceof \PDOException && isset($prev->errorInfo[1])) {
            return (int) $prev->errorInfo[1];
        }
        return 0;
    }
}
