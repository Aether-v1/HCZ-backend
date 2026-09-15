<?php
namespace app\service;

use app\model\RefundIntent;
use think\facade\Db;
use think\facade\Log;

/**
 * HCZ INFO-022-A: 积分返还意图服务（DB Outbox / Crash Recovery）
 *
 * 核心保证：
 *   一旦返还义务通过 createIntent() 持久化到 DB，
 *   无论 Redis 是否可用、PHP 在哪个毫秒级位置 crash、Worker 重启或并发执行，
 *   都不会造成永久漏返。
 *
 * 三层幂等：
 *   1. cz_refund_intent.uk_refund_key UNIQUE — 防重复创建 intent
 *   2. cz_points_record.uk_refund_key UNIQUE — 防重复 addPoints
 *   3. PointsService::addPoints(..., $refundKey) — 写入 refund_key 到 points_record
 *
 * 并发安全：
 *   - 原子 claim：UPDATE ... WHERE status='pending' LIMIT N（InnoDB 行锁）
 *   - lease：Worker 标识 + 过期时间
 *   - stale reclaim：lease 过期的 processing intent 重新变为 pending
 */
class RefundIntentService
{
    /** 最大重试次数，超过后进入 failed 终态需人工介入 */
    public const MAX_RETRY_COUNT = 10;

    /** Worker 租约超时秒数，超过后可被其他 Worker reclaim */
    public const LEASE_TIMEOUT_SECONDS = 300;

    /** Worker 每批处理数量 */
    public const BATCH_SIZE = 100;

    /** 指数退避最大延迟秒数（24 小时） */
    public const MAX_BACKOFF_SECONDS = 86400;

