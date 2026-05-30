<?php
namespace app\dao\distributor;

use app\dao\BaseDao;
use app\model\distributor\DistributorUserBind;

class DistributorUserBindDao extends BaseDao
{
    protected function setModel(): string
    {
        return DistributorUserBind::class;
    }
}
