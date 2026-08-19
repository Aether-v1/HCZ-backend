<?php
declare (strict_types=1);

namespace app\controller\indexapi;

use app\model\CheckinRecord;
use app\model\Order;
use app\model\PointsRecord;
use app\model\User as UserModel;
use app\service\PointsService;
use think\facade\Db;
use think\facade\Log;

trait PointsActions
{
    protected function handlePointsInfo()
    {
        try {
            if (empty($this->user_info['id'])) {
                return show(401, 'error', '请先登录');
            }

            $user = UserModel::where('id', $this->user_info['id'])
                ->field('id, points_balance, month_earned, month_used, total_earned')
                ->find();

            if (!$user) {
                return show(404, 'error', '用户信息不存在');
            }

            return show(200, 'success', '获取积分信息成功', [
                'points_balance' => $user['points_balance'] ?? 0,
                'month_earned' => $user['month_earned'] ?? 0,
                'month_used' => $user['month_used'] ?? 0,
                'total_earned' => $user['total_earned'] ?? 0,
                'checkin_info' => $this->getUserCheckinInfo((int)$this->user_info['id']),
            ]);
        } catch (\Throwable $e) {
            Log::error('获取积分信息失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return show(500, 'error', '获取积分信息失败');
        }
    }

    protected function handlePointsCheckin()
    {
        return $this->claimPointsTask('daily_checkin');
    }

    protected function handlePointsTasks()
    {
        if (empty($this->user_info['id'])) {
            return show(401, 'error', '请先登录');
        }

        try {
            $userId = (int)$this->user_info['id'];
            $today = date('Y-m-d');
            $tasks = $this->buildPointsTasks($userId, $today);

            return show(200, 'success', '获取任务中心成功', [
                'daily' => array_values(array_filter($tasks, static fn ($task) => $task['group'] === 'daily')),
                'newbie' => array_values(array_filter($tasks, static fn ($task) => $task['group'] === 'newbie')),
                'server_date' => $today,
            ]);
        } catch (\Throwable $e) {
            Log::error('获取积分任务失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $this->user_info['id'] ?? 0,
            ]);
            return show(500, 'error', '获取任务中心失败');
        }
    }

    protected function handlePointsTaskClaim()
    {
        $taskKey = trim((string)$this->request->post('task_key', ''));
        return $this->claimPointsTask($taskKey);
    }

    protected function handlePointsExchangeItems()
    {
        if (empty($this->user_info['id'])) {
            return show(401, 'error', '请先登录');
        }

        $rawItems = (string)(getConfig('points_exchange_items') ?: '[]');
        $decodedItems = json_decode($rawItems, true);
        if (!is_array($decodedItems)) {
            $decodedItems = [];
        }

        $items = [];
        foreach ($decodedItems as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = strtolower(trim((string)($item['type'] ?? 'coupon')));
            if (!in_array($type, ['coupon', 'physical'], true)) {
                $type = 'coupon';
            }

            $enabled = !empty($item['enabled']) ? 1 : 0;
            if ($enabled !== 1) {
                continue;
            }

            $items[] = [
                'id' => trim((string)($item['id'] ?? ('item_' . ((int)$index + 1))), ' '),
                'type' => $type,
                'title' => trim((string)($item['title'] ?? ($type === 'physical' ? '实物商品' : '优惠券'))),
                'points' => max(1, (int)($item['points'] ?? 1)),
                'stock' => max(0, (int)($item['stock'] ?? 0)),
                'coupon_amount' => max(0, (int)($item['coupon_amount'] ?? 0)),
                'sku' => trim((string)($item['sku'] ?? '')),
                'description' => trim((string)($item['description'] ?? '')),
                'image' => trim((string)($item['image'] ?? '')),
            ];
        }

        // 批量查询各商品已用库存，返回给前端做乐观渲染
        $usedCounts = [];
        if (!empty($items)) {
            $itemIds = array_column($items, 'id');
            $rawCounts = Db::name('points_exchange_order')
                ->whereIn('item_id', $itemIds)
                ->where('status', '<>', 2)
                ->group('item_id')
                ->column('COUNT(*)', 'item_id');
            foreach ($itemIds as $id) {
                $usedCounts[$id] = (int)($rawCounts[$id] ?? 0);
            }
        }

        return show(200, 'success', '获取兑换配置成功', [
            'items'       => $items,
            'notice'      => (string)(getConfig('points_exchange_notice') ?: '兑换申请提交后，客服会尽快处理。'),
            'used_counts' => $usedCounts,
        ]);
    }

