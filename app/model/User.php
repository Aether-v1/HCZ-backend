<?php
declare (strict_types=1);

namespace app\model;

use think\Model;
use app\model\PointsRecord;
use app\model\CheckinRecord;

/**
 * @mixin Model
 */
class User extends Model
{
    // 指定用户表为cz_user（关键配置，与数据库表对应）
    protected $table = 'cz_user';
    
    // 1. 设置JSON类型字段（保留原有配置）
    protected $json = ['certification'];
    // 设置JSON数据返回数组（保留原有配置）
    protected $jsonAssoc = true;

    // 2. 自动时间戳配置（保留原有配置）
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 3. 字段类型转换（新增积分相关字段的类型定义）
    protected $type = [
        'id' => 'integer',
        'mobile' => 'string',
        'balance' => 'float',
        'status' => 'integer',
        // 积分相关字段类型
        'points_balance' => 'integer',    // 当前积分余额
        'month_earned' => 'integer',      // 本月获得积分
        'month_used' => 'integer',        // 本月使用积分
        'total_earned' => 'integer',      // 累计获得积分
        'continuous_checkin_days' => 'integer', // 连续签到天数（如果需要存储）
    ];

    // 4. 隐藏敏感字段（保留原有配置）
    protected $hidden = [
        'password',
        'salt',
        'token',
        'id_card',
        'twofa_secret',
        'twofa_recovery_codes',
    ];

    // 5. 手机号脱敏获取器（保留原有配置）
    public function getMobileMaskedAttr($value, $data)
    {
        $mobile = $data['mobile'] ?? '';
        if (strlen($mobile) === 11) {
            return substr($mobile, 0, 3) . '****' . substr($mobile, 7);
        }
        return $mobile;
    }

    // 6. 积分相关获取器
    // 获取可用积分（格式化显示）
    public function getPointsBalanceFormattedAttr($value, $data)
    {
        return number_format($data['points_balance'] ?? 0);
    }

    // 7. 关联关系定义
    // 关联充值订单（保留原有配置）
    public function recharges()
    {
        return $this->hasMany(Recharge::class, 'user_id', 'id');
    }

    // 关联积分记录（新增）
    public function pointsRecords()
    {
        return $this->hasMany(PointsRecord::class, 'uid', 'id')
                    ->order('create_time', 'desc');
    }

    // 关联签到记录（新增）
    public function checkinRecords()
    {
        return $this->hasMany(CheckinRecord::class, 'uid', 'id')
                    ->order('checkin_date', 'desc');
    }

    // 8. 积分操作方法
    /**
     * 增加用户积分
     * @param int $points 增加的积分数量
     * @param string $reason 积分变动原因
     * @return bool
     */
    public function addPoints(int $points, string $reason): bool
    {
        if ($points <= 0) {
            return false;
        }

        // 开启事务
        $this->startTrans();
        try {
            // 更新用户积分字段
            $this->points_balance = ($this->points_balance ?? 0) + $points;
            $this->month_earned = ($this->month_earned ?? 0) + $points;
            $this->total_earned = ($this->total_earned ?? 0) + $points;
            $this->save();

            // 记录积分变动
            $record = new PointsRecord();
            $record->uid = $this->id;
            $record->points = $points;
            $record->reason = $reason;
            $record->type = 'earned';
            $record->create_time = date('Y-m-d H:i:s');
            $record->save();

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            return false;
        }
    }

    /**
     * 减少用户积分
     * @param int $points 减少的积分数量
     * @param string $reason 积分变动原因
     * @return bool
     */
    public function reducePoints(int $points, string $reason): bool
    {
        if ($points <= 0 || ($this->points_balance ?? 0) < $points) {
            return false;
        }

        // 开启事务
        $this->startTrans();
        try {
            // 更新用户积分字段
            $this->points_balance = ($this->points_balance ?? 0) - $points;
            $this->month_used = ($this->month_used ?? 0) + $points;
            $this->save();

            // 记录积分变动
            $record = new PointsRecord();
            $record->uid = $this->id;
            $record->points = $points;
            $record->reason = $reason;
            $record->type = 'used';
            $record->create_time = date('Y-m-d H:i:s');
            $record->save();

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            return false;
        }
    }

    /**
     * 获取用户今日是否已签到
     * @return bool
     */
    public function hasCheckedInToday(): bool
    {
        return CheckinRecord::where('uid', $this->id)
                            ->where('checkin_date', date('Y-m-d'))
                            ->exists();
    }

    /**
     * 获取用户连续签到天数
     * @return int
     */
    public function getContinuousCheckinDays(): int
    {
        $days = 0;
        $currentDate = date('Y-m-d');
        
        while (true) {
            $hasCheckin = CheckinRecord::where('uid', $this->id)
                                      ->where('checkin_date', $currentDate)
                                      ->exists();
                                      
            if ($hasCheckin) {
                $days++;
                $currentDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
            } else {
                break;
            }
        }
        
        return $days;
    }
}
    
