<?php
namespace app\model\purchase;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;

class DistributorPurchaseOrderItem extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor_purchase_order_item';
    protected $autoWriteTimestamp = false;
}
