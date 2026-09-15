<?php
declare(strict_types=1);

namespace tests\Integration;

use app\model\IdempotencyRecord;
use app\service\IdempotencyService;
use app\support\IdempotencyResult;
use think\facade\Db;
use tests\Support\SchemaEnsurer;

/**
 * R1.3b: IdempotencyService Integration Tests
 *
 * S1-S10: service contract
 * T1-T3: transaction atomicity
 * Fail-closed: corrupt/unsupported/unknown/invalid/oversized
 * Input validation, 204, session timeout restoration
 *
 * 注意：本测试需要真实 COMMIT（duplicate 依赖首事务 commit），
 * 不使用 DbTestCase 的 beginTransaction/rollback 隔离。
 * 每个测试前后手动清空 cz_idempotency_record。
 */
class IdempotencyServiceTest extends DbTestCase
{
    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaEnsurer::ensureIdempotencyRecordTable();
        $this->service = new IdempotencyService();
        $this->cleanTable();
    }

    protected function tearDown(): void
    {
        $this->cleanTable();
        parent::tearDown();
    }

    private function cleanTable(): void
    {
        Db::execute('DELETE FROM cz_idempotency_record');
    }

    private function keyHash(string $s = 'test-key-0001'): string
    {
        return hash('sha256', $s);
    }

    private function requestHash(array $payload = ['product_id' => 1]): string
    {
        return hash('sha256', json_encode($payload));
    }

    private function defaultArgs(): array
    {
        return [
            'principalType' => 'user',
            'principalId' => 1001,
            'operation' => 'order.create',
            'keyHash' => $this->keyHash(),
            'requestHash' => $this->requestHash(),
            'ttlSeconds' => 86400,
        ];
    }

    // ==================== S1: first execution ====================

    public function testS1_firstExecutionProducesCompleted(): void
    {
        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 201, 'body' => ['order_id' => 42, 'order_number' => 'ORD001'], 'resource_type' => 'order', 'resource_id' => '42'];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $result->status);
        $this->assertSame(201, $result->httpStatus);
        $this->assertSame(['order_id' => 42, 'order_number' => 'ORD001'], $result->body);
        $this->assertFalse($result->isReplay);
        $this->assertSame(1, $counter);

        // record in DB = COMPLETED
        $record = IdempotencyRecord::where('id', '=', $result->recordId)->find();
        $this->assertNotNull($record);
        $this->assertSame(IdempotencyRecord::STATUS_COMPLETED, (string) $record->status);
        $this->assertSame(201, (int) $record->http_status);
        $this->assertSame('order', (string) $record->resource_type);
        $this->assertSame('42', (string) $record->resource_id);
    }

    // ==================== S2: completed replay ====================

    public function testS2_completedReplaySkipsCallback(): void
    {
        $counter = 0;
        $business = function () use (&$counter) {
            $counter++;
            return ['http_status' => 200, 'body' => ['order_id' => 7]];
        };

        $first = $this->service->execute(...array_values($this->defaultArgs()), business: $business);
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $first->status);

        $second = $this->service->execute(...array_values($this->defaultArgs()), business: $business);
        $this->assertSame(IdempotencyResult::STATUS_REPLAY, $second->status);
        $this->assertTrue($second->isReplay);
        $this->assertSame(200, $second->httpStatus);
        $this->assertSame(['order_id' => 7], $second->body);
        $this->assertSame(1, $counter, 'callback must execute exactly once');
    }

    // ==================== S3: hash conflict ====================

    public function testS3_differentRequestHashReturnsConflict(): void
    {
        $counter = 0;
        $first = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => ['order_id' => 1]];
            }
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $first->status);

        $args = $this->defaultArgs();
        $args['requestHash'] = $this->requestHash(['product_id' => 999]);

        $second = $this->service->execute(
            ...array_values($args),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => ['order_id' => 2]];
            }
        );
        $this->assertSame(IdempotencyResult::STATUS_CONFLICT, $second->status);
        $this->assertSame(1, $counter, 'conflict must not execute callback');
    }

    // ==================== S4: visible processing fail-closed ====================

    public function testS4_visibleProcessingReturnsInProgress(): void
    {
        // 人工插入一条 visible PROCESSING record
        IdempotencyRecord::create([
            'principal_type' => 'user',
            'principal_id' => 1001,
            'operation' => 'order.create',
            'idempotency_key_hash' => $this->keyHash(),
            'request_hash' => $this->requestHash(),
            'fingerprint_version' => 1,
            'status' => IdempotencyRecord::STATUS_PROCESSING,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => []];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_IN_PROGRESS, $result->status);
        $this->assertSame(0, $counter, 'visible processing must not execute callback');
    }

    // ==================== S5: expired completed new execution ====================

    public function testS5_expiredCompletedAllowsNewExecution(): void
    {
        // 人工插入一条 expired COMPLETED record
        IdempotencyRecord::create([
            'principal_type' => 'user',
            'principal_id' => 1001,
            'operation' => 'order.create',
            'idempotency_key_hash' => $this->keyHash(),
            'request_hash' => $this->requestHash(),
            'fingerprint_version' => 1,
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'http_status' => 200,
            'response_body' => json_encode(['order_id' => 1]),
            'expires_at' => date('Y-m-d H:i:s', time() - 3600), // expired 1h ago
        ]);

        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 201, 'body' => ['order_id' => 999]];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $result->status);
        $this->assertSame(201, $result->httpStatus);
        $this->assertSame(['order_id' => 999], $result->body);
        $this->assertSame(1, $counter);

        // old expired row should be replaced (only 1 row with this scope)
        $count = IdempotencyRecord::where([
            ['principal_type', '=', 'user'],
            ['principal_id', '=', 1001],
            ['operation', '=', 'order.create'],
            ['idempotency_key_hash', '=', $this->keyHash()],
        ])->count();
        $this->assertSame(1, $count);
    }

    // ==================== S6: unknown status fail-closed ====================

    public function testS6_unknownStatusFailClosed(): void
    {
        IdempotencyRecord::create([
            'principal_type' => 'user',
            'principal_id' => 1001,
            'operation' => 'order.create',
            'idempotency_key_hash' => $this->keyHash(),
            'request_hash' => $this->requestHash(),
            'fingerprint_version' => 1,
            'status' => 'banana',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => []];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_ERROR, $result->status);
        $this->assertSame(0, $counter, 'unknown status must not execute callback');
    }

    // ==================== S7: response roundtrip ====================

    public function testS7_responseRoundtripExactMatch(): void
    {
        $payload = ['order_id' => 123, 'order_number' => 'ORD-2026-001', 'status' => 'pending', 'amount' => '99.50', 'items' => [['sku' => 'A', 'qty' => 2], ['sku' => 'B', 'qty' => 1]]];

        $first = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => $payload]
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $first->status);

        $second = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => ['should_not' => 'appear']]
        );
        $this->assertSame(IdempotencyResult::STATUS_REPLAY, $second->status);
        $this->assertSame($payload, $second->body);
    }

    // ==================== S8: fingerprint version mismatch ====================

    public function testS8_unsupportedFingerprintVersionFailClosed(): void
    {
        IdempotencyRecord::create([
            'principal_type' => 'user',
            'principal_id' => 1001,
            'operation' => 'order.create',
            'idempotency_key_hash' => $this->keyHash(),
            'request_hash' => $this->requestHash(),
            'fingerprint_version' => 99,
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'http_status' => 200,
            'response_body' => '{}',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => []];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_ERROR, $result->status);
        $this->assertSame(0, $counter);
    }

    // ==================== S9: TTL range / expires_at ====================

    public function testS9_ttlMinBoundaryAndExpiresAt(): void
    {
        $before = time();
        $result = $this->service->execute(
            principalType: 'user',
            principalId: 1001,
            operation: 'order.create',
            keyHash: $this->keyHash(),
            requestHash: $this->requestHash(),
            ttlSeconds: 3600,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
        $after = time();

        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $result->status);
        $record = IdempotencyRecord::where('id', '=', $result->recordId)->find();
        $expires = strtotime((string) $record->expires_at);
        $this->assertGreaterThanOrEqual($before + 3600 - 1, $expires);
        $this->assertLessThanOrEqual($after + 3600 + 1, $expires);
    }

    public function testS9_ttlBelowMinRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 1001, operation: 'order.create',
            keyHash: $this->keyHash(), requestHash: $this->requestHash(), ttlSeconds: 3599,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    public function testS9_ttlAboveMaxRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 1001, operation: 'order.create',
            keyHash: $this->keyHash(), requestHash: $this->requestHash(), ttlSeconds: 604801,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    // ==================== S10: corrupt response JSON fail-closed ====================

    public function testS10_corruptResponseJsonFailClosed(): void
    {
        IdempotencyRecord::create([
            'principal_type' => 'user',
            'principal_id' => 1001,
            'operation' => 'order.create',
            'idempotency_key_hash' => $this->keyHash(),
            'request_hash' => $this->requestHash(),
            'fingerprint_version' => 1,
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'http_status' => 200,
            'response_body' => '{invalid json',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $counter = 0;
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => []];
            }
        );

        $this->assertSame(IdempotencyResult::STATUS_ERROR, $result->status);
        $this->assertSame(0, $counter, 'corrupt replay must not re-execute business');
    }

    // ==================== T1: callback exception rollback ====================

    public function testT1_callbackExceptionRollsBackRecordAndMutation(): void
    {
        $counter = 0;
        try {
            $this->service->execute(
                ...array_values($this->defaultArgs()),
                business: function () use (&$counter) {
                    $counter++;
                    // simulate business DB mutation
                    IdempotencyRecord::where('id', '=', 999999)->update(['status' => 'x']);
                    throw new \RuntimeException('business failed');
                }
            );
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('business failed', $e->getMessage());
        }

        // PROCESSING row must be rolled back (not exist)
        $count = IdempotencyRecord::where([
            ['principal_type', '=', 'user'],
            ['principal_id', '=', 1001],
            ['operation', '=', 'order.create'],
            ['idempotency_key_hash', '=', $this->keyHash()],
        ])->count();
        $this->assertSame(0, $count, 'PROCESSING row must be rolled back');

        // second attempt = fresh execute (no replay)
        $second = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => ['retry' => true]]
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $second->status);
        $this->assertSame(1, $counter, 'callback executed once in failed attempt, not in second');
    }

    // ==================== T2: successful + COMPLETED atomic ====================

    public function testT2_successfulCompletionAtomic(): void
    {
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 201, 'body' => ['order_id' => 55]]
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $result->status);

        // record must be COMPLETED with correct payload (proves atomic commit)
        $record = IdempotencyRecord::where('id', '=', $result->recordId)->find();
        $this->assertSame(IdempotencyRecord::STATUS_COMPLETED, (string) $record->status);
        $this->assertSame(201, (int) $record->http_status);
        $this->assertSame(['order_id' => 55], json_decode((string) $record->response_body, true));
    }

    // ==================== T3: completed committed → retry replay ====================

    public function testT3_completedCommittedRetryReplaysWithoutCallback(): void
    {
        $counter = 0;
        $first = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => ['order_id' => 77]];
            }
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $first->status);

        // simulate "discard first response, client retries"
        $second = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: function () use (&$counter) {
                $counter++;
                return ['http_status' => 200, 'body' => ['order_id' => 88]];
            }
        );
        $this->assertSame(IdempotencyResult::STATUS_REPLAY, $second->status);
        $this->assertSame(['order_id' => 77], $second->body, 'must replay original, not execute new callback');
        $this->assertSame(1, $counter);
    }

    // ==================== 204 behavior ====================

    public function test204_responseBodyNullReplay(): void
    {
        $first = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 204, 'body' => null]
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $first->status);

        $record = IdempotencyRecord::where('id', '=', $first->recordId)->find();
        $this->assertNull($record->response_body);

        $second = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => ['should' => 'not appear']]
        );
        $this->assertSame(IdempotencyResult::STATUS_REPLAY, $second->status);
        $this->assertSame(204, $second->httpStatus);
        $this->assertNull($second->body);
    }

    public function test204_withBodyRejectedRollback(): void
    {
        try {
            $this->service->execute(
                ...array_values($this->defaultArgs()),
                business: fn () => ['http_status' => 204, 'body' => ['foo' => 'bar']]
            );
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('204', $e->getMessage());
        }

        // record must not exist (rolled back)
        $count = IdempotencyRecord::where([
            ['principal_type', '=', 'user'],
            ['principal_id', '=', 1001],
            ['operation', '=', 'order.create'],
            ['idempotency_key_hash', '=', $this->keyHash()],
        ])->count();
        $this->assertSame(0, $count);
    }

    // ==================== oversized payload rollback ====================

    public function testOversizedPayloadRollsBack(): void
    {
        $huge = str_repeat('x', 20000);
        try {
            $this->service->execute(
                ...array_values($this->defaultArgs()),
                business: fn () => ['http_status' => 200, 'body' => ['data' => $huge]]
            );
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }

        $count = IdempotencyRecord::where([
            ['principal_type', '=', 'user'],
            ['principal_id', '=', 1001],
            ['operation', '=', 'order.create'],
            ['idempotency_key_hash', '=', $this->keyHash()],
        ])->count();
        $this->assertSame(0, $count, 'oversized payload must rollback record');
    }

    // ==================== invalid http status rejected ====================

    public function testInvalidHttpStatusRejected(): void
    {
        foreach ([199, 300, 400, 409, 422, 500] as $status) {
            $this->cleanTable();
            try {
                $this->service->execute(
                    ...array_values($this->defaultArgs()),
                    business: fn () => ['http_status' => $status, 'body' => []]
                );
                $this->fail("expected exception for status {$status}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('2xx', $e->getMessage());
            }
        }
    }

    // ==================== input validation ====================

    public function testInputValidationInvalidPrincipalType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'guest', principalId: 1, operation: 'order.create',
            keyHash: $this->keyHash(), requestHash: $this->requestHash(), ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    public function testInputValidationPrincipalIdZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 0, operation: 'order.create',
            keyHash: $this->keyHash(), requestHash: $this->requestHash(), ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    public function testInputValidationInvalidOperation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 1, operation: 'order create; DROP TABLE',
            keyHash: $this->keyHash(), requestHash: $this->requestHash(), ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    public function testInputValidationInvalidKeyHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 1, operation: 'order.create',
            keyHash: 'not-a-valid-hash', requestHash: $this->requestHash(), ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    public function testInputValidationInvalidRequestHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(
            principalType: 'user', principalId: 1, operation: 'order.create',
            keyHash: $this->keyHash(), requestHash: 'ZZZ' . str_repeat('0', 61), ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => []],
        );
    }

    // ==================== session timeout restoration ====================

    public function testSessionLockTimeoutRestoredAfterSuccess(): void
    {
        $before = (int) Db::query('SELECT @@session.innodb_lock_wait_timeout AS v')[0]['v'];

        $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => []]
        );

        $after = (int) Db::query('SELECT @@session.innodb_lock_wait_timeout AS v')[0]['v'];
        $this->assertSame($before, $after, 'lock wait timeout must be restored after success');
    }

    public function testSessionLockTimeoutRestoredAfterException(): void
    {
        $before = (int) Db::query('SELECT @@session.innodb_lock_wait_timeout AS v')[0]['v'];

        try {
            $this->service->execute(
                ...array_values($this->defaultArgs()),
                business: fn () => throw new \RuntimeException('fail')
            );
        } catch (\RuntimeException) {
        }

        $after = (int) Db::query('SELECT @@session.innodb_lock_wait_timeout AS v')[0]['v'];
        $this->assertSame($before, $after, 'lock wait timeout must be restored after exception');
    }

    // ==================== resource fields validation ====================

    public function testResourceTypeTooLongRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => [], 'resource_type' => str_repeat('x', 33)]
        );
    }

    public function testResourceIdTooLongRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => [], 'resource_id' => str_repeat('x', 65)]
        );
    }

    // ==================== admin principal type ====================

    public function testAdminPrincipalTypeAccepted(): void
    {
        $result = $this->service->execute(
            principalType: 'admin', principalId: 1, operation: 'admin.action',
            keyHash: $this->keyHash('admin-key-0001'), requestHash: $this->requestHash(),
            ttlSeconds: 86400,
            business: fn () => ['http_status' => 200, 'body' => ['ok' => true]],
        );
        $this->assertSame(IdempotencyResult::STATUS_EXECUTED, $result->status);
    }

    // ==================== fingerprint version written ====================

    public function testFingerprintVersionOneWrittenToNewRecord(): void
    {
        $result = $this->service->execute(
            ...array_values($this->defaultArgs()),
            business: fn () => ['http_status' => 200, 'body' => []]
        );
        $record = IdempotencyRecord::where('id', '=', $result->recordId)->find();
        $this->assertSame(1, (int) $record->fingerprint_version);
    }
}
