<?php
namespace app\model\distributor;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class DistributorUserBind extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor_user_bind';
    protected $autoWriteTimestamp = false;

    public function searchUidAttr(Model $query, $value)
    {
        $query->where('uid', $value);
    }

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }
}
