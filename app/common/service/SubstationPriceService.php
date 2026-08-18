<?php
declare (strict_types=1);

namespace app\common\service;

use app\model\Product;
use app\model\SubstationProductTierPrice;
use think\Exception;
use think\facade\Db;

class SubstationPriceService
{
    private static function calcPriceByDiscount(float $amountMoney, float $discount): float
    {
        return round($amountMoney * ($discount / 10), 2);
    }

    private static function normalizeProductDescribe($value): string
    {
        return trim((string)$value);
    }

    private static function inferSubstationDiscount(?array $cover, array $tier): float
    {
        $platformDiscount = round((float)($tier['discount'] ?? 0), 4);
        if (!$cover) {
            return $platformDiscount;
        }

        if (isset($cover['substation_discount']) && $cover['substation_discount'] !== null && $cover['substation_discount'] !== '') {
            return round((float)$cover['substation_discount'], 4);
        }

        $minAmount = round((float)($tier['min_amount'] ?? 0), 2);
        if ($minAmount > 0) {
            return round(((float)($cover['substation_price'] ?? 0) / $minAmount) * 10, 4);
        }

        $platformPriceSnapshot = round((float)($cover['platform_price_snapshot'] ?? 0), 2);
        if ($platformPriceSnapshot > 0 && $platformDiscount > 0) {
            return round(((float)($cover['substation_price'] ?? 0) / $platformPriceSnapshot) * $platformDiscount, 4);
        }

        return $platformDiscount;
    }

    private static function buildPriceResult(array $tier, float $amountMoney, ?array $cover = null): array
    {
        $platformDiscount = round((float)($tier['discount'] ?? 0), 4);
        $platformPrice = round((float)($tier['platform_price'] ?? 0), 2);
        $finalDiscount = $platformDiscount;
        $finalPrice = $platformPrice;
        $markup = 0.00;
        $coverId = 0;

        if ($cover) {
            $coverId = (int)($cover['id'] ?? 0);
            $finalDiscount = self::inferSubstationDiscount($cover, $tier);
            if ($finalDiscount < $platformDiscount) {
                throw new Exception('分站折扣不能低于平台折扣');
            }
            $finalPrice = self::calcPriceByDiscount($amountMoney, $finalDiscount);
            $markup = round($finalPrice - $platformPrice, 2);
        }

        return [
            'tier_key' => (string)$tier['tier_key'],
            'tier_type' => (int)$tier['tier_type'],
            'min_amount' => round((float)$tier['min_amount'], 2),
            'max_amount' => $tier['max_amount'] === null ? null : round((float)$tier['max_amount'], 2),
            'par_value_snapshot' => (string)$tier['par_value_snapshot'],
            'original_amount' => round($amountMoney, 2),
            'platform_original_price' => round($amountMoney, 2),
            'platform_discount' => $platformDiscount,
            'platform_price' => $platformPrice,
            'platform_settlement_price' => $platformPrice,
            'commission_base' => round((float)$tier['commission_base'], 2),
            'min_allowed_price' => $platformPrice,
            'min_allowed_discount' => $platformDiscount,
            'substation_cover_discount' => $cover ? $finalDiscount : null,
            'substation_cover_price' => $cover ? round($finalPrice, 2) : null,
            'substation_discount' => $finalDiscount,
            'substation_price' => round($finalPrice, 2),
            'final_discount' => $finalDiscount,
            'final_price' => round($finalPrice, 2),
            'effective_price' => round($finalPrice, 2),
            'markup_amount' => round(max(0, $markup), 2),
            'cover_id' => $coverId,
            'cover_hit' => $cover ? 1 : 0,
        ];
    }

