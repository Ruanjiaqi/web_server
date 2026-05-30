<?php
namespace app\services\purchase;

use app\dao\purchase\DistributorPurchaseOrderDao;
use app\dao\purchase\DistributorPurchaseOrderItemDao;
use app\services\BaseServices;
use app\services\inventory\DistributorInventoryServices;
use app\services\pay\PayServices;
use app\services\wechat\WechatUserServices;
use crmeb\exceptions\ApiException;
use crmeb\utils\Str;
use think\facade\Db;

class DistributorPurchaseOrderServices extends BaseServices
{
    public function __construct(DistributorPurchaseOrderDao $dao)
    {
        $this->dao = $dao;
    }

    public function createOrder(int $distributorId, array $items, string $remark = '')
    {
        if (!$items) {
            throw new ApiException('请选择订货商品');
        }
        return $this->transaction(function () use ($distributorId, $items, $remark) {
            $totalNum = 0;
            $totalAmount = '0.00';
            $snapshots = $this->normalizePurchaseItems($items);
            foreach ($snapshots as $item) {
                $num = (int)$item['num'];
                if ($num <= 0) {
                    throw new ApiException('订货数量必须大于0');
                }
                $totalNum += $num;
                $totalAmount = bcadd($totalAmount, bcmul((string)$item['price'], (string)$num, 2), 2);
            }
            $order = $this->dao->save([
                'order_no' => 'PO' . date('YmdHis') . random_int(1000, 9999),
                'distributor_id' => $distributorId,
                'total_num' => $totalNum,
                'total_amount' => $totalAmount,
                'status' => 'pending_pay',
                'remark' => $remark,
                'add_time' => time(),
                'update_time' => time(),
            ]);
            $itemDao = app()->make(DistributorPurchaseOrderItemDao::class);
            foreach ($snapshots as $item) {
                $itemDao->save([
                    'purchase_order_id' => $order->id,
                    'product_id' => (int)$item['product_id'],
                    'unique' => (string)$item['unique'],
                    'product_name' => (string)$item['product_name'],
                    'num' => (int)$item['num'],
                    'price' => (string)$item['price'],
                    'add_time' => time(),
                ]);
            }
            return $order;
        });
    }

    public function markPaid(int $id, string $payNo = '')
    {
        $order = $this->dao->get($id);
        if (!$order || $order['status'] !== 'pending_pay') {
            throw new ApiException('订货单状态不可支付');
        }
        $order->status = 'paid';
        $order->pay_no = $payNo;
        $order->pay_time = time();
        $order->update_time = time();
        $order->save();
        return $order;
    }

    public function cancel(int $id, int $distributorId = 0)
    {
        $order = $this->dao->get($id);
        if (!$order || ($distributorId > 0 && (int)$order['distributor_id'] !== $distributorId)) {
            throw new ApiException('订货单不存在');
        }
        if ($order['status'] !== 'pending_pay') {
            throw new ApiException('订货单状态不可取消');
        }
        $order->status = 'canceled';
        $order->update_time = time();
        $order->save();
        return $order;
    }

    public function createPayParams(int $id, int $uid, string $payType = PayServices::WEIXIN_PAY): array
    {
        $order = $this->dao->get($id);
        if (!$order || $order['status'] !== 'pending_pay') {
            throw new ApiException('订货单状态不可支付');
        }
        $distributor = Db::name('distributor')->where('id', (int)$order['distributor_id'])->where('uid', $uid)->find();
        if (!$distributor) {
            throw new ApiException('订货单不存在');
        }
        $options = [];
        if ($payType === PayServices::WEIXIN_PAY && (request()->isWechat() || request()->isRoutine())) {
            $userType = request()->isWechat() ? 'wechat' : 'routine';
            $openid = app()->make(WechatUserServices::class)->uidToOpenid($uid, $userType);
            if (!$openid) {
                throw new ApiException('获取用户openid失败,无法支付');
            }
            $options['openid'] = $openid;
        }
        $body = Str::substrUTf8(sys_config('site_name') . '--分销商订货款', 20);
        $params = app()->make(PayServices::class)->pay($payType, (string)$order['order_no'], (string)$order['total_amount'], 'fmcg_purchase', $body, $options);
        return [
            'order_no' => $order['order_no'],
            'pay_type' => $payType,
            'pay_price' => (string)$order['total_amount'],
            'jsConfig' => $params,
        ];
    }

