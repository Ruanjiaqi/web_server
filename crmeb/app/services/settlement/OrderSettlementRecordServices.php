<?php
namespace app\services\settlement;

use app\dao\settlement\OrderSettlementRecordDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class OrderSettlementRecordServices extends BaseServices
{
    public function __construct(OrderSettlementRecordDao $dao)
    {
        $this->dao = $dao;
    }

    public function recordConsignment(int $distributorId, string $orderId, float $amount, float $deliveryFee = 0, string $status = 'pending')
    {
        $exists = $this->dao->getOne(['order_id' => $orderId, 'settlement_type' => 'wechat_profit_sharing']);
        if ($exists) {
            $update = [];
            if (bccomp((string)$exists['amount'], (string)$amount, 2) !== 0) {
                $update['amount'] = $amount;
            }
            if (bccomp((string)$exists['delivery_fee'], (string)$deliveryFee, 2) !== 0) {
                $update['delivery_fee'] = $deliveryFee;
            }
            if ($update) {
                $update['update_time'] = time();
                $this->dao->update((int)$exists['id'], $update);
                return $this->dao->get((int)$exists['id']);
            }
            return $exists;
        }
        return $this->dao->save([
            'distributor_id' => $distributorId,
            'order_id' => $orderId,
            'settlement_type' => 'wechat_profit_sharing',
            'amount' => $amount,
            'delivery_fee' => $deliveryFee,
            'status' => $status,
            'add_time' => time(),
            'update_time' => time(),
        ]);
    }

    public function recordDeliveryFeeSharing(int $distributorId, string $orderId, float $deliveryFee, string $status = 'pending')
    {
        return $this->dao->getOne(['order_id' => $orderId, 'settlement_type' => 'wechat_profit_sharing']);
    }

    public function createEligibleForOrder($order)
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        if (empty($order['order_id']) || empty($order['distributor_id'])) {
            return null;
        }
        if ((int)($order['paid'] ?? 0) !== 1 || (int)($order['refund_status'] ?? 0) !== 0 || (int)($order['status'] ?? 0) < 2) {
            return null;
        }
        $distributor = Db::name('distributor')->where('id', (int)$order['distributor_id'])->find();
        if (!$distributor || ($distributor['cooperation_mode'] ?? '') !== 'consignment') {
            return null;
        }
        return $this->recordConsignment(
            (int)$order['distributor_id'],
            (string)$order['order_id'],
            $this->orderReceivableAmount($order),
            (float)$order['pay_postage'],
            'pending'
        );
    }

    public function scanAndShare(int $limit = 20): array
    {
        $rows = Db::name('order_settlement_record')
            ->where('settlement_type', 'wechat_profit_sharing')
            ->where('status', 'pending')
            ->order('id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $result = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            try {
                $this->requestSharing((int)$row['id']);
                $result['success']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->markFailed((int)$row['id'], $e->getMessage());
            }
        }
        return $result;
    }

    public function requestSharing(int $id)
    {
        $record = $this->dao->get($id);
        if (!$record) {
            throw new ApiException('分账记录不存在');
        }
        if ($record['status'] === 'success') {
            return $record;
        }
        $order = Db::name('store_order')->where('order_id', (string)$record['order_id'])->find();
        if (!$order) {
            throw new ApiException('订单不存在，不能发起分账');
        }
        if ((int)$order['paid'] !== 1 || (int)$order['status'] < 2 || (int)$order['refund_status'] !== 0) {
            throw new ApiException('订单未达到分账条件');
        }
        $distributor = Db::name('distributor')->where('id', (int)$record['distributor_id'])->find();
        if (!$distributor || (string)($distributor['cooperation_mode'] ?? '') !== 'consignment') {
            throw new ApiException('非代销订单不能发起微信分账');
        }
        $isDeliveryFeeSharing = (string)$record['settlement_type'] === 'delivery_fee_wechat_profit_sharing';
        if ($isDeliveryFeeSharing) {
            throw new ApiException('配送费已并入订单款分账，不能独立发起配送费分账');
        }
        $shareAmount = $this->orderReceivableAmount($order);
        if ($shareAmount <= 0) {
            throw new ApiException('订单实付金额为0，不能发起分账');
        }
        if (bccomp((string)$record['amount'], (string)$shareAmount, 2) !== 0) {
            $this->dao->update((int)$record['id'], [
                'amount' => $shareAmount,
                'delivery_fee' => (float)($order['pay_postage'] ?? $record['delivery_fee'] ?? 0),
                'update_time' => time(),
            ]);
            $record = $this->dao->get((int)$record['id']);
        }
        $transactionId = (string)($order['trade_no'] ?: $record['wechat_transaction_id']);
        $outOrderNo = (string)($record['profit_sharing_no'] ?: 'PS' . $order['order_id']);
        $receiver = app()->make(WechatProfitSharingServices::class)->buildReceiverForDistributor(
            (int)$record['distributor_id'],
            $shareAmount,
            'FMCG代销订单款及配送费分账'
        );
        app()->make(WechatProfitSharingServices::class)->addReceiver((string)$receiver['account']);
        $response = app()->make(WechatProfitSharingServices::class)->requestSharing($transactionId, $outOrderNo, [$receiver]);
        $status = $this->wechatResponseStatus($response);
        $update = [
            'wechat_transaction_id' => $transactionId,
            'profit_sharing_no' => (string)($response['order_id'] ?? $response['out_order_no'] ?? $outOrderNo),
            'status' => $status,
            'update_time' => time(),
        ];
        if ($status === 'failed' && $this->hasColumn('order_settlement_record', 'fail_reason')) {
            $update['fail_reason'] = $this->wechatFailReason($response);
        }
        $this->dao->update((int)$record['id'], $update);
        if (in_array($status, ['pending', 'success', 'failed'], true)) {
            app()->make(DeliveryFeeRecordServices::class)->syncWechatSharingStatus(
                (string)$record['order_id'],
                $status,
                (string)$update['profit_sharing_no'],
                (string)($update['fail_reason'] ?? '')
            );
        }
        return $this->dao->get((int)$record['id']);
    }

    public function handleProfitSharingNotify(array $notify)
    {
        $outOrderNo = (string)($notify['out_order_no'] ?? $notify['order_id'] ?? '');
        if ($outOrderNo === '') {
            throw new ApiException('分账回调缺少分账单号');
        }
        $record = $this->dao->getOne(['profit_sharing_no' => $outOrderNo]);
        if (!$record) {
            $record = $this->dao->getOne(['profit_sharing_no' => (string)($notify['order_id'] ?? '')]);
        }
        if (!$record) {
            throw new ApiException('分账记录不存在');
        }
        $status = $this->wechatResponseStatus($notify);
        $failReason = $this->wechatFailReason($notify);
        $this->updateStatus((int)$record['id'], $status, $outOrderNo, $failReason);
        if (in_array((string)$record['settlement_type'], ['wechat_profit_sharing', 'delivery_fee_wechat_profit_sharing'], true)) {
            app()->make(DeliveryFeeRecordServices::class)->syncWechatSharingStatus((string)$record['order_id'], $status, $outOrderNo, $failReason);
        }
        return $this->dao->get((int)$record['id']);
    }

    public function updateStatus(int $id, string $status, string $profitSharingNo = '', string $message = '')
    {
        if (!in_array($status, ['pending', 'success', 'failed', 'return_pending', 'returned', 'refund_blocked'], true)) {
            throw new ApiException('分账状态不支持');
        }
        $data = [
            'status' => $status,
            'update_time' => time(),
        ];
        if ($profitSharingNo !== '') {
            $data['profit_sharing_no'] = $profitSharingNo;
        }
        if ($message !== '') {
            if ($this->hasColumn('order_settlement_record', 'fail_reason')) {
                $data['fail_reason'] = $message;
            } else {
                \think\facade\Log::info('FMCG profit sharing status updated: ' . $message);
            }
        }
        return $this->dao->update($id, $data);
    }

    protected function markFailed(int $id, string $message): void
    {
        $this->updateStatus($id, 'failed', '', mb_substr($message, 0, 255));
        $record = $this->dao->get($id);
        if ($record && in_array((string)$record['settlement_type'], ['wechat_profit_sharing', 'delivery_fee_wechat_profit_sharing'], true)) {
            app()->make(DeliveryFeeRecordServices::class)->syncWechatSharingStatus(
                (string)$record['order_id'],
                'failed',
                (string)($record['profit_sharing_no'] ?? ''),
                mb_substr($message, 0, 255)
            );
        }
    }

    protected function orderReceivableAmount(array $order): float
    {
        return round(max(0, (float)($order['pay_price'] ?? 0)), 2);
    }

    protected function wechatResponseStatus(array $response): string
    {
        $state = strtoupper((string)($response['state'] ?? ''));
        if ($state === 'FINISHED') {
            return 'success';
        }
        if (in_array($state, ['CLOSED', 'FAILED'], true)) {
            return 'failed';
        }
        foreach ((array)($response['receivers'] ?? []) as $receiver) {
            $result = strtoupper((string)($receiver['result'] ?? ''));
            if ($result === 'SUCCESS') {
                return 'success';
            }
            if (in_array($result, ['CLOSED', 'FAILED'], true)) {
                return 'failed';
            }
        }
        return 'pending';
    }

    protected function wechatFailReason(array $response): string
    {
        foreach ((array)($response['receivers'] ?? []) as $receiver) {
            if (!empty($receiver['fail_reason'])) {
                return mb_substr((string)$receiver['fail_reason'], 0, 255);
            }
        }
        return '';
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
