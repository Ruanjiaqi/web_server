<?php
$root = dirname(__DIR__);

function fmcg_assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fmcg_read($path)
{
    fmcg_assert_true(is_file($path), "missing file {$path}");
    return file_get_contents($path);
}

$sql = fmcg_read($root . '/public/install/crmeb.sql');
$tables = [
    'eb_distributor',
    'eb_distributor_user_bind',
    'eb_distributor_sku_inventory',
    'eb_distributor_inventory_log',
    'eb_distributor_purchase_order',
    'eb_commission_apply',
    'eb_commission_rule',
    'eb_commission_settlement',
    'eb_order_settlement_record',
    'eb_delivery_fee_record',
    'eb_buy_x_get_x_campaign',
    'eb_buy_x_get_x_usage',
    'eb_distributor_share_event',
];
foreach ($tables as $table) {
    fmcg_assert_true((bool)preg_match('/CREATE TABLE IF NOT EXISTS `?' . preg_quote($table, '/') . '`?/i', $sql), "missing schema table {$table}");
}
foreach (['distributor_id', 'fmcg_delivery_type', 'fmcg_inventory_locked'] as $column) {
    fmcg_assert_true(strpos($sql, "`{$column}`") !== false, "store_order missing {$column}");
}

$contracts = [
    '/app/adminapi/route/distributor.php' => ['commission/eligible_orders', 'purchase/:id/ship', 'settlement/:id/share', 'settlement/scan_share'],
    '/app/adminapi/controller/v1/distributor/DistributorController.php' => ['cooperation_mode', 'eligibleCommissionOrders', 'shipPurchase', 'requestSettlementSharing', 'scanSettlementSharing'],
    '/app/api/controller/v1/distributor/DistributorController.php' => ['commissionApply', 'purchaseCreate', 'purchasePay', 'deliveryOptions', 'updateDelivery', 'fmcg_delivery_type', 'shareClick'],
    '/app/services/distributor/DistributorServices.php' => ['createDistributor', 'bindUser', 'cooperation_mode', "preg_match('/^\\d{8}$/", 'recordShareEvent', 'order_paid'],
    '/app/services/inventory/DistributorInventoryServices.php' => ['function lock', 'function release', 'function deductLocked'],
    '/app/services/purchase/DistributorPurchaseOrderServices.php' => ['function createOrder', 'function createPayParams', 'function markPaidByPayment', 'fmcg_purchase', 'function receive'],
    '/app/services/commission/CommissionSettlementServices.php' => ['eligibleOrders', 'createManualSettlement'],
    '/app/services/distributor/BuyXGetXCampaignServices.php' => ['function publish', 'reserveQuotaForOrder', 'releaseOrder', 'markPaid', 'markFulfilled', 'cancelOrder'],
];
foreach ($contracts as $file => $needles) {
    $content = fmcg_read($root . $file);
    foreach ($needles as $needle) {
        fmcg_assert_true(strpos($content, $needle) !== false, "{$file} missing {$needle}");
    }
}

$fmcgOrder = fmcg_read($root . '/app/services/order/FmcgOrderAdapterServices.php');
fmcg_assert_true(strpos($fmcgOrder, 'deductLocked') !== false, 'paid order must deduct locked distributor inventory');
fmcg_assert_true(strpos($fmcgOrder, 'releaseLockedInventory') !== false, 'cancel path must expose distributor inventory release');
foreach (['appendGiftItems', 'buy_x_get_x_usage', 'fmcg_is_gift', 'truePrice', 'releaseGiftQuotaByOrderId', 'afterOrderDelivered', 'afterOrderReceived', 'recordShareEvent'] as $needle) {
    fmcg_assert_true(strpos($fmcgOrder, $needle) !== false, "FMCG gift order path missing {$needle}");
}
fmcg_assert_true(strpos($fmcgOrder, "product_attr_unique") !== false && strpos($fmcgOrder, "\$row['unique'] ?:") === false, 'FMCG inventory must use SKU unique, not store_order_cart_info row unique');
fmcg_assert_true(strpos($fmcgOrder, 'recordConsignment') === false, 'paid consignment order must not create wechat profit-sharing record');
fmcg_assert_true(strpos($fmcgOrder, 'assertBoundDistributorPayload') !== false, 'order creation must validate distributor binding server-side');
fmcg_assert_true(strpos($fmcgOrder, "\$distributorId = (int)(\$payload['distributor_id'] ?? 0);") === false, 'order creation must not trust frontend distributor_id before user binding');
fmcg_assert_true(strpos($fmcgOrder, "where('delivery_fee_record'") !== false || strpos($fmcgOrder, 'delivery_fee_record') !== false, 'delivery fee application must be idempotent by order');
fmcg_assert_true(strpos($fmcgOrder, "['delivery_fee']") === false && strpos($fmcgOrder, 'DeliveryFeeCalculatorServices') !== false, 'order creation must calculate delivery fee server-side');

