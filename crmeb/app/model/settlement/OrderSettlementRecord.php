<?php
namespace app\model\settlement;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class OrderSettlementRecord extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'order_settlement_record';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchOrderIdAttr(Model $query, $value)
    {
        $query->where('order_id', $value);
    }
}
