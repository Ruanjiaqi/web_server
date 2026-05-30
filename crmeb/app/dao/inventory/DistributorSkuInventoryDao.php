<?php
namespace app\dao\inventory;

use app\dao\BaseDao;
use app\model\inventory\DistributorSkuInventory;

class DistributorSkuInventoryDao extends BaseDao
{
    protected function setModel(): string
    {
        return DistributorSkuInventory::class;
    }

    public function getSku(int $distributorId, int $productId, string $unique = '')
    {
        $where = ['distributor_id' => $distributorId, 'product_id' => $productId];
        if ($unique !== '') {
            $where['unique'] = $unique;
        }
        return $this->getOne($where);
    }
}