$settlementService = fmcg_read($root . '/app/services/settlement/OrderSettlementRecordServices.php');
foreach (['createEligibleForOrder', 'scanAndShare', 'requestSharing', 'recordDeliveryFeeSharing', 'handleProfitSharingNotify', 'wechat_transaction_id', 'fail_reason'] as $needle) {
    fmcg_assert_true(strpos($settlementService, $needle) !== false, "settlement service missing {$needle}");
}
foreach (['orderReceivableAmount', "pay_price'] ?? 0", 'FMCG代销订单款及配送费分账', '配送费已并入订单款分账', 'buildReceiverForDistributor'] as $needle) {
    fmcg_assert_true(strpos($settlementService, $needle) !== false, "settlement service must share order payment with delivery fee: {$needle}");
}
fmcg_assert_true(strpos($settlementService, 'productReceivableAmount') === false, 'settlement service must not use product-only receivable calculation');
fmcg_assert_true(strpos($settlementService, "'DFPS'") === false && strpos($settlementService, 'FMCG配送费分账') === false, 'settlement service must not create standalone delivery fee profit sharing orders');
fmcg_assert_true(strpos($settlementService, 'syncWechatSharingStatus') !== false, 'settlement service must sync delivery fee record status from the unified sharing record');
$taskJob = fmcg_read($root . '/app/jobs/TaskJob.php');
fmcg_assert_true(strpos($taskJob, 'fmcgScanWechatProfitSharing') !== false && strpos($taskJob, 'scanAndShare') !== false, 'task job must expose FMCG profit sharing scan');
$crontabRun = fmcg_read($root . '/app/services/system/crontab/CrontabRunServices.php');
fmcg_assert_true(strpos($crontabRun, 'fmcgWechatProfitSharing') !== false && strpos($crontabRun, 'scanAndShare') !== false, 'crontab must expose FMCG profit sharing scan');
$profitSharing = fmcg_read($root . '/app/services/settlement/WechatProfitSharingServices.php');
foreach (['addReceiver', 'requestSharing', 'querySharing', 'finishSharing', 'returnSharing', 'pay_weixin_serial_no'] as $needle) {
    fmcg_assert_true(strpos($profitSharing, $needle) !== false, "wechat profit sharing missing {$needle}");
}
$afterSaleSettlement = fmcg_read($root . '/app/services/order/FmcgAfterSaleSettlementServices.php');
foreach (['onRefundApplied', 'onRefundSucceeded', 'onRefundCanceled', 'commission_settlement', 'order_settlement_record', 'returnSharing', 'cancelGiftUsageByOrderId', 'return_pending'] as $needle) {
    fmcg_assert_true(strpos($afterSaleSettlement, $needle) !== false, "FMCG after-sale settlement sync missing {$needle}");
}

$storeOrderController = fmcg_read($root . '/app/api/controller/v1/order/StoreOrderController.php');
fmcg_assert_true(strpos($storeOrderController, 'assertBoundDistributorPayload') !== false, 'real order create path must validate FMCG distributor payload before creating order');
fmcg_assert_true(strpos($storeOrderController, 'catch (\\Throwable $e)') !== false && strpos($storeOrderController, 'releaseLockedInventory') !== false && strpos($storeOrderController, "'is_cancel' => 1") !== false, 'FMCG post-create failure must roll back created order and locks');

$inventoryService = fmcg_read($root . '/app/services/inventory/DistributorInventoryServices.php');
fmcg_assert_true(strpos($inventoryService, "whereRaw('stock - locked_stock >= ?'") !== false && strpos($inventoryService, "inc('locked_stock', \$num)") !== false, 'inventory lock must use conditional atomic update');
fmcg_assert_true(strpos($inventoryService, 'lockedForBiz') !== false, 'inventory release/deduct must be scoped to the order lock record');