    protected function handlePointsRecords()
    {
        if (empty($this->user_info['id'])) {
            return show(401, 'error', '请先登录');
        }

        $type = $this->request->get('type', 'earned');
        $page = max(1, intval($this->request->get('page', 1)));
        $pageSize = max(1, min(100, intval($this->request->get('pageSize', 10))));

        if (!in_array($type, ['earned', 'used'], true)) {
            return show(400, 'error', '无效的记录类型');
        }

        try {
            $query = PointsRecord::where('uid', $this->user_info['id'])
                ->where('type', $type)
                ->order('create_time', 'desc');

            $total = $query->count();
            $records = $query->page($page, $pageSize)->select()->toArray();

            $formattedRecords = array_map(static function ($item) {
                return [
                    'id' => $item['id'] ?? 0,
                    'points' => $item['points'] ?? 0,
                    'reason' => $item['reason'] ?? '',
                    'time' => !empty($item['create_time']) ? date('Y-m-d H:i', strtotime($item['create_time'])) : '',
                    'create_time' => $item['create_time'] ?? '',
                ];
            }, $records);

            return show(200, 'success', '获取积分记录成功', [
                'records' => $formattedRecords,
                'total' => $total,
                'totalPages' => ceil($total / $pageSize),
                'currentPage' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            Log::error('获取积分记录失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $this->user_info['id'] ?? 0,
            ]);
            return show(500, 'error', '获取积分记录失败');
        }
    }

    protected function claimPointsTask(string $taskKey)
    {
        if (empty($this->user_info['id'])) {
            return show(401, 'error', '请先登录');
        }

        $userId = (int)$this->user_info['id'];
        $today = date('Y-m-d');
        $definitions = $this->getPointsTaskDefinitions();
        $claimKey = '';
        $checkinInserted = false;
        $pointsAwarded = false;

        if (!isset($definitions[$taskKey])) {
            return show(400, 'error', '任务不存在');
        }

        try {
            $this->ensurePointsTaskClaimTable();
            $task = $this->buildPointsTask($definitions[$taskKey], $userId, $today);
            if (empty($task['enabled'])) {
                return show(400, 'error', '任务已停用');
            }

            if (!empty($task['claimed'])) {
                return show(400, 'error', '该任务已领取');
            }

            if (empty($task['claimable'])) {
                return show(400, 'error', (string)($task['locked_text'] ?? '任务条件未完成'));
            }

            $claimKey = $this->makePointsTaskClaimKey($task, $today);
            $now = date('Y-m-d H:i:s');

            try {
                Db::name('points_task_claim')->insert([
                    'uid' => $userId,
                    'task_key' => $task['key'],
                    'claim_key' => $claimKey,
                    'task_type' => $task['type'],
                    'task_date' => $task['type'] === 'daily' ? $today : null,
                    'points' => (int)$task['points'],
                    'status' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                if ($this->isDuplicateClaimException($e)) {
                    return show(400, 'error', '该任务已领取');
                }
                throw $e;
            }

            if ($task['key'] === 'daily_checkin') {
                CheckinRecord::create([
                    'uid' => $userId,
                    'checkin_date' => $today,
                    'points' => (int)$task['points'],
                    'create_time' => $now,
                ]);
                $checkinInserted = true;
            }

            $result = (new PointsService())->addPoints($userId, (int)$task['points'], $task['title']);
            if (empty($result['code'])) {
                Db::name('points_task_claim')->where('uid', $userId)->where('claim_key', $claimKey)->delete();
                if ($checkinInserted) {
                    CheckinRecord::where('uid', $userId)->where('checkin_date', $today)->delete();
                }
                return show(500, 'error', (string)($result['msg'] ?? '积分发放失败'));
            }
            $pointsAwarded = true;

            Db::name('points_task_claim')
                ->where('uid', $userId)
                ->where('claim_key', $claimKey)
                ->update([
                    'status' => 1,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            return show(200, 'success', '领取成功，+' . (int)$task['points'] . ' 积分', [
                'task_key' => $task['key'],
                'points' => (int)$task['points'],
                'new_balance' => $result['new_balance'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('领取积分任务失败: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $userId,
                'task_key' => $taskKey,
            ]);
            return show(500, 'error', '领取失败，请稍后重试');
        }
    }

    protected function getPointsTaskDefinitions(): array
    {
        $enabled = $this->getPointsTaskEnabledSettings();
        return [
            'daily_checkin' => [
                'key' => 'daily_checkin',
                'group' => 'daily',
                'type' => 'daily',
                'title' => '每日签到',
                'description' => '每天登录后可领取一次签到奖励',
                'points' => $this->getConfiguredPointsReward('points_daily_checkin', 1),
                'enabled' => $enabled['daily_checkin'],
                'limit_text' => '每日一次',
            ],
            'daily_order_completed' => [
                'key' => 'daily_order_completed',
                'group' => 'daily',
                'type' => 'daily',
                'title' => '每日完成订单',
                'description' => '当天有一笔订单完成后可领取',
                'points' => $this->getConfiguredPointsReward('points_daily_order_completed', 2),
                'enabled' => $enabled['daily_order_completed'],
                'limit_text' => '每日一次，订单完成后可领取',
            ],
            'bind_2fa' => [
                'key' => 'bind_2fa',
                'group' => 'newbie',
                'type' => 'once',
                'title' => '绑定 2FA',
                'description' => '开启二步验证，提高账户安全等级',
                'points' => $this->getConfiguredPointsReward('points_bind_2fa', 3),
                'enabled' => $enabled['bind_2fa'],
                'limit_text' => '账号仅一次',
            ],
            'bind_telegram' => [
                'key' => 'bind_telegram',
                'group' => 'newbie',
                'type' => 'once',
                'title' => '绑定 Telegram',
                'description' => '绑定 Telegram 后可接收订单与安全提醒',
                'points' => $this->getConfiguredPointsReward('points_bind_telegram', 3),
                'enabled' => $enabled['bind_telegram'],
                'limit_text' => '账号仅一次',
            ],
        ];
    }

    protected function getConfiguredPointsReward(string $key, int $fallback): int
    {
        $value = (int)(getConfig($key) ?: $fallback);
        return max(1, $value);
    }

    protected function getPointsTaskEnabledSettings(): array
    {
        $defaults = [
            'daily_checkin' => 1,
            'daily_order_completed' => 1,
            'bind_2fa' => 1,
            'bind_telegram' => 1,
        ];
        $raw = (string)(getConfig('points_task_settings') ?: '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_map(static fn ($value) => empty($value) ? 0 : 1, array_merge($defaults, $decoded));
    }

    protected function buildPointsTasks(int $userId, string $today): array
    {
        $this->ensurePointsTaskClaimTable();
        return array_map(function ($definition) use ($userId, $today) {
            return $this->buildPointsTask($definition, $userId, $today);
        }, $this->getPointsTaskDefinitions());
    }

    protected function buildPointsTask(array $definition, int $userId, string $today): array
    {
        $task = $definition;
        $task['enabled'] = empty($task['enabled']) ? 0 : 1;
        if (empty($task['enabled'])) {
            $task['claimed'] = 0;
            $task['claimable'] = 0;
            $task['locked_text'] = '任务已停用';
            $task['progress_text'] = '后台已关闭该任务';
            return $task;
        }

        $claimKey = $this->makePointsTaskClaimKey($task, $today);
        $claimed = Db::name('points_task_claim')
            ->where('uid', $userId)
            ->where('claim_key', $claimKey)
            ->count() > 0;

        if ($task['key'] === 'daily_checkin') {
            $claimed = $claimed || CheckinRecord::where('uid', $userId)->where('checkin_date', $today)->count() > 0;
        }

        [$claimable, $lockedText, $progressText] = $this->getPointsTaskEligibility($task['key'], $userId, $today);

        $task['claimed'] = $claimed ? 1 : 0;
        $task['claimable'] = (!$claimed && $claimable) ? 1 : 0;
        $task['locked_text'] = $claimed ? '已领取' : $lockedText;
        $task['progress_text'] = $progressText;

        return $task;
    }

    protected function getPointsTaskEligibility(string $taskKey, int $userId, string $today): array
    {
        if ($taskKey === 'daily_checkin') {
            return [true, '今日可签到', '登录即可领取'];
        }

        if ($taskKey === 'daily_order_completed') {
            $start = $today . ' 00:00:00';
            $end = $today . ' 23:59:59';
            $count = Order::userVisibleQuery($userId)
                ->where('status', 2)
                ->whereTime('complete_time', 'between', [$start, $end])
                ->count();

            return [
                $count > 0,
                '今日完成订单后可领取',
                $count > 0 ? '今日已完成 ' . $count . ' 单' : '今日暂无完成订单',
            ];
        }

        $user = UserModel::where('id', $userId)
            ->field('id, twofa_enabled, tg_is_bind, telegram_id')
            ->find();

        if ($taskKey === 'bind_2fa') {
            $enabled = !empty($user['twofa_enabled']);
            return [$enabled, '绑定 2FA 后可领取', $enabled ? '已绑定 2FA' : '未绑定 2FA'];
        }

        if ($taskKey === 'bind_telegram') {
            $bound = !empty($user['tg_is_bind']) && !empty($user['telegram_id']);
            return [$bound, '绑定 Telegram 后可领取', $bound ? '已绑定 Telegram' : '未绑定 Telegram'];
        }

        return [false, '任务条件未完成', ''];
    }

    protected function makePointsTaskClaimKey(array $task, string $today): string
    {
        if (($task['type'] ?? '') === 'daily') {
            return 'daily:' . $task['key'] . ':' . $today;
        }

        return 'once:' . $task['key'];
    }

    protected function ensurePointsTaskClaimTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $table = Db::name('points_task_claim')->getTable();
        Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `task_key` varchar(64) NOT NULL DEFAULT '',
            `claim_key` varchar(128) NOT NULL DEFAULT '',
            `task_type` varchar(16) NOT NULL DEFAULT '',
            `task_date` date DEFAULT NULL,
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_uid_claim_key` (`uid`,`claim_key`),
            KEY `idx_uid_task` (`uid`,`task_key`),
            KEY `idx_task_date` (`task_key`,`task_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ensured = true;
    }

    protected function isDuplicateClaimException(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'SQLSTATE[23000]');
    }

    protected function handlePointsExchangeSubmit()
    {
        if (empty($this->user_info['id'])) {
            return show(401, 'error', '请先登录');
        }

        $userId  = (int)$this->user_info['id'];
        $itemId  = trim((string)$this->request->post('item_id', ''));

        if ($itemId === '') {
            return show(400, 'error', '参数错误');
        }

        // 加载兑换项配置
        $rawItems   = (string)(getConfig('points_exchange_items') ?: '[]');
        $allItems   = json_decode($rawItems, true);
        if (!is_array($allItems)) {
            $allItems = [];
        }

        $item = null;
        foreach ($allItems as $i) {
            if (is_array($i) && trim((string)($i['id'] ?? '')) === $itemId) {
                $item = $i;
                break;
            }
        }

        if (!$item || empty($item['enabled'])) {
            return show(400, 'error', '兑换项不存在或已下架');
        }

        $type           = strtolower(trim((string)($item['type'] ?? 'coupon')));
        if (!in_array($type, ['coupon', 'physical'], true)) {
            $type = 'coupon';
        }
        $title          = trim((string)($item['title'] ?? '兑换'));
        $requiredPoints = max(1, (int)($item['points'] ?? 1));
        $configStock    = max(0, (int)($item['stock'] ?? 0));

        try {
            $this->ensurePointsExchangeOrderTable();

            // 库存校验（计算已用库存，0 = 不限）
            if ($configStock > 0) {
                $usedCount = Db::name('points_exchange_order')
                    ->where('item_id', $itemId)
                    ->where('status', '<>', 2)
                    ->count();
                if ($usedCount >= $configStock) {
                    return show(400, 'error', '库存不足，兑换已结束');
                }
            }

            // 检查该用户是否有相同商品的待处理申请
            $pendingCount = Db::name('points_exchange_order')
                ->where('uid', $userId)
                ->where('item_id', $itemId)
                ->where('status', 0)
                ->count();
            if ($pendingCount > 0) {
                return show(400, 'error', '您已提交过该商品的兑换申请，请等待处理');
            }

            $now = date('Y-m-d H:i:s');

            Db::startTrans();
            try {
                // 事务内加锁读取用户积分（FOR UPDATE 必须在活跃事务中才能持锁）
                $user = UserModel::lock(true)->where('id', $userId)->find();
                if (!$user) {
                    Db::rollback();
                    return show(404, 'error', '用户不存在');
                }

                if ((int)$user['points_balance'] < $requiredPoints) {
                    Db::rollback();
                    return show(400, 'error', '积分不足，当前积分 ' . (int)$user['points_balance']);
                }

                // 扣减积分
                $newBalance = (int)$user['points_balance'] - $requiredPoints;
                $updated = UserModel::where('id', $userId)
                    ->where('points_balance', (int)$user['points_balance'])
                    ->update([
                        'points_balance' => $newBalance,
                        'month_used'     => Db::raw('month_used + ' . $requiredPoints),
                        'update_time'    => $now,
                    ]);
                if (!$updated) {
                    throw new \RuntimeException('积分更新冲突，请重试');
                }

                // 写积分变动记录
                Db::name('points_record')->insert([
                    'uid'         => $userId,
                    'points'      => $requiredPoints,
                    'reason'      => '兑换：' . $title,
                    'type'        => 'used',
                    'create_time' => $now,
                ]);

                // 写兑换订单
                $orderId = Db::name('points_exchange_order')->insertGetId([
                    'uid'        => $userId,
                    'item_id'    => $itemId,
                    'item_type'  => $type,
                    'item_title' => $title,
                    'points'     => $requiredPoints,
                    'status'     => 0,
                    'remark'     => '',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                Db::commit();

                return show(200, 'success', '兑换申请已提交，客服会尽快处理', [
                    'order_id'    => $orderId,
                    'points_cost' => $requiredPoints,
                    'new_balance' => $newBalance,
                ]);
            } catch (\Throwable $inner) {
                Db::rollback();
                throw $inner;
            }
        } catch (\Throwable $e) {
            Log::error('积分兑换提交失败: ' . $e->getMessage(), [
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $userId,
                'item_id' => $itemId,
            ]);
            return show(500, 'error', $e->getMessage() ?: '兑换失败，请稍后重试');
        }
    }

    protected function ensurePointsExchangeOrderTable(): void
    {
        static $ensuredExchange = false;
        if ($ensuredExchange) {
            return;
        }

        $table = Db::name('points_exchange_order')->getTable();
        Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `uid` int(11) unsigned NOT NULL DEFAULT '0',
            `item_id` varchar(64) NOT NULL DEFAULT '',
            `item_type` varchar(16) NOT NULL DEFAULT 'coupon',
            `item_title` varchar(128) NOT NULL DEFAULT '',
            `points` int(11) NOT NULL DEFAULT '0',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已发放 2=已拒绝',
            `remark` varchar(256) NOT NULL DEFAULT '',
            `create_time` datetime DEFAULT NULL,
            `update_time` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_uid` (`uid`),
            KEY `idx_item_status` (`item_id`,`status`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ensuredExchange = true;
    }

    protected function getUserCheckinInfo(int $userId): array
    {
        $today = date('Y-m-d');
        $startOfMonth = date('Y-m-01');

        // 一次查询获取该用户全部签到日期（按日期降序）。
        // checkin_record 表有 UNIQUE KEY uid_date，保证一天最多一条记录。
        // 原实现对连续签到逐日查库（while(true)），连续 N 天产生 N+ 次查询；
        // 此处改为单用户全量签到日期一次读取，后续在 PHP 内存中计算。
        $records = CheckinRecord::where('uid', $userId)
            ->order('checkin_date', 'desc')
            ->column('checkin_date');

        $checkinSet = array_flip($records);

        // 今天是否签到（与原 count() > 0 语义一致）
        $isCheckedIn = isset($checkinSet[$today]);

        // 连续签到天数：从今天向前追溯，遇断档即停止。
        // 保持原 while(true) 的无限跨月追溯语义，不设天数上限。
        $continuousDays = 0;
        $currentDate = $today;
        while (isset($checkinSet[$currentDate])) {
            $continuousDays++;
            $currentDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
        }

        // 本月签到次数（与原 checkin_date >= startOfMonth 语义一致）
        $monthCheckinCount = 0;
        foreach ($records as $date) {
            if ($date >= $startOfMonth) {
                $monthCheckinCount++;
            }
        }

        return [
            'is_checked_in' => $isCheckedIn,
            'continuous_days' => $continuousDays,
            'month_checkin_count' => $monthCheckinCount,
        ];
    }
}
