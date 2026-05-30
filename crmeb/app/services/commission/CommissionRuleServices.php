<?php
namespace app\services\commission;

use app\dao\commission\CommissionRuleDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;

class CommissionRuleServices extends BaseServices
{
    public function __construct(CommissionRuleDao $dao)
    {
        $this->dao = $dao;
    }

    public function saveRule(array $data)
    {
        $rate = round((float)($data['rate'] ?? 0), 4);
        if ($rate < 0 || $rate > 1) {
            throw new ApiException('佣金比例需为0到1之间的小数，例如0.1000表示10%');
        }
        $where = [
            'distributor_id' => (int)($data['distributor_id'] ?? 0),
            'product_id' => (int)($data['product_id'] ?? 0),
            'unique' => (string)($data['unique'] ?? ''),
        ];
        $row = $this->dao->getOne($where);
        $payload = array_merge($where, [
            'template_name' => $data['template_name'] ?? '固定模板',
            'rate' => $rate,
            'status' => (int)($data['status'] ?? 1),
            'update_time' => time(),
        ]);
        if ($row) {
            $row->save($payload);
            return $row;
        }
        $payload['add_time'] = time();
        return $this->dao->save($payload);
    }
}
