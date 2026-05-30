<?php
namespace app\dao\distributor;

use app\dao\BaseDao;
use app\model\distributor\BuyXGetXCampaign;

class BuyXGetXCampaignDao extends BaseDao
{
    protected function setModel(): string
    {
        return BuyXGetXCampaign::class;
    }
}
