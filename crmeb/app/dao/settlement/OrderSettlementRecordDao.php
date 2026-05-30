<?php
namespace app\dao\settlement;

use app\dao\BaseDao;
use app\model\settlement\OrderSettlementRecord;

class OrderSettlementRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrderSettlementRecord::class;
    }
}
