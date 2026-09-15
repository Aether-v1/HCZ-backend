<?php
declare(strict_types=1);

namespace app\support;

/**
 * R1.3b: IdempotencyService 执行结果（极轻量 readonly value object）
 *
 * 不依赖 Response / Request / Controller。HTTP 映射留 R1.4/R3。
 */
final readonly class IdempotencyResult
{
    /** 首次执行业务成功 */
    public const STATUS_EXECUTED = 'executed';
    /** 重放已完成结果（business callback 未执行） */
    public const STATUS_REPLAY = 'replay';
    /** 同 key 不同 request_hash → 冲突 */
    public const STATUS_CONFLICT = 'conflict';
    /** 首请求仍在处理中（lock wait timeout） */
    public const STATUS_IN_PROGRESS = 'in_progress';
    /** 无法确认幂等状态 → fail closed（caller 应返回 500/503） */
    public const STATUS_ERROR = 'error';

    public function __construct(
        public string $status,
        public ?int $httpStatus = null,
        public ?array $body = null,
        public bool $isReplay = false,
        public ?int $recordId = null,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function executed(int $httpStatus, ?array $body, ?int $recordId, ?string $resourceType, ?string $resourceId): self
    {
        return new self(
            status: self::STATUS_EXECUTED,
            httpStatus: $httpStatus,
            body: $body,
            isReplay: false,
            recordId: $recordId,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );
    }

    public static function replay(int $httpStatus, ?array $body, int $recordId, ?string $resourceType, ?string $resourceId): self
    {
        return new self(
            status: self::STATUS_REPLAY,
            httpStatus: $httpStatus,
            body: $body,
            isReplay: true,
            recordId: $recordId,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );
    }

    public static function conflict(): self
    {
        return new self(status: self::STATUS_CONFLICT);
    }

    public static function inProgress(): self
    {
        return new self(status: self::STATUS_IN_PROGRESS);
    }

    public static function error(string $message): self
    {
        return new self(status: self::STATUS_ERROR, errorMessage: $message);
    }
}
