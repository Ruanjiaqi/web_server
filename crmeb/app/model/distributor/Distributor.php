<?php
namespace app\model\distributor;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class Distributor extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor';
    protected $autoWriteTimestamp = false;

    public function searchIdAttr(Model $query, $value)
    {
        $query->where('id', $value);
    }

    public function searchUidAttr(Model $query, $value)
    {
        $query->where('uid', $value);
    }

    public function searchKeywordAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->whereLike('store_name|contact_name|phone|identify_code', '%' . $value . '%');
        }
    }

    public function searchCooperationModeAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('cooperation_mode', $value);
        }
    }

    public function searchStatusAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->where('status', $value);
        }
    }
}
