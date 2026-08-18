<?php
declare (strict_types=1);

namespace app\model;

use think\Model;
use think\facade\Log;

/**
 * @mixin Model
 */
class Order extends Model
{
    protected const USER_SOFT_DELETE_FIELD = 'user_deleted';
    protected const USER_SOFT_DELETE_TIME_FIELD = 'user_deleted_time';

    protected static ?bool $userSoftDeleteColumnsReady = null;

    // 设置json类型字段
    protected $json = ['product_info', 'order_info'];
    // 设置JSON数据返回数组
    protected $jsonAssoc = true;

    public static function supportsUserSoftDelete(): bool
    {
        if (self::$userSoftDeleteColumnsReady !== null) {
            return self::$userSoftDeleteColumnsReady;
        }

        try {
            $model = new self();
            $query = $model->db();
            $fields = $query->getConnection()->getTableFields($query->getTable());
            self::$userSoftDeleteColumnsReady = in_array(self::USER_SOFT_DELETE_FIELD, $fields, true)
                && in_array(self::USER_SOFT_DELETE_TIME_FIELD, $fields, true);
        } catch (\Throwable $e) {
            Log::warning('order soft delete column detection failed: ' . $e->getMessage());
            self::$userSoftDeleteColumnsReady = false;
        }

        return self::$userSoftDeleteColumnsReady;
    }

    public static function scopeUserVisible($query)
    {
        if (!self::supportsUserSoftDelete()) {
            return $query;
        }

        return $query->where(function ($innerQuery) {
            $innerQuery->whereNull(self::USER_SOFT_DELETE_FIELD)
                ->whereOr(self::USER_SOFT_DELETE_FIELD, 0);
        });
    }

    public static function userVisibleQuery(int $uid)
    {
        return self::scopeUserVisible(self::where('uid', $uid));
    }

    public function isUserDeleted(): bool
    {
        if (!self::supportsUserSoftDelete()) {
            return false;
        }

        return (int)($this->getAttr(self::USER_SOFT_DELETE_FIELD) ?? 0) === 1;
    }

    public function markUserDeleted(): bool
    {
        if (!self::supportsUserSoftDelete()) {
            return false;
        }

        if ($this->isUserDeleted()) {
            return true;
        }

        $this->setAttr(self::USER_SOFT_DELETE_FIELD, 1);
        $this->setAttr(self::USER_SOFT_DELETE_TIME_FIELD, date('Y-m-d H:i:s'));

        return $this->save() !== false;
    }
}
