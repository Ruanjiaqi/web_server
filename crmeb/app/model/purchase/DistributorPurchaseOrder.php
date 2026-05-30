<?php
namespace app\model\purchase;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class DistributorPurchaseOrder extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor_purchase_order';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchStatusAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('status', $value);
        }
    }

    public function searchOrderNoAttr(Model $query, $value)
    {
        $query->where('order_no', $value);
    }
}
