<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\CanonicalFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * R1.3a — CanonicalFingerprint Contract Tests
 *
 * 覆盖：associative key 排序/递归/list 顺序保留/null/类型区分/float/非法 UTF-8/INF-NAN/空 payload/稀疏数组。
 * 一旦封板将成为未来 MySQL request_hash 稳定协议。
 */
class CanonicalFingerprintContractTest extends TestCase
{
    // ---------- associative key 顺序规范化 ----------

    public function testSameAssociativeDataDifferentKeyOrderSameHash(): void
    {
        $a = ['b' => 2, 'a' => 1];
        $b = ['a' => 1, 'b' => 2];
        $this->assertSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    public function testNestedObjectDifferentKeyOrderSameHash(): void
    {
        $a = [
            'profile' => ['name' => 'A', 'phone' => '1'],
            'type' => 'x',
        ];
        $b = [
            'type' => 'x',
            'profile' => ['phone' => '1', 'name' => 'A'],
        ];
        $this->assertSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    public function testThreeLevelNestedCanonicalization(): void
    {
        $a = ['a' => ['b' => ['d' => 4, 'c' => 3]]];
        $b = ['a' => ['b' => ['c' => 3, 'd' => 4]]];
        $this->assertSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    // ---------- list 顺序保留 ----------

    public function testListSameOrderSameHash(): void
    {
        $a = ['A', 'B', 'C'];
        $b = ['A', 'B', 'C'];
        $this->assertSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    public function testListDifferentOrderDifferentHash(): void
    {
        $a = ['A', 'B'];
        $b = ['B', 'A'];
        $this->assertNotSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    public function testNestedListOrderPreserved(): void
    {
        $a = ['items' => [1, 2, 3]];
        $b = ['items' => [3, 2, 1]];
        $this->assertNotSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    // ---------- 不同值不同 hash ----------

    public function testDifferentScalarValueDifferentHash(): void
    {
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => 1]),
            CanonicalFingerprint::hash(['x' => 2])
        );
    }

    // ---------- null 保留 ----------

    public function testNullVsMissingDifferent(): void
    {
        $withNull = ['note' => null];
        $missing = [];
        $this->assertNotSame(CanonicalFingerprint::hash($withNull), CanonicalFingerprint::hash($missing));
    }

    public function testNullPreservedInNested(): void
    {
        $a = ['a' => ['b' => null]];
        $b = ['a' => []];
        $this->assertNotSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    // ---------- 类型区分（CRITICAL） ----------

    public function testStringVsIntDifferent(): void
    {
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => '100']),
            CanonicalFingerprint::hash(['x' => 100])
        );
    }

    public function testTrueVsIntOneDifferent(): void
    {
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => true]),
            CanonicalFingerprint::hash(['x' => 1])
        );
    }

    public function testFalseVsIntZeroDifferent(): void
    {
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => false]),
            CanonicalFingerprint::hash(['x' => 0])
        );
    }

    // ---------- float 语义 ----------

    public function testIntOneVsFloatOnePointZeroDifferent(): void
    {
        // JSON_PRESERVE_ZERO_FRACTION 确保 1.0 输出 "1.0"，与 1 不同
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => 1]),
            CanonicalFingerprint::hash(['x' => 1.0])
        );
    }

    public function testFloatOnePointZeroVsOnePointFiveDifferent(): void
    {
        $this->assertNotSame(
            CanonicalFingerprint::hash(['x' => 1.0]),
            CanonicalFingerprint::hash(['x' => 1.5])
        );
    }

    public function testFloatSameValueSameHash(): void
    {
        $this->assertSame(
            CanonicalFingerprint::hash(['x' => 1.5]),
            CanonicalFingerprint::hash(['x' => 1.5])
        );
    }

    // ---------- 空 payload ----------

    public function testEmptyPayloadDeterministic(): void
    {
        $this->assertSame(CanonicalFingerprint::hash([]), CanonicalFingerprint::hash([]));
    }

    public function testEmptyPayloadHashLength64(): void
    {
        $hash = CanonicalFingerprint::hash([]);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    // ---------- 稀疏 numeric-key array ----------

    public function testSparseNumericArrayDeterministic(): void
    {
        // [0=>a, 2=>b] 按 associative map 处理，key 排序后确定性
        $a = [0 => 'a', 2 => 'b'];
        $b = [0 => 'a', 2 => 'b'];
        $this->assertSame(CanonicalFingerprint::hash($a), CanonicalFingerprint::hash($b));
    }

    public function testSparseArrayVsListDifferent(): void
    {
        $sparse = [0 => 'a', 2 => 'b']; // non-list
        $list = ['a', 'b']; // list (0,1)
        $this->assertNotSame(CanonicalFingerprint::hash($sparse), CanonicalFingerprint::hash($list));
    }

    // ---------- 非法 UTF-8 ----------

    public function testInvalidUtf8Throws(): void
    {
        $payload = ['x' => "\xff\xfe\xfd"];
        $this->expectException(InvalidArgumentException::class);
        CanonicalFingerprint::hash($payload);
    }

    public function testInvalidUtf8DoesNotProduceHash(): void
    {
        try {
            CanonicalFingerprint::hash(['x' => "\xff"]);
            $this->fail('expected exception');
        } catch (InvalidArgumentException) {
            // expected
            $this->assertTrue(true);
        }
    }

    // ---------- INF / NAN ----------

    public function testInfinityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalFingerprint::hash(['x' => INF]);
    }

    public function testNegativeInfinityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalFingerprint::hash(['x' => -INF]);
    }

    public function testNanThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalFingerprint::hash(['x' => NAN]);
    }

    // ---------- 未知字段交互（与 R1.2 契约一致） ----------

    public function testFingerprintBasedOnValidatedPayloadOnly(): void
    {
        // 模拟 R1.2 V1Validation 白名单提取后：is_admin 已被移除
        $validated = ['product_id' => 1, 'quantity' => 1];
        $alsoValidated = ['quantity' => 1, 'product_id' => 1];
        // 两个 validated array（key 顺序不同）应产生相同 hash
        $this->assertSame(
            CanonicalFingerprint::hash($validated),
            CanonicalFingerprint::hash($alsoValidated)
        );
    }

    public function testExtraFieldChangesHash(): void
    {
        // 如果调用方错误地把 raw request（含 is_admin）直接传进来，hash 会不同
        // 这证明 fingerprint 必须基于 validated payload
        $withoutExtra = ['product_id' => 1];
        $withExtra = ['product_id' => 1, 'is_admin' => true];
        $this->assertNotSame(
            CanonicalFingerprint::hash($withoutExtra),
            CanonicalFingerprint::hash($withExtra)
        );
    }

    // ---------- hash 格式 ----------

    public function testHashIsLowerHex64(): void
    {
        $hash = CanonicalFingerprint::hash(['a' => 1, 'b' => 'test']);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testHashDeterministicAcrossCalls(): void
    {
        $payload = ['order' => ['items' => [1, 2], 'note' => null], 'type' => 'create'];
        $this->assertSame(CanonicalFingerprint::hash($payload), CanonicalFingerprint::hash($payload));
    }
}
