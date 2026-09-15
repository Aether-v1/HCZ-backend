<?php
declare(strict_types=1);

namespace app\support;

/**
 * Request ID 工具
 *
 * 为每个 API 请求生成唯一标识符，贯穿：
 * Request → Middleware → Controller → Service → Exception → Log → Response
 *
 * 用途：
 * - 通过 request_id 追踪完整调用链
 * - 日志关联（同一个请求的所有日志共享 request_id）
 * - 客户端可通过 X-Request-ID 响应头定位问题
 *
 * 设计原则：
 * - 不影响现有 API 行为（仅添加响应头和日志上下文）
 * - 客户端传入 X-Request-ID 时优先使用（便于前端追踪）
 * - 未传入时自动生成
 * - 格式：hcz_ + 时间戳 + 随机字符串（共 32 字符）
 */
class RequestId
{
    private static ?string $currentId = null;

    /**
     * 获取当前请求 ID（不存在则生成）
     */
    public static function current(): string
    {
        if (self::$currentId === null) {
            self::$currentId = self::generate();
        }
        return self::$currentId;
    }

    /**
     * 设置当前请求 ID（通常来自请求头 X-Request-ID）
     */
    public static function set(string $id): void
    {
        self::$currentId = $id;
    }

    /**
     * 生成新的请求 ID
     * 格式：hcz_{yyyyMMddHHmmss}_{16位随机十六进制}
     */
    public static function generate(): string
    {
        return 'hcz_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }

    /**
     * 重置（测试用）
     */
    public static function reset(): void
    {
        self::$currentId = null;
    }

    /**
     * R1.1: 校验客户端传入的 Request ID 是否可接受
     *
     * 规则：
     * - 非空、trim 后长度 <= 128
     * - 仅允许 [A-Za-z0-9._:-]（常见 UUID/连字符/下划线/冒号格式）
     * - 含空白/控制字符/超长一律视为非法
     *
     * @return bool
     */
    public static function isValid(string $id): bool
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 128) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._:\-]+$/D', $id) === 1;
    }

    /**
     * R1.1: 清洗客户端传入的 Request ID；非法返回 null（调用方应改用自己的生成值）
     */
    public static function sanitize(string $id): ?string
    {
        $trimmed = trim($id);
        return self::isValid($trimmed) ? $trimmed : null;
    }

    /**
     * 获取日志上下文数组
     */
    public static function context(): array
    {
        return ['request_id' => self::current()];
    }
}
