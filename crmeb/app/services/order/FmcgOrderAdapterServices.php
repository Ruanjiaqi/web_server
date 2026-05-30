<?php
namespace app\services\order;

use app\services\BaseServices;
use app\services\distributor\BuyXGetXCampaignServices;
use app\services\distributor\DistributorServices;
use app\services\inventory\DistributorInventoryServices;
use app\services\settlement\DeliveryFeeCalculatorServices;
use app\services\settlement\DeliveryFeeRecordServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class FmcgOrderAdapterServices extends BaseServices
{
    protected array $deliveryTypes = ['pickup', 'merchant', 'city', 'express'];

    public function assertBoundDistributorPayload(int $uid, array $payload, array $cartGroup = []): int
    {
        $binding = app()->make(DistributorServices::class)->userBinding($uid);
        $distributorId = (int)($binding['bind']['distributor_id'] ?? 0);
        if ($distributorId <= 0) {
            throw new ApiException('请先绑定分销商后再下单');
        }
        $payloadDistributorId = (int)($payload['distributor_id'] ?? 0);
        if ($payloadDistributorId <= 0 || $payloadDistributorId !== $distributorId) {
            throw new ApiException('下单分销商与当前绑定分销商不一致');
        }
        $items = $this->normalizePayloadItems($payload['fmcg_items'] ?? []);
        if (!$items) {
            throw new ApiException('请提交分销商商品明细');
        }
        if ($cartGroup) {
            $cartItems = $this->normalizeCartItems($cartGroup['cartInfo'] ?? []);
            if ($items !== $cartItems) {
                throw new ApiException('分销商商品明细与当前购物车不一致');
            }
        }
        foreach ($items as $item) {
            $stockRow = Db::name('distributor_sku_inventory')
                ->where('distributor_id', $distributorId)
                ->where('product_id', $item['product_id'])
                ->where('unique', $item['unique'])
                ->fieldRaw('stock - locked_stock as available_stock')
                ->find();
            $available = (int)($stockRow['available_stock'] ?? 0);
            if ($available < $item['num']) {
                throw new ApiException('分销商库存不足');
            }
        }
        $this->normalizeDelivery($payload, $distributorId);
        return $distributorId;
    }

    public function afterOrderCreated(int $uid, string $orderId, array $payload): void
    {
        $distributorId = $this->assertBoundDistributorPayload($uid, $payload);
        if (!$distributorId) {
            return;
        }
        [$deliveryType, $deliveryFee] = $this->normalizeDelivery($payload, $distributorId);
        $order = Db::name('store_order')->where('order_id', $orderId)->field('id,pay_price,pay_postage')->find();
        if (!$order) {
            throw new ApiException('订单不存在');
        }
        $update = [
            'distributor_id' => $distributorId,
            'fmcg_delivery_type' => $deliveryType,
            'delivery_type' => $deliveryType,
            'fmcg_inventory_locked' => 1,
        ];
        if (!Db::name('delivery_fee_record')->where('order_id', $orderId)->count()) {
            $legacyPostage = (float)($order['pay_postage'] ?? 0);
            $basePayPrice = max(0, (float)$order['pay_price'] - $legacyPostage);
            $update['pay_postage'] = $deliveryFee;
            $update['pay_price'] = bcadd((string)$basePayPrice, (string)$deliveryFee, 2);
        }
        Db::name('store_order')->where('order_id', $orderId)->update($update);
        $items = $this->normalizePayloadItems($payload['fmcg_items'] ?? []);
        if ($items) {
            $inventory = app()->make(DistributorInventoryServices::class);
            foreach ($items as $item) {
                $inventory->lock($distributorId, $item['product_id'], $item['unique'], $item['num'], $orderId);
            }
            app()->make(BuyXGetXCampaignServices::class)->reserveQuotaForOrder($orderId, $items);
            $this->appendGiftItems((int)$order['id'], $uid, $orderId);
        }
        if ($deliveryFee > 0) {
            app()->make(DeliveryFeeRecordServices::class)->record($distributorId, $orderId, $deliveryType, $deliveryFee);
        }
    }

    public function afterOrderPaid(string $orderId): void
    {
        $order = Db::name('store_order')->where('order_id', $orderId)->find();
        if (!$order || empty($order['distributor_id'])) {
            return;
        }
        $distributorId = (int)$order['distributor_id'];
        if (!empty($order['fmcg_inventory_locked'])) {
            $inventory = app()->make(DistributorInventoryServices::class);
            foreach ($this->orderItems((int)$order['id']) as $item) {
                $inventory->deductLocked($distributorId, (int)$item['product_id'], (string)$item['unique'], (int)$item['num'], $orderId);
            }
            Db::name('store_order')->where('id', (int)$order['id'])->update([
                'fmcg_inventory_locked' => 0,
            ]);
        }
        app()->make(BuyXGetXCampaignServices::class)->markPaid($orderId);
        app()->make(DeliveryFeeRecordServices::class)->createSettlementPlanForOrder($order);
        app()->make(DistributorServices::class)->recordShareEvent((int)$distributorId, 'order_paid', (int)$order['uid'], '', $orderId, (float)$order['pay_price']);
    }

    public function afterOrderDelivered($order): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        if (empty($order['order_id']) || empty($order['distributor_id'])) {
            return;
        }
        app()->make(BuyXGetXCampaignServices::class)->markFulfilled((string)$order['order_id']);
    }

    public function afterOrderReceived($order): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        if (empty($order['order_id']) || empty($order['distributor_id'])) {
            return;
        }
        app()->make(BuyXGetXCampaignServices::class)->markFulfilled((string)$order['order_id']);
    }

    public function releaseLockedInventory($order): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        if (empty($order['id']) || empty($order['order_id']) || empty($order['distributor_id'])) {
            return;
        }
        app()->make(BuyXGetXCampaignServices::class)->releaseOrder((string)$order['order_id']);
        if (empty($order['fmcg_inventory_locked'])) {
            return;
        }
        $inventory = app()->make(DistributorInventoryServices::class);
        foreach ($this->orderItems((int)$order['id']) as $item) {
            $inventory->release((int)$order['distributor_id'], (int)$item['product_id'], (string)$item['unique'], (int)$item['num'], (string)$order['order_id']);
        }
        Db::name('store_order')->where('id', (int)$order['id'])->update([
            'fmcg_inventory_locked' => 0,
        ]);
    }

    public function restoreRefundedInventory($order, string $refundNo = ''): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        if (!empty($order['id']) && (!array_key_exists('paid', $order) || !array_key_exists('distributor_id', $order))) {
            $latest = Db::name('store_order')->where('id', (int)$order['id'])->find();
            $order = array_merge($latest ?: [], $order);
        }
        if (empty($order['id']) || empty($order['order_id']) || empty($order['distributor_id']) || (int)($order['paid'] ?? 0) !== 1) {
            return;
        }
        $hasRefundNo = $refundNo !== '';
        $refundNo = $hasRefundNo ? $refundNo : ('refund_' . (string)$order['order_id']);
        $items = $this->refundedItems((int)$order['id'], $refundNo);
        if (!$items && !$hasRefundNo) {
            $items = $this->orderItems((int)$order['id']);
        }
        if (!$items) {
            return;
        }
        $inventory = app()->make(DistributorInventoryServices::class);
        foreach ($items as $item) {
            $inventory->refundPaid(
                (int)$order['distributor_id'],
                (int)$item['product_id'],
                (string)$item['unique'],
                (int)$item['num'],
                (string)$order['order_id'],
                $refundNo
            );
        }
    }

    public function releaseGiftQuotaByOrderId(string $orderId): void
    {
        app()->make(BuyXGetXCampaignServices::class)->releaseOrder($orderId);
    }

    public function cancelGiftUsageByOrderId(string $orderId): void
    {
        app()->make(BuyXGetXCampaignServices::class)->cancelOrder($orderId);
    }

    protected function orderItems(int $orderId): array
    {
        $rows = Db::name('store_order_cart_info')->where('oid', $orderId)->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $cartInfo = json_decode((string)($row['cart_info'] ?? ''), true) ?: [];
            if (!empty($cartInfo['is_gift']) || !empty($cartInfo['fmcg_is_gift'])) {
                continue;
            }
            $productInfo = $cartInfo['productInfo'] ?? [];
            $attrInfo = $productInfo['attrInfo'] ?? [];
            $items[] = [
                'product_id' => (int)($row['product_id'] ?: ($productInfo['id'] ?? $productInfo['product_id'] ?? 0)),
                'unique' => (string)($cartInfo['product_attr_unique'] ?? $cartInfo['unique'] ?? $attrInfo['unique'] ?? ''),
                'num' => (int)($row['cart_num'] ?: ($cartInfo['cart_num'] ?? 0)),
            ];
        }
        return array_values(array_filter($items, function ($item) {
            return $item['product_id'] > 0 && $item['num'] > 0;
        }));
    }

    protected function refundedItems(int $orderId, string $refundNo): array
    {
        $refund = Db::name('store_order_refund')
            ->where('order_id', $refundNo)
            ->find();
        if (!$refund || empty($refund['cart_info'])) {
            return [];
        }
        $cartInfo = json_decode((string)$refund['cart_info'], true);
        if (!is_array($cartInfo)) {
            return [];
        }
        $items = [];
        foreach ($cartInfo as $row) {
            if (is_string($row)) {
                $row = json_decode($row, true);
            }
            if (!is_array($row)) {
                continue;
            }
            $item = $this->normalizeRefundCartRow($row);
            if (!$item) {
                continue;
            }
            $key = $item['product_id'] . '|' . $item['unique'];
            if (!isset($items[$key])) {
                $items[$key] = ['product_id' => $item['product_id'], 'unique' => $item['unique'], 'num' => 0];
            }
            $items[$key]['num'] += $item['num'];
        }
        return array_values($items);
    }

    protected function normalizeRefundCartRow(array $row): array
    {
        if (!empty($row['is_gift']) || !empty($row['fmcg_is_gift'])) {
            return [];
        }
        $productInfo = $row['productInfo'] ?? [];
        $attrInfo = $productInfo['attrInfo'] ?? [];
        if (!empty($productInfo['is_gift']) || !empty($attrInfo['is_gift'])) {
            return [];
        }
        $productId = (int)($row['product_id'] ?? $productInfo['id'] ?? $productInfo['product_id'] ?? 0);
        $unique = (string)($row['product_attr_unique'] ?? $row['unique'] ?? $attrInfo['unique'] ?? '');
        $num = (int)($row['cart_num'] ?? $row['refund_num'] ?? $row['num'] ?? 0);
        if ($productId <= 0 || $num <= 0) {
            return [];
        }
        return ['product_id' => $productId, 'unique' => $unique, 'num' => $num];
    }

    protected function appendGiftItems(int $oid, int $uid, string $orderId): void
    {
        $usages = Db::name('buy_x_get_x_usage')->where('order_id', $orderId)->where('status', 'reserved')->select()->toArray();
        if (!$usages) {
            return;
        }
        $exists = Db::name('store_order_cart_info')->where('oid', $oid)->whereLike('cart_id', 'gift_%')->count();
        if ($exists) {
            return;
        }
        $sourceRows = Db::name('store_order_cart_info')->where('oid', $oid)->select()->toArray();
        $sourceByProduct = [];
        foreach ($sourceRows as $row) {
            $sourceByProduct[(int)$row['product_id']] = $row;
        }
        $rows = [];
        foreach ($usages as $usage) {
            $productId = (int)$usage['product_id'];
            $source = $sourceByProduct[$productId] ?? null;
            $cartInfo = $source ? (json_decode((string)$source['cart_info'], true) ?: []) : [];
            $productInfo = $cartInfo['productInfo'] ?? Db::name('store_product')->where('id', $productId)->find() ?: [];
            if (!isset($cartInfo['productInfo'])) {
                $cartInfo['productInfo'] = $productInfo;
            }
            $giftNum = (int)$usage['gift_num'];
            $cartInfo['id'] = 'gift_' . (int)$usage['id'];
            $cartInfo['cart_num'] = $giftNum;
            $cartInfo['is_gift'] = 1;
            $cartInfo['fmcg_is_gift'] = 1;
            $cartInfo['gift_type'] = 'buy_x_get_x';
            $cartInfo['buy_x_get_x_usage_id'] = (int)$usage['id'];
            $cartInfo['truePrice'] = 0;
            $cartInfo['sum_price'] = 0;
            $cartInfo['sum_true_price'] = 0;
            $cartInfo['vip_truePrice'] = 0;
            $rows[] = [
                'oid' => $oid,
                'uid' => $uid,
                'cart_id' => 'gift_' . (int)$usage['id'],
                'product_id' => $productId,
                'cart_info' => json_encode($cartInfo, JSON_UNESCAPED_UNICODE),
                'cart_num' => $giftNum,
                'surplus_num' => $giftNum,
                'unique' => md5('gift_' . (int)$usage['id'] . '_' . $oid),
            ];
        }
        if ($rows) {
            Db::name('store_order_cart_info')->insertAll($rows);
            app()->make(StoreOrderCartInfoServices::class)->clearOrderCartInfo($oid);
        }
    }

    protected function normalizePayloadItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            $unique = (string)($item['unique'] ?? $item['product_attr_unique'] ?? '');
            $num = (int)($item['num'] ?? $item['cart_num'] ?? 0);
            if ($productId > 0 && $num > 0) {
                $key = $productId . '|' . $unique;
                if (!isset($normalized[$key])) {
                    $normalized[$key] = ['product_id' => $productId, 'unique' => $unique, 'num' => 0];
                }
                $normalized[$key]['num'] += $num;
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }

    protected function normalizeCartItems(array $cartInfo): array
    {
        $normalized = [];
        foreach ($cartInfo as $cart) {
            $productInfo = $cart['productInfo'] ?? [];
            $attrInfo = $productInfo['attrInfo'] ?? [];
            $productId = (int)($cart['product_id'] ?? $productInfo['id'] ?? $productInfo['product_id'] ?? 0);
            $unique = (string)($cart['product_attr_unique'] ?? $cart['unique'] ?? $attrInfo['unique'] ?? '');
            $num = (int)($cart['cart_num'] ?? $cart['num'] ?? 0);
            if ($productId > 0 && $num > 0) {
                $key = $productId . '|' . $unique;
                if (!isset($normalized[$key])) {
                    $normalized[$key] = ['product_id' => $productId, 'unique' => $unique, 'num' => 0];
                }
                $normalized[$key]['num'] += $num;
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }

    protected function normalizeDelivery(array $payload, int $distributorId = 0): array
    {
        /** @var DeliveryFeeCalculatorServices $calculator */
        $calculator = app()->make(DeliveryFeeCalculatorServices::class);
        $deliveryType = (string)($payload['fmcg_delivery_type'] ?? $payload['delivery_type'] ?? '');
        $deliveryType = $calculator->normalizeType($deliveryType);
        $deliveryFee = $calculator->calculate($deliveryType, $distributorId, $payload);
        return [$deliveryType, $deliveryFee];
    }
}
