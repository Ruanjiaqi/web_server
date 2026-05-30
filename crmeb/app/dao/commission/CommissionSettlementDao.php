<?php
namespace app\dao\commission;

use app\dao\BaseDao;
use app\model\commission\CommissionSettlement;

class CommissionSettlementDao extends BaseDao
{
    protected function setModel(): string
    {
        return CommissionSettlement::class;
    }
}
