<?php
namespace app\services\commission;

use app\dao\commission\CommissionSettlementDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class CommissionSettlementServices extends BaseServices
{
    public function __construct(CommissionSettlementDao $dao)
    {
        $this->dao = $dao;
    }

    public function eligibleOrders(int $distributorId, int $refundDays = 7): array
    {
        return $this->settleableOrderQuery($distributorId, $refundDays)
            ->field('o.id,o.order_id,o.uid,o.pay_price,o.pay_postage,o.pay_time,o.status,o.refund_status,' . $this->completionTimeExpression('o') . ' as completion_time')
            ->order('completion_time asc')
            ->select()
            ->toArray();
    }

    public function createManualSettlement(int $distributorId, array $orderIds, int $adminId = 0, int $refundDays = 7)
    {
        $orderIds = array_values(array_filter(array_unique(array_map('strval', $orderIds))));
        if (!$orderIds) {
            throw new ApiException('请选择需要结算的订单');
        }
        return $this->transaction(function () use ($distributorId, $orderIds, $adminId, $refundDays) {
            $orders = $this->settleableOrderQuery($distributorId, $refundDays)
                ->whereIn('o.order_id', $orderIds)
                ->lock(true)
                ->select()
                ->toArray();
            $found = array_column($orders, 'order_id');
            $missing = array_diff($orderIds, $found);
            if ($missing) {
                throw new ApiException('存在不可结算或已结算订单');
            }
            $rows = [];
            foreach ($orders as $order) {
                if (Db::name('commission_settlement')->where('order_id', (string)$order['order_id'])->count()) {
                    throw new ApiException('订单已生成佣金结算');
                }
                $amount = $this->calculateOrderCommission($distributorId, $order);
                if ($amount <= 0) {
                    throw new ApiException('订单无可结算佣金');
                }
                $rows[] = [
                    'settlement_no' => 'CS' . date('YmdHis') . random_int(1000, 9999),
                    'distributor_id' => $distributorId,
                    'order_id' => $order['order_id'],
                    'amount' => $amount,
                    'status' => 'pending',
                    'admin_id' => $adminId,
                    'add_time' => time(),
                    'update_time' => time(),
                ];
            }
            if ($rows) {
                $this->dao->saveAll($rows);
            }
            return $rows;
        });
    }

    public function settlementList(array $where = []): array
    {
        [$page, $limit] = $this->getPageValue();
        $filter = [];
        if (!empty($where['distributor_id'])) {
            $filter['distributor_id'] = (int)$where['distributor_id'];
        }
        if (($where['status'] ?? '') !== '') {
            $filter['status'] = (string)$where['status'];
        }
        return [
            'count' => $this->dao->count($filter),
            'list' => $this->dao->selectList($filter, '*', $page, $limit, 'id desc')->toArray(),
        ];
    }

    public function updateStatus(int $id, string $status)
    {
        if (!in_array($status, ['pending', 'paid', 'rejected', 'refund_pending', 'refunded'], true)) {
            throw new ApiException('佣金结算状态不支持');
        }
        $settlement = $this->dao->get($id);
        if (!$settlement) {
            throw new ApiException('佣金结算记录不存在');
        }
        if ($status === 'paid' && in_array((string)$settlement['status'], ['refund_pending', 'refunded'], true)) {
            throw new ApiException('退款中或已退款的佣金结算不能标记为已支付');
        }
        if ($status === 'paid' && $this->orderHasRefundRisk((string)$settlement['order_id'])) {
            throw new ApiException('订单已进入退款流程，不能标记为已支付');
        }
        $data = [
            'status' => $status,
            'update_time' => time(),
        ];
        if ($status === 'paid') {
            $data['pay_time'] = time();
        }
        return $this->dao->update($id, $data);
    }

    protected function settleableOrderQuery(int $distributorId, int $refundDays)
    {
        $this->assertCommissionDistributor($distributorId);
        $before = time() - max(0, $refundDays) * 86400;
        $completionTime = $this->completionTimeExpression('o');
        return Db::name('store_order')
            ->alias('o')
            ->where('o.distributor_id', $distributorId)
            ->where('o.paid', 1)
            ->where('o.refund_status', 0)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->whereIn('o.status', [2, 3, 4])
            ->whereRaw($completionTime . ' <= ' . (int)$before)
            ->whereNotIn('o.order_id', function ($query) {
                $query->name('commission_settlement')->field('order_id');
            })
            ->whereNotIn('o.id', function ($query) {
                $query->name('store_order_refund')
                    ->whereIn('refund_type', [1, 2, 4, 5])
                    ->where('is_cancel', 0)
                    ->where('is_del', 0)
                    ->field('store_order_id');
            });
    }

    protected function completionTimeExpression(string $orderAlias): string
    {
        $prefix = config('database.connections.' . config('database.default') . '.prefix') ?: '';
        $statusTable = $prefix . 'store_order_status';
        return "IFNULL(NULLIF((SELECT MAX(sos.change_time) FROM `{$statusTable}` sos WHERE sos.oid = {$orderAlias}.id AND sos.change_type IN ('check_order_over','take_delivery','user_take_delivery')), 0), IF({$orderAlias}.status IN (3,4), {$orderAlias}.pay_time, 0))";
    }

    protected function orderHasRefundRisk(string $orderId): bool
    {
        $order = Db::name('store_order')->where('order_id', $orderId)->field('id,refund_status')->find();
        if (!$order) {
            return true;
        }
        if ((int)$order['refund_status'] !== 0) {
            return true;
        }
        return Db::name('store_order_refund')
            ->where('store_order_id', (int)$order['id'])
            ->whereIn('refund_type', [1, 2, 4, 5])
            ->where('is_cancel', 0)
            ->where('is_del', 0)
            ->count() > 0;
    }

    protected function assertCommissionDistributor(int $distributorId): array
    {
        $distributor = Db::name('distributor')
            ->where('id', $distributorId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->where('cooperation_mode', 'commission')
            ->find();
        if (!$distributor) {
            throw new ApiException('分销商不存在、已禁用或不是佣金合作模式');
        }
        return $distributor;
    }

    protected function calculateOrderCommission(int $distributorId, array $order): float
    {
        $rows = Db::name('store_order_cart_info')->where('oid', (int)$order['id'])->select()->toArray();
        $amount = 0.0;
        foreach ($rows as $row) {
            $cartInfo = json_decode((string)($row['cart_info'] ?? ''), true) ?: [];
            if (!empty($cartInfo['is_gift']) || !empty($cartInfo['fmcg_is_gift'])) {
                continue;
            }
            $productInfo = $cartInfo['productInfo'] ?? [];
            $attrInfo = $productInfo['attrInfo'] ?? [];
            $productId = (int)($row['product_id'] ?: ($productInfo['id'] ?? $productInfo['product_id'] ?? 0));
            $unique = (string)($cartInfo['product_attr_unique'] ?? $cartInfo['unique'] ?? $attrInfo['unique'] ?? '');
            $num = (int)($row['cart_num'] ?: ($cartInfo['cart_num'] ?? 0));
            if ($productId <= 0 || $num <= 0) {
                continue;
            }
            $rate = $this->matchSkuRuleRate($distributorId, $productId, $unique);
            if ($rate <= 0) {
                continue;
            }
            $lineAmount = (float)($cartInfo['sum_true_price'] ?? 0);
            if ($lineAmount <= 0) {
                $lineAmount = (float)($cartInfo['truePrice'] ?? $attrInfo['price'] ?? 0) * $num;
            }
            $amount += $lineAmount * $rate;
        }
        return round($amount, 2);
    }

    protected function matchSkuRuleRate(int $distributorId, int $productId, string $unique): float
    {
        $rules = Db::name('commission_rule')
            ->where('status', 1)
            ->whereIn('distributor_id', [$distributorId, 0])
            ->whereIn('product_id', [$productId, 0])
            ->whereIn('unique', [$unique, ''])
            ->orderRaw("FIELD(distributor_id, {$distributorId}, 0), FIELD(product_id, {$productId}, 0), FIELD(`unique`, '" . addslashes($unique) . "', '')")
            ->select()
            ->toArray();
        foreach ($rules as $rule) {
            if ((int)$rule['distributor_id'] === $distributorId && (int)$rule['product_id'] === $productId && (string)$rule['unique'] === $unique) {
                return (float)$rule['rate'];
            }
        }
        foreach ($rules as $rule) {
            if ((int)$rule['distributor_id'] === $distributorId && (int)$rule['product_id'] === $productId && (string)$rule['unique'] === '') {
                return (float)$rule['rate'];
            }
        }
        foreach ($rules as $rule) {
            if ((int)$rule['distributor_id'] === 0 && (int)$rule['product_id'] === $productId && in_array((string)$rule['unique'], [$unique, ''], true)) {
                return (float)$rule['rate'];
            }
        }
        foreach ($rules as $rule) {
            if ((int)$rule['distributor_id'] === 0 && (int)$rule['product_id'] === 0) {
                return (float)$rule['rate'];
            }
        }
        return 0.0;
    }
}
