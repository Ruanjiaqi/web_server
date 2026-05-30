<?php
namespace app\dao\purchase;

use app\dao\BaseDao;
use app\model\purchase\DistributorPurchaseOrderItem;

class DistributorPurchaseOrderItemDao extends BaseDao
{
    protected function setModel(): string
    {
        return DistributorPurchaseOrderItem::class;
    }
}
