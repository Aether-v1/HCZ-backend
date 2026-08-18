<?php
declare (strict_types=1);

namespace app\model;

use think\Model;
use app\model\User; // 引入用户模型（如果需要关联）

/**
 * @mixin Model
 */
class Recharge extends Model
{
    // 1. 定义数据表名（默认与类名一致，小写，若表名不同需指定）
    // protected $table = 'recharge_orders'; // 例如实际表名是 recharge_orders

    // 2. 定义主键（默认是 id，若主键不同需指定）
    // protected $pk = 'recharge_id';

    // 3. 开启自动时间戳（自动维护 create_time 和 update_time 字段）
    protected $autoWriteTimestamp = 'datetime'; // 自动写入时间，格式为 datetime
    protected $createTime = 'create_time'; // 对应数据库的创建时间字段
    protected $updateTime = 'update_time'; // 对应数据库的更新时间字段（如果需要）

    // 4. 定义字段类型转换（将数据库字段自动转换为指定类型）
    protected $type = [
        'amount' => 'float', // 金额字段转为浮点型
        'status' => 'integer', // 状态字段转为整型
        'submit_time' => 'datetime', // 提交时间转为 datetime 对象
    ];

    // 5. 定义状态获取器（将数字状态转换为文字描述）
    public function getStatusTextAttr($value, $data)
    {
        // $data 是当前模型的所有字段数据
        $status = $data['status'] ?? 0;
        $statusMap = [
            1 => '已提交',
            2 => '处理中',
            3 => '已完成',
            4 => '已取消',
        ];
        return $statusMap[$status] ?? '未知状态';
    }

    // 6. 定义支付方式获取器（转换支付方式为文字）
    public function getPaymentMethodTextAttr($value, $data)
    {
        $payType = $data['pay_type'] ?? 0;
        $payMap = [
            1 => 'U支付',
            2 => '易支付',
            3 => '其他支付',
        ];
        return $payMap[$payType] ?? '未知支付方式';
    }

    // 7. 关联用户模型（通过 user_id 关联 User 模型）
    public function user()
    {
        // 一个充值订单属于一个用户
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 8. 定义查询范围（简化常用查询）
    public function scopeUnfinished($query)
    {
        // 查询未完成的订单（状态不是 3 和 4）
        return $query->whereNotIn('status', [3, 4]);
    }
}