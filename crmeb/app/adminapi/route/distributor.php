<?php
use think\facade\Route;

Route::group('distributor', function () {
    Route::get('list', 'v1.distributor.DistributorController/index')->option(['real_name' => '分销商列表']);
    Route::post('save', 'v1.distributor.DistributorController/save')->option(['real_name' => '新增分销商']);
    Route::get('read/:id', 'v1.distributor.DistributorController/read')->option(['real_name' => '分销商详情']);
    Route::get('commission/apply', 'v1.distributor.DistributorController/commissionApplications')->option(['real_name' => '佣金分销商申请列表']);
    Route::post('commission/apply/:id/review', 'v1.distributor.DistributorController/reviewCommissionApply')->option(['real_name' => '审核佣金分销商申请']);
    Route::get('commission/rule/list', 'v1.distributor.DistributorController/commissionRules')->option(['real_name' => 'SKU佣金规则列表']);
    Route::post('commission/rule/save', 'v1.distributor.DistributorController/saveCommissionRule')->option(['real_name' => '保存SKU佣金规则']);
    Route::get(':id/commission/eligible_orders', 'v1.distributor.DistributorController/eligibleCommissionOrders')->option(['real_name' => '佣金可结算订单']);
    Route::post(':id/commission/settle', 'v1.distributor.DistributorController/settleCommission')->option(['real_name' => '生成佣金结算']);
    Route::get('commission/settlement/list', 'v1.distributor.DistributorController/commissionSettlements')->option(['real_name' => '佣金结算列表']);
    Route::post('commission/settlement/:id/status', 'v1.distributor.DistributorController/updateCommissionSettlement')->option(['real_name' => '更新佣金结算状态']);
    Route::get('purchase/list', 'v1.distributor.DistributorController/purchaseOrders')->option(['real_name' => '订货单列表']);
    Route::post('purchase/:id/pay', 'v1.distributor.DistributorController/payPurchase')->option(['real_name' => '确认订货款']);
    Route::post('purchase/:id/ship', 'v1.distributor.DistributorController/shipPurchase')->option(['real_name' => '订货单发货']);
    Route::get('inventory/list', 'v1.distributor.DistributorController/inventoryList')->option(['real_name' => '分销商库存列表']);
    Route::get('delivery_fee/list', 'v1.distributor.DistributorController/deliveryFeeRecords')->option(['real_name' => '配送费记录列表']);
    Route::post('delivery_fee/:id/status', 'v1.distributor.DistributorController/updateDeliveryFeeRecord')->option(['real_name' => '更新配送费状态']);
    Route::get('settlement/list', 'v1.distributor.DistributorController/settlementRecords')->option(['real_name' => '分账记录列表']);
    Route::post('settlement/:id/status', 'v1.distributor.DistributorController/updateSettlementRecord')->option(['real_name' => '更新分账状态']);
    Route::post('settlement/:id/share', 'v1.distributor.DistributorController/requestSettlementSharing')->option(['real_name' => '发起微信分账']);
    Route::post('settlement/scan_share', 'v1.distributor.DistributorController/scanSettlementSharing')->option(['real_name' => '扫描待分账记录']);
    Route::get('buy_x_get_x/list', 'v1.distributor.DistributorController/buyXGetXList')->option(['real_name' => '买赠活动列表']);
    Route::post('buy_x_get_x/save', 'v1.distributor.DistributorController/saveBuyXGetX')->option(['real_name' => '发布买赠活动']);
})->middleware([
    \app\http\middleware\AllowOriginMiddleware::class,
    \app\adminapi\middleware\AdminAuthTokenMiddleware::class,
    \app\adminapi\middleware\AdminCheckRoleMiddleware::class,
    \app\adminapi\middleware\AdminLogMiddleware::class
])->option(['mark' => 'distributor', 'mark_name' => '分销管理']);
