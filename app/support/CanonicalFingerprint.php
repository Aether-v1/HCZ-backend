<?php
declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;
use JsonException;

/**
 * R1.3a: 基于已校验 payload 的规范化指纹（Canonical Fingerprint）。
 *
 * 职责：
 *   - 接收已经过 R1.2 V1Validation 校验/白名单提取的 payload（array）
 *   - 递归规范化：associative map key 字典序排序；list 保持顺序
 *   - 确定性 JSON encode + SHA-256
 *
 * 输入边界（CRITICAL）：
 *   正确：raw request → V1Validation → validated payload → CanonicalFingerprint
 *   禁止：raw request → CanonicalFingerprint（未知字段会制造不同 fingerprint）
 *
 * 不职责：
 *   - 不读取 raw HTTP body / 不调用 Validator / 不查询 DB / 不访问 Session
 *   - 不做类型转换（int 100 与 string "100" 保持不同）
 *   - 不做 Unicode normalization（按 validated PHP string 原值，byte-different → different hash）
 *
 * 类型语义（一旦封板将成为未来 MySQL request_hash 稳定协议）：
 *   - associative object key：递归字典序排序
 *   - list（array_is_list）：保持元素顺序
 *   - sparse numeric-key array（如 [0=>a,2=>b]）：按 associative map 处理
 *   - null：保留（['note'=>null] 与 [] 不同）
 *   - int vs string：100 与 "100" 不同
 *   - bool：true/false 不转 1/0
 *   - float：1.0 与 1 不同（JSON_PRESERVE_ZERO_FRACTION）
 *   - INF/NAN/非法 UTF-8：抛异常（不得静默变 null）
 */
class CanonicalFingerprint
{
    /**
     * 对已校验 payload 计算规范化 SHA-256 指纹。
     *
     * @param array $validatedPayload 已经过 V1Validation 校验/白名单提取的 payload
     *
     * @return string 64 字符小写 hex SHA-256
     *
     * @throws InvalidArgumentException payload 含非法值（非法 UTF-8 / INF / NAN / 过深嵌套）
     */
    public static function hash(array $validatedPayload): string
    {
        $canonical = self::canonicalize($validatedPayload);

        try {
            $json = json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Cannot canonicalize payload for fingerprint: ' . $e->getMessage()
            );
        }

        return hash('sha256', $json);
    }

    /**
     * 递归规范化：
     *   - list array（array_is_list）：保持顺序，递归每个元素
     *   - associative map：按 key 字典序排序，递归每个 value
     *   - scalar：保持原值
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            // list：保持元素顺序（数组顺序是业务语义）
            $result = [];
            foreach ($value as $item) {
                $result[] = self::canonicalize($item);
            }
            return $result;
        }

        // associative map / sparse numeric-key array：按 key 字典序排序
        ksort($value);
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::canonicalize($item);
        }
        return $result;
    }
}
