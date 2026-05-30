<?php
namespace app\services\distributor;

use app\dao\distributor\DistributorDao;
use app\dao\distributor\DistributorUserBindDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DistributorServices extends BaseServices
{
    public function __construct(DistributorDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        [$page, $limit] = $this->getPageValue();
        $filter = [
            'keyword' => $where['keyword'] ?? '',
            'cooperation_mode' => $where['cooperation_mode'] ?? '',
            'status' => $where['status'] ?? '',
        ];
        return [
            'count' => $this->dao->count($filter),
            'list' => $this->dao->getList($filter, $page, $limit),
        ];
    }

    public function createDistributor(array $data): array
    {
        $mode = $data['cooperation_mode'] ?? 'commission';
        $type = $data['qualification_type'] ?? 'personal';
        if ($type === 'personal') {
            $mode = 'commission';
        } elseif ($mode !== 'consignment') {
            $mode = 'consignment';
        }
        if (!empty($data['phone']) && $this->dao->be(['phone' => $data['phone']])) {
            throw new ApiException('手机号已经存在');
        }
        if (!empty($data['store_name']) && $this->dao->be(['store_name' => $data['store_name']])) {
            throw new ApiException('店铺名称已经存在');
        }
        $identifyCode = (string)($data['identify_code'] ?? '');
        if ($identifyCode !== '' && !preg_match('/^\d{8}$/', $identifyCode)) {
            throw new ApiException('分销商识别码必须为8位数字');
        }
        if ($identifyCode === '') {
            $identifyCode = $this->makeIdentifyCode();
        }
        if ($this->dao->be(['identify_code' => $identifyCode])) {
            throw new ApiException('分销商识别码已经存在');
        }
        $data = array_merge([
            'uid' => 0,
            'identify_code' => $identifyCode,
            'qualification_type' => $type,
            'cooperation_mode' => $mode,
            'store_name' => '',
            'contact_name' => '',
            'phone' => '',
            'status' => 1,
            'switch_mode_enabled' => 0,
            'add_time' => time(),
            'update_time' => time(),
        ], $data);
        $data['qualification_type'] = $type;
        $data['cooperation_mode'] = $mode;
        $data['identify_code'] = $identifyCode;
        $data['switch_mode_enabled'] = 0;
        $row = $this->dao->save($data);
        return $row->toArray();
    }

    public function bindUser(int $uid, string $identifyCode, string $source = 'manual'): array
    {
        if (!preg_match('/^\d{8}$/', $identifyCode)) {
            throw new ApiException('分销商识别码必须为8位数字');
        }
        /** @var DistributorUserBindDao $bindDao */
        $bindDao = app()->make(DistributorUserBindDao::class);
        $exists = $bindDao->getOne(['uid' => $uid, 'is_del' => 0]);
        if ($exists) {
            return ['locked' => 1, 'bind' => $exists->toArray()];
        }
        $distributor = $this->dao->getOne(['identify_code' => $identifyCode, 'status' => 1, 'is_del' => 0]);
        if (!$distributor) {
            throw new ApiException('分销商不存在或已禁用');
        }
        $bind = $bindDao->save([
            'uid' => $uid,
            'distributor_id' => $distributor['id'],
            'source' => $source,
            'identify_code' => $identifyCode,
            'bind_time' => time(),
            'is_del' => 0,
        ]);
        $this->recordShareEvent((int)$distributor['id'], 'bind', $uid, $source);
        return ['locked' => 0, 'bind' => $bind->toArray(), 'distributor' => $distributor->toArray()];
    }

    public function userBinding(int $uid): array
    {
        /** @var DistributorUserBindDao $bindDao */
        $bindDao = app()->make(DistributorUserBindDao::class);
        $bind = $bindDao->getOne(['uid' => $uid, 'is_del' => 0]);
        if (!$bind) {
            return [];
        }
        $distributor = $this->dao->get((int)$bind['distributor_id']);
        return [
            'bind' => $bind->toArray(),
            'distributor' => $distributor ? $distributor->toArray() : null,
        ];
    }

    public function workbench(int $distributorId): array
    {
        $today = strtotime(date('Y-m-d'));
        $newBindCount = Db::name('distributor_user_bind')->where('distributor_id', $distributorId)->where('is_del', 0)->where('bind_time', '>=', $today)->count();
        $conversionOrderCount = Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->where('refund_status', 0)->count();
        $conversionAmount = (float)Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->where('refund_status', 0)->sum('pay_price');
        return [
            'today_order_count' => Db::name('store_order')->where('distributor_id', $distributorId)->where('add_time', '>=', $today)->count(),
            'today_sales_amount' => (float)Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->where('pay_time', '>=', $today)->sum('pay_price'),
            'pending_delivery_count' => Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->whereIn('status', [0, 1])->count(),
            'inventory_warning_count' => Db::name('distributor_sku_inventory')->where('distributor_id', $distributorId)->whereRaw('stock - locked_stock <= warning_stock')->count(),
            'new_bind_count' => (int)$newBindCount,
            'conversion_order_count' => (int)$conversionOrderCount,
            'conversion_amount' => $conversionAmount,
        ];
    }

    public function customers(int $distributorId, int $page = 1, int $limit = 20): array
    {
        return Db::name('distributor_user_bind')->alias('b')
            ->leftJoin('user u', 'u.uid = b.uid')
            ->where('b.distributor_id', $distributorId)
            ->where('b.is_del', 0)
            ->field('b.id,b.uid,b.source,b.bind_time,u.nickname,u.phone,u.avatar')
            ->page($page, $limit)
            ->order('b.id desc')
            ->select()
            ->toArray();
    }

    public function shareProfile(int $distributorId): array
    {
        $info = $this->dao->get($distributorId);
        if (!$info) {
            throw new ApiException('分销商不存在');
        }
        $bindCount = app()->make(DistributorUserBindDao::class)->count(['distributor_id' => $distributorId, 'is_del' => 0]);
        $today = strtotime(date('Y-m-d'));
        $newBindCount = Db::name('distributor_user_bind')->where('distributor_id', $distributorId)->where('is_del', 0)->where('bind_time', '>=', $today)->count();
        $conversionOrderCount = Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->where('refund_status', 0)->count();
        $conversionAmount = (float)Db::name('store_order')->where('distributor_id', $distributorId)->where('paid', 1)->where('refund_status', 0)->sum('pay_price');
        return [
            'identify_code' => $info['identify_code'],
            'store_name' => $info['store_name'],
            'path' => '/pages/index/index?distributor=' . $info['identify_code'],
            'share_count' => (int)($info['share_count'] ?? 0),
            'click_count' => (int)($info['click_count'] ?? 0),
            'bind_count' => $bindCount,
            'new_bind_count' => (int)$newBindCount,
            'conversion_order_count' => (int)$conversionOrderCount,
            'conversion_amount' => $conversionAmount,
        ];
    }

    public function recordShareEvent(int $distributorId, string $eventType, int $uid = 0, string $channel = 'wechat', string $orderId = '', float $amount = 0): void
    {
        if (!in_array($eventType, ['share', 'click', 'bind', 'order_paid'], true) || $distributorId <= 0) {
            return;
        }
        $update = ['update_time' => time()];
        if ($eventType === 'share') {
            $update['share_count'] = Db::raw('share_count + 1');
        } elseif ($eventType === 'click') {
            $update['click_count'] = Db::raw('click_count + 1');
        }
        Db::name('distributor')->where('id', $distributorId)->update($update);
        try {
            Db::name('distributor_share_event')->insert([
                'distributor_id' => $distributorId,
                'uid' => $uid,
                'event_type' => $eventType,
                'channel' => $channel,
                'order_id' => $orderId,
                'amount' => $amount,
                'add_time' => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public function recordShareEventByIdentifyCode(string $identifyCode, string $eventType, int $uid = 0, string $channel = 'wechat', string $orderId = '', float $amount = 0): int
    {
        if (!preg_match('/^\d{8}$/', $identifyCode)) {
            throw new ApiException('分销商识别码必须为8位数字');
        }
        $distributorId = (int)Db::name('distributor')
            ->where('identify_code', $identifyCode)
            ->where('status', 1)
            ->where('is_del', 0)
            ->value('id');
        if ($distributorId <= 0) {
            throw new ApiException('分销商不存在或已禁用');
        }
        $this->recordShareEvent($distributorId, $eventType, $uid, $channel, $orderId, $amount);
        return $distributorId;
    }

    protected function makeIdentifyCode(): string
    {
        for ($i = 0; $i < 3; $i++) {
            $code = (string)random_int(10000000, 99999999);
            if (!$this->dao->be(['identify_code' => $code])) {
                return $code;
            }
        }
        return (string)(time() % 90000000 + 10000000);
    }
}
