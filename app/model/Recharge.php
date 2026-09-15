<?php
declare (strict_types=1);

namespace app\model;

use think\Model;
use app\model\User; // 引入用户模型（如果需要关联）

/**
 * @mixin Model
 *
 * Recharge Status Contract (Pre-R1 Batch A)
 * -------------------------------------------
 * STATUS_PENDING   = 0  待支付/待汇款（在线支付待回调，或手动汇款待提交凭证）
 * STATUS_SUBMITTED = 1  已提交（手动汇款已上传凭证，等待 Admin 审核）
 * STATUS_EXPIRED   = 2  已过期（支付窗口超时，但 Provider 仍可能已收款；
 *                          经过签名+金额验证的真实迟到付款 MUST be settled）
 * STATUS_PAID      = 3  已支付/已完成（终态，单调不可逆）
 *
 * 注意：status=2 语义从历史的"已取消/处理中"正式变更为 EXPIRED。
 *       区分取消来源使用 cancel_source 字段（见下方常量）。
 *       用户主动取消 (cancel_source='user') 的订单不应被自动 settlement，
 *       需人工审核；Cron 超时 (cancel_source='cron') 的订单允许迟到付款自动入账。
 */
class Recharge extends Model
{
    // ===== 状态常量 (Single Source of Truth) =====
    public const STATUS_PENDING = 0;
    public const STATUS_SUBMITTED = 1;
    public const STATUS_EXPIRED = 2;
    public const STATUS_PAID = 3;

    // ===== 取消来源常量 (cancel_source) =====
    public const CANCEL_SOURCE_NONE = '';
    public const CANCEL_SOURCE_CRON = 'cron';
    public const CANCEL_SOURCE_USER = 'user';
    public const CANCEL_SOURCE_CREATE_FAILED = 'create_failed';
    public const CANCEL_SOURCE_ADMIN_REJECT = 'admin_reject';
    public const CANCEL_SOURCE_PROVIDER_EXPIRED = 'provider_expired';

    // 允许迟到付款自动 settlement 的取消来源
    // (Cron 超时 / Provider 创建失败 / Provider 端过期 — 这些不是用户主动终止)
    public const AUTO_SETTLE_CANCEL_SOURCES = [
        self::CANCEL_SOURCE_CRON,
        self::CANCEL_SOURCE_CREATE_FAILED,
        self::CANCEL_SOURCE_PROVIDER_EXPIRED,
        self::CANCEL_SOURCE_NONE, // 历史数据无 cancel_source，默认允许（兼容）
    ];

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
        $status = (int)($data['status'] ?? 0);
        $statusMap = [
            self::STATUS_PENDING => '待支付',
            self::STATUS_SUBMITTED => '已提交',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_PAID => '已完成',
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
    // status=2(EXPIRED) 仍算未完成，因为迟到付款可能恢复为 PAID
    public function scopeUnfinished($query)
    {
        return $query->whereNotIn('status', [self::STATUS_PAID]);
    }

    // 9. 判断是否允许自动 settlement（迟到付款恢复）
    public function isAutoSettleable(): bool
    {
        $status = (int)($this->status ?? 0);
        if ($status === self::STATUS_PENDING) {
            return true;
        }
        if ($status === self::STATUS_EXPIRED) {
            $cancelSource = (string)($this->cancel_source ?? self::CANCEL_SOURCE_NONE);
            return in_array($cancelSource, self::AUTO_SETTLE_CANCEL_SOURCES, true);
        }
        return false;
    }
}
