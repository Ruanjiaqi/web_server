<?php
namespace app\model\settlement;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class DeliveryFeeRecord extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'delivery_fee_record';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchDeliveryTypeAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('delivery_type', $value);
        }
    }
}
