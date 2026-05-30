<?php
namespace app\adminapi\controller\v1\distributor;

use app\adminapi\controller\AuthController;
use app\services\commission\CommissionApplyServices;
use app\services\commission\CommissionRuleServices;
use app\services\commission\CommissionSettlementServices;
use app\services\distributor\BuyXGetXCampaignServices;
use app\services\distributor\DistributorServices;
use app\services\purchase\DistributorPurchaseOrderServices;
use app\services\settlement\DeliveryFeeRecordServices;
use app\services\settlement\OrderSettlementRecordServices;
use think\facade\App;
use think\facade\Db;

class DistributorController extends AuthController
{
    protected $services;

    public function __construct(App $app, DistributorServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['cooperation_mode', ''],
            ['status', ''],
            ['page', 1],
            ['limit', 20],
        ]);
        return app('json')->success($this->services->adminList($where));
    }

    public function save()
    {
        $data = $this->request->postMore([
            ['uid', 0],
            ['identify_code', ''],
            ['store_name', ''],
            ['contact_name', ''],
            ['phone', ''],
            ['qualification_type', 'personal'],
            ['cooperation_mode', 'commission'],
            ['license_no', ''],
            ['wechat_mch_id', ''],
            ['address', ''],
            ['status', 1],
        ]);
        $this->validate($data, \app\adminapi\validate\distributor\DistributorValidate::class);
        return app('json')->success('保存成功', $this->services->createDistributor($data));
    }

    public function read($id)
    {
        return app('json')->success($this->services->get((int)$id));
    }

    public function commissionApplications(CommissionApplyServices $services)
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([['status', '']]);
        return app('json')->success([
            'count' => $services->count($where),
            'list' => $services->selectList($where, '*', $page, $limit, 'id desc', [], true),
        ]);
    }

    public function reviewCommissionApply($id, CommissionApplyServices $services)
    {
        [$status, $reason] = $this->request->postMore([
            ['status', 1],
            ['reason', ''],
        ], true);
        return app('json')->success('审核完成', $services->review((int)$id, (int)$status, (string)$reason));
    }

    public function saveCommissionRule(CommissionRuleServices $services)
    {
        $data = $this->request->postMore([
            ['distributor_id', 0],
            ['product_id', 0],
            ['unique', ''],
            ['template_name', '固定模板'],
            ['rate', 0],
            ['status', 1],
        ]);
        return app('json')->success('保存成功', $services->saveRule($data));
    }

    public function commissionRules(CommissionRuleServices $services)
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['status', ''],
        ]);
        $filter = [];
        if ((int)$where['distributor_id'] > 0) {
            $filter['distributor_id'] = (int)$where['distributor_id'];
        }
        if ($where['status'] !== '') {
            $filter['status'] = (int)$where['status'];
        }
        return app('json')->success([
            'count' => $services->count($filter),
            'list' => $services->selectList($filter, '*', $page, $limit, 'id desc')->toArray(),
        ]);
    }

    public function eligibleCommissionOrders($id, CommissionSettlementServices $services)
    {
        $refundDays = (int)$this->request->get('refund_days', 7);
        return app('json')->success($services->eligibleOrders((int)$id, $refundDays));
    }

    public function settleCommission($id, CommissionSettlementServices $services)
    {
        [$orderIds, $refundDays] = $this->request->postMore([
            [['order_ids', 'a'], []],
            [['refund_days', 'd'], 7],
        ], true);
        return app('json')->success('结算单已生成', $services->createManualSettlement((int)$id, $orderIds, (int)$this->adminId, (int)$refundDays));
    }

    public function commissionSettlements(CommissionSettlementServices $services)
    {
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['status', ''],
        ]);
        return app('json')->success($services->settlementList($where));
    }

    public function updateCommissionSettlement($id, CommissionSettlementServices $services)
    {
        [$status] = $this->request->postMore([
            ['status', 'paid'],
        ], true);
        return app('json')->success('佣金结算状态已更新', $services->updateStatus((int)$id, (string)$status));
    }

    public function purchaseOrders(DistributorPurchaseOrderServices $services)
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['status', ''],
        ]);
        return app('json')->success([
            'count' => $services->count(array_filter($where, function ($value) {
                return $value !== '' && $value !== 0;
            })),
            'list' => $services->selectList(array_filter($where, function ($value) {
                return $value !== '' && $value !== 0;
            }), '*', $page, $limit, 'id desc'),
        ]);
    }

    public function inventoryList()
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['keyword', ''],
            ['warning', ''],
        ]);
        $query = Db::name('distributor_sku_inventory')->alias('i')
            ->leftJoin('store_product p', 'p.id = i.product_id')
            ->leftJoin('distributor d', 'd.id = i.distributor_id')
            ->when((int)$where['distributor_id'] > 0, function ($query) use ($where) {
                $query->where('i.distributor_id', (int)$where['distributor_id']);
            })
            ->when($where['keyword'] !== '', function ($query) use ($where) {
                $query->whereLike('p.store_name|d.store_name', '%' . $where['keyword'] . '%');
            })
            ->when($where['warning'] !== '', function ($query) {
                $query->whereRaw('i.stock - i.locked_stock <= i.warning_stock');
            });
        return app('json')->success([
            'count' => $query->count(),
            'list' => $query->field('i.*,p.store_name as product_name,p.image,d.store_name as distributor_name')
                ->order('i.id desc')->page($page, $limit)->select()->toArray(),
        ]);
    }

    public function deliveryFeeRecords()
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['status', ''],
            ['delivery_type', ''],
            ['settlement_method', ''],
            ['settlement_subject', ''],
            ['receiver', ''],
        ]);
        $query = Db::name('delivery_fee_record')->alias('r')
            ->leftJoin('distributor d', 'd.id = r.distributor_id')
            ->when((int)$where['distributor_id'] > 0, function ($query) use ($where) {
                $query->where('r.distributor_id', (int)$where['distributor_id']);
            })
            ->when($where['status'] !== '', function ($query) use ($where) {
                $query->where('r.status', (string)$where['status']);
            })
            ->when($where['delivery_type'] !== '', function ($query) use ($where) {
                $query->where('r.delivery_type', (string)$where['delivery_type']);
            })
            ->when($where['settlement_method'] !== '', function ($query) use ($where) {
                $query->where('r.settlement_method', (string)$where['settlement_method']);
            })
            ->when($where['settlement_subject'] !== '', function ($query) use ($where) {
                $query->where('r.settlement_subject', (string)$where['settlement_subject']);
            })
            ->when($where['receiver'] !== '', function ($query) use ($where) {
                $query->whereLike('r.receiver', '%' . (string)$where['receiver'] . '%');
            });
        return app('json')->success([
            'count' => $query->count(),
            'list' => $query->field('r.*,d.store_name as distributor_name')->order('r.id desc')->page($page, $limit)->select()->toArray(),
        ]);
    }

    public function updateDeliveryFeeRecord($id, DeliveryFeeRecordServices $services)
    {
        [$status, $receiver, $paymentNo, $failReason, $settlementBatchNo] = $this->request->postMore([
            ['status', 'settled'],
            ['receiver', ''],
            ['payment_no', ''],
            ['fail_reason', ''],
            ['settlement_batch_no', ''],
        ], true);
        return app('json')->success('配送费状态已更新', $services->updateStatus(
            (int)$id,
            (string)$status,
            (string)$receiver,
            (string)$paymentNo,
            (string)$failReason,
            (string)$settlementBatchNo
        ));
    }

    public function settlementRecords()
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['distributor_id', 0],
            ['status', ''],
        ]);
        $query = Db::name('order_settlement_record')->alias('r')
            ->leftJoin('distributor d', 'd.id = r.distributor_id')
            ->when((int)$where['distributor_id'] > 0, function ($query) use ($where) {
                $query->where('r.distributor_id', (int)$where['distributor_id']);
            })
            ->when($where['status'] !== '', function ($query) use ($where) {
                $query->where('r.status', (string)$where['status']);
            });
        return app('json')->success([
            'count' => $query->count(),
            'list' => $query->field('r.*,d.store_name as distributor_name')->order('r.id desc')->page($page, $limit)->select()->toArray(),
        ]);
    }

    public function updateSettlementRecord($id, OrderSettlementRecordServices $services)
    {
        [$status, $profitSharingNo, $message] = $this->request->postMore([
            ['status', 'success'],
            ['profit_sharing_no', ''],
            ['message', ''],
        ], true);
        return app('json')->success('分账状态已更新', $services->updateStatus((int)$id, (string)$status, (string)$profitSharingNo, (string)$message));
    }

    public function requestSettlementSharing($id, OrderSettlementRecordServices $services)
    {
        return app('json')->success('微信分账已发起', $services->requestSharing((int)$id));
    }

    public function scanSettlementSharing(OrderSettlementRecordServices $services)
    {
        $limit = (int)$this->request->post('limit', 20);
        return app('json')->success('待分账扫描完成', $services->scanAndShare($limit));
    }

    public function payPurchase($id, DistributorPurchaseOrderServices $services)
    {
        return app('json')->success('支付确认成功', $services->markPaid((int)$id, (string)$this->request->post('pay_no', '')));
    }

    public function shipPurchase($id, DistributorPurchaseOrderServices $services)
    {
        $data = $this->request->postMore([
            ['delivery_type', 'offline'],
            ['express_name', ''],
            ['express_no', ''],
        ]);
        return app('json')->success('发货成功', $services->ship((int)$id, $data));
    }

    public function buyXGetXList()
    {
        [$page, $limit] = $this->services->getPageValue();
        $where = $this->request->getMore([
            ['product_id', 0],
            ['status', ''],
        ]);
        $query = \think\facade\Db::name('buy_x_get_x_campaign')
            ->when((int)$where['product_id'] > 0, function ($query) use ($where) {
                $query->where('product_id', (int)$where['product_id']);
            })
            ->when($where['status'] !== '', function ($query) use ($where) {
                $query->where('status', (int)$where['status']);
            });
        return app('json')->success([
            'count' => $query->count(),
            'list' => $query->order('id desc')->page($page, $limit)->select()->toArray(),
        ]);
    }

    public function saveBuyXGetX(BuyXGetXCampaignServices $services)
    {
        $data = $this->request->postMore([
            ['id', 0],
            ['title', '买赠活动'],
            ['product_id', 0],
            ['buy_num', 1],
            ['gift_num', 1],
            ['quota', 0],
            ['start_time', 0],
            ['end_time', 0],
            ['start', 0],
            ['end', 0],
            ['status', 1],
        ]);
        if (!empty($data['start']) && empty($data['start_time'])) {
            $data['start_time'] = $data['start'];
        }
        if (!empty($data['end']) && empty($data['end_time'])) {
            $data['end_time'] = $data['end'];
        }
        if (empty($data['start_time'])) {
            $data['start_time'] = time();
        }
        return app('json')->success('保存成功', ['id' => $services->publish($data)]);
    }
}
