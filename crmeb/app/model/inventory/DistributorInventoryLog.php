<?php
namespace app\model\inventory;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class DistributorInventoryLog extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor_inventory_log';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchProductIdAttr(Model $query, $value)
    {
        $query->where('product_id', $value);
    }

    public function searchBizNoAttr(Model $query, $value)
    {
        $query->where('biz_no', $value);
    }
}
