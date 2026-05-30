<?php
namespace app\dao\commission;

use app\dao\BaseDao;
use app\model\commission\CommissionApply;

class CommissionApplyDao extends BaseDao
{
    protected function setModel(): string
    {
        return CommissionApply::class;
    }
}
