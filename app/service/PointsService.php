<?php
namespace app\service;

use app\model\CheckinRecord; // 对应checkin_record表
use app\model\PointsRecord; // 对应points_record表
use app\model\User; // 对应cz_user表
use think\db\exception\DbException;
use think\facade\Db;
use think\facade\Log;

class PointsService
{
    // 签到相关常量
    const SIGN_IN_REASON = '每日签到'; // 积分变动原因
    const SIGN_IN_TYPE = 'earned'; // 积分变动类型（获得）

    /**
     * 用户签到
     * @param int $userId 用户ID（对应cz_user表的id）
     * @return array 签到结果
     */
    public function signIn($userId)
    {
        // 验证用户ID格式
        if (!is_numeric($userId) || (int)$userId != $userId) {
            Log::error("签到失败：无效的用户ID", ['user_id' => $userId]);
            return ['code' => 0, 'msg' => '无效的用户信息'];
        }
        $userId = (int)$userId;

        // 获取当前日期（用于签到日期判断）
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        Log::info("用户签到开始", [
            'user_id' => $userId,
            'today' => $today,
            'yesterday' => $yesterday
        ]);

        try {
            if (!$this->isPointsTaskEnabled('daily_checkin')) {
                return ['code' => 0, 'msg' => '签到任务已停用'];
            }

            // 1. 验证用户是否存在
            $user = User::where('id', $userId)->find();
            if (!$user) {
                Log::error("签到失败：用户不存在", ['user_id' => $userId]);
                return ['code' => 0, 'msg' => '用户不存在'];
            }

            // 2. 检查今日是否已签到（查询checkin_record表）
            $todayCheckin = CheckinRecord::where('uid', $userId)
                ->where('checkin_date', $today)
                ->find();

            if ($todayCheckin) {
                Log::info("用户今日已签到", [
                    'user_id' => $userId,
                    'checkin_id' => $todayCheckin->id,
                    'checkin_date' => $today
                ]);
                return ['code' => 0, 'msg' => '今天已经签过到了哦～'];
            }

            // 3. 计算连续签到天数（查询昨天是否有签到记录）
            $yesterdayCheckin = CheckinRecord::where('uid', $userId)
                ->where('checkin_date', $yesterday)
                ->find();

            $continuousDays = 1;
            if ($yesterdayCheckin) {
                // 从最近一次签到记录中获取连续天数（或重新计算）
                // 这里假设连续天数需要累计，可根据实际逻辑调整
                $continuousDays = $this->calculateContinuousDays($userId) + 1;
                Log::info("检测到昨日签到，更新连续天数", [
                    'user_id' => $userId,
                    'previous_days' => $continuousDays - 1,
                    'new_days' => $continuousDays
                ]);
            } else {
                Log::info("无昨日签到记录，连续天数重置为1", ['user_id' => $userId]);
            }

            // 4. 计算签到获得的积分
            // 与积分中心每日签到任务保持一致：每日固定 +1 积分。
            $points = max(1, (int)(getConfig('points_daily_checkin') ?: 1));
            Log::info("签到积分计算完成", [
                'user_id' => $userId,
                'continuous_days' => $continuousDays,
                'points' => $points
            ]);

            // 5. 开启事务，执行签到逻辑
            Db::startTrans();
            try {
                // 5.1 新增签到记录（checkin_record表）
                $checkinRecord = new CheckinRecord();
                $checkinRecord->uid = $userId;
                $checkinRecord->checkin_date = $today;
                $checkinRecord->points = $points;
                $checkinRecord->create_time = date('Y-m-d H:i:s'); // 当前时间

                if (!$checkinRecord->save()) {
                    throw new DbException('签到记录保存失败');
                }

                // 5.2 新增积分变动记录（points_record表）
                $pointsRecord = new PointsRecord();
                $pointsRecord->uid = $userId;
                $pointsRecord->points = $points;
                $pointsRecord->reason = self::SIGN_IN_REASON;
                $pointsRecord->type = self::SIGN_IN_TYPE;
                $pointsRecord->create_time = date('Y-m-d H:i:s');

                if (!$pointsRecord->save()) {
                    throw new DbException('积分变动记录保存失败');
                }

                // 5.3 更新用户表积分信息（cz_user表）
                $user = User::lock(true)->where('id', $userId)->find(); // 加锁防止并发问题
                $user->points_balance += $points; // 当前积分余额增加
                $user->month_earned += $points; // 本月获得积分增加
                $user->total_earned += $points; // 累计获得积分增加
                // 若需要更新本月获得积分，可在此处处理：
                // if (date('Y-m') == date('Y-m', strtotime($user->create_time))) {
                //     $user->month_earned += $points;
                // }

                if (!$user->save()) {
                    throw new DbException('用户积分信息更新失败');
                }

                // 提交事务
                Db::commit();
                Log::info("签到成功，事务已提交", [
                    'user_id' => $userId,
                    'checkin_id' => $checkinRecord->id,
                    'points' => $points,
                    'continuous_days' => $continuousDays,
                    'new_balance' => $user->points_balance
                ]);

                return [
                    'code' => 1,
                    'msg' => '签到成功',
                    'points' => $points,
                    'continuous_days' => $continuousDays,
                    'total_points' => $user->points_balance
                ];

            } catch (DbException $e) {
                // 回滚事务
                Db::rollback();
                Log::error("签到事务回滚", [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                return ['code' => 0, 'msg' => '签到失败，请稍后重试'];
            }

        } catch (DbException $e) {
            Log::error("签到数据库操作异常", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['code' => 0, 'msg' => '系统错误，获取用户信息失败'];
        } catch (\Exception $e) {
            Log::error("签到过程异常", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['code' => 0, 'msg' => '系统异常，请联系客服'];
        }
    }
/**
 * 扣除用户积分
 * @param int $userId 用户ID
 * @param int $points 要扣除的积分数量（必须为正数）
 * @param string $reason 扣除原因
 * @return array 操作结果
 */
public function deductPoints($userId, $points, $reason = '积分扣除')
{
    // 验证参数
    if (!is_numeric($userId) || (int)$userId != $userId) {
        Log::error("积分扣除失败：无效的用户ID", ['user_id' => $userId]);
        return ['code' => 0, 'msg' => '无效的用户信息'];
    }
    $userId = (int)$userId;
    $points = (int)$points;
    
    if ($points <= 0) {
        Log::error("积分扣除失败：扣除数量必须为正数", ['user_id' => $userId, 'points' => $points]);
        return ['code' => 0, 'msg' => '扣除积分数量必须为正数'];
    }

    try {
        // 验证用户是否存在并获取当前积分
        $user = User::lock(true)->where('id', $userId)->find(); // 加锁防止并发问题
        if (!$user) {
            Log::error("积分扣除失败：用户不存在", ['user_id' => $userId]);
            return ['code' => 0, 'msg' => '用户不存在'];
        }

        // 验证积分是否充足
        if ($user->points_balance < $points) {
            Log::error("积分扣除失败：积分不足", [
                'user_id' => $userId,
                'required' => $points,
                'balance' => $user->points_balance
            ]);
            return ['code' => 0, 'msg' => '积分余额不足'];
        }

        // 开启事务
        Db::startTrans();
        try {
            // 1. 更新用户积分余额
            $user->points_balance -= $points;
            $user->month_used += $points;
            // 若有累计消费字段，可在此处更新（如$user->total_spent += $points;）
            if (!$user->save()) {
                throw new DbException('用户积分余额更新失败');
            }

            // 2. 记录积分变动（类型为"消耗"）
            $pointsRecord = new PointsRecord();
            $pointsRecord->uid = $userId;
            $pointsRecord->points = -$points; // 负数表示扣除
            $pointsRecord->reason = $reason;
            $pointsRecord->type = 'used'; // 消耗类型（与签到的earned对应）
            $pointsRecord->create_time = date('Y-m-d H:i:s');

            if (!$pointsRecord->save()) {
                throw new DbException('积分扣除记录保存失败');
            }

            // 提交事务
            Db::commit();
            Log::info("积分扣除成功", [
                'user_id' => $userId,
                'deducted' => $points,
                'remaining' => $user->points_balance
            ]);

            return [
                'code' => 1,
                'msg' => '积分扣除成功',
                'remaining_points' => $user->points_balance
            ];

        } catch (DbException $e) {
            // 回滚事务
            Db::rollback();
            Log::error("积分扣除事务回滚", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['code' => 0, 'msg' => '积分扣除失败，请稍后重试'];
        }

    } catch (DbException $e) {
        Log::error("积分扣除数据库操作异常", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return ['code' => 0, 'msg' => '系统错误，操作失败'];
    }
}
/**
 * 增加用户积分（用于返还等场景）
 * @param int $userId 用户ID
 * @param int $points 要增加的积分数量（必须为正数）
 * @param string $reason 增加原因
 * @return array 操作结果
 */
public function addPoints($userId, $points, $reason = '积分增加')
{
    // 验证参数
    if (!is_numeric($userId) || (int)$userId != $userId) {
        Log::error("积分增加失败：无效的用户ID", ['user_id' => $userId]);
        return ['code' => 0, 'msg' => '无效的用户信息'];
    }
    $userId = (int)$userId;
    $points = (int)$points;
    
    if ($points <= 0) {
        Log::error("积分增加失败：增加数量必须为正数", ['user_id' => $userId, 'points' => $points]);
        return ['code' => 0, 'msg' => '增加积分数量必须为正数'];
    }

    try {
        // 验证用户是否存在
        $user = User::lock(true)->where('id', $userId)->find(); // 加锁防止并发问题
        if (!$user) {
            Log::error("积分增加失败：用户不存在", ['user_id' => $userId]);
            return ['code' => 0, 'msg' => '用户不存在'];
        }

        // 开启事务
        Db::startTrans();
        try {
            // 1. 更新用户积分余额
            $user->points_balance += $points;
            $user->month_earned += $points;
            $user->total_earned += $points; // 累计获得积分增加
            if (!$user->save()) {
                throw new DbException('用户积分余额更新失败');
            }

            // 2. 记录积分变动（类型为"获得"）
            $pointsRecord = new PointsRecord();
            $pointsRecord->uid = $userId;
            $pointsRecord->points = $points;
            $pointsRecord->reason = $reason;
            $pointsRecord->type = self::SIGN_IN_TYPE; // 使用签到的"获得"类型
            $pointsRecord->create_time = date('Y-m-d H:i:s');

            if (!$pointsRecord->save()) {
                throw new DbException('积分增加记录保存失败');
            }

            // 提交事务
            Db::commit();
            Log::info("积分增加成功", [
                'user_id' => $userId,
                'added' => $points,
                'new_balance' => $user->points_balance
            ]);

            return [
                'code' => 1,
                'msg' => '积分增加成功',
                'new_balance' => $user->points_balance
            ];

        } catch (DbException $e) {
            // 回滚事务
            Db::rollback();
            Log::error("积分增加事务回滚", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['code' => 0, 'msg' => '积分增加失败，请稍后重试'];
        }

    } catch (DbException $e) {
        Log::error("积分增加数据库操作异常", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return ['code' => 0, 'msg' => '系统错误，操作失败'];
    }
}

/**
 * 调整用户积分
 * @param int $userId 用户ID
 * @param int $delta 调整值，正数增加，负数扣除，0 不处理
 * @param string $reason 积分变动原因
 * @param array $meta 兼容扩展参数
 * @return array 操作结果
 */
public function adjustPoints($userId, $delta, $reason = '', $meta = [])
{
    $delta = (int)$delta;

    if ($delta === 0) {
        return ['code' => 1, 'msg' => '积分无需调整'];
    }

    if ($reason === '') {
        $reason = $delta > 0 ? '积分增加' : '积分扣除';
    }

    if (!empty($meta)) {
        Log::info('adjustPoints调用', [
            'user_id' => (int)$userId,
            'delta' => $delta,
            'meta_keys' => array_keys((array)$meta)
        ]);
    }

    if ($delta > 0) {
        return $this->addPoints($userId, $delta, $reason);
    }

    return $this->deductPoints($userId, abs($delta), $reason);
}
/**
 * 获取用户积分余额
 * @param int $userId 用户ID
 * @return int 用户当前积分余额
 */
public function getUserPointsBalance($userId)
{
    try {
        $userId = (int)$userId;
        $user = User::where('id', $userId)->find();
        
        if (!$user) {
            Log::error("获取用户积分失败：用户不存在", ['user_id' => $userId]);
            return 0;
        }
        
        return $user->points_balance ?? 0;
    } catch (DbException $e) {
        Log::error("获取用户积分失败", [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return 0;
    }
}
    /**
     * 计算连续签到天数
     * @param int $userId 用户ID
     * @return int 连续签到天数
     */
    private function calculateContinuousDays($userId)
    {
        try {
            // 查询用户最近的签到记录（按日期倒序）
            $records = CheckinRecord::where('uid', $userId)
                ->order('checkin_date', 'desc')
                ->select();

            if (empty($records)) {
                return 0;
            }

            $days = 1;
            $lastDate = strtotime($records[0]['checkin_date']);

            // 从第二条记录开始检查是否连续
            for ($i = 1; $i < count($records); $i++) {
                $currentDate = strtotime($records[$i]['checkin_date']);
                // 检查是否为前一天
                if ($lastDate - $currentDate == 86400) { // 86400秒 = 1天
                    $days++;
                    $lastDate = $currentDate;
                } else {
                    break; // 不连续则停止计数
                }
            }

            return $days;
        } catch (DbException $e) {
            Log::error("计算连续签到天数失败", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * 根据连续签到天数计算积分
     * @param int $days 连续签到天数
     * @return int 积分数量
     */
    private function calculatePoints($days)
    {
        $days = (int)$days;
        // 积分规则：连续天数越多，获得积分越多
        if ($days >= 7) {
            return 2; // 连续7天及以上，获得2积分
        } elseif ($days >= 3) {
            return 1; // 连续3-6天，获得1积分
        }
        return 1; // 连续1-2天，获得1积分
    }

    /**
     * 检查用户今日是否已签到
     * @param int $userId 用户ID
     * @return bool 是否已签到
     */
    public function hasSignedInToday($userId)
    {
        try {
            $count = CheckinRecord::where('uid', $userId)
                ->where('checkin_date', date('Y-m-d'))
                ->count();
            return $count > 0;
        } catch (DbException $e) {
            Log::error("检查今日签到状态失败", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取用户签到记录
     * @param int $userId 用户ID
     * @param int $limit 记录条数
     * @return array 签到记录列表
     */
    public function getSignRecords($userId, $limit = 30)
    {
        try {
            $records = CheckinRecord::where('uid', $userId)
                ->order('checkin_date', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            return ['code' => 1, 'data' => $records];
        } catch (DbException $e) {
            Log::error("获取签到记录失败", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['code' => 0, 'msg' => '获取签到记录失败'];
        }
    }

    private function isPointsTaskEnabled(string $taskKey): bool
    {
        $defaults = [
            'daily_checkin' => 1,
            'daily_order_completed' => 1,
            'bind_2fa' => 1,
            'bind_telegram' => 1,
        ];
        $raw = (string)(getConfig('points_task_settings') ?: '');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $defaults = array_merge($defaults, $decoded);
        }

        return !empty($defaults[$taskKey]);
    }
}
