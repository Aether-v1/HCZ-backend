<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\IdempotencyKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * R1.3a — IdempotencyKey Contract Tests
 *
 * 覆盖：长度/字符集/大小写保留/CRLF 注入拒绝/SHA-256 确定性/先校验后哈希。
 */
class IdempotencyKeyContractTest extends TestCase
{
    // ---------- 合法 key ----------

    public function testValidUuidAccepted(): void
    {
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue(IdempotencyKey::isValid($key));
        $this->assertSame($key, IdempotencyKey::validate($key));
    }

    public function testValidMinimumLengthAccepted(): void
    {
        $key = 'abcd1234'; // 8 chars
        $this->assertTrue(IdempotencyKey::isValid($key));
        $this->assertSame($key, IdempotencyKey::validate($key));
    }

    public function testValidMaximumLengthAccepted(): void
    {
        $key = str_repeat('a', 128);
        $this->assertTrue(IdempotencyKey::isValid($key));
        $this->assertSame($key, IdempotencyKey::validate($key));
    }

    public function testValidWithDotsUnderscoresColons(): void
    {
        $key = 'order_1234.abcd:mobile-android';
        $this->assertTrue(IdempotencyKey::isValid($key));
    }

    // ---------- 非法 key ----------

    public function testEmptyRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid(''));
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::validate('');
    }

    public function testTooShortRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('abcd123')); // 7 chars
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::validate('abcd123');
    }

    public function testTooLongRejected(): void
    {
        $key = str_repeat('a', 129);
        $this->assertFalse(IdempotencyKey::isValid($key));
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::validate($key);
    }

    public function testSpaceRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('abcd 1234'));
    }

    public function testWhitespaceOnlyRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('        '));
    }

    public function testCrlfRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid("abc12345\r\nX-Evil:1"));
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::validate("abc12345\r\nX-Evil:1");
    }

    public function testNewlineRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid("abc12345\n"));
    }

    public function testTabRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid("abc1234\t5"));
    }

    public function testUnicodeRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('订单号12345678'));
    }

    public function testSlashRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('abc/12345'));
    }

    public function testBackslashRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('abc\\12345'));
    }

    public function testQuoteRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid("abc'12345"));
        $this->assertFalse(IdempotencyKey::isValid('abc"12345'));
    }

    public function testSemicolonRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid('abc;12345'));
    }

    public function testControlCharRejected(): void
    {
        $this->assertFalse(IdempotencyKey::isValid("abc\x0012345"));
        $this->assertFalse(IdempotencyKey::isValid("abc\x0712345"));
    }

    // ---------- 大小写保留（CRITICAL） ----------

    public function testCasePreservedByValidate(): void
    {
        $key = 'ABCdef1234';
        $this->assertSame('ABCdef1234', IdempotencyKey::validate($key));
    }

    public function testCaseSensitiveHash(): void
    {
        $upper = 'ABC12345';
        $lower = 'abc12345';
        $this->assertTrue(IdempotencyKey::isValid($upper));
        $this->assertTrue(IdempotencyKey::isValid($lower));
        $this->assertNotSame(IdempotencyKey::hash($upper), IdempotencyKey::hash($lower));
    }

    // ---------- 不做 silent sanitization ----------

    public function testNoSilentTrim(): void
    {
        // " bad key " 不会变成 "badkey"——直接拒绝
        $this->assertFalse(IdempotencyKey::isValid(' abcd1234'));
        $this->assertFalse(IdempotencyKey::isValid('abcd1234 '));
    }

    // ---------- SHA-256 哈希 ----------

    public function testHashDeterministic(): void
    {
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame(IdempotencyKey::hash($key), IdempotencyKey::hash($key));
    }

    public function testHashLengthIs64(): void
    {
        $hash = IdempotencyKey::hash('abcd1234');
        $this->assertSame(64, strlen($hash));
    }

    public function testHashIsLowerHex(): void
    {
        $hash = IdempotencyKey::hash('ABCD1234');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testHashRejectsInvalidKey(): void
    {
        // 必须先校验后哈希：非法 key 不得获得可持久化 hash
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::hash('bad');
    }

    public function testHashDifferentKeysDifferent(): void
    {
        $this->assertNotSame(
            IdempotencyKey::hash('order_0001'),
            IdempotencyKey::hash('order_0002')
        );
    }

    // ---------- 边界长度精确 ----------

    public function testBoundary7CharsInvalid(): void
    {
        $this->assertFalse(IdempotencyKey::isValid(str_repeat('a', 7)));
    }

    public function testBoundary8CharsValid(): void
    {
        $this->assertTrue(IdempotencyKey::isValid(str_repeat('a', 8)));
    }

    public function testBoundary128CharsValid(): void
    {
        $this->assertTrue(IdempotencyKey::isValid(str_repeat('a', 128)));
    }

    public function testBoundary129CharsInvalid(): void
    {
        $this->assertFalse(IdempotencyKey::isValid(str_repeat('a', 129)));
    }
}