    public function markPaidByPayment(string $orderNo, string $tradeNo = '', string $payType = PayServices::WEIXIN_PAY)
    {
        $order = $this->dao->getOne(['order_no' => $orderNo]);
        if (!$order) {
            return true;
        }
        if ($order['status'] !== 'pending_pay') {
            return true;
        }
        $data = [
            'status' => 'paid',
            'pay_no' => $tradeNo,
            'pay_time' => time(),
            'update_time' => time(),
        ];
        if ($this->hasColumn('distributor_purchase_order', 'trade_no')) {
            $data['trade_no'] = $tradeNo;
        }
        return $this->dao->update((int)$order['id'], $data);
    }

    public function ship(int $id, array $data)
    {
        $order = $this->dao->get($id);
        if (!$order || $order['status'] !== 'paid') {
            throw new ApiException('订货单状态不可发货');
        }
        $order->status = 'shipped';
        $order->delivery_type = $data['delivery_type'] ?? 'offline';
        $order->express_name = $data['express_name'] ?? '';
        $order->express_no = $data['express_no'] ?? '';
        $order->ship_time = time();
        $order->update_time = time();
        $order->save();
        return $order;
    }

    public function receive(int $id)
    {
        $order = $this->dao->get($id);
        if (!$order || $order['status'] !== 'shipped') {
            throw new ApiException('订货单状态不可收货');
        }
        return $this->transaction(function () use ($order) {
            $items = app()->make(DistributorPurchaseOrderItemDao::class)->selectList(['purchase_order_id' => $order->id])->toArray();
            $inventory = app()->make(DistributorInventoryServices::class);
            foreach ($items as $item) {
                $inventory->increase((int)$order->distributor_id, (int)$item['product_id'], (string)$item['unique'], (int)$item['num'], 'purchase_receive', $order->order_no, [
                    'product_name' => (string)$item['product_name'],
                ]);
            }
            $order->status = 'finished';
            $order->receive_time = time();
            $order->update_time = time();
            $order->save();
            return $order;
        });
    }

    protected function normalizePurchaseItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            $unique = (string)($item['unique'] ?? $item['product_attr_unique'] ?? '');
            $num = (int)($item['num'] ?? 0);
            if ($productId <= 0 || $num <= 0) {
                throw new ApiException('订货商品参数错误');
            }
            $snapshot = $this->trustedProductSnapshot($productId, $unique);
            $key = $productId . '|' . $unique;
            if (!isset($normalized[$key])) {
                $normalized[$key] = $snapshot + ['num' => 0];
            }
            $normalized[$key]['num'] += $num;
        }
        if (!$normalized) {
            throw new ApiException('请选择订货商品');
        }
        return array_values($normalized);
    }

    protected function trustedProductSnapshot(int $productId, string $unique): array
    {
        $product = Db::name('store_product')
            ->where('id', $productId)
            ->where('is_show', 1)
            ->where('is_del', 0)
            ->field('id,store_name,price,spec_type')
            ->find();
        if (!$product) {
            throw new ApiException('订货商品不存在或已下架');
        }
        $price = (string)$product['price'];
        if ((int)$product['spec_type'] === 1 || $unique !== '') {
            if ($unique === '') {
                throw new ApiException('请选择商品规格');
            }
            $sku = Db::name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->where('type', 0)
                ->where('is_show', 1)
                ->field('price,suk')
                ->find();
            if (!$sku) {
                throw new ApiException('订货商品规格不存在或已下架');
            }
            $price = (string)$sku['price'];
        }
        return [
            'product_id' => $productId,
            'unique' => $unique,
            'product_name' => (string)$product['store_name'],
            'price' => number_format((float)$price, 2, '.', ''),
        ];
    }

    protected function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array($column, Db::name($table)->getTableFields(), true);
        }
        return $cache[$key];
    }
}
