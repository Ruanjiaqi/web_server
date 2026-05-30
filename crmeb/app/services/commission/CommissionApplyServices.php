<?php
namespace app\services\commission;

use app\dao\commission\CommissionApplyDao;
use app\services\BaseServices;
use app\services\distributor\DistributorServices;
use app\services\message\MessageSystemServices;
use crmeb\exceptions\ApiException;

class CommissionApplyServices extends BaseServices
{
    public function __construct(CommissionApplyDao $dao)
    {
        $this->dao = $dao;
    }

    public function submit(int $uid, array $data)
    {
        $exists = $this->dao->getOne(['uid' => $uid, 'status' => 0]);
        if ($exists) {
            throw new ApiException('已有待审核申请');
        }
        $apply = $this->dao->save([
            'uid' => $uid,
            'real_name' => $data['real_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'id_card' => $data['id_card'] ?? '',
            'material_urls' => json_encode($data['material_urls'] ?? [], JSON_UNESCAPED_UNICODE),
            'status' => 0,
            'apply_time' => time(),
            'remark' => $data['remark'] ?? '',
        ]);
        $this->notify(0, '佣金分销商申请待审核', '用户提交了佣金分销商申请，请及时审核。', 'fmcg_commission_apply_submit', ['uid' => $uid, 'apply_id' => (int)$apply->id], 2);
        event('CustomEventListener', ['fmcg_commission_apply_submit', ['uid' => $uid, 'apply_id' => (int)$apply->id]]);
        return $apply;
    }

    public function review(int $id, int $status, string $reason = '')
    {
        return $this->transaction(function () use ($id, $status, $reason) {
            $status = $this->normalizeReviewStatus($status);
            $apply = $this->dao->get($id);
            if (!$apply) {
                throw new ApiException('申请不存在');
            }
            if ((int)$apply['status'] !== 0) {
                return $apply;
            }
            $apply->status = $status;
            $apply->review_time = time();
            $apply->review_reason = $reason;
            $apply->save();
            if ($status === 1) {
                app()->make(DistributorServices::class)->createDistributor([
                    'uid' => (int)$apply->uid,
                    'qualification_type' => 'personal',
                    'cooperation_mode' => 'commission',
                    'store_name' => $apply->real_name . '的佣金店铺',
                    'contact_name' => $apply->real_name,
                    'phone' => $apply->phone,
                ]);
            }
            $this->notify((int)$apply->uid, '佣金分销商申请审核结果', $status === 1 ? '您的佣金分销商申请已通过。' : '您的佣金分销商申请未通过：' . $reason, 'fmcg_commission_apply_review', ['apply_id' => $id, 'status' => $status, 'reason' => $reason], 1);
            event('CustomEventListener', ['fmcg_commission_apply_review', ['uid' => (int)$apply->uid, 'apply_id' => $id, 'status' => $status, 'reason' => $reason]]);
            return $apply;
        });
    }

    protected function normalizeReviewStatus(int $status): int
    {
        if ($status === -1) {
            return 2;
        }
        if (!in_array($status, [1, 2], true)) {
            throw new ApiException('审核状态不支持');
        }
        return $status;
    }

    protected function notify(int $uid, string $title, string $content, string $mark, array $data, int $type): void
    {
        try {
            app()->make(MessageSystemServices::class)->save([
                'mark' => $mark,
                'uid' => $uid,
                'title' => $title,
                'content' => $content,
                'type' => $type,
                'add_time' => time(),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('FMCG commission apply notice failed: ' . $e->getMessage());
        }
    }
}
