<?php
namespace app\adminapi\validate\distributor;

use think\Validate;

class DistributorValidate extends Validate
{
    protected $rule = [
        'store_name' => 'require|max:80',
        'phone' => 'require|mobile',
        'qualification_type' => 'require|in:personal,individual,enterprise',
        'cooperation_mode' => 'require|in:commission,consignment',
    ];

    protected $message = [
        'store_name.require' => '请填写店铺名称',
        'phone.require' => '请填写手机号',
        'phone.mobile' => '手机号格式错误',
        'qualification_type.require' => '请选择资质类型',
        'cooperation_mode.require' => '请选择合作模式',
    ];
}
