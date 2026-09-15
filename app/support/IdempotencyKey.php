<?php
declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;

/**
 * R1.3a: HTTP Idempotency-Key 无状态校验与哈希。
 *
 * 职责：
 *   - 校验 key 格式（长度 8..128，字符集 [A-Za-z0-9._:-]）
 *   - 合法 key 原样返回（不 trim、不 sanitize、不转大小写）
 *   - SHA-256 哈希（先校验后哈希，非法 key 不得获得可持久化 hash）
 *
 * 不职责：
 *   - 不查询 DB / 不读 Session / 不读 Request / 不写 Cache / 不执行业务逻辑
 *
 * 安全：
 *   - case-sensitive（ABC 与 abc 是不同 key，hash 不同）
 *   - 拒绝 CRLF / control chars，防止 header injection
 *   - 不做 silent sanitization（" bad key " 不会变成 "badkey"）
 */
class IdempotencyKey
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /**
     * 允许字符集：字母数字 + . _ : -
     * 拒绝空白、控制字符、斜杠、引号、分号等。
     */
    private const CHARSET_PATTERN = '/^[A-Za-z0-9._:-]+\z/';

    /**
     * 校验 key 是否合法（不抛异常）。
     */
    public static function isValid(string $key): bool
    {
        $len = strlen($key);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) {
            return false;
        }
        return preg_match(self::CHARSET_PATTERN, $key) === 1;
    }

    /**
     * 校验 key，合法则原样返回，非法抛 InvalidArgumentException。
     *
     * @throws InvalidArgumentException key 格式非法
     */
    public static function validate(string $key): string
    {
        if (!self::isValid($key)) {
            throw new InvalidArgumentException(
                'Invalid Idempotency-Key: must be 8-128 chars, charset [A-Za-z0-9._:-]'
            );
        }
        return $key;
    }

    /**
     * 对合法 key 计算 SHA-256，返回 64 字符小写 hex。
     *
     * 必须先校验：非法 key 不得获得可持久化 hash。
     *
     * @throws InvalidArgumentException key 格式非法
     */
    public static function hash(string $key): string
    {
        self::validate($key);
        return hash('sha256', $key);
    }
}
