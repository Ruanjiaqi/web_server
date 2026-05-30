<?php
namespace app\model\distributor;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class BuyXGetXCampaign extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'buy_x_get_x_campaign';
    protected $autoWriteTimestamp = false;

    public function searchStatusAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('status', $value);
        }
    }
}
