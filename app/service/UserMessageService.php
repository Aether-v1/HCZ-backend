<?php
declare(strict_types=1);

namespace app\service;

use app\job\GlobalMessageFanoutJob;
use app\model\UserMessage;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
class UserMessageService
{
    public const SOURCE_TYPES = ['admin', 'system'];
    public const MESSAGE_TYPES = ['official', 'global', 'recharge', 'withdraw', 'order', 'trade', 'agent', 'auth', 'other'];
    public const ACTION_TYPES = ['none', 'route', 'link'];
    private const BLOCKED_ACTION_PROTOCOLS = ['javascript:', 'data:', 'vbscript:', 'file:'];
    private const ACTION_ROUTE_PATTERNS = [
        '/^\/$/',
        '/^\/orders(?:\/[^\/?#]+)?$/',
        '/^\/wallet(?:-details|-detail-list)?$/',
        '/^\/profile$/',
        '/^\/account-(?:settings|telegram|profile|password|twofa)$/',
        '/^\/finance-center$/',
        '/^\/finance-recharge\/[^\/?#]+$/',
        '/^\/finance-withdrawal$/',
        '/^\/points$/',
        '/^\/market$/',
        '/^\/transaction-trading-details\/[^\/?#]+$/',
        '/^\/invite-friends$/',
        '/^\/contact-service$/',
        '/^\/official-info(?:\/detail\/[^\/?#]+)?$/',
        '/^\/help-center$/',
        '/^\/agent-center$/',
        '/^\/substation-(?:center|profile|product-price|income-log)$/',
        '/^\/bank-card$/',
        '/^\/wallet-address$/',
    ];
    private const ACTION_LINK_STATIC_HOSTS = ['your-frontend-domain.com'];
    private const GLOBAL_SYNC_TTL_SECONDS = 86400;
    private const GLOBAL_SYNC_LOCK_TTL_SECONDS = 12;
    private const GLOBAL_LATEST_TEMPLATE_CACHE_SECONDS = 10;

    public static function createUserMessage(
        int $userId,
        string $title,
        string $content,
        string $sourceType = 'system',
        string $messageType = 'official',
        int|string|null $bizId = null,
        string $actionType = 'none',
        ?string $actionValue = null,
        ?int $senderAdminId = null,
        ?string $summary = null,
        int $isPinned = 0
    ): UserMessage {
        $normalizedMessageType = self::normalizeMessageType($messageType);
        if ($userId <= 0 && $normalizedMessageType !== 'global') {
            throw new \InvalidArgumentException('接收用户ID不能为空');
        }

        $title = trim($title);
        $content = trim($content);
        if ($title === '') {
            throw new \InvalidArgumentException('消息标题不能为空');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('消息正文不能为空');
        }

        $normalizedActionType = self::normalizeActionType($actionType);
        $normalizedActionValue = self::normalizeActionValue($normalizedActionType, $actionValue);
        if ($normalizedActionType !== 'none' && $normalizedActionValue === null) {
            throw new \InvalidArgumentException('消息跳转地址不安全或不在允许范围内');
        }

        $message = new UserMessage();
        $message->user_id = $userId;
        $message->title = $title;
        $message->content = $content;
        $message->summary = self::normalizeNullableString($summary);
        $message->source_type = self::normalizeSourceType($sourceType);
        $message->message_type = $normalizedMessageType;
        $message->biz_id = self::normalizeBizId($bizId);
        $message->action_type = $normalizedActionType;
        $message->action_value = $normalizedActionValue;
        $message->is_pinned = $isPinned > 0 ? 1 : 0;
        $message->is_read = 0;
        $message->read_time = null;
        $message->is_deleted = 0;
        $message->sender_admin_id = $senderAdminId ?: null;
        $message->save();

        return $message;
    }

    public static function publishGlobalMessage(
        string $title,
        string $content,
        string $sourceType = 'admin',
        string $actionType = 'none',
        ?string $actionValue = null,
        ?int $senderAdminId = null,
        ?string $summary = null,
        int $isPinned = 0
    ): array {
        $template = self::createUserMessage(
            0,
            $title,
            $content,
            $sourceType,
            'global',
            null,
            $actionType,
            $actionValue,
            $senderAdminId,
            $summary,
            $isPinned
        );

        $queued = self::dispatchGlobalMessageFanout((int)($template['id'] ?? 0));

        return [
            'template' => $template,
            'queued' => $queued ? 1 : 0,
        ];
    }

