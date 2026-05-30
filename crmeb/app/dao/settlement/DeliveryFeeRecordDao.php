<?php
namespace app\dao\settlement;

use app\dao\BaseDao;
use app\model\settlement\DeliveryFeeRecord;

class DeliveryFeeRecordDao extends BaseDao
{
    protected function setModel(): string
    {
        return DeliveryFeeRecord::class;
    }
}
