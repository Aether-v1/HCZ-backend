<?php
namespace app\service\telegram;

use app\service\TelegramService;
use app\model\User as UserModel;
use app\model\UserTelegramBindCode;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Config;
use think\db\exception\DbException;
use think\facade\Db;

class BindingManager
{
    private const BIND_CODE_STATUS_UNUSED = 0;
    private const BIND_CODE_STATUS_USED = 1;
    private const BIND_CODE_STATUS_INVALID = 2;

    /** @var TelegramService 主服务实例 */
    private $telegramService;
    
    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function generateBindCodeForUser(int $userId): array
    {
        Db::startTrans();
        try {
            $user = UserModel::where('id', $userId)->lock(true)->find();
            if (!$user) {
                Db::rollback();
                return ['success' => false, 'message' => '用户不存在'];
            }

            if ((int)($user['tg_is_bind'] ?? 0) === 1 && !empty($user['telegram_id'])) {
                Db::rollback();
                return ['success' => false, 'message' => '当前账号已绑定TG，如需更换请先解绑'];
            }

            $this->expireBindCodes($userId);

            UserTelegramBindCode::where('user_id', $userId)
                ->where('status', self::BIND_CODE_STATUS_UNUSED)
                ->update([
                    'status' => self::BIND_CODE_STATUS_INVALID,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            $bindCode = $this->createUniqueBindCode();
            $expireTime = date('Y-m-d H:i:s', time() + 600);

            UserTelegramBindCode::create([
                'user_id' => $userId,
                'bind_code' => $bindCode,
                'status' => self::BIND_CODE_STATUS_UNUSED,
                'expire_time' => $expireTime,
            ]);

            Db::commit();

            return [
                'success' => true,
                'message' => '绑定码生成成功',
                'data' => $this->buildBindingStatusPayload($user, $bindCode, $expireTime),
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('生成TG绑定码失败', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => '生成绑定码失败，请稍后重试'];
        }
    }

    public function getBindingStatusForUser(int $userId): array
    {
        $user = UserModel::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        $this->expireBindCodes($userId);

        $activeCode = null;
        if ((int)($user['tg_is_bind'] ?? 0) !== 1) {
            $activeCode = UserTelegramBindCode::where('user_id', $userId)
                ->where('status', self::BIND_CODE_STATUS_UNUSED)
                ->where('expire_time', '>', date('Y-m-d H:i:s'))
                ->order('id', 'desc')
                ->find();
        }

        return [
            'success' => true,
            'message' => '查询成功',
            'data' => $this->buildBindingStatusPayload(
                $user,
                $activeCode['bind_code'] ?? '',
                $activeCode['expire_time'] ?? ''
            ),
        ];
    }

    public function bindUserByCode(string $bindCode, int $tgUserId, int $tgChatId, string $tgUsername = ''): array
    {
        $normalizedCode = $this->normalizeBindCode($bindCode);
        if ($normalizedCode === '') {
            return ['success' => false, 'message' => '绑定码不能为空'];
        }

        Db::startTrans();
        try {
            $codeRecord = UserTelegramBindCode::where('bind_code', $normalizedCode)
                ->lock(true)
                ->find();

            if (!$codeRecord) {
                Db::rollback();
                return ['success' => false, 'message' => '绑定码不存在或已失效'];
            }

            $status = (int)($codeRecord['status'] ?? self::BIND_CODE_STATUS_INVALID);
            if ($status === self::BIND_CODE_STATUS_USED) {
                Db::rollback();
                return ['success' => false, 'message' => '绑定码已被使用'];
            }
            if ($status === self::BIND_CODE_STATUS_INVALID) {
                Db::rollback();
                return ['success' => false, 'message' => '绑定码已失效'];
            }

            $expireTimestamp = strtotime((string)($codeRecord['expire_time'] ?? ''));
            if ($expireTimestamp === false || $expireTimestamp <= time()) {
                $codeRecord->status = self::BIND_CODE_STATUS_INVALID;
                $codeRecord->update_time = date('Y-m-d H:i:s');
                $codeRecord->save();
                Db::commit();
                return ['success' => false, 'message' => '绑定码已过期'];
            }

            $user = UserModel::where('id', (int)$codeRecord['user_id'])->lock(true)->find();
            if (!$user) {
                $codeRecord->status = self::BIND_CODE_STATUS_INVALID;
                $codeRecord->update_time = date('Y-m-d H:i:s');
                $codeRecord->save();
                Db::commit();
                return ['success' => false, 'message' => '平台账号不存在'];
            }

            $tgBoundUser = UserModel::where('telegram_id', $tgUserId)
                ->where('tg_is_bind', 1)
                ->lock(true)
                ->find();

            if ($tgBoundUser && (int)$tgBoundUser['id'] !== (int)$user['id']) {
                Db::rollback();
                return ['success' => false, 'message' => '该TG已绑定其他平台账号，请先解绑'];
            }

            if ((int)($user['tg_is_bind'] ?? 0) === 1) {
                if ((int)($user['telegram_id'] ?? 0) === $tgUserId) {
                    $codeRecord->status = self::BIND_CODE_STATUS_INVALID;
                    $codeRecord->update_time = date('Y-m-d H:i:s');
                    $codeRecord->save();
                    Db::commit();
                    return ['success' => false, 'message' => '该TG已绑定当前平台账号，无需重复绑定'];
                }

                Db::rollback();
                return ['success' => false, 'message' => '该平台账号已绑定其他TG，请先解绑后再绑定'];
            }

            $user->telegram_id = $tgUserId;
            $user->telegram_username = $tgUsername;
            $user->tg_is_bind = 1;
            $user->update_time = date('Y-m-d H:i:s');
            if (!$user->save()) {
                throw new \RuntimeException('用户保存失败');
            }

            $codeRecord->status = self::BIND_CODE_STATUS_USED;
            $codeRecord->telegram_user_id = $tgUserId;
            $codeRecord->telegram_chat_id = $tgChatId;
            $codeRecord->used_time = date('Y-m-d H:i:s');
            $codeRecord->update_time = date('Y-m-d H:i:s');
            $codeRecord->save();

            UserTelegramBindCode::where('user_id', (int)$user['id'])
                ->where('status', self::BIND_CODE_STATUS_UNUSED)
                ->where('id', '<>', (int)$codeRecord['id'])
                ->update([
                    'status' => self::BIND_CODE_STATUS_INVALID,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            $this->syncLegacyBindRecord((int)$user['id'], $tgUserId, $tgUsername, 1);

            Cache::delete($this->telegramService->getCachePrefix() . "bind:{$tgUserId}");
            Cache::delete($this->telegramService->getCachePrefix() . "binding:{$tgUserId}");

            Db::commit();

            return ['success' => true, 'message' => '绑定成功，后续订单通知将发送到当前TG'];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('TG绑定码绑定失败', [
                'bind_code_hash' => $this->hashLogIdentifier($normalizedCode),
                'tg_user_id' => $this->hashLogIdentifier($tgUserId),
                'tg_chat_id' => $this->hashLogIdentifier($tgChatId),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => '绑定失败，请稍后重试'];
        }
    }

    public function unbindByUserId(int $userId): array
    {
        Db::startTrans();
        try {
            $user = UserModel::where('id', $userId)->lock(true)->find();
            if (!$user) {
                Db::rollback();
                return ['success' => false, 'message' => '用户不存在'];
            }

            if ((int)($user['tg_is_bind'] ?? 0) !== 1 || empty($user['telegram_id'])) {
                Db::rollback();
                return ['success' => false, 'message' => '当前账号未绑定TG'];
            }

            $currentTgUserId = (int)($user['telegram_id'] ?? 0);

            $user->telegram_id = null;
            $user->telegram_username = null;
            $user->tg_is_bind = 0;
            $user->update_time = date('Y-m-d H:i:s');
            $user->save();

            UserTelegramBindCode::where('user_id', $userId)
                ->where('status', self::BIND_CODE_STATUS_UNUSED)
                ->update([
                    'status' => self::BIND_CODE_STATUS_INVALID,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            Db::name('user_tg_bind')->where('user_id', $userId)->delete();

            Cache::delete($this->telegramService->getCachePrefix() . "bind:{$currentTgUserId}");
            Cache::delete($this->telegramService->getCachePrefix() . "binding:{$currentTgUserId}");

            Db::commit();

            return ['success' => true, 'message' => 'TG解绑成功'];
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('TG解绑失败', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => '解绑失败，请稍后重试'];
        }
    }
    
    /**
     * 获取用户绑定信息
     */
    public function getUserBindInfo($tgUserId)
    {
        $cacheKey = $this->telegramService->getCachePrefix() . "bind:{$tgUserId}";
        $bindInfo = Cache::get($cacheKey);
        
        if ($bindInfo === null) {
            try {
                $user = UserModel::where('telegram_id', $tgUserId)
                    ->where('tg_is_bind', 1)
                    ->find();
                    
                $bindInfo = $user ? [
                    'user_id' => $user->id,
                    'telegram_id' => $user->telegram_id,
                    'status' => 1
                ] : null;
            } catch (DbException $e) {
                Log::error('查询用户绑定信息失败', [
                    'error' => $e->getMessage(),
                    'tg_user_id' => $this->hashLogIdentifier($tgUserId)
                ]);
                return Cache::get($cacheKey);
            }
            
            Cache::set($cacheKey, $bindInfo, 600);
        }
        
        return $bindInfo;
    }
    
    /**
     * 检查用户绑定状态
     */
    public function checkUserBinding($chatId, $tgUserId)
    {
        $bindInfo = $this->getUserBindInfo($tgUserId);
        
        if (!$bindInfo) {
            $this->telegramService->sendBasicReply($chatId, "请先在平台 TG绑定 页面获取绑定码，再私聊机器人发送 /bind 绑定码 完成绑定。");
            return false;
        }
        
        return $bindInfo['user_id'];
    }

    private function buildBindingStatusPayload(UserModel $user, string $bindCode = '', string $expireTime = ''): array
    {
        $isBound = (int)($user['tg_is_bind'] ?? 0) === 1 && !empty($user['telegram_id']);
        $tgChatId = '';
        $botUsername = ltrim((string) Config::get('telegram.bot_username', ''), '@');
        $botUrl = trim((string) Config::get('telegram.bot_url', ''));

        if ($isBound) {
            $latestBindRecord = UserTelegramBindCode::where('user_id', (int)($user['id'] ?? 0))
                ->where('status', self::BIND_CODE_STATUS_USED)
                ->where('telegram_user_id', (int)($user['telegram_id'] ?? 0))
                ->order('id', 'desc')
                ->find();

            if ($latestBindRecord && !empty($latestBindRecord['telegram_chat_id'])) {
                $tgChatId = (string)$latestBindRecord['telegram_chat_id'];
            }
        }

        $remainingSeconds = 0;
        if ($bindCode !== '' && $expireTime !== '') {
            $remainingSeconds = max(0, strtotime($expireTime) - time());
        }

        if ($botUrl === '' && $botUsername !== '') {
            $botUrl = 'https://t.me/' . $botUsername;
        }

        $bindInstruction = $botUsername !== ''
            ? '复制绑定码后，打开 Telegram 机器人 @' . $botUsername . ' 私聊窗口发送 /bind 绑定码'
            : '复制绑定码后，在 Telegram 机器人私聊窗口发送 /bind 绑定码';

        $pendingInstruction = $botUsername !== ''
            ? '请先点击获取绑定码，再前往 Telegram 机器人 @' . $botUsername . ' 发送 /bind 绑定码'
            : '请先点击获取绑定码，再前往 Telegram 机器人发送 /bind 绑定码';

        return [
            'is_bound' => $isBound ? 1 : 0,
            'tg_user_id' => $isBound ? (string)($user['telegram_id'] ?? '') : '',
            'tg_chat_id' => $tgChatId,
            'tg_username' => $isBound ? (string)($user['telegram_username'] ?? '') : '',
            'bot_username' => $botUsername,
            'bot_url' => $botUrl,
            'bind_code' => $bindCode,
            'expire_time' => $expireTime,
            'remaining_seconds' => $remainingSeconds,
            'command_text' => $bindCode !== '' ? '/bind ' . $bindCode : '',
            'instruction' => $bindCode !== '' ? $bindInstruction : $pendingInstruction,
        ];
    }

    private function normalizeBindCode(string $bindCode): string
    {
        return strtoupper(trim($bindCode));
    }

    private function expireBindCodes(?int $userId = null): void
    {
        $query = UserTelegramBindCode::where('status', self::BIND_CODE_STATUS_UNUSED)
            ->where('expire_time', '<=', date('Y-m-d H:i:s'));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $query->update([
            'status' => self::BIND_CODE_STATUS_INVALID,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    private function createUniqueBindCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($index = 0; $index < 8; $index++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $exists = UserTelegramBindCode::where('bind_code', $code)->find();
            if (!$exists) {
                return $code;
            }
        }

        throw new \RuntimeException('绑定码生成失败');
    }

    private function syncLegacyBindRecord(int $userId, int $tgUserId, string $tgUsername, int $status): void
    {
        $table = Db::name('user_tg_bind');

        if ($status === 1) {
            $table->where('user_id', $userId)->delete();
            $table->where('tg_user_id', $tgUserId)->delete();
            $table->insert([
                'user_id' => $userId,
                'tg_user_id' => $tgUserId,
                'tg_username' => $tgUsername,
                'bind_time' => time(),
                'status' => 1,
            ]);
            return;
        }

        $table->where('user_id', $userId)->update([
            'status' => 0,
            'tg_username' => $tgUsername,
            'bind_time' => time(),
        ]);
    }

    private function hashLogIdentifier($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash('sha256', (string) $value), 0, 12);
    }
}
    