    public static function syncGlobalMessagesForUser(int $userId, bool $force = false): int
    {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return 0;
        }

        $latestTemplateId = self::latestGlobalTemplateId();
        if ($latestTemplateId <= 0) {
            return 0;
        }

        $stateKey = self::buildGlobalSyncStateKey($uid);
        $lastSyncedTemplateId = self::readGlobalSyncState($stateKey);
        if (!$force && $lastSyncedTemplateId >= $latestTemplateId) {
            return 0;
        }

        $templates = Db::name('user_message')
            ->where('user_id', 0)
            ->where('is_deleted', 0)
            ->where('message_type', 'global')
            ->where('id', '>', max(0, $lastSyncedTemplateId))
            ->whereRaw("title is not null and title <> ''")
            ->whereRaw("content is not null and content <> ''")
            ->field('id,title,content,summary,source_type,message_type,action_type,action_value,is_pinned,sender_admin_id,created_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (empty($templates)) {
            self::writeGlobalSyncState($stateKey, $latestTemplateId);
            return 0;
        }

        $affected = self::syncGlobalMessagesForUserIds([$uid], $templates);
        $missingTemplateCount = self::countMissingGlobalTemplatesForUser($uid, array_column($templates, 'id'));
        if ($missingTemplateCount <= 0) {
            self::writeGlobalSyncState($stateKey, $latestTemplateId);
        }
        return $affected;
    }

    public static function syncGlobalMessageTemplateToAllUsers(int $templateId): int
    {
        $tid = (int)$templateId;
        if ($tid <= 0) {
            return 0;
        }

        $template = Db::name('user_message')
            ->where('id', $tid)
            ->where('user_id', 0)
            ->where('is_deleted', 0)
            ->where('message_type', 'global')
            ->find();

        if (!$template) {
            return 0;
        }

        $affected = 0;
        Db::name('user')
            ->where('id', '>', 0)
            ->field('id')
            ->order('id', 'asc')
            ->chunk(500, function ($users) use (&$affected, $template) {
                $userIds = [];
                foreach ($users as $user) {
                    $uid = (int)($user['id'] ?? 0);
                    if ($uid > 0) {
                        $userIds[] = $uid;
                    }
                }

                if (!empty($userIds)) {
                    $affected += self::syncGlobalMessagesForUserIds($userIds, [$template]);
                }
            });

        return $affected;
    }

    public static function dispatchGlobalMessageFanout(int $templateId): bool
    {
        $tid = (int)$templateId;
        if ($tid <= 0) {
            return false;
        }

        try {
            $queueName = trim((string)env('QUEUE_NAME', 'default')) ?: 'default';
            $result = Queue::push(GlobalMessageFanoutJob::class, [
                'template_id' => $tid,
                'created_at' => time(),
            ], $queueName);
            return $result !== false;
        } catch (\Throwable $e) {
            Log::error('dispatch global message fanout failed', [
                'template_id' => $tid,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    private static function syncGlobalMessagesForUserIds(array $userIds, ?array $templateRows = null): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0)));
        if (empty($userIds)) {
            return 0;
        }

        $templates = $templateRows;
        if ($templates === null) {
            $templates = Db::name('user_message')
                ->where('user_id', 0)
                ->where('is_deleted', 0)
                ->where('message_type', 'global')
                ->whereRaw("title is not null and title <> ''")
                ->whereRaw("content is not null and content <> ''")
                ->field('id,title,content,summary,source_type,message_type,action_type,action_value,is_pinned,sender_admin_id,created_at')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }

        if (empty($templates)) {
            return 0;
        }

        $templateMap = [];
        foreach ($templates as $template) {
            $templateId = (int)($template['id'] ?? 0);
            if ($templateId <= 0) {
                continue;
            }
            $templateMap[$templateId] = $template;
        }
        if (empty($templateMap)) {
            return 0;
        }

        $templateIds = array_keys($templateMap);

        $now = date('Y-m-d H:i:s');
        $affected = 0;

        foreach ($userIds as $uid) {
            if (!self::acquireGlobalSyncLock((int)$uid)) {
                continue;
            }

            try {
                $existingRows = Db::name('user_message')
                    ->where('user_id', (int)$uid)
                    ->where('message_type', 'global')
                    ->where('biz_id', 'in', $templateIds)
                    ->field('biz_id')
                    ->select()
                    ->toArray();

                $existingBizIds = [];
                foreach ($existingRows as $existing) {
                    $existingBizIds[(int)($existing['biz_id'] ?? 0)] = true;
                }

                $insertRows = [];
                foreach ($templateMap as $templateId => $template) {
                    if (isset($existingBizIds[(int)$templateId])) {
                        continue;
                    }

                    $createdAt = trim((string)($template['created_at'] ?? ''));
                    if ($createdAt === '') {
                        $createdAt = $now;
                    }

                    $insertRows[] = [
                        'user_id' => (int)$uid,
                        'title' => (string)($template['title'] ?? ''),
                        'content' => (string)($template['content'] ?? ''),
                        'summary' => (string)($template['summary'] ?? ''),
                        'source_type' => self::normalizeSourceType((string)($template['source_type'] ?? 'admin')),
                        'message_type' => 'global',
                        'biz_id' => (int)$templateId,
                        'action_type' => self::normalizeActionType((string)($template['action_type'] ?? 'none')),
                        'action_value' => (string)($template['action_value'] ?? ''),
                        'is_pinned' => (int)($template['is_pinned'] ?? 0) > 0 ? 1 : 0,
                        'is_read' => 0,
                        'read_time' => null,
                        'is_deleted' => 0,
                        'sender_admin_id' => (int)($template['sender_admin_id'] ?? 0) ?: null,
                        'created_at' => $createdAt,
                        'updated_at' => $now,
                    ];
                }

                if (!empty($insertRows)) {
                    Db::name('user_message')->insertAll($insertRows);
                    $affected += count($insertRows);
                }
            } finally {
                self::releaseGlobalSyncLock((int)$uid);
            }
        }

        return $affected;
    }

    private static function latestGlobalTemplateId(): int
    {
        $cacheKey = 'umsg:global:latest_template_id';
        try {
            $cached = (int)Cache::store('redis')->get($cacheKey, -1);
            if ($cached >= 0) {
                return $cached;
            }
        } catch (\Throwable $e) {
        }

        $latestId = (int)Db::name('user_message')
            ->where('user_id', 0)
            ->where('is_deleted', 0)
            ->where('message_type', 'global')
            ->max('id');

        try {
            Cache::store('redis')->set($cacheKey, $latestId, self::GLOBAL_LATEST_TEMPLATE_CACHE_SECONDS);
        } catch (\Throwable $e) {
        }

        return $latestId;
    }

    private static function buildGlobalSyncStateKey(int $userId): string
    {
        return 'umsg:global:last_template:' . $userId;
    }

    private static function buildGlobalSyncLockKey(int $userId): string
    {
        return 'umsg:global:sync:lock:' . $userId;
    }

    private static function acquireGlobalSyncLock(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $key = self::buildGlobalSyncLockKey($userId);
            return (bool)Cache::store('redis')->handler()->set($key, (string)time(), ['nx', 'ex' => self::GLOBAL_SYNC_LOCK_TTL_SECONDS]);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private static function releaseGlobalSyncLock(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            Cache::store('redis')->delete(self::buildGlobalSyncLockKey($userId));
        } catch (\Throwable $e) {
        }
    }

    private static function readGlobalSyncState(string $stateKey): int
    {
        try {
            return (int)Cache::store('redis')->get($stateKey, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function writeGlobalSyncState(string $stateKey, int $templateId): void
    {
        try {
            Cache::store('redis')->set($stateKey, $templateId, self::GLOBAL_SYNC_TTL_SECONDS);
        } catch (\Throwable $e) {
        }
    }

    private static function countMissingGlobalTemplatesForUser(int $userId, array $templateIds): int
    {
        $uid = (int)$userId;
        $ids = array_values(array_unique(array_filter(array_map('intval', $templateIds), static fn ($id) => $id > 0)));
        if ($uid <= 0 || empty($ids)) {
            return 0;
        }

        $existing = Db::name('user_message')
            ->where('user_id', $uid)
            ->where('message_type', 'global')
            ->where('biz_id', 'in', $ids)
            ->column('biz_id');

        $existingMap = [];
        foreach ($existing as $bizId) {
            $existingMap[(int)$bizId] = true;
        }

        $missing = 0;
        foreach ($ids as $id) {
            if (!isset($existingMap[$id])) {
                $missing++;
            }
        }

        return $missing;
    }

    public static function markAsRead(UserMessage $message): bool
    {
        if ((int)($message->is_read ?? 0) === 1) {
            return false;
        }

        $message->is_read = 1;
        $message->read_time = date('Y-m-d H:i:s');
        $message->save();

        return true;
    }

    public static function markAllAsRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int)UserMessage::where('user_id', $userId)
            ->where('is_deleted', 0)
            ->where('is_read', 0)
            ->update([
                'is_read' => 1,
                'read_time' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public static function buildSummary(?string $summary, string $content, int $length = 80): string
    {
        $summary = trim((string)$summary);
        if ($summary !== '') {
            return $summary;
        }

        $plain = html_entity_decode(strip_tags((string)$content), ENT_QUOTES, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain);
        $plain = trim((string)$plain);
        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain, 'UTF-8') <= $length) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, $length, 'UTF-8')) . '...';
    }

    public static function normalizeSourceType(?string $value): string
    {
        $value = trim((string)$value);
        return in_array($value, self::SOURCE_TYPES, true) ? $value : 'system';
    }

    public static function normalizeMessageType(?string $value): string
    {
        $value = trim((string)$value);
        return in_array($value, self::MESSAGE_TYPES, true) ? $value : 'other';
    }

    public static function normalizeActionType(?string $value): string
    {
        $value = trim((string)$value);
        return in_array($value, self::ACTION_TYPES, true) ? $value : 'none';
    }

    public static function normalizeActionValue(?string $actionType, ?string $value): ?string
    {
        $normalizedActionType = self::normalizeActionType($actionType);
        if ($normalizedActionType === 'none') {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '' || self::containsBlockedActionProtocol($value)) {
            return null;
        }

        if ($normalizedActionType === 'route') {
            return self::normalizeRouteActionValue($value);
        }

        return self::normalizeLinkActionValue($value);
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private static function containsBlockedActionProtocol(string $value): bool
    {
        $normalized = strtolower(ltrim($value));
        foreach (self::BLOCKED_ACTION_PROTOCOLS as $protocol) {
            if (str_starts_with($normalized, $protocol)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeRouteActionValue(string $value): ?string
    {
        if (!str_starts_with($value, '/')) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || !empty($parts['scheme']) || !empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '' || str_starts_with($path, '//')) {
            return null;
        }

        foreach (self::ACTION_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return self::buildRelativeUrl($parts);
            }
        }

        return null;
    }

    private static function normalizeLinkActionValue(string $value): ?string
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || !empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }

        if (!in_array($host, self::allowedActionLinkHosts(), true)) {
            return null;
        }

        return self::buildAbsoluteUrl($parts);
    }

    private static function allowedActionLinkHosts(): array
    {
        $hosts = self::ACTION_LINK_STATIC_HOSTS;
        foreach (['contact_service_url', 'chatwoot_base_url'] as $configKey) {
            $configValue = trim((string)getConfig($configKey));
            if ($configValue === '') {
                continue;
            }
            $scheme = strtolower((string)parse_url($configValue, PHP_URL_SCHEME));
            $host = strtolower((string)parse_url($configValue, PHP_URL_HOST));
            if ($scheme === 'https' && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function buildRelativeUrl(array $parts): string
    {
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $path . $query . $fragment;
    }

    private static function buildAbsoluteUrl(array $parts): string
    {
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    private static function normalizeBizId(int|string|null $bizId): ?int
    {
        if ($bizId === null || $bizId === '') {
            return null;
        }

        return (int)$bizId;
    }
}
