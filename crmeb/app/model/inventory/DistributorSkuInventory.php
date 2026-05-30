<?php
namespace app\model\inventory;

use crmeb\basic\BaseModel;
use crmeb\traits\ModelTrait;
use think\Model;

class DistributorSkuInventory extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'distributor_sku_inventory';
    protected $autoWriteTimestamp = false;

    public function searchDistributorIdAttr(Model $query, $value)
    {
        $query->where('distributor_id', $value);
    }

    public function searchProductIdAttr(Model $query, $value)
    {
        $query->where('product_id', $value);
    }

    public function searchUniqueAttr(Model $query, $value)
    {
        $query->where('unique', $value);
    }

    public function searchWarningAttr(Model $query, $value)
    {
        if ($value !== '') {
            $query->whereRaw('stock - locked_stock <= warning_stock');
        }
    }
}
