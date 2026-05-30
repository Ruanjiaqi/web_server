<?php
namespace app\dao\distributor;

use app\dao\BaseDao;
use app\model\distributor\Distributor;

class DistributorDao extends BaseDao
{
    protected function setModel(): string
    {
        return Distributor::class;
    }

    public function getList(array $where, int $page = 0, int $limit = 0): array
    {
        return $this->search($where)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('id desc')->select()->toArray();
    }
}