$productController = fmcg_read($root . '/app/api/controller/v1/store/StoreProductController.php');
fmcg_assert_true(strpos($productController, "success(['bind_required' => 1, 'list' => []])") === false, 'product list APIs must not wrap data and break array response contract');
fmcg_assert_true(strpos($productController, "Fmcg-Bind-Required") !== false, 'product list binding metadata should be exposed outside the data array');
$productScope = fmcg_read($root . '/app/services/product/product/FmcgProductScopeServices.php');
foreach (['boundDistributorId', 'filterListByDistributorInventory', 'trimDetailByDistributorInventory', 'good_list', 'filterHomeData'] as $needle) {
    fmcg_assert_true(strpos($productScope, $needle) !== false, "shared product scope missing {$needle}");
}
$diy = fmcg_read($root . '/app/services/diy/DiyServices.php');
fmcg_assert_true(strpos($diy, 'filterHomeData') !== false, 'DIY product source must use shared FMCG product filter');
$productService = fmcg_read($root . '/app/services/product/product/StoreProductServices.php');
fmcg_assert_true(strpos($productService, 'good_list') !== false && strpos($productService, 'FmcgProductScopeServices') !== false, 'detail good_list must be filtered by distributor inventory');

$paySuccess = fmcg_read($root . '/app/services/order/StoreOrderSuccessServices.php');
fmcg_assert_true(strpos($paySuccess, 'afterOrderPaid') !== false, 'CRMEB pay success must call FMCG afterOrderPaid');

$storeOrder = fmcg_read($root . '/app/services/order/StoreOrderServices.php');
fmcg_assert_true(substr_count($storeOrder, 'releaseLockedInventory') >= 2, 'manual and timeout cancel must release distributor locked inventory');
$storeOrderRefund = fmcg_read($root . '/app/services/order/StoreOrderRefundServices.php');
foreach (['onRefundApplied', 'onRefundSucceeded', 'onRefundCanceled'] as $needle) {
    fmcg_assert_true(strpos($storeOrderRefund, $needle) !== false, "refund flow must sync FMCG after-sale settlement: {$needle}");
}
$storeOrderRefundController = fmcg_read($root . '/app/api/controller/v1/order/StoreOrderRefundController.php');
fmcg_assert_true(strpos($storeOrderRefundController, 'onRefundCanceled') !== false, 'user refund cancel must restore FMCG settlement state');
$storeOrderDelivery = fmcg_read($root . '/app/services/order/StoreOrderDeliveryServices.php');
fmcg_assert_true(strpos($storeOrderDelivery, 'afterOrderDelivered') !== false, 'delivery must fulfill buy-x-get-x usage');
$storeOrderTake = fmcg_read($root . '/app/services/order/StoreOrderTakeServices.php');
fmcg_assert_true(strpos($storeOrderTake, 'afterOrderReceived') !== false, 'receive/take must fulfill buy-x-get-x usage');

$distributorApi = fmcg_read($root . '/app/api/controller/v1/distributor/DistributorController.php');
fmcg_assert_true(strpos($distributorApi, 'currentDistributorId') !== false, 'mngt distributor APIs must derive distributor id from authenticated user');
fmcg_assert_true(strpos($distributorApi, "['distributor_id', 0]") === false, 'mngt write APIs must not trust frontend distributor_id');
$apiRoute = fmcg_read($root . '/app/api/route/v1.php');
foreach (['mngt/purchase/:id/pay', 'order/fmcg_delivery_options', 'distributor/delivery/options', 'distributor/share/click'] as $needle) {
    fmcg_assert_true(strpos($apiRoute, $needle) !== false, "api route missing {$needle}");
}

$upgrade = fmcg_read($root . '/upgrade/versions/v6.0.1-fmcg.php');
foreach (['ADD KEY `distributor_id`', 'fmcg-distributor-entry', '/fmcg-distributor.html', 'fmcg-buy-x-get-x-save'] as $needle) {
    fmcg_assert_true(strpos($upgrade, $needle) !== false, "FMCG upgrade migration missing {$needle}");
}
fmcg_assert_true(strpos($upgrade, '/admin/fmcg-distributor.html') === false, 'FMCG upgrade migration must not seed duplicated /admin path');

