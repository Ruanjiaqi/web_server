<?php
namespace app\services\settlement;

use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use crmeb\services\pay\Pay;
use think\facade\Db;

class WechatProfitSharingServices extends BaseServices
{
    public function addReceiver(string $mchId, string $name = '', string $relationType = 'DISTRIBUTOR'): array
    {
        $receiver = $this->receiver($mchId, $name, $relationType);
        return $this->wechat()->profitSharingAddReceiver($receiver);
    }

    public function requestSharing(string $transactionId, string $outOrderNo, array $receivers, bool $unfreezeUnsplit = false): array
    {
        if ($transactionId === '') {
            throw new ApiException('微信支付流水号缺失，不能发起分账');
        }
        if ($outOrderNo === '' || !$receivers) {
            throw new ApiException('微信分账参数缺失');
        }
        return $this->wechat()->profitSharingOrders($transactionId, $outOrderNo, $receivers, $unfreezeUnsplit);
    }

    public function querySharing(string $transactionId, string $outOrderNo): array
    {
        return $this->wechat()->profitSharingQuery($transactionId, $outOrderNo);
    }

    public function finishSharing(string $transactionId, string $outOrderNo, string $description = '订单已完成'): array
    {
        return $this->wechat()->profitSharingFinish($transactionId, $outOrderNo, $description);
    }

    public function returnSharing(string $outOrderNo, string $outReturnNo, string $returnMchid, float $amount, string $description = '分账回退'): array
    {
        return $this->wechat()->profitSharingReturn($outOrderNo, $outReturnNo, $returnMchid, (int)bcmul((string)$amount, '100', 0), $description);
    }

    public function receiver(string $mchId, string $name = '', string $relationType = 'DISTRIBUTOR'): array
    {
        if ($mchId === '') {
            throw new ApiException('分销商微信商户号缺失，不能发起分账');
        }
        $receiver = [
            'type' => 'MERCHANT_ID',
            'account' => $mchId,
            'relation_type' => $relationType,
        ];
        if ($name !== '') {
            $receiver['name'] = $name;
        }
        return $receiver;
    }

    public function buildReceiverForDistributor(int $distributorId, float $amount, string $description = 'FMCG代销订单分账'): array
    {
        $distributor = Db::name('distributor')->where('id', $distributorId)->find();
        if (!$distributor) {
            throw new ApiException('分销商不存在，不能发起分账');
        }
        $receiver = $this->receiver((string)($distributor['wechat_mch_id'] ?? ''), (string)($distributor['store_name'] ?? ''));
        $receiver['amount'] = (int)bcmul((string)$amount, '100', 0);
        $receiver['description'] = $description;
        return $receiver;
    }

    protected function wechat()
    {
        $required = [
            'pay_weixin_mchid' => sys_config('pay_weixin_mchid'),
            'pay_weixin_key_v3' => sys_config('pay_weixin_key_v3'),
            'pay_weixin_serial_no' => sys_config('pay_weixin_serial_no'),
        ];
        foreach ($required as $key => $value) {
            if (!$value) {
                throw new ApiException('微信分账配置缺失：' . $key);
            }
        }
        /** @var Pay $pay */
        $pay = app()->make(Pay::class, ['v3_wechat_pay']);
        return $pay;
    }
}
