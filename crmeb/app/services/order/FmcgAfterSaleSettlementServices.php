<?php
namespace app\services\order;

use app\services\BaseServices;
use app\services\settlement\WechatProfitSharingServices;
use think\facade\Db;

class FmcgAfterSaleSettlementServices extends BaseServices
{
    public function onRefundApplied($order, string $refundNo = ''): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        $orderId = (string)($order['order_id'] ?? '');
        if ($orderId !== '' && empty($order['distributor_id'])) {
            $latest = Db::name('store_order')->where('order_id', $orderId)->field('id,order_id,distributor_id,paid')->find();
            $order = array_merge($latest ?: [], $order);
        }
        if ($orderId === '' || empty($order['distributor_id'])) {
            return;
        }
        $now = time();
        Db::name('commission_settlement')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'paid'])
            ->update([
                'status' => 'refund_pending',
                'update_time' => $now,
            ]);
        Db::name('order_settlement_record')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'success'])
            ->update([
                'status' => 'return_pending',
                'fail_reason' => $refundNo ? ('售后申请:' . $refundNo) : '售后申请',
                'update_time' => $now,
            ]);
    }

    public function onRefundSucceeded($order, string $refundNo = ''): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        $orderId = (string)($order['order_id'] ?? '');
        if ($orderId !== '' && empty($order['distributor_id'])) {
            $latest = Db::name('store_order')->where('order_id', $orderId)->field('id,order_id,distributor_id,paid')->find();
            $order = array_merge($latest ?: [], $order);
        }
        if ($orderId === '' || empty($order['distributor_id'])) {
            return;
        }
        app()->make(FmcgOrderAdapterServices::class)->cancelGiftUsageByOrderId($orderId);
        app()->make(FmcgOrderAdapterServices::class)->restoreRefundedInventory($order, $refundNo);
        Db::name('commission_settlement')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'paid', 'refund_pending'])
            ->update([
                'status' => 'refunded',
                'update_time' => time(),
            ]);
        $this->returnProfitSharing($order, $refundNo);
    }

    public function onRefundCanceled($order, string $refundNo = ''): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        $orderId = (string)($order['order_id'] ?? '');
        if ($orderId === '' || empty($order['distributor_id'])) {
            return;
        }
        Db::name('commission_settlement')
            ->where('order_id', $orderId)
            ->where('status', 'refund_pending')
            ->update([
                'status' => 'pending',
                'update_time' => time(),
            ]);
        Db::name('order_settlement_record')
            ->where('order_id', $orderId)
            ->where('status', 'return_pending')
            ->update([
                'status' => 'pending',
                'fail_reason' => $refundNo ? ('售后取消:' . $refundNo) : '售后取消',
                'update_time' => time(),
            ]);
    }

    protected function returnProfitSharing(array $order, string $refundNo = ''): void
    {
        $records = Db::name('order_settlement_record')
            ->where('order_id', (string)$order['order_id'])
            ->whereIn('status', ['success', 'return_pending'])
            ->select()
            ->toArray();
        foreach ($records as $record) {
            $distributor = Db::name('distributor')->where('id', (int)$record['distributor_id'])->find();
            $mchId = (string)($distributor['wechat_mch_id'] ?? '');
            if ($mchId === '' || (float)$record['amount'] <= 0 || (string)$record['profit_sharing_no'] === '') {
                $this->markProfitSharingReturnPending($record, '分账回退参数缺失');
                continue;
            }
            try {
                $returnNo = 'PSR' . (string)$order['order_id'] . '_' . (int)$record['id'];
                if ($refundNo !== '') {
                    $returnNo .= '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $refundNo);
                }
                app()->make(WechatProfitSharingServices::class)->returnSharing(
                    (string)$record['profit_sharing_no'],
                    $returnNo,
                    $mchId,
                    (float)$record['amount'],
                    '订单退款分账回退'
                );
                Db::name('order_settlement_record')->where('id', (int)$record['id'])->update([
                    'status' => 'returned',
                    'fail_reason' => '',
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                $this->markProfitSharingReturnPending($record, mb_substr($e->getMessage(), 0, 255));
            }
        }
    }

    protected function markProfitSharingReturnPending(array $record, string $reason): void
    {
        Db::name('order_settlement_record')->where('id', (int)$record['id'])->update([
            'status' => 'return_pending',
            'fail_reason' => $reason,
            'update_time' => time(),
        ]);
    }
}
