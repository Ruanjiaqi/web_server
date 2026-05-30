<?php
namespace app\dao\commission;

use app\dao\BaseDao;
use app\model\commission\CommissionRule;

class CommissionRuleDao extends BaseDao
{
    protected function setModel(): string
    {
        return CommissionRule::class;
    }
}