    private static function loadCoverMap(int $substationId, int $productId): array
    {
        if ($substationId <= 0) {
            return [];
        }

        $rows = SubstationProductTierPrice::where('substation_id', $substationId)
            ->where('product_id', $productId)
            ->where('status', 1)
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(string)($row['tier_key'] ?? '')] = $row;
        }

        return $map;
    }

    private static function resolveProductDescribeFromRows(array $rows, string $fallback = ''): string
    {
        foreach ($rows as $row) {
            $candidate = self::normalizeProductDescribe($row['product_describe'] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return self::normalizeProductDescribe($fallback);
    }

    public static function resolveProductDescribeOverride(int $productId, int $substationId = 0): string
    {
        if ($productId <= 0 || $substationId <= 0) {
            return '';
        }

        return self::resolveProductDescribeFromRows(array_values(self::loadCoverMap($substationId, $productId)));
    }

    public static function resolveProductDescribe(int $productId, int $substationId = 0, string $fallback = ''): string
    {
        if ($productId <= 0 || $substationId <= 0) {
            return self::normalizeProductDescribe($fallback);
        }

        return self::resolveProductDescribeFromRows(array_values(self::loadCoverMap($substationId, $productId)), $fallback);
    }

    public static function resolveEffectivePrice(int $productId, float $amountMoney, int $substationId = 0): array
    {
        $tier = ProductTierService::resolveProductAndTier($productId, $amountMoney);
        $coverMap = self::loadCoverMap($substationId, $productId);
        $cover = $coverMap[(string)$tier['tier_key']] ?? null;
        return self::buildPriceResult($tier, $amountMoney, $cover);
    }

    public static function resolveDiscountPreview(int $productId, float $amountMoney, int $substationId = 0): array
    {
        $price = self::resolveEffectivePrice($productId, $amountMoney, $substationId);
        $rate = (float)(getConfig('rate') ?: 1);
        $paymentAmount = round((float)$price['final_price'], 2);
        $discountAmount = round(max(0, (float)$price['original_amount'] - $paymentAmount), 2);
        $usdtAmount = round($paymentAmount / $rate, 2);

        return array_merge($price, [
            'inDiscountRange' => 1,
            'discountAmount' => number_format($discountAmount, 2, '.', ''),
            'paymentAmount' => number_format($paymentAmount, 2, '.', ''),
            'discount' => $price['final_discount'],
            'cnyAmount' => number_format($usdtAmount, 2, '.', ''),
            'reference_exchange_rate' => $rate,
            'substation_price_usdt' => number_format($usdtAmount, 2, '.', ''),
            'platform_price_usdt' => number_format(round((float)$price['platform_settlement_price'] / $rate, 2), 2, '.', ''),
        ]);
    }

    public static function listEffectiveTiersForProduct(int $productId, int $substationId = 0): array
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception('商品不存在');
        }

        $tiers = ProductTierService::extractTiers($product);
        $coverMap = self::loadCoverMap($substationId, $productId);
        $rate = (float)(getConfig('rate') ?: 1);
        $result = [];

        foreach ($tiers as $tier) {
            $amountMoney = round((float)$tier['min_amount'], 2);
            $tier['platform_price'] = round($amountMoney * ((float)$tier['discount'] / 10), 2);
            $tier['commission_base'] = $tier['platform_price'];
            $resolved = self::buildPriceResult($tier, $amountMoney, $coverMap[(string)$tier['tier_key']] ?? null);
            $resolved['final_price_usdt'] = round((float)$resolved['final_price'] / $rate, 2);
            $resolved['platform_price_usdt'] = round((float)$resolved['platform_settlement_price'] / $rate, 2);
            $result[] = $resolved;
        }

        return $result;
    }

    public static function resolveFinalPrice(int $productId, float $amountMoney, int $substationId = 0): array
    {
        return self::resolveEffectivePrice($productId, $amountMoney, $substationId);
    }

    public static function listTiersForProduct(int $uid, int $substationId, int $productId): array
    {
        $product = Product::find($productId);
        if (!$product) {
            throw new Exception('商品不存在');
        }

        $tiers = ProductTierService::extractTiers($product);
        $rate = (float)(getConfig('rate') ?: 1);
        $covers = SubstationProductTierPrice::where('substation_id', $substationId)
            ->where('product_id', $productId)
            ->select()
            ->toArray();

        $coverMap = [];
        foreach ($covers as $row) {
            $coverMap[$row['tier_key']] = $row;
        }

        $result = [];
        foreach ($tiers as $tier) {
            $platformDiscount = round((float)$tier['discount'], 4);
            $platformPrice = self::calcPriceByDiscount((float)$tier['min_amount'], $platformDiscount);
            $cover = $coverMap[$tier['tier_key']] ?? null;
            $substationDiscount = self::inferSubstationDiscount($cover, $tier);
            $substationPrice = self::calcPriceByDiscount((float)$tier['min_amount'], $substationDiscount);
            $result[] = [
                'tier_key' => $tier['tier_key'],
                'tier_type' => (int)$tier['tier_type'],
                'min_amount' => round((float)$tier['min_amount'], 2),
                'max_amount' => $tier['max_amount'] === null ? null : round((float)$tier['max_amount'], 2),
                'par_value_snapshot' => (string)$tier['par_value_snapshot'],
                'platform_discount' => $platformDiscount,
                'platform_price' => $platformPrice,
                'platform_price_usdt' => round($platformPrice / $rate, 2),
                'min_allowed_price' => $platformPrice,
                'min_allowed_price_usdt' => round($platformPrice / $rate, 2),
                'min_allowed_discount' => $platformDiscount,
                'substation_discount' => $substationDiscount,
                'substation_price' => $substationPrice,
                'substation_price_usdt' => round($substationPrice / $rate, 2),
                'markup_amount' => round(max(0, $substationPrice - $platformPrice), 2),
                'markup_amount_usdt' => round(max(0, $substationPrice - $platformPrice) / $rate, 2),
                'reference_exchange_rate' => $rate,
                'product_describe' => self::normalizeProductDescribe($cover['product_describe'] ?? ''),
                'status' => (int)($cover['status'] ?? 1),
            ];
        }

        return $result;
    }

    public static function saveTiers(int $uid, int $substationId, int $productId, array $tiers, string $productDescribe = ''): void
    {
        if ($substationId <= 0) {
            throw new Exception('分站不存在');
        }

        $normalizedProductDescribe = self::normalizeProductDescribe($productDescribe);

        Db::transaction(function () use ($uid, $substationId, $productId, $tiers, $normalizedProductDescribe) {
            $product = Product::find($productId);
            if (!$product) {
                throw new Exception('商品不存在');
            }

            $platformTiers = ProductTierService::extractTiers($product);
            $platformMap = [];
            foreach ($platformTiers as $tier) {
                $platformMap[$tier['tier_key']] = $tier;
            }

            foreach ($tiers as $item) {
                $tierKey = (string)($item['tier_key'] ?? '');
                if ($tierKey === '' || !isset($platformMap[$tierKey])) {
                    throw new Exception('档位不存在：' . $tierKey);
                }

                $baseTier = $platformMap[$tierKey];
                $platformDiscount = round((float)$baseTier['discount'], 4);
                $platformPrice = self::calcPriceByDiscount((float)$baseTier['min_amount'], $platformDiscount);
                $substationDiscount = isset($item['substation_discount']) && $item['substation_discount'] !== ''
                    ? round((float)$item['substation_discount'], 4)
                    : self::inferSubstationDiscount([
                        'substation_price' => round((float)($item['substation_price'] ?? 0), 2),
                        'platform_price_snapshot' => $platformPrice,
                    ], $baseTier);

                if ($substationDiscount < $platformDiscount) {
                    throw new Exception('分站折扣不能低于平台折扣：' . $tierKey);
                }

                $substationPrice = self::calcPriceByDiscount((float)$baseTier['min_amount'], $substationDiscount);
                $row = [
                    'uid' => $uid,
                    'substation_id' => $substationId,
                    'product_id' => $productId,
                    'tier_key' => $tierKey,
                    'tier_type' => (int)$baseTier['tier_type'],
                    'min_amount' => round((float)$baseTier['min_amount'], 2),
                    'max_amount' => $baseTier['max_amount'] === null ? null : round((float)$baseTier['max_amount'], 2),
                    'par_value_snapshot' => (string)$baseTier['par_value_snapshot'],
                    'platform_discount_snapshot' => $platformDiscount,
                    'platform_price_snapshot' => $platformPrice,
                    'min_allowed_price' => $platformPrice,
                    'substation_price' => $substationPrice,
                    'markup_amount' => round($substationPrice - $platformPrice, 2),
                    'product_describe' => $normalizedProductDescribe,
                    'status' => isset($item['status']) ? (int)$item['status'] : 1,
                    'update_time' => date('Y-m-d H:i:s'),
                ];

                $exists = SubstationProductTierPrice::where('substation_id', $substationId)
                    ->where('product_id', $productId)
                    ->where('tier_key', $tierKey)
                    ->find();

                if ($exists) {
                    $exists->save($row);
                } else {
                    $row['create_time'] = date('Y-m-d H:i:s');
                    SubstationProductTierPrice::create($row);
                }
            }
        });
    }
}
