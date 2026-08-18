<?php
declare (strict_types=1);

namespace app\model;

use think\Model;

class UserPoints extends Model
{
    protected $name = 'user_points'; // 表名：cz_user_points（结合前缀）
    protected $autoWriteTimestamp = false;
    // 数据库实际字段映射（移除表中不存在的字段）
    protected $schema = [
        'id'              => 'int',
        'user_id'         => 'int',    // 数据库中用户ID字段是user_id（原模型用了uid）
        'points'          => 'int',    // 数据库中积分字段是points（原模型用了balance）
        'type'            => 'int',    // 数据库中存在的type字段
        'continuous_days' => 'int',    // 连续签到天数（与模型一致）
        'create_time'     => 'int',    // 数据库中是int类型时间戳
        'date_str'        => 'string', // 数据库中存储日期的字段（原模型用了last_checkin_date）
    ];
    
    /**
     * 获取用户积分信息，不存在则自动创建（修正字段名）
     */
    public static function getUserPoints($userId)
    {
        // 用数据库实际字段user_id查询（原模型用了uid）
        $points = self::where('user_id', $userId)->find();
        if (!$points) {
            $points = new self();
            $points->user_id = $userId;       // 修正为user_id
            $points->points = 0;              // 修正为points（原模型用了balance）
            $points->type = 0;                // 补充数据库中存在的type字段
            $points->continuous_days = 0;     // 连续签到天数（不变）
            $points->date_str = null;         // 修正为date_str（原模型用了last_checkin_date）
            $points->create_time = time();    // 补充时间戳（数据库中存在）
            $points->save();
        }
        return $points;
    }
    
    /**
     * 检查今日是否已签到（适配date_str字段）
     */
    public function isCheckedInToday()
    {
        if (!$this->date_str) {
            return false;
        }
        // 用date_str字段判断（原模型用了last_checkin_date）
        return $this->date_str == date('Y-m-d');
    }
    
    /**
     * 更新连续签到天数（适配date_str字段）
     */
    public function updateContinuousDays()
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // 用date_str字段判断（原模型用了last_checkin_date）
        if ($this->date_str && $this->date_str == $yesterday) {
            // 最后签到是昨天，连续天数+1
            $this->continuous_days += 1;
        } 
        // 最后签到不是昨天也不是今天，重置为1
        elseif (!$this->isCheckedInToday()) {
            $this->continuous_days = 1;
        }
        
        // 连续签到最多记录7天
        if ($this->continuous_days > 7) {
            $this->continuous_days = 7;
        }
        
        $this->date_str = $today;           // 更新为今日日期（用date_str字段）
        $this->create_time = time();        // 更新时间戳
    }
}
