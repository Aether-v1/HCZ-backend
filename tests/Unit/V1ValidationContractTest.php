<?php
declare(strict_types=1);

namespace tests\Unit;

use app\support\ErrorCode;
use app\support\V1ApiResponse;
use app\support\V1Validation;
use app\support\V1ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * R1.2 — V1Validation Contract Tests
 *
 * 覆盖：必填/格式/枚举/整数/长度、白名单提取、unknown field 策略、
 * 多字段错误、details 结构、mass assignment、password 不 trim、
 * 与 R1.1 V1ApiResponse/ErrorCode 的 cross-contract 映射。
 */
class V1ValidationContractTest extends TestCase
{
    // ---------- 合法通过 ----------

    public function testValidDataPassesAndReturnsData(): void
    {
        $result = V1Validation::validate(
            ['email' => 'user@example.com', 'age' => 25],
            ['email' => 'require|email', 'age' => 'require|integer'],
            [],
            ['email', 'age']
        );
        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame(25, $result['age']);
    }

    // ---------- 单字段失败 ----------

    public function testRequiredMissingFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate([], ['email' => 'require'], [], ['email']);
    }

    public function testNullRequiredFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(['email' => null], ['email' => 'require'], [], ['email']);
    }

    public function testEmptyStringRequiredFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(['email' => ''], ['email' => 'require'], [], ['email']);
    }

    public function testInvalidEmailFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(['email' => 'not-an-email'], ['email' => 'require|email'], [], ['email']);
    }

    public function testInvalidEnumFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(
            ['status' => 'evil'],
            ['status' => 'require|in:active,inactive'],
            [],
            ['status']
        );
    }

    public function testInvalidIntegerFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(['age' => 'abc'], ['age' => 'require|integer'], [], ['age']);
    }

    public function testTooLongFails(): void
    {
        $this->expectException(V1ValidationException::class);
        V1Validation::validate(['name' => 'toolong'], ['name' => 'require|max:2'], [], ['name']);
    }

    // ---------- 白名单 / unknown field ----------

    public function testUnknownFieldIgnoredByDefault(): void
    {
        $result = V1Validation::validate(
            ['email' => 'a@b.com', 'is_admin' => true],
            ['email' => 'require|email'],
            [],
            ['email']
        );
        $this->assertArrayNotHasKey('is_admin', $result);
        $this->assertArrayHasKey('email', $result);
    }

    public function testStrictUnknownRejected(): void
    {
        try {
            V1Validation::validate(
                ['email' => 'a@b.com', 'is_admin' => true],
                ['email' => 'require|email'],
                [],
                ['email'],
                true
            );
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            $this->assertArrayHasKey('is_admin', $e->details);
            $this->assertSame('Unknown field', $e->details['is_admin'][0]);
        }
    }

    public function testWhitelistOutputOnlyContainsAllowedFields(): void
    {
        $result = V1Validation::validate(
            ['a' => 1, 'b' => 2, 'c' => 3],
            [],
            [],
            ['a', 'b']
        );
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    // ---------- mass assignment ----------

    public function testMassAssignmentFieldsRemoved(): void
    {
        // 攻击者传入 uid/amount/admin_id，但 endpoint 只声明 email/name
        $result = V1Validation::validate(
            ['email' => 'a@b.com', 'name' => 'x', 'uid' => 999, 'amount' => 10000, 'admin_id' => 1],
            ['email' => 'require|email', 'name' => 'require|max:10'],
            [],
            ['email', 'name']
        );
        $this->assertArrayNotHasKey('uid', $result);
        $this->assertArrayNotHasKey('amount', $result);
        $this->assertArrayNotHasKey('admin_id', $result);
        $this->assertCount(2, $result);
    }

    // ---------- 多字段错误 / details 结构 ----------

    public function testMultipleFieldErrorsReturned(): void
    {
        try {
            V1Validation::validate(
                ['email' => 'bad', 'age' => 'abc', 'name' => 'toolong'],
                ['email' => 'require|email', 'age' => 'require|integer', 'name' => 'require|max:2'],
                [],
                ['email', 'age', 'name']
            );
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            $this->assertArrayHasKey('email', $e->details);
            $this->assertArrayHasKey('age', $e->details);
            $this->assertArrayHasKey('name', $e->details);
        }
    }

    public function testDetailsFieldValuesAreAlwaysArrays(): void
    {
        try {
            V1Validation::validate(['email' => 'bad'], ['email' => 'require|email'], [], ['email']);
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            // 即使只有一条错误，也必须是数组
            $this->assertIsArray($e->details['email']);
            $this->assertCount(1, $e->details['email']);
            $this->assertIsString($e->details['email'][0]);
            $this->assertNotEmpty($e->details['email'][0]);
        }
    }

    public function testDetailsUsesApiFieldNamesNotChinese(): void
    {
        try {
            V1Validation::validate(['email' => 'bad'], ['email' => 'require|email'], [], ['email']);
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            // key 必须是 API 字段名 email，不是中文别名
            $this->assertArrayHasKey('email', $e->details);
        }
    }

    // ---------- password / token 不被 Foundation 擅自 trim ----------

    public function testPasswordIsNotImplicitlyTrimmed(): void
    {
        // 无规则时 Foundation 不做任何 normalize，password 前后空格保留
        $result = V1Validation::validate(
            ['password' => ' abc '],
            [],
            [],
            ['password']
        );
        $this->assertSame(' abc ', $result['password']);
    }

    public function testTokenIsNotImplicitlyTrimmed(): void
    {
        $result = V1Validation::validate(
            ['token' => ' abc.def.ghi '],
            [],
            [],
            ['token']
        );
        $this->assertSame(' abc.def.ghi ', $result['token']);
    }

    // ---------- Cross-contract：与 R1.1 V1ApiResponse 映射 ----------

    public function testValidationFailureMapsToR11Envelope(): void
    {
        try {
            V1Validation::validate(
                ['email' => 'bad'],
                ['email' => 'require|email'],
                [],
                ['email']
            );
            $this->fail('expected exception');
        } catch (V1ValidationException $e) {
            $resp = V1ApiResponse::error(
                ErrorCode::VALIDATION_FAILED,
                'Request validation failed',
                422,
                $e->details
            );
            $body = json_decode($resp->getContent(), true);

            $this->assertSame(422, $resp->getCode());
            $this->assertFalse($body['success']);
            $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
            $this->assertIsString($body['error']['message']);
            $this->assertArrayHasKey('details', $body['error']);
            $this->assertArrayHasKey('email', $body['error']['details']);
            $this->assertArrayHasKey('request_id', $body);
            $this->assertNotEmpty($body['request_id']);
        }
    }

    public function testValidationFailureDoesNotIntroduceSecondEnvelope(): void
    {
        // cross-contract 输出必须是 R1.1 唯一 envelope，不嵌套
        try {
            V1Validation::validate(['email' => 'bad'], ['email' => 'email'], [], ['email']);
        } catch (V1ValidationException $e) {
            $resp = V1ApiResponse::error(ErrorCode::VALIDATION_FAILED, 'x', 422, $e->details);
            $body = json_decode($resp->getContent(), true);
            // 顶层只有 success/error/request_id，不应有第二个 data/error 嵌套
            $this->assertArrayHasKey('success', $body);
            $this->assertArrayHasKey('error', $body);
            $this->assertArrayHasKey('request_id', $body);
            $this->assertCount(3, $body);
        }
    }

    // ---------- 无规则时通过 ----------

    public function testEmptyRulesPasses(): void
    {
        $result = V1Validation::validate(['a' => 1], [], [], ['a']);
        $this->assertSame(['a' => 1], $result);
    }

    public function testAllowedFieldsEmptyReturnsAllData(): void
    {
        // allowedFields 为空时不做白名单过滤（caller 自负其责）
        $result = V1Validation::validate(['a' => 1, 'b' => 2], []);
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }
}
