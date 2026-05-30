<?php
namespace app\services\distributor;

use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class BuyXGetXCampaignServices extends BaseServices
{
    public function publish(array $data): int
    {
        $productId = (int)($data['product_id'] ?? 0);
        $buyNum = (int)($data['buy_num'] ?? 0);
        $giftNum = (int)($data['gift_num'] ?? 0);
        if ($productId <= 0 || $buyNum <= 0 || $giftNum <= 0) {
            throw new ApiException('买赠活动商品和数量配置不完整');
        }
        $id = (int)($data['id'] ?? 0);
        $payload = [
            'title' => (string)($data['title'] ?? '买赠活动'),
            'product_id' => $productId,
            'buy_num' => $buyNum,
            'gift_num' => $giftNum,
            'quota' => max(0, (int)($data['quota'] ?? 0)),
            'start_time' => (int)($data['start_time'] ?? time()),
            'end_time' => (int)($data['end_time'] ?? 0),
            'status' => (int)($data['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($id > 0) {
            Db::name('buy_x_get_x_campaign')->where('id', $id)->update($payload);
            return $id;
        }
        $payload['used_quota'] = 0;
        $payload['add_time'] = time();
        return (int)Db::name('buy_x_get_x_campaign')->insertGetId($payload);
    }

    public function activeCampaigns(array $productIds): array
    {
        $productIds = array_values(array_filter(array_unique(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $now = time();
        return Db::name('buy_x_get_x_campaign')
            ->whereIn('product_id', $productIds)
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->where('end_time', 0)->whereOr('end_time', '>=', $now);
            })
            ->order('id desc')
            ->select()
            ->toArray();
    }

    public function reserveQuotaForOrder(string $orderId, array $items): void
    {
        $productNums = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $productNums[$productId] = ($productNums[$productId] ?? 0) + (int)($item['num'] ?? 0);
        }
        foreach ($this->activeCampaigns(array_keys($productNums)) as $campaign) {
            $buyTimes = intdiv((int)($productNums[(int)$campaign['product_id']] ?? 0), max(1, (int)$campaign['buy_num']));
            $giftTotal = $buyTimes * (int)$campaign['gift_num'];
            if ($giftTotal <= 0) {
                continue;
            }
            $this->transaction(function () use ($campaign, $orderId, $giftTotal) {
                $fresh = Db::name('buy_x_get_x_campaign')->where('id', (int)$campaign['id'])->lock(true)->find();
                if (!$fresh || !(int)$fresh['status']) {
                    return;
                }
                $quota = (int)$fresh['quota'];
                $available = $quota <= 0 ? $giftTotal : max(0, $quota - (int)$fresh['used_quota']);
                $giftNum = min($giftTotal, $available);
                if ($giftNum <= 0) {
                    return;
                }
                if (Db::name('buy_x_get_x_usage')->where('order_id', $orderId)->where('campaign_id', (int)$fresh['id'])->count()) {
                    return;
                }
                Db::name('buy_x_get_x_usage')->insert([
                    'campaign_id' => (int)$fresh['id'],
                    'order_id' => $orderId,
                    'product_id' => (int)$fresh['product_id'],
                    'gift_num' => $giftNum,
                    'status' => 'reserved',
                    'add_time' => time(),
                    'update_time' => time(),
                ]);
                Db::name('buy_x_get_x_campaign')->where('id', (int)$fresh['id'])->inc('used_quota', $giftNum)->update();
            });
        }
    }

    public function releaseOrder(string $orderId): void
    {
        $rows = Db::name('buy_x_get_x_usage')->where('order_id', $orderId)->whereIn('status', ['reserved', 'paid', 'confirmed'])->select()->toArray();
        foreach ($rows as $row) {
            $this->transaction(function () use ($row) {
                Db::name('buy_x_get_x_usage')->where('id', (int)$row['id'])->update([
                    'status' => 'released',
                    'update_time' => time(),
                ]);
                Db::name('buy_x_get_x_campaign')->where('id', (int)$row['campaign_id'])->update([
                    'used_quota' => Db::raw('GREATEST(used_quota - ' . (int)$row['gift_num'] . ', 0)'),
                    'update_time' => time(),
                ]);
            });
        }
    }

    public function markPaid(string $orderId): void
    {
        $this->markOrderStatus($orderId, 'paid', ['reserved']);
    }

    public function markConfirmed(string $orderId): void
    {
        $this->markOrderStatus($orderId, 'confirmed', ['reserved', 'paid']);
    }

    public function markFulfilled(string $orderId): void
    {
        $this->markOrderStatus($orderId, 'fulfilled', ['reserved', 'paid', 'confirmed']);
    }

    public function cancelOrder(string $orderId): void
    {
        $rows = Db::name('buy_x_get_x_usage')
            ->where('order_id', $orderId)
            ->whereIn('status', ['reserved', 'paid', 'confirmed', 'fulfilled'])
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $this->transaction(function () use ($row) {
                $affected = Db::name('buy_x_get_x_usage')
                    ->where('id', (int)$row['id'])
                    ->whereIn('status', ['reserved', 'paid', 'confirmed', 'fulfilled'])
                    ->update([
                        'status' => 'canceled',
                        'update_time' => time(),
                    ]);
                if ($affected) {
                    Db::name('buy_x_get_x_campaign')->where('id', (int)$row['campaign_id'])->update([
                        'used_quota' => Db::raw('GREATEST(used_quota - ' . (int)$row['gift_num'] . ', 0)'),
                        'update_time' => time(),
                    ]);
                }
            });
        }
    }

    protected function markOrderStatus(string $orderId, string $status, array $fromStatuses): void
    {
        Db::name('buy_x_get_x_usage')
            ->where('order_id', $orderId)
            ->whereIn('status', $fromStatuses)
            ->update([
                'status' => $status,
                'update_time' => time(),
            ]);
    }
}
