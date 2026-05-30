<?php
namespace app\services\settlement;

use app\dao\settlement\DeliveryFeeRecordDao;
use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DeliveryFeeRecordServices extends BaseServices
{
    public function __construct(DeliveryFeeRecordDao $dao)
    {
        $this->dao = $dao;
    }

    public function record(int $distributorId, string $orderId, string $deliveryType, float $fee, string $receiver = '')
    {
        $exists = $this->dao->getOne(['order_id' => $orderId]);
        if ($exists) {
            return $exists;
        }
        $plan = $this->settlementPlan($deliveryType, $distributorId);
        $data = [
            'distributor_id' => $distributorId,
            'order_id' => $orderId,
            'delivery_type' => $deliveryType,
            'fee' => $fee,
            'receiver' => $receiver ?: $plan['receiver'],
            'settlement_batch_no' => $plan['settlement_batch_no'],
            'status' => 'pending',
            'add_time' => time(),
            'update_time' => time(),
        ];
        foreach (['settlement_method', 'settlement_subject', 'reconcile_status'] as $column) {
            if ($this->hasColumn('delivery_fee_record', $column)) {
                $data[$column] = $column === 'reconcile_status' ? 'unmatched' : $plan[$column];
            }
        }
        return $this->dao->save($data);
    }

    public function updateStatus(int $id, string $status, string $receiver = '', string $paymentNo = '', string $failReason = '', string $settlementBatchNo = '')
    {
        if (!in_array($status, ['pending', 'processing', 'settled', 'failed'], true)) {
            throw new ApiException('配送费状态不支持');
        }
        $data = ['status' => $status, 'update_time' => time()];
        if ($receiver !== '') {
            $data['receiver'] = $receiver;
        }
        if ($paymentNo !== '' && $this->hasColumn('delivery_fee_record', 'payment_no')) {
            $data['payment_no'] = $paymentNo;
        }
        if ($failReason !== '' && $this->hasColumn('delivery_fee_record', 'fail_reason')) {
            $data['fail_reason'] = mb_substr($failReason, 0, 255);
        }
        if ($settlementBatchNo !== '' && $this->hasColumn('delivery_fee_record', 'settlement_batch_no')) {
            $data['settlement_batch_no'] = $settlementBatchNo;
        }
        if ($status === 'failed' && $this->hasColumn('delivery_fee_record', 'retry_count')) {
            $data['retry_count'] = Db::raw('retry_count + 1');
        }
        return $this->dao->update($id, $data);
    }

    public function manualSettle(int $id, string $paymentNo, string $receiver = '', string $settlementBatchNo = '')
    {
        if ($paymentNo === '') {
            throw new ApiException('请输入配送费付款流水');
        }
        return $this->updateStatus($id, 'settled', $receiver, $paymentNo, '', $settlementBatchNo);
    }

    public function createSettlementPlanForOrder($order): void
    {
        $order = is_object($order) ? $order->toArray() : (array)$order;
        $record = $this->dao->getOne(['order_id' => (string)($order['order_id'] ?? '')]);
        if (!$record || (float)$record['fee'] <= 0) {
            return;
        }
        $plan = $this->settlementPlan((string)$record['delivery_type'], (int)$record['distributor_id']);
        $batchNo = (string)($record['settlement_batch_no'] ?: $plan['settlement_batch_no']);
        $update = [
            'receiver' => (string)($record['receiver'] ?: $plan['receiver']),
            'settlement_batch_no' => $batchNo,
            'update_time' => time(),
        ];
        foreach (['settlement_method', 'settlement_subject'] as $column) {
            if ($this->hasColumn('delivery_fee_record', $column)) {
                $update[$column] = $plan[$column];
            }
        }
        if ((string)$record['delivery_type'] === 'merchant') {
            $update['status'] = (string)$record['status'] === 'settled' ? 'settled' : 'pending';
        } elseif ((string)$record['status'] === 'pending') {
            $update['status'] = 'processing';
        }
        $this->dao->update((int)$record['id'], $update);
    }

    public function syncWechatSharingStatus(string $orderId, string $sharingStatus, string $profitSharingNo = '', string $failReason = ''): void
    {
        $record = $this->dao->getOne(['order_id' => $orderId]);
        if (!$record || (string)$record['delivery_type'] !== 'merchant') {
            return;
        }
        $status = $sharingStatus === 'success' ? 'settled' : ($sharingStatus === 'failed' ? 'failed' : 'processing');
        $this->updateStatus((int)$record['id'], $status, '', $profitSharingNo, $failReason, (string)($record['settlement_batch_no'] ?: $this->settlementBatchNo('wechat_delivery')));
    }

    public function markFailed(int $id, string $failReason, string $receiver = '', string $settlementBatchNo = '')
    {
        if ($failReason === '') {
            throw new ApiException('请输入配送费失败原因');
        }
        return $this->updateStatus($id, 'failed', $receiver, '', $failReason, $settlementBatchNo);
    }

    protected function defaultReceiver(string $deliveryType, int $distributorId): string
    {
        if ($deliveryType === 'merchant' && $distributorId > 0) {
            return 'distributor:' . $distributorId;
        }
        if ($deliveryType === 'city') {
            return 'delivery_provider:city';
        }
        if ($deliveryType === 'express') {
            return 'delivery_provider:express';
        }
        return 'headquarters';
    }

    protected function settlementPlan(string $deliveryType, int $distributorId): array
    {
        $receiver = $this->defaultReceiver($deliveryType, $distributorId);
        $cooperationMode = '';
        if ($distributorId > 0) {
            $cooperationMode = (string)(Db::name('distributor')->where('id', $distributorId)->value('cooperation_mode') ?: '');
        }
        $method = $deliveryType === 'merchant' && $cooperationMode === 'consignment'
            ? 'wechat_profit_sharing'
            : 'headquarters_payable';
        $subject = 'headquarters';
        if ($deliveryType === 'merchant') {
            $subject = 'distributor';
        } elseif (in_array($deliveryType, ['city', 'express'], true)) {
            $subject = 'delivery_provider';
        }
        return [
            'settlement_method' => $method,
            'receiver' => $receiver,
            'settlement_subject' => $subject,
            'settlement_batch_no' => $this->settlementBatchNo($method),
        ];
    }

    protected function settlementBatchNo(string $method): string
    {
        return strtoupper(substr($method, 0, 3)) . date('Ymd') . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
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
