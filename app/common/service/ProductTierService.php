<?php
declare (strict_types=1);

namespace app\common\service;

use app\model\Product;
use think\Exception;

class ProductTierService
{
    public static function resolveProductAndTier(int $productId, float $amountMoney): array
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception('商品不存在');
        }
        return self::resolveTier($product, $amountMoney);
    }

    public static function resolveTier($product, float $amountMoney): array
    {
        $tiers = self::extractTiers($product);
        foreach ($tiers as $tier) {
            $min = (float)$tier['min_amount'];
            $max = $tier['max_amount'] === null ? null : (float)$tier['max_amount'];
            if ($amountMoney >= $min && ($max === null || $amountMoney <= $max)) {
                $discount = round((float)$tier['discount'], 4);
                $platformPrice = round($amountMoney * ($discount / 10), 2);
                return [
                    'product_id' => (int)$product['id'],
                    'tier_key' => (string)$tier['tier_key'],
                    'tier_type' => (int)$tier['tier_type'],
                    'min_amount' => round($min, 2),
                    'max_amount' => $max === null ? null : round($max, 2),
                    'par_value_snapshot' => (string)$tier['par_value_snapshot'],
                    'discount' => $discount,
                    'platform_price' => $platformPrice,
                    'commission_base' => $platformPrice,
                ];
            }
        }
        throw new Exception('未匹配到商品档位');
    }

    public static function extractTiers($product): array
    {
        $discountConfig = $product['discount'] ?? [];
        if (is_string($discountConfig)) {
            $decoded = json_decode($discountConfig, true);
            $discountConfig = is_array($decoded) ? $decoded : [];
        }
        $tiers = [];
        foreach ($discountConfig as $idx => $item) {
            $min = isset($item['mini_amount']) ? round((float)$item['mini_amount'], 2) : 0.0;
            $maxRaw = $item['maxi_amount'] ?? null;
            $max = ($maxRaw === '' || $maxRaw === null) ? null : round((float)$maxRaw, 2);
            $discount = round((float)($item['discount'] ?? 0), 4);
            $tierType = ($max === null || abs($max - $min) > 0.000001) ? 2 : 1;
            $tierKey = self::buildTierKey($min, $max);
            $tiers[] = [
                'tier_key' => $tierKey,
                'tier_type' => $tierType,
                'min_amount' => $min,
                'max_amount' => $max,
                'discount' => $discount,
                'par_value_snapshot' => self::buildTierLabel($min, $max),
                'sort' => $idx,
            ];
        }
        usort($tiers, function ($a, $b) {
            return ($a['sort'] <=> $b['sort']);
        });
        return $tiers;
    }

    public static function buildTierKey(float $min, ?float $max): string
    {
        $minText = self::normalizeNumber($min);
        if ($max === null) {
            return 'range_' . $minText . '_up';
        }
        $maxText = self::normalizeNumber($max);
        if (abs($min - $max) < 0.000001) {
            return 'fixed_' . $minText;
        }
        return 'range_' . $minText . '_' . $maxText;
    }

    public static function buildTierLabel(float $min, ?float $max): string
    {
        $minText = self::normalizeNumber($min);
        if ($max === null) {
            return $minText . '以上';
        }
        $maxText = self::normalizeNumber($max);
        if (abs($min - $max) < 0.000001) {
            return $minText;
        }
        return $minText . '-' . $maxText;
    }

    private static function normalizeNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.000001) {
            return (string)(int)round($value);
        }
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }
}
