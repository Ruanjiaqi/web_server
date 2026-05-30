<?php
namespace app\services\settlement;

use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DeliveryFeeCalculatorServices extends BaseServices
{
    protected array $types = [
        'pickup' => '用户自提',
        'merchant' => '分销商配送',
        'city' => '同城配送',
        'express' => '快递配送',
    ];

    public function options(int $distributorId = 0, array $context = []): array
    {
        $options = [];
        foreach ($this->types as $type => $label) {
            $options[] = [
                'type' => $type,
                'label' => $label,
                'fee' => $this->calculate($type, $distributorId, $context),
            ];
        }
        return $options;
    }

    public function normalizeType(string $type): string
    {
        $map = ['distributor' => 'merchant', 'third_party_city' => 'city'];
        $type = $map[$type] ?? $type;
        if (!isset($this->types[$type])) {
            throw new ApiException('配送方式不支持');
        }
        return $type;
    }

    public function calculate(string $type, int $distributorId = 0, array $context = []): float
    {
        $type = $this->normalizeType($type);
        $items = $this->resolveChargeItems($context);
        if ($type === 'pickup') {
            return 0.0;
        }
        $sameCity = $this->isSameCity($distributorId, $context);
        if ($type === 'merchant') {
            $fee = $sameCity ? $this->configFloat('fmcg_delivery_fee_merchant_same_city', 0) : $this->configFloat('fmcg_delivery_fee_merchant_remote', 5);
        } elseif ($type === 'city') {
            $fee = $sameCity ? $this->configFloat('fmcg_delivery_fee_city_same_city', 8) : $this->configFloat('fmcg_delivery_fee_city_remote', 15);
        } else {
            $base = $this->configFloat('fmcg_delivery_fee_express_base', $this->configFloat('fmcg_delivery_fee_express', 10));
            $firstWeight = max(0.01, $this->configFloat('fmcg_delivery_fee_express_first_weight', 1));
            $extraWeightFee = $this->configFloat('fmcg_delivery_fee_express_extra_weight', 3);
            $perItemFee = $this->configFloat('fmcg_delivery_fee_express_per_item', 0);
            $weight = $this->contextWeight($context, $items);
            $itemCount = $this->contextItemCount($context, $items);
            $fee = $base + max(0, ceil($weight - $firstWeight)) * $extraWeightFee + max(0, $itemCount - 1) * $perItemFee;
        }
        $fallback = sys_config('fmcg_delivery_fee_' . $type, null);
        if ($fee <= 0 && $fallback !== null && $fallback !== '') {
            $fee = (float)$fallback;
        }
        $fee = round((float)$fee, 2);
        if ($fee < 0 || $fee > 9999) {
            throw new ApiException('配送费配置不合法');
        }
        return $fee;
    }

    protected function configFloat(string $key, float $default): float
    {
        $value = sys_config($key, null);
        return $value === null || $value === '' ? $default : (float)$value;
    }

    protected function isSameCity(int $distributorId, array $context): bool
    {
        $addressCity = (string)($context['city'] ?? $context['city_name'] ?? $context['address_city'] ?? '');
        if (!$addressCity && !empty($context['address_id'])) {
            $address = Db::name('user_address')->where('id', (int)$context['address_id'])->find();
            $addressCity = (string)($address['city'] ?? $address['city_name'] ?? $address['city_id'] ?? '');
        }
        $distributor = $distributorId > 0 ? Db::name('distributor')->where('id', $distributorId)->find() : [];
        $distributorCity = (string)($distributor['city'] ?? $distributor['city_name'] ?? $distributor['city_id'] ?? '');
        if (!$distributorCity && !empty($distributor['address']) && $addressCity !== '') {
            $distributorCity = mb_strpos((string)$distributor['address'], $addressCity) !== false ? $addressCity : '';
        }
        return $addressCity !== '' && $distributorCity !== '' && $addressCity === $distributorCity;
    }

    protected function resolveChargeItems(array $context): array
    {
        $items = [];
        $cartIds = $this->normalizeIds($context['cart_id'] ?? $context['cart_ids'] ?? []);
        if ($cartIds) {
            $query = Db::name('store_cart')->alias('c')
                ->leftJoin('store_product_attr_value a', 'a.unique = c.product_attr_unique')
                ->whereIn('c.id', $cartIds)
                ->where('c.is_del', 0)
                ->field('c.product_id,c.product_attr_unique as unique,c.cart_num,a.weight as sku_weight');
            if (!empty($context['uid'])) {
                $query->where('c.uid', (int)$context['uid']);
            }
            foreach ($query->select()->toArray() as $row) {
                $items[] = [
                    'product_id' => (int)$row['product_id'],
                    'unique' => (string)$row['unique'],
                    'num' => max(1, (int)$row['cart_num']),
                    'weight' => (float)($row['sku_weight'] ?: 0),
                ];
            }
        }
        if (!$items && !empty($context['cartInfo']) && is_array($context['cartInfo'])) {
            $items = $this->itemsFromCartInfo($context['cartInfo']);
        }
        if (!$items && !empty($context['items']) && is_array($context['items'])) {
            $items = $this->itemsFromPayload($context['items']);
        }
        if (!$items && !empty($context['fmcg_items']) && is_array($context['fmcg_items'])) {
            $items = $this->itemsFromPayload($context['fmcg_items']);
        }
        return $this->attachServerWeights($items);
    }

    protected function normalizeIds($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        $ids = [];
        foreach ((array)$value as $item) {
            $ids[] = (int)(is_array($item) ? ($item['cart_id'] ?? $item['id'] ?? 0) : $item);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    protected function itemsFromCartInfo(array $cartInfo): array
    {
        $items = [];
        foreach ($cartInfo as $cart) {
            if (!is_array($cart)) {
                continue;
            }
            $productInfo = $cart['productInfo'] ?? [];
            $attrInfo = $productInfo['attrInfo'] ?? ($cart['attrInfo'] ?? []);
            $items[] = [
                'product_id' => (int)($cart['product_id'] ?? $productInfo['id'] ?? $productInfo['product_id'] ?? 0),
                'unique' => (string)($cart['product_attr_unique'] ?? $cart['unique'] ?? $attrInfo['unique'] ?? ''),
                'num' => max(1, (int)($cart['cart_num'] ?? $cart['num'] ?? 1)),
            ];
        }
        return $items;
    }

    protected function itemsFromPayload(array $payloadItems): array
    {
        $items = [];
        foreach ($payloadItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'product_id' => (int)($item['product_id'] ?? 0),
                'unique' => (string)($item['unique'] ?? $item['product_attr_unique'] ?? ''),
                'num' => max(1, (int)($item['num'] ?? $item['cart_num'] ?? 1)),
            ];
        }
        return $items;
    }

    protected function attachServerWeights(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $key = $productId . '|' . (string)($item['unique'] ?? '');
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'product_id' => $productId,
                    'unique' => (string)($item['unique'] ?? ''),
                    'num' => 0,
                    'weight' => (float)($item['weight'] ?? 0),
                ];
            }
            $normalized[$key]['num'] += max(1, (int)($item['num'] ?? 1));
        }
        if (!$normalized) {
            return [];
        }
        $uniques = array_values(array_filter(array_unique(array_column($normalized, 'unique'))));
        $skuWeights = $uniques ? Db::name('store_product_attr_value')->whereIn('unique', $uniques)->column('weight', 'unique') : [];
        foreach ($normalized as &$item) {
            $item['weight'] = (float)($skuWeights[$item['unique']] ?? $item['weight'] ?? 0);
        }
        return array_values($normalized);
    }

    protected function contextWeight(array $context, array $items = []): float
    {
        $weight = 0.0;
        foreach ($items as $item) {
            $weight += (float)($item['weight'] ?? 0) * max(1, (int)($item['num'] ?? 1));
        }
        return $weight > 0 ? $weight : 1.0;
    }

    protected function contextItemCount(array $context, array $items = []): int
    {
        $count = 0;
        foreach ($items as $item) {
            $count += max(1, (int)($item['num'] ?? 1));
        }
        return max(1, $count);
    }
}
