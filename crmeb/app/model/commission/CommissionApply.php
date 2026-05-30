<?php
namespace app\model\commission;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class CommissionApply extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'commission_apply';
    protected $autoWriteTimestamp = false;

    public function searchUidAttr(Model $query, $value)
    {
        $query->where('uid', $value);
    }

    public function searchStatusAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('status', $value);
        }
    }
}
