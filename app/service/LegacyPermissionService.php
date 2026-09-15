<?php
declare(strict_types=1);

namespace app\service;

/**
 * Batch 2-C: Legacy Permission Compatibility Service
 *
 * 旧中文权限 → 新 RBAC resource.action 映射。
 * 仅用于迁移期间兼容，最终在 Batch D6 删除。
 *
 * @deprecated 将在 Batch 2-D6 Legacy Removal 中删除
 */
class LegacyPermissionService
{
    /**
     * 旧中文权限 → RBAC code 映射（16 项）
     */
    private const LEGACY_TO_RBAC = [
        '用户列表' => 'admin.user.view',
        '支付管理' => 'admin.payment.manage',
        '充值业务 - 产品列表' => 'admin.product.recharge.view',
        '查询业务 - 产品列表' => 'admin.product.query.view',
        '充值业务 - 订单列表' => 'admin.order.recharge.view',
        '查询业务 - 订单列表' => 'admin.order.query.view',
        '交易挂单数据' => 'admin.transaction.pending.view',
        '交易订单数据' => 'admin.transaction.order.view',
        '充值订单记录' => 'admin.recharge.view',
        '提现订单记录' => 'admin.withdrawal.view',
        '返佣记录' => 'admin.rebate.view',
        '首页轮播图' => 'admin.banner.manage',
        '积分管理' => 'admin.points.manage',
        '管理员列表' => 'admin.admin.view',
        '操作记录' => 'admin.log.view',
        '系统设置管理' => 'admin.setting.manage',
    ];

    public function getMappingCount(): int
    {
        return count(self::LEGACY_TO_RBAC);
    }

    public function getAllCodes(): array
    {
        return array_values(self::LEGACY_TO_RBAC);
    }

    public function mapLegacyPermission(string $legacy): ?string
    {
        $key = trim($legacy);
        return self::LEGACY_TO_RBAC[$key] ?? null;
    }

    public function mapCodeToLegacy(string $code): ?string
    {
        $reverse = array_flip(self::LEGACY_TO_RBAC);
        return $reverse[$code] ?? null;
    }

    public function parseLegacyPowerString(string $power): array
    {
        if (trim($power) === '') {
            return [];
        }
        $items = preg_split('/[,，]/', $power);
        $items = array_map('trim', $items);
        $items = array_filter($items, fn($v) => $v !== '');
        return array_values($items);
    }

    public function convertPowerStringToCodes(string $power): array
    {
        $legacyItems = $this->parseLegacyPowerString($power);
        $codes = [];
        $unmapped = [];
        foreach ($legacyItems as $item) {
            $code = $this->mapLegacyPermission($item);
            if ($code !== null) {
                $codes[] = $code;
            } else {
                $unmapped[] = $item;
            }
        }
        return ['codes' => array_values(array_unique($codes)), 'unmapped' => array_values($unmapped)];
    }

    /**
     * 复现旧 power() 的 strpos 行为（包括前缀匹配风险）。
     * 仅用于测试验证旧行为，新代码应使用 AuthorizationService::can()。
     */
    public function hasLegacyPermission(string $powerString, string $permission): bool
    {
        return strpos($powerString, $permission) !== false;
    }
}