    /**
     * 创建或获取返还意图（幂等）。
     *
     * 同一 refund_key 只能存在一条 intent。
     * 如果已存在，返回已有 intent（可能是 pending/processing/completed/failed）。
     * 如果新建成功，status=pending。
     *
     * @param string $refundKey 返还幂等键（与 Redis SETNX key / points_record.refund_key 完全一致）
     * @param int    $uid       用户 ID
     * @param int    $points    返还积分数
     * @param string $reason    返还原因
     * @return array|null 成功返回 intent 数组，DB 错误返回 null
     */
    public function createIntent(string $refundKey, int $uid, int $points, string $reason): ?array
    {
        if ($refundKey === '' || $points <= 0 || $uid <= 0) {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        // 尝试 INSERT（UNIQUE 约束处理重复）
        try {
            Db::name('refund_intent')->insert([
                'refund_key'      => $refundKey,
                'uid'             => $uid,
                'points'          => $points,
                'reason'          => mb_substr($reason, 0, 255),
                'status'          => RefundIntent::STATUS_PENDING,
                'retry_count'     => 0,
                'next_retry_time' => null,
                'lease_owner'     => null,
                'lease_expire_time' => null,
                'error_message'   => null,
                'create_time'     => $now,
                'update_time'     => $now,
            ]);
        } catch (\Throwable $e) {
            // 可能是 UNIQUE 冲突（intent 已存在）或其他 DB 错误。
            // 不依赖错误信息解析，直接查询是否已存在。
        }

        // 查询已有或新建的 intent
        $intent = Db::name('refund_intent')->where('refund_key', $refundKey)->find();
        if (!$intent) {
            Log::critical('refund_intent 创建失败且不存在已有记录', [
                'refund_key' => $refundKey,
                'uid' => $uid,
                'points' => $points,
            ]);
            return null;
        }

        // Db::name()->find() 返回数组（非模型对象），直接返回
        return is_array($intent) ? $intent : ($intent ? $intent->toArray() : null);
    }

    /**
     * 处理单个返还意图：调用 PointsService::addPoints，更新 intent 状态。
     *
     * 成功 → status=completed
     * addPoints UNIQUE 冲突（points_record 已存在）→ status=completed（幂等成功）
     * 其他失败 → status=pending + retry_count+1 + 指数退避（达到上限则 failed）
     *
     * @param array $intent intent 记录（必须包含 id, refund_key, uid, points, reason, status, retry_count）
     * @return array{code:int, msg:string, status:string}
     */
    public function processIntent(array $intent): array
    {
        $id = (int)$intent['id'];
        $refundKey = (string)$intent['refund_key'];
        $uid = (int)$intent['uid'];
        $points = (int)$intent['points'];
        $reason = (string)$intent['reason'];
        $retryCount = (int)($intent['retry_count'] ?? 0);

        try {
            $pointsService = new PointsService();
            $result = $pointsService->addPoints($uid, $points, $reason, $refundKey);

            if (($result['code'] ?? 0) == 1) {
                // addPoints 成功 → 标记 completed
                $this->markCompleted($id, null);
                return ['code' => 1, 'msg' => '积分返还成功', 'status' => RefundIntent::STATUS_COMPLETED];
            }

            // addPoints 返回失败 — 检查是否因 UNIQUE 冲突（record 已存在，幂等）
            if ($this->pointsRecordExists($refundKey)) {
                $this->markCompleted($id, 'idempotent: points_record already exists');
                return ['code' => 1, 'msg' => '积分已返还（幂等）', 'status' => RefundIntent::STATUS_COMPLETED];
            }

            // 真正失败 → 重试
            return $this->markForRetry($id, $retryCount, $result['msg'] ?? 'addPoints returned failure');
        } catch (\Throwable $e) {
            // 异常 — 检查 record 是否已存在（可能 addPoints 事务提交后异常，或 UNIQUE 冲突异常）
            if ($this->pointsRecordExists($refundKey)) {
                $this->markCompleted($id, 'idempotent: points_record exists after exception');
                return ['code' => 1, 'msg' => '积分已返还（幂等）', 'status' => RefundIntent::STATUS_COMPLETED];
            }

            return $this->markForRetry($id, $retryCount, $e->getMessage());
        }
    }

    /**
     * 同步快速路径：创建 intent 并立即处理。
     *
     * 正常路径：createIntent → processIntent → 即时返还（与旧行为一致）。
     * Crash 路径：createIntent 已 COMMIT → processIntent crash → intent=pending → Worker 恢复。
     *
     * 这是 5 条返还路径的统一接入方法。
     *
     * @param string $refundKey 返还幂等键
     * @param int    $uid       用户 ID
     * @param int    $points    返还积分数
     * @param string $reason    返还原因
     * @return array{code:int, msg:string, status:string}
     */
    public function createAndProcess(string $refundKey, int $uid, int $points, string $reason): array
    {
        if ($refundKey === '' || $points <= 0) {
            return ['code' => 1, 'msg' => '无需返还积分', 'status' => 'skipped'];
        }

        $intent = $this->createIntent($refundKey, $uid, $points, $reason);
        if (!$intent) {
            return ['code' => 0, 'msg' => '积分返还意图创建失败', 'status' => 'error'];
        }

        // 已完成 → 幂等返回
        if ($intent['status'] === RefundIntent::STATUS_COMPLETED) {
            return ['code' => 1, 'msg' => '积分已返还', 'status' => RefundIntent::STATUS_COMPLETED];
        }

        // failed 终态 → 返回失败（需人工介入）
        if ($intent['status'] === RefundIntent::STATUS_FAILED) {
            return ['code' => 0, 'msg' => '积分返还已达最大重试次数，需人工介入', 'status' => RefundIntent::STATUS_FAILED];
        }

        // pending 或 processing → 同步处理（快速路径）
        return $this->processIntent($intent);
    }

    /**
     * Worker 入口：回收 stale lease → 原子 claim pending intent → 批量处理。
     *
     * @param int $limit 每批处理数量
     * @return array{processed:int, succeeded:int, failed:int, reclaimed:int}
     */
    public function processPendingIntents(int $limit = self::BATCH_SIZE): array
    {
        $workerId = bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        $leaseExpire = date('Y-m-d H:i:s', time() + self::LEASE_TIMEOUT_SECONDS);

        // Step 1: 回收 stale lease（Worker crash 后租约过期的 processing intent）
        $reclaimed = $this->reclaimStaleLeases();

        // Step 2: 原子 claim pending intent
        // 使用原生 SQL 确保 UPDATE ... WHERE status='pending' LIMIT N 的原子性
        $claimed = Db::execute(
            "UPDATE `cz_refund_intent` 
             SET `status` = ?, `lease_owner` = ?, `lease_expire_time` = ?, `update_time` = ?
             WHERE `status` = ? 
               AND (`next_retry_time` IS NULL OR `next_retry_time` <= ?)
             ORDER BY `id` ASC
             LIMIT ?",
            [
                RefundIntent::STATUS_PROCESSING,
                $workerId,
                $leaseExpire,
                $now,
                RefundIntent::STATUS_PENDING,
                $now,
                $limit,
            ]
        );

        if ($claimed === 0) {
            return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'reclaimed' => $reclaimed];
        }

        // Step 3: 获取本 Worker claim 的 intent 并处理
        $intents = Db::name('refund_intent')
            ->where('lease_owner', $workerId)
            ->where('status', RefundIntent::STATUS_PROCESSING)
            ->select()
            ->toArray();

        $succeeded = 0;
        $failed = 0;

        foreach ($intents as $intent) {
            $result = $this->processIntent($intent);
            if (($result['code'] ?? 0) == 1) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => count($intents),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'reclaimed' => $reclaimed,
        ];
    }

