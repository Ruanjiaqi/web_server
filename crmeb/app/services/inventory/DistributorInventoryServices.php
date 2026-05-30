<?php
namespace app\services\inventory;

use app\dao\inventory\DistributorInventoryLogDao;
use app\dao\inventory\DistributorSkuInventoryDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DistributorInventoryServices extends BaseServices
{
    public function __construct(DistributorSkuInventoryDao $dao)
    {
        $this->dao = $dao;
    }

    public function getList(int $distributorId, array $where = []): array
    {
        [$page, $limit] = $this->getPageValue();
        $filter = ['distributor_id' => $distributorId];
        if (!empty($where['warning'])) {
            $filter['warning'] = 1;
        }
        return $this->dao->selectList($filter, '*', $page, $limit, 'id desc', [], true)->toArray();
    }

    public function increase(int $distributorId, int $productId, string $unique, int $num, string $bizType, string $bizNo, array $extra = [])
    {
        if ($num <= 0) {
            throw new ApiException('库存增加数量必须大于0');
        }
        return $this->transaction(function () use ($distributorId, $productId, $unique, $num, $bizType, $bizNo, $extra) {
            $sku = $this->dao->getSku($distributorId, $productId, $unique);
            if ($sku) {
                $sku->stock = (int)$sku->stock + $num;
                $sku->update_time = time();
                $sku->save();
            } else {
                $sku = $this->dao->save(array_merge([
                    'distributor_id' => $distributorId,
                    'product_id' => $productId,
                    'unique' => $unique,
                    'stock' => $num,
                    'locked_stock' => 0,
                    'warning_stock' => 5,
                    'add_time' => time(),
                    'update_time' => time(),
                ], $extra));
            }
            $this->log($distributorId, $productId, $unique, $num, $bizType, $bizNo, 'in');
            return $sku;
        });
    }

    public function lock(int $distributorId, int $productId, string $unique, int $num, string $bizNo): bool
    {
        if ($num <= 0) {
            throw new ApiException('锁定数量必须大于0');
        }
        return $this->transaction(function () use ($distributorId, $productId, $unique, $num, $bizNo) {
            $updated = Db::name('distributor_sku_inventory')
                ->where('distributor_id', $distributorId)
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->whereRaw('stock - locked_stock >= ?', [$num])
                ->inc('locked_stock', $num)
                ->update(['update_time' => time()]);
            if (!$updated) {
                throw new ApiException('分销商库存不足');
            }
            $this->log($distributorId, $productId, $unique, $num, 'order_lock', $bizNo, 'lock');
            return true;
        });
    }

    public function release(int $distributorId, int $productId, string $unique, int $num, string $bizNo): bool
    {
        return $this->transaction(function () use ($distributorId, $productId, $unique, $num, $bizNo) {
            $num = min($num, $this->lockedForBiz($distributorId, $productId, $unique, $bizNo));
            if ($num <= 0) {
                return true;
            }
            $sku = $this->dao->getSku($distributorId, $productId, $unique);
            if (!$sku) {
                return true;
            }
            $sku->locked_stock = max(0, (int)$sku->locked_stock - $num);
            $sku->update_time = time();
            $sku->save();
            $this->log($distributorId, $productId, $unique, $num, 'order_release', $bizNo, 'release');
            return true;
        });
    }

    public function deductLocked(int $distributorId, int $productId, string $unique, int $num, string $bizNo): bool
    {
        return $this->transaction(function () use ($distributorId, $productId, $unique, $num, $bizNo) {
            if ($this->lockedForBiz($distributorId, $productId, $unique, $bizNo) < $num) {
                throw new ApiException('分销商库存不足');
            }
            $updated = Db::name('distributor_sku_inventory')
                ->where('distributor_id', $distributorId)
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->where('stock', '>=', $num)
                ->where('locked_stock', '>=', $num)
                ->dec('stock', $num)
                ->dec('locked_stock', $num)
                ->inc('sales', $num)
                ->update(['update_time' => time()]);
            if (!$updated) {
                throw new ApiException('分销商库存不足');
            }
            $this->log($distributorId, $productId, $unique, -$num, 'order_paid', $bizNo, 'out');
            return true;
        });
    }

    public function refundPaid(int $distributorId, int $productId, string $unique, int $num, string $orderNo, string $refundNo): bool
    {
        if ($num <= 0 || $refundNo === '') {
            return true;
        }
        return $this->transaction(function () use ($distributorId, $productId, $unique, $num, $orderNo, $refundNo) {
            $refundBizNo = $orderNo . ':' . $refundNo;
            $refundable = $this->refundablePaidForBiz($distributorId, $productId, $unique, $orderNo);
            $alreadyRefundedByNo = (int)Db::name('distributor_inventory_log')
                ->where('distributor_id', $distributorId)
                ->where('product_id', $productId)
                ->where('unique', $unique)
                ->where('biz_no', $refundBizNo)
                ->where('biz_type', 'order_refund')
                ->sum('change_num');
            if ($alreadyRefundedByNo > 0) {
                return true;
            }
            $num = min($num, $refundable);
            if ($num <= 0) {
                return true;
            }
            $sku = $this->dao->getSku($distributorId, $productId, $unique);
            if (!$sku) {
                return true;
            }
            $sku->stock = (int)$sku->stock + $num;
            $sku->sales = max(0, (int)$sku->sales - $num);
            $sku->update_time = time();
            $sku->save();
            $this->log($distributorId, $productId, $unique, $num, 'order_refund', $refundBizNo, 'in');
            return true;
        });
    }

    protected function lockedForBiz(int $distributorId, int $productId, string $unique, string $bizNo): int
    {
        $locked = (int)Db::name('distributor_inventory_log')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('biz_no', $bizNo)
            ->where('biz_type', 'order_lock')
            ->where('direction', 'lock')
            ->sum('change_num');
        $released = (int)Db::name('distributor_inventory_log')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('biz_no', $bizNo)
            ->where('biz_type', 'order_release')
            ->sum('change_num');
        $paid = (int)Db::name('distributor_inventory_log')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('biz_no', $bizNo)
            ->where('biz_type', 'order_paid')
            ->sum('change_num');
        return max(0, $locked - $released + $paid);
    }

    protected function refundablePaidForBiz(int $distributorId, int $productId, string $unique, string $bizNo): int
    {
        $paid = -(int)Db::name('distributor_inventory_log')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('biz_no', $bizNo)
            ->where('biz_type', 'order_paid')
            ->sum('change_num');
        $refunded = (int)Db::name('distributor_inventory_log')
            ->where('distributor_id', $distributorId)
            ->where('product_id', $productId)
            ->where('unique', $unique)
            ->where('biz_type', 'order_refund')
            ->whereLike('biz_no', $bizNo . ':%')
            ->sum('change_num');
        return max(0, $paid - $refunded);
    }

    protected function log(int $distributorId, int $productId, string $unique, int $change, string $bizType, string $bizNo, string $direction): void
    {
        app()->make(DistributorInventoryLogDao::class)->save([
            'distributor_id' => $distributorId,
            'product_id' => $productId,
            'unique' => $unique,
            'change_num' => $change,
            'direction' => $direction,
            'biz_type' => $bizType,
            'biz_no' => $bizNo,
            'add_time' => time(),
        ]);
    }
}
