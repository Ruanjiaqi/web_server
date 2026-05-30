<?php
namespace app\api\controller\v1\distributor;

use app\Request;
use app\services\commission\CommissionApplyServices;
use app\services\distributor\DistributorServices;
use app\services\inventory\DistributorInventoryServices;
use app\services\pay\PayServices;
use app\services\product\product\FmcgProductScopeServices;
use app\services\purchase\DistributorPurchaseOrderServices;
use app\services\settlement\DeliveryFeeCalculatorServices;
use think\facade\Db;

class DistributorController
{
    protected $services;

    public function __construct(DistributorServices $services)
    {
        $this->services = $services;
    }

    public function binding(Request $request)
    {
        return app('json')->success($this->services->userBinding((int)$request->uid()));
    }

    public function bind(Request $request)
    {
        [$identifyCode, $source] = $request->postMore([
            ['identify_code', ''],
            ['source', 'manual'],
        ], true);
        return app('json')->success('绑定成功', $this->services->bindUser((int)$request->uid(), (string)$identifyCode, (string)$source));
    }

    public function commissionApply(Request $request, CommissionApplyServices $services)
    {
        $data = $request->postMore([
            ['real_name', ''],
            ['phone', ''],
            ['id_card', ''],
            [['material_urls', 'a'], []],
            ['remark', ''],
        ]);
        return app('json')->success('提交成功', $services->submit((int)$request->uid(), $data));
    }

    public function commissionApplyStatus(Request $request)
    {
        $uid = (int)$request->uid();
        $statusMap = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
        $apply = Db::name('commission_apply')->where('uid', $uid)->order('id desc')->find();
        $distributor = Db::name('distributor')
            ->where('uid', $uid)
            ->where('cooperation_mode', 'commission')
            ->where('status', 1)
            ->where('is_del', 0)
            ->find();
        $applyStatus = null;
        if ($distributor) {
            $applyStatus = [
                'status' => 'approved',
                'apply_id' => (int)($apply['id'] ?? 0),
                'reason' => '',
                'distributor' => $distributor,
            ];
        } elseif ($apply) {
            $applyStatus = [
                'status' => $statusMap[(int)$apply['status']] ?? 'pending',
                'apply_id' => (int)$apply['id'],
                'apply_time' => (int)$apply['apply_time'],
                'review_time' => (int)$apply['review_time'],
                'reason' => (string)($apply['review_reason'] ?? ''),
            ];
        }
        $distributorId = (int)($distributor['id'] ?? 0);
        $settlementRows = $distributorId > 0
            ? Db::name('commission_settlement')->where('distributor_id', $distributorId)->field('status,amount')->select()->toArray()
            : [];
        $overview = ['total_commission' => '0.00', 'pending_commission' => '0.00', 'paid_commission' => '0.00'];
        foreach ($settlementRows as $row) {
            $amount = (float)($row['amount'] ?? 0);
            $overview['total_commission'] = number_format((float)$overview['total_commission'] + $amount, 2, '.', '');
            if ((string)$row['status'] === 'paid') {
                $overview['paid_commission'] = number_format((float)$overview['paid_commission'] + $amount, 2, '.', '');
            } else {
                $overview['pending_commission'] = number_format((float)$overview['pending_commission'] + $amount, 2, '.', '');
            }
        }
        return app('json')->success([
            'apply_status' => $applyStatus,
            'is_commission_distributor' => $distributorId > 0 ? 1 : 0,
            'income_overview' => $overview,
        ]);
    }

    public function products(Request $request)
    {
        $binding = $this->services->userBinding((int)$request->uid());
        if (!$binding) {
            return app('json')->success(['bind_required' => 1, 'list' => []]);
        }
        $distributorId = (int)$binding['bind']['distributor_id'];
        $list = Db::name('distributor_sku_inventory')->alias('i')
            ->leftJoin('store_product p', 'p.id = i.product_id')
            ->where('i.distributor_id', $distributorId)
            ->where('p.is_show', 1)
            ->field('i.product_id,i.unique,i.stock,i.locked_stock,i.sales,p.store_name,p.image,p.price')
            ->order('i.id desc')
            ->select()
            ->toArray();
        return app('json')->success(['bind_required' => 0, 'distributor_id' => $distributorId, 'list' => $list]);
    }

    public function workbench(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        return app('json')->success($this->services->workbench($distributorId));
    }

    public function profile(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        $info = $this->services->get($distributorId);
        if (!$info) {
            return app('json')->fail('分销商不存在');
        }
        return app('json')->success($info->toArray());
    }

