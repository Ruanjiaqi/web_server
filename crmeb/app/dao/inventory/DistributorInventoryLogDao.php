<?php
namespace app\dao\inventory;

use app\dao\BaseDao;
use app\model\inventory\DistributorInventoryLog;

class DistributorInventoryLogDao extends BaseDao
{
    protected function setModel(): string
    {
        return DistributorInventoryLog::class;
    }
}
