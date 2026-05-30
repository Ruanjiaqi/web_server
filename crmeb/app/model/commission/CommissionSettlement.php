<?php
namespace app\model\commission;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class CommissionSettlement extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'commission_settlement';
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
}
