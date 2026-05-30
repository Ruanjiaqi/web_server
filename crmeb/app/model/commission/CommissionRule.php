<?php
namespace app\model\commission;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class CommissionRule extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'commission_rule';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchProductIdAttr(Model $query, $value)
    {
        $query->where('product_id', $value);
    }
}