    public function customers(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 20);
        return app('json')->success($this->services->customers($distributorId, $page, $limit));
    }

    public function share(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        return app('json')->success($this->services->shareProfile($distributorId));
    }

    public function shareRecord(Request $request)
    {
        $data = $request->postMore([
            ['identify_code', ''],
            ['channel', 'wechat'],
        ]);
        $distributorId = $this->services->recordShareEventByIdentifyCode((string)$data['identify_code'], 'share', (int)$request->uid(), (string)$data['channel']);
        return app('json')->success($this->services->shareProfile($distributorId));
    }

    public function shareClick(Request $request)
    {
        $data = $request->postMore([
            ['identify_code', ''],
            ['channel', 'wechat'],
            ['event_type', 'click'],
        ]);
        $eventType = in_array((string)$data['event_type'], ['share', 'click'], true) ? (string)$data['event_type'] : 'click';
        $distributorId = $this->services->recordShareEventByIdentifyCode((string)$data['identify_code'], $eventType, (int)$request->uid(), (string)$data['channel']);
        return app('json')->success($this->services->shareProfile($distributorId));
    }

    public function inventory(Request $request, DistributorInventoryServices $services)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        return app('json')->success($services->getList($distributorId, $request->getMore([['warning', '']])));
    }

    public function inventorySummary(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $row = Db::name('distributor_sku_inventory')
            ->where('distributor_id', $distributorId)
            ->field('COUNT(*) as sku_count,SUM(stock) as stock,SUM(locked_stock) as locked_stock,SUM(sales) as sales')
            ->find();
        $warningCount = Db::name('distributor_sku_inventory')
            ->where('distributor_id', $distributorId)
            ->whereRaw('stock - locked_stock <= warning_stock')
            ->count();
        return app('json')->success([
            'sku_count' => (int)($row['sku_count'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'locked_stock' => (int)($row['locked_stock'] ?? 0),
            'available_stock' => max(0, (int)($row['stock'] ?? 0) - (int)($row['locked_stock'] ?? 0)),
            'sales' => (int)($row['sales'] ?? 0),
            'warning_count' => (int)$warningCount,
        ]);
    }

    public function purchaseOrders(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $status = $request->get('status', '');
        $statusText = [
            'pending_pay' => '待支付',
            'paid' => '已支付',
            'shipped' => '已发货',
            'finished' => '已完结',
            'canceled' => '已取消',
        ];
        $list = Db::name('distributor_purchase_order')->where('distributor_id', $distributorId)
            ->when($status !== '', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->order('id desc')
            ->page((int)$request->get('page', 1), (int)$request->get('limit', 20))
            ->select()
            ->toArray();
        foreach ($list as &$item) {
            $item['status_text'] = $statusText[$item['status']] ?? '处理中';
            $item['items'] = Db::name('distributor_purchase_order_item')
                ->where('purchase_order_id', (int)$item['id'])
                ->select()
                ->toArray();
        }
        return app('json')->success($list);
    }

    public function purchaseCreate(Request $request, DistributorPurchaseOrderServices $services)
    {
        $data = $request->postMore([
            [['items', 'a'], []],
            ['remark', ''],
        ]);
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        return app('json')->success('订货单已创建', $services->createOrder($distributorId, $data['items'], (string)$data['remark']));
    }

    public function purchasePay(Request $request, $id, DistributorPurchaseOrderServices $services)
    {
        $data = $request->postMore([
            ['pay_type', PayServices::WEIXIN_PAY],
        ]);
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $exists = Db::name('distributor_purchase_order')->where('id', (int)$id)->where('distributor_id', $distributorId)->count();
        if (!$exists) {
            return app('json')->fail('订货单不存在');
        }
        return app('json')->success('支付参数创建成功', $services->createPayParams((int)$id, (int)$request->uid(), (string)$data['pay_type']));
    }

    public function purchaseCancel(Request $request, $id, DistributorPurchaseOrderServices $services)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        return app('json')->success('订货单已取消', $services->cancel((int)$id, $distributorId));
    }

    public function deliveryOptions(Request $request, DeliveryFeeCalculatorServices $services)
    {
        $distributorId = app()->make(FmcgProductScopeServices::class)->boundDistributorId($request);
        $context = $request->getMore([
            ['address_id', 0],
            ['cart_id', ''],
        ]);
        $context['uid'] = (int)$request->uid();
        return app('json')->success($services->options($distributorId, $context));
    }

    public function purchaseReceive(Request $request, $id, DistributorPurchaseOrderServices $services)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $exists = Db::name('distributor_purchase_order')->where('id', (int)$id)->where('distributor_id', $distributorId)->count();
        if (!$exists) {
            return app('json')->fail('订货单不存在');
        }
        return app('json')->success('确认收货成功', $services->receive((int)$id));
    }

    public function orders(Request $request)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $status = $request->get('status', '');
        $list = Db::name('store_order')->where('distributor_id', $distributorId)
            ->when($status !== '', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->field('id,order_id,uid,real_name,user_phone,user_address,total_num,pay_price,pay_postage,paid,status,delivery_type,fmcg_delivery_type,delivery_name,delivery_id,add_time')
            ->order('id desc')
            ->page((int)$request->get('page', 1), (int)$request->get('limit', 20))
            ->select()
            ->toArray();
        return app('json')->success($list);
    }

    public function orderDetail(Request $request, $id)
    {
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $order = Db::name('store_order')
            ->where('distributor_id', $distributorId)
            ->where(function ($query) use ($id) {
                $query->where('id', (int)$id)->whereOr('order_id', (string)$id);
            })
            ->field('id,order_id,uid,real_name,user_phone,user_address,total_num,total_price,pay_price,pay_postage,paid,status,delivery_type,fmcg_delivery_type,delivery_name,delivery_id,add_time,pay_time,distributor_id')
            ->find();
        if (!$order) {
            return app('json')->fail('订单不存在');
        }
        $order['items'] = $this->orderItems((int)$order['id']);
        return app('json')->success($order);
    }

    public function updateDelivery(Request $request, $id)
    {
        $data = $request->postMore([
            ['delivery_type', 'merchant'],
            ['delivery_name', ''],
            ['delivery_id', ''],
            ['express_company', ''],
            ['express_no', ''],
            ['status', 1],
        ]);
        $deliveryMap = [
            'distributor' => 'merchant',
            'third_party_city' => 'city',
        ];
        $deliveryType = $deliveryMap[(string)$data['delivery_type']] ?? (string)$data['delivery_type'];
        if (!in_array($deliveryType, ['pickup', 'merchant', 'city', 'express'], true)) {
            return app('json')->fail('配送方式不支持');
        }
        $distributorId = $this->currentDistributorId($request);
        if ($distributorId <= 0) {
            return app('json')->fail('分销商身份缺失');
        }
        $deliveryName = (string)($data['delivery_name'] ?: $data['express_company']);
        $deliveryId = (string)($data['delivery_id'] ?: $data['express_no']);
        $order = Db::name('store_order')
            ->where('distributor_id', $distributorId)
            ->where(function ($query) use ($id) {
                $query->where('id', (int)$id)->whereOr('order_id', (string)$id);
            })
            ->field('id')
            ->find();
        if (!$order) {
            return app('json')->fail('订单不存在');
        }
        Db::name('store_order')->where('id', (int)$order['id'])->update([
            'fmcg_delivery_type' => $deliveryType,
            'delivery_type' => $deliveryType,
            'delivery_name' => $deliveryName,
            'delivery_id' => $deliveryId,
            'status' => (int)$data['status'],
        ]);
        return app('json')->success('配送状态已更新');
    }

    protected function currentDistributorId(Request $request): int
    {
        $uid = (int)$request->uid();
        if ($uid <= 0) {
            return 0;
        }
        return (int)Db::name('distributor')
            ->where('uid', $uid)
            ->where('status', 1)
            ->where('is_del', 0)
            ->value('id');
    }

    protected function orderItems(int $orderId): array
    {
        $rows = Db::name('store_order_cart_info')->where('oid', $orderId)->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $cartInfo = json_decode((string)($row['cart_info'] ?? ''), true);
            $productInfo = $cartInfo['productInfo'] ?? [];
            $attrInfo = $productInfo['attrInfo'] ?? [];
            $items[] = [
                'id' => (int)$row['id'],
                'product_id' => (int)($row['product_id'] ?: ($productInfo['id'] ?? $productInfo['product_id'] ?? 0)),
                'unique' => (string)($cartInfo['product_attr_unique'] ?? $cartInfo['unique'] ?? $attrInfo['unique'] ?? ''),
                'product_name' => (string)($productInfo['store_name'] ?? $cartInfo['product_name'] ?? ''),
                'image' => (string)($attrInfo['image'] ?? $productInfo['image'] ?? ''),
                'sku' => (string)($attrInfo['suk'] ?? ''),
                'price' => (float)($cartInfo['truePrice'] ?? $attrInfo['price'] ?? 0),
                'num' => (int)($row['cart_num'] ?: ($cartInfo['cart_num'] ?? 0)),
                'is_gift' => !empty($cartInfo['is_gift']) || !empty($cartInfo['fmcg_is_gift']) ? 1 : 0,
                'gift_type' => (string)($cartInfo['gift_type'] ?? ''),
            ];
        }
        return $items;
    }
}