$deliveryCalculator = fmcg_read($root . '/app/services/settlement/DeliveryFeeCalculatorServices.php');
foreach (['function options', 'function calculate', 'fmcg_delivery_fee_', 'pickup', 'merchant', 'city', 'express'] as $needle) {
    fmcg_assert_true(strpos($deliveryCalculator, $needle) !== false, "delivery calculator missing {$needle}");
}
foreach (['isSameCity', 'address_id', 'fmcg_delivery_fee_city_same_city', 'fmcg_delivery_fee_merchant_remote', 'fmcg_delivery_fee_express_base', 'contextWeight', 'contextItemCount'] as $needle) {
    fmcg_assert_true(strpos($deliveryCalculator, $needle) !== false, "delivery calculator missing enhanced rule {$needle}");
}
foreach (['resolveChargeItems', 'store_cart', 'store_product_attr_value', 'fmcg_items', 'attachServerWeights'] as $needle) {
    fmcg_assert_true(strpos($deliveryCalculator, $needle) !== false, "delivery calculator must resolve server-side charge data {$needle}");
}
fmcg_assert_true(strpos($deliveryCalculator, "isset(\$context['weight'])") === false && strpos($deliveryCalculator, "isset(\$context['total_num'])") === false, 'delivery calculator must not trust client weight/total_num');

$env = fmcg_read($root . '/.env');
fmcg_assert_true(strpos($env, "\r") === false && strpos($env, "\n[DATABASE]\n") !== false, '.env must be LF formatted and parseable');

$adminRoute = fmcg_read($root . '/app/adminapi/route/distributor.php');
foreach (['buy_x_get_x/list', 'buy_x_get_x/save'] as $needle) {
    fmcg_assert_true(strpos($adminRoute, $needle) !== false, "admin route missing {$needle}");
}
$adminPage = fmcg_read($root . '/public/admin/fmcg-distributor.html');
fmcg_assert_true(strpos($adminPage, 'buy_x_get_x/list') !== false && strpos($adminPage, 'commission/apply') !== false, 'admin frontend handoff page missing FMCG management links');
fmcg_assert_true(strpos($adminPage, "headers['Authori-zation']") !== false, 'admin frontend must send CRMEB Authori-zation header');
fmcg_assert_true(strpos($adminPage, 'commission/rule/list') !== false && strpos($adminPage, 'commission/settlement/list') !== false, 'admin frontend must expose commission rule and settlement operations');
foreach (['发起微信分账', '扫描待分账', "status: 'paid'", "status: 'rejected'"] as $needle) {
    fmcg_assert_true(strpos($adminPage, $needle) !== false, "admin frontend missing {$needle}");
}
foreach (['campaignTitle', 'campaignProductId', 'campaignBuyNum', 'campaignGiftNum', 'campaignQuota', 'campaignStart', 'campaignEnd', 'saveCampaign', 'buy_x_get_x/save'] as $needle) {
    fmcg_assert_true(strpos($adminPage, $needle) !== false, "admin buy-x-get-x UI missing {$needle}");
}
foreach (['deliveryPaymentNo', 'deliveryFailReason', 'deliveryBatchNo', '人工结算'] as $needle) {
    fmcg_assert_true(strpos($adminPage, $needle) !== false, "admin delivery fee settlement UI missing {$needle}");
}

$commissionSettlement = fmcg_read($root . '/app/services/commission/CommissionSettlementServices.php');
foreach (['settleableOrderQuery', "where('o.paid', 1)", "where('o.refund_status', 0)", "whereNotIn('o.order_id'", 'lock(true)', 'updateStatus'] as $needle) {
    fmcg_assert_true(strpos($commissionSettlement, $needle) !== false, "commission settlement missing guard {$needle}");
}
foreach (['assertCommissionDistributor', "where('status', 1)", "where('is_del', 0)", "where('cooperation_mode', 'commission')"] as $needle) {
    fmcg_assert_true(strpos($commissionSettlement, $needle) !== false, "commission settlement must enforce commission distributor: {$needle}");
}
foreach (["'pending'", "'paid'", "'rejected'", "'refund_pending'", "'refunded'"] as $needle) {
    fmcg_assert_true(strpos($commissionSettlement, $needle) !== false, "commission settlement missing status {$needle}");
}
fmcg_assert_true(strpos($commissionSettlement, "'settled'") === false && strpos($commissionSettlement, "'failed'") === false, 'commission settlement statuses must use pending/paid/rejected/refund_pending/refunded consistently');
fmcg_assert_true(strpos($commissionSettlement, 'fmcg_is_gift') !== false, 'commission settlement must skip gift/free order lines');

