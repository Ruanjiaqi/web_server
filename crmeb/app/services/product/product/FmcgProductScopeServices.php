<?php
namespace app\services\product\product;

use app\Request;
use app\services\BaseServices;
use app\services\distributor\DistributorServices;
use think\facade\Db;

class FmcgProductScopeServices extends BaseServices
{
    public function boundDistributorId(Request $request): int
    {
        return $this->boundDistributorIdByUid((int)$request->uid());
    }

    public function boundDistributorIdByUid(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }
        $binding = app()->make(DistributorServices::class)->userBinding($uid);
        return (int)($binding['bind']['distributor_id'] ?? 0);
    }

    public function filterListByDistributorInventory(array $list, int $distributorId): array
    {
        return $this->filterListByDistributorInventoryField($list, $distributorId, 'id');
    }

    public function filterListByDistributorInventoryField(array $list, int $distributorId, string $productIdField): array
    {
        if (!$list || $distributorId <= 0) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', array_column($list, $productIdField))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('distributor_sku_inventory')
            ->where('distributor_id', $distributorId)
            ->whereIn('product_id', $ids)
            ->field('product_id,SUM(stock - locked_stock) as available_stock')
            ->group('product_id')
            ->having('available_stock > 0')
            ->select()
            ->toArray();
        $stockMap = array_column($rows, 'available_stock', 'product_id');
        $filtered = [];
        foreach ($list as $item) {
            $productId = (int)($item[$productIdField] ?? 0);
            if ($productId > 0 && isset($stockMap[$productId])) {
                $item['stock'] = (int)$stockMap[$productId];
                $item['fmcg_stock'] = (int)$stockMap[$productId];
                $item['distributor_id'] = $distributorId;
                $filtered[] = $item;
            }
        }
        return $filtered;
    }

    public function productHasDistributorStock(int $distributorId, int $productId): bool
    {
        return $distributorId > 0 && (int)Db::name('distributor_sku_inventory')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->whereRaw('stock - locked_stock > 0')
            ->count() > 0;
    }

    public function trimDetailByDistributorInventory(array $data, int $distributorId, int $productId): array
    {
        $rows = Db::name('distributor_sku_inventory')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->select()
            ->toArray();
        $stockMap = [];
        foreach ($rows as $row) {
            $stockMap[(string)$row['unique']] = max(0, (int)$row['stock'] - (int)$row['locked_stock']);
        }
        $totalStock = array_sum($stockMap);
        $data['distributor_id'] = $distributorId;
        $data['bind_required'] = 0;
        $data['storeInfo']['stock'] = $totalStock;
        $data['storeInfo']['fmcg_stock'] = $totalStock;
        if (!empty($data['productValue']) && is_array($data['productValue'])) {
            foreach ($data['productValue'] as $key => &$sku) {
                $unique = (string)($sku['unique'] ?? $key);
                $sku['stock'] = (int)($stockMap[$unique] ?? 0);
                $sku['fmcg_stock'] = $sku['stock'];
                if ($sku['stock'] <= 0) {
                    $sku['is_show'] = 0;
                }
            }
        }
        if (!empty($data['spec_unique']) && isset($stockMap[(string)$data['spec_unique']])) {
            $data['storeInfo']['stock'] = (int)$stockMap[(string)$data['spec_unique']];
            $data['storeInfo']['fmcg_stock'] = (int)$stockMap[(string)$data['spec_unique']];
        }
        if (!empty($data['good_list']) && is_array($data['good_list'])) {
            $data['good_list'] = $this->filterListByDistributorInventory($data['good_list'], $distributorId);
        }
        return $data;
    }

    public function filterHomeData(array $data, int $uid): array
    {
        $distributorId = $this->boundDistributorIdByUid($uid);
        if (isset($data['list']) && is_array($data['list'])) {
            $data['list'] = $distributorId > 0 ? $this->filterListByDistributorInventory($data['list'], $distributorId) : [];
        }
        $data['bind_required'] = $distributorId > 0 ? 0 : 1;
        $data['distributor_id'] = $distributorId;
        return $data;
    }
}
