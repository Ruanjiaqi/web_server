<?php
namespace app\dao\purchase;

use app\dao\BaseDao;
use app\model\purchase\DistributorPurchaseOrder;

class DistributorPurchaseOrderDao extends BaseDao
{
    protected function setModel(): string
    {
        return DistributorPurchaseOrder::class;
    }
}