$commissionApply = fmcg_read($root . '/app/services/commission/CommissionApplyServices.php');
fmcg_assert_true(strpos($commissionApply, '$this->transaction') !== false && strpos($commissionApply, 'createDistributor') !== false, 'commission apply review must be transactional');
fmcg_assert_true(strpos($commissionApply, 'MessageSystemServices') !== false && strpos($commissionApply, 'CustomEventListener') !== false, 'commission apply review must notify via system message/custom event');

$cartService = fmcg_read($root . '/app/services/order/StoreCartServices.php');
foreach (['assertFmcgCartStock', 'fmcgCartAvailability', 'fmcgInvalid', 'fmcgAvailable', 'DistributorServices', 'distributor_sku_inventory'] as $needle) {
    fmcg_assert_true(strpos($cartService, $needle) !== false, "cart FMCG stock guard missing {$needle}");
}

$categoryController = fmcg_read($root . '/app/api/controller/v1/store/CategoryController.php');
$categoryService = fmcg_read($root . '/app/services/product/product/StoreCategoryServices.php');
fmcg_assert_true(strpos($categoryController, 'getFmcgCategory') !== false, 'category API must use FMCG-scoped category list');
foreach (['getFmcgCategory', 'distributor_sku_inventory', 'store_product_cate', 'whereRaw', 'CATEGORY'] as $needle) {
    fmcg_assert_true(strpos($categoryService, $needle) !== false, "category FMCG scope missing {$needle}");
}
fmcg_assert_true(strpos($categoryService, "CacheService::remember('CATEGORY'") !== false, 'global CATEGORY cache must remain isolated from FMCG scoped category query');

$distributorService = fmcg_read($root . '/app/services/distributor/DistributorServices.php');
foreach (['new_bind_count', 'conversion_order_count', 'conversion_amount'] as $needle) {
    fmcg_assert_true(strpos($distributorService, $needle) !== false, "share/workbench stats missing {$needle}");
}
foreach (['recordShareEventByIdentifyCode', "'share'", "'click'", "'bind'", "'order_paid'", 'distributor_share_event'] as $needle) {
    fmcg_assert_true(strpos($distributorService, $needle) !== false, "share attribution missing {$needle}");
}

$deliveryFee = fmcg_read($root . '/app/services/settlement/DeliveryFeeRecordServices.php');
fmcg_assert_true(strpos($deliveryFee, "getOne(['order_id' => \$orderId]") !== false, 'delivery fee records must be idempotent per order');
foreach (['settlement_batch_no', 'payment_no', 'fail_reason', 'manualSettle', 'markFailed', 'defaultReceiver', 'settlementPlan', 'createSettlementPlanForOrder', 'syncWechatSharingStatus', 'retry_count', 'reconcile_status', 'headquarters_payable', 'wechat_profit_sharing'] as $needle) {
    fmcg_assert_true(strpos($deliveryFee, $needle) !== false, "delivery fee settlement plan loop missing {$needle}");
}

$publicController = fmcg_read($root . '/app/api/controller/v1/PublicController.php');
foreach (['function themeProduct', 'FmcgProductScopeServices', 'boundDistributorId', 'filterListByDistributorInventory', 'Fmcg-Bind-Required'] as $needle) {
    fmcg_assert_true(strpos($publicController, $needle) !== false, "theme/product FMCG scope missing {$needle}");
}
$publicControllerV2 = fmcg_read($root . '/app/api/controller/v2/PublicController.php');
foreach (['FmcgProductScopeServices', 'boundDistributorId', 'Fmcg-Bind-Required'] as $needle) {
    fmcg_assert_true(strpos($publicControllerV2, $needle) !== false, "v2 home FMCG scope missing {$needle}");
}
$productService = fmcg_read($root . '/app/services/product/product/StoreProductServices.php');
foreach (['function getRecommendProductArr', 'boundDistributorIdByUid', 'filterListByDistributorInventory'] as $needle) {
    fmcg_assert_true(strpos($productService, $needle) !== false, "home recommend scope missing {$needle}");
}
$sql = fmcg_read($root . '/public/install/crmeb.sql');
foreach (['订单实付分账金额，包含配送费', 'wechat_profit_sharing=订单款和配送费统一分账', 'settlement_method', 'settlement_subject', 'settlement_batch_no', 'payment_no', 'fail_reason', 'retry_count', 'reconcile_status', 'reserved/paid/confirmed/fulfilled/released/canceled', 'share/click/bind/order_paid'] as $needle) {
    fmcg_assert_true(strpos($sql, $needle) !== false, "FMCG settlement schema/comment missing {$needle}");
}
fmcg_assert_true(strpos($sql, '商品实收分账金额，不含配送费') === false && strpos($sql, '配送费独立分账') === false, 'FMCG install schema must not describe product-only or standalone delivery fee sharing');