    /**
     * 回收 stale lease：Worker crash 后 lease 过期的 processing intent 重新变为 pending。
     *
     * @param int $leaseTimeoutSeconds 租约超时秒数（默认使用类常量）
     * @return int 回收的 intent 数量
     */
    public function reclaimStaleLeases(int $leaseTimeoutSeconds = self::LEASE_TIMEOUT_SECONDS): int
    {
        $now = date('Y-m-d H:i:s');

        return Db::name('refund_intent')
            ->where('status', RefundIntent::STATUS_PROCESSING)
            ->where('lease_expire_time', '<', $now)
            ->update([
                'status'            => RefundIntent::STATUS_PENDING,
                'lease_owner'       => null,
                'lease_expire_time' => null,
                'update_time'       => $now,
            ]);
    }

    // ==================== 私有辅助方法 ====================

    /**
     * 检查 points_record 是否已存在该 refund_key。
     */
    private function pointsRecordExists(string $refundKey): bool
    {
        try {
            $count = Db::name('points_record')->where('refund_key', $refundKey)->count();
            return $count > 0;
        } catch (\Throwable $e) {
            Log::error('检查 points_record refund_key 失败', [
                'refund_key' => $refundKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 标记 intent 为 completed。
     */
    private function markCompleted(int $id, ?string $note): void
    {
        Db::name('refund_intent')->where('id', $id)->update([
            'status'            => RefundIntent::STATUS_COMPLETED,
            'lease_owner'       => null,
            'lease_expire_time' => null,
            'error_message'     => $note,
            'update_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 标记 intent 为重试（指数退避）或 failed（达到上限）。
     *
     * @return array{code:int, msg:string, status:string}
     */
    private function markForRetry(int $id, int $currentRetryCount, string $error): array
    {
        $retryCount = $currentRetryCount + 1;
        $now = date('Y-m-d H:i:s');

        if ($retryCount >= self::MAX_RETRY_COUNT) {
            Db::name('refund_intent')->where('id', $id)->update([
                'status'            => RefundIntent::STATUS_FAILED,
                'retry_count'       => $retryCount,
                'lease_owner'       => null,
                'lease_expire_time' => null,
                'error_message'     => mb_substr($error, 0, 1000),
                'update_time'       => $now,
            ]);
            Log::critical('refund_intent 达到最大重试次数，进入 failed 终态', [
                'intent_id' => $id,
                'retry_count' => $retryCount,
                'error' => $error,
            ]);
            return ['code' => 0, 'msg' => '积分返还失败（已达最大重试次数 ' . self::MAX_RETRY_COUNT . ' 次），需人工介入', 'status' => RefundIntent::STATUS_FAILED];
        }

        // 指数退避：2^retry * 60 秒，最大 24 小时
        $delaySeconds = min(pow(2, $retryCount) * 60, self::MAX_BACKOFF_SECONDS);
        $nextRetryTime = date('Y-m-d H:i:s', time() + (int)$delaySeconds);

        Db::name('refund_intent')->where('id', $id)->update([
            'status'            => RefundIntent::STATUS_PENDING,
            'retry_count'       => $retryCount,
            'next_retry_time'   => $nextRetryTime,
            'lease_owner'       => null,
            'lease_expire_time' => null,
            'error_message'     => mb_substr($error, 0, 1000),
            'update_time'       => $now,
        ]);

        Log::warning('refund_intent 处理失败，将在 ' . $delaySeconds . ' 秒后重试', [
            'intent_id' => $id,
            'retry_count' => $retryCount,
            'next_retry_time' => $nextRetryTime,
            'error' => $error,
        ]);

        return ['code' => 0, 'msg' => '积分返还失败，将在 ' . $delaySeconds . ' 秒后重试（第 ' . $retryCount . ' 次）', 'status' => RefundIntent::STATUS_PENDING];
    }
}