$sql = fmcg_read($root . '/public/install/crmeb.sql');
foreach (['fmcg-distributor-entry', '/fmcg-distributor.html', 'fmcg-commission-apply', 'fmcg-buy-x-get-x-save'] as $needle) {
    fmcg_assert_true(strpos($sql, $needle) !== false, "admin menu seed missing {$needle}");
}
fmcg_assert_true(strpos($sql, '/admin/fmcg-distributor.html') === false, 'admin menu seed must not include duplicated /admin path');
foreach (['Dockerfile', 'docker-compose.yml', 'container.config.json'] as $file) {
    fmcg_assert_true(is_file($root . '/' . $file), "missing docker/cloud config {$file}");
}
$dockerCompose = fmcg_read($root . '/docker-compose.yml');
$containerConfig = fmcg_read($root . '/container.config.json');
$databaseConfig = fmcg_read($root . '/config/database.php');
$startScript = fmcg_read($root . '/container/start.sh');
$readme = fmcg_read($root . '/README.md');
foreach (['WECHAT_PAY_MCHID', 'WECHAT_PAY_KEY_V3', 'WECHAT_PAY_SERIAL_NO', 'WECHAT_PAY_CERT_PATH', 'WECHAT_PAY_CERT_CONTENT', 'WECHAT_PAY_PRIVATE_KEY_PATH', 'WECHAT_PAY_PRIVATE_KEY_CONTENT', 'WECHAT_PAY_PROFIT_SHARING_NOTIFY_URL', 'WECHAT_PAY_NOTIFY_URL', 'WECHAT_PAY_TRANSFER_NOTIFY_URL', 'SITE_URL'] as $needle) {
    fmcg_assert_true(strpos($dockerCompose, $needle) !== false, "docker-compose missing {$needle}");
    fmcg_assert_true(strpos($containerConfig, $needle) !== false, "container config missing {$needle}");
    fmcg_assert_true(strpos($readme, $needle) !== false, "README missing {$needle}");
}
foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $needle) {
    fmcg_assert_true(strpos($databaseConfig, $needle) !== false, "database env bridge missing {$needle}");
}
foreach (['/docker-entrypoint-initdb.d/crmeb.sql', 'public/install/crmeb.sql'] as $needle) {
    fmcg_assert_true(strpos($dockerCompose, $needle) !== false, "docker init missing {$needle}");
}
foreach (['write_wechat_pay_env', 'system_config', 'system_pem', 'WECHAT_PAY_PRIVATE_KEY_CONTENT'] as $needle) {
    fmcg_assert_true(strpos($startScript, $needle) !== false, "container start env bridge missing {$needle}");
}
foreach (['配送费纳入资金闭环', '订单款和配送费通过同一笔', '总部应付结算计划', '/api/pay/notify/wechat', '/api/pay/notify/profit_sharing', '/api/transfer/notify/wechat', 'CloudBase Run Secret', '部署后校验'] as $needle) {
    fmcg_assert_true(strpos($readme, $needle) !== false, "README delivery fee settlement wording missing {$needle}");
}
$payClient = fmcg_read($root . '/crmeb/services/easywechat/v3pay/PayClient.php');
foreach (['profitSharingNotifyUrl', "'notify_url' => \$this->profitSharingNotifyUrl()", 'handleProfitSharingNotify', 'verifyWechatpaySignature', 'Wechatpay-Signature', 'PROFITSHARING.', 'decrypt($request->post'] as $needle) {
    fmcg_assert_true(strpos($payClient, $needle) !== false, "PayClient profit sharing notify contract missing {$needle}");
}
$v3WechatPay = fmcg_read($root . '/crmeb/services/pay/storage/V3WechatPay.php');
foreach (['fmcg_wechat_profit_sharing_notify_url', '/api/pay/notify/profit_sharing', 'handleProfitSharingNotify', 'OrderSettlementRecordServices'] as $needle) {
    fmcg_assert_true(strpos($v3WechatPay, $needle) !== false, "V3WechatPay profit sharing notify contract missing {$needle}");
}
foreach (['pay/notify/profit_sharing', "'profit_sharing'"] as $needle) {
    fmcg_assert_true(strpos($apiRoute, $needle) !== false, "api route missing profit sharing notify {$needle}");
}

echo "fmcg-contract-check-ok\n";
