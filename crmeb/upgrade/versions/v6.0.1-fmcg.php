<?php

$createTables = [
    'distributor' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分销商ID',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '关联用户UID',
  `identify_code` varchar(16) NOT NULL DEFAULT '' COMMENT '8位识别号',
  `qualification_type` varchar(32) NOT NULL DEFAULT 'personal' COMMENT '资质类型 personal/individual/enterprise',
  `cooperation_mode` varchar(32) NOT NULL DEFAULT 'commission' COMMENT '合作模式 commission/consignment',
  `store_name` varchar(80) NOT NULL DEFAULT '' COMMENT '店铺名称',
  `contact_name` varchar(64) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(32) NOT NULL DEFAULT '' COMMENT '手机号',
  `license_no` varchar(64) NOT NULL DEFAULT '' COMMENT '证照号码',
  `wechat_mch_id` varchar(64) NOT NULL DEFAULT '' COMMENT '微信商户号',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '收货/经营地址',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0禁用',
  `switch_mode_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否开放合作模式切换',
  `share_count` int(11) NOT NULL DEFAULT '0' COMMENT '分享次数',
  `click_count` int(11) NOT NULL DEFAULT '0' COMMENT '点击次数',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `is_del` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `identify_code` (`identify_code`),
  KEY `uid` (`uid`),
  KEY `cooperation_mode` (`cooperation_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销商'
SQL,
    'distributor_user_bind' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '绑定ID',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户UID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `source` varchar(32) NOT NULL DEFAULT 'manual' COMMENT '绑定来源 manual/share',
  `identify_code` varchar(16) NOT NULL DEFAULT '' COMMENT '识别号',
  `bind_time` int(11) NOT NULL DEFAULT '0' COMMENT '绑定时间',
  `is_del` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_active` (`uid`,`is_del`),
  KEY `distributor_id` (`distributor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG用户分销商绑定'
SQL,
    'distributor_sku_inventory' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '库存ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `unique` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU唯一值',
  `product_name` varchar(255) NOT NULL DEFAULT '' COMMENT '商品名称快照',
  `stock` int(11) NOT NULL DEFAULT '0' COMMENT '现货库存',
  `locked_stock` int(11) NOT NULL DEFAULT '0' COMMENT '锁定库存',
  `sales` int(11) NOT NULL DEFAULT '0' COMMENT '销售数量',
  `warning_stock` int(11) NOT NULL DEFAULT '5' COMMENT '预警库存',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `distributor_sku` (`distributor_id`,`product_id`,`unique`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销商二级SKU库存'
SQL,
    'distributor_inventory_log' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `unique` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU唯一值',
  `change_num` int(11) NOT NULL DEFAULT '0' COMMENT '变更数量',
  `direction` varchar(16) NOT NULL DEFAULT '' COMMENT '方向 in/out/lock/release',
  `biz_type` varchar(32) NOT NULL DEFAULT '' COMMENT '业务类型',
  `biz_no` varchar(64) NOT NULL DEFAULT '' COMMENT '业务单号',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `biz_no` (`biz_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销商库存流水'
SQL,
    'distributor_purchase_order' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '订货单ID',
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT '订货单号',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `total_num` int(11) NOT NULL DEFAULT '0' COMMENT '总件数',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '订货总额',
  `status` varchar(32) NOT NULL DEFAULT 'pending_pay' COMMENT 'pending_pay/paid/shipped/finished/canceled',
  `pay_no` varchar(64) NOT NULL DEFAULT '' COMMENT '支付流水号',
  `trade_no` varchar(100) NOT NULL DEFAULT '' COMMENT '第三方支付交易号',
  `pay_time` int(11) NOT NULL DEFAULT '0' COMMENT '支付时间',
  `delivery_type` varchar(32) NOT NULL DEFAULT '' COMMENT '配送方式',
  `express_name` varchar(64) NOT NULL DEFAULT '' COMMENT '物流公司',
  `express_no` varchar(64) NOT NULL DEFAULT '' COMMENT '物流单号',
  `ship_time` int(11) NOT NULL DEFAULT '0' COMMENT '发货时间',
  `receive_time` int(11) NOT NULL DEFAULT '0' COMMENT '收货时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `distributor_id` (`distributor_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销商订货单'
SQL,
    'distributor_purchase_order_item' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '明细ID',
  `purchase_order_id` int(11) NOT NULL DEFAULT '0' COMMENT '订货单ID',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `unique` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU唯一值',
  `product_name` varchar(255) NOT NULL DEFAULT '' COMMENT '商品名称快照',
  `num` int(11) NOT NULL DEFAULT '0' COMMENT '数量',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '单价',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销商订货单明细'
SQL,
    'commission_apply' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '申请ID',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户UID',
  `real_name` varchar(64) NOT NULL DEFAULT '' COMMENT '姓名',
  `phone` varchar(32) NOT NULL DEFAULT '' COMMENT '手机号',
  `id_card` varchar(64) NOT NULL DEFAULT '' COMMENT '身份证号',
  `material_urls` text COMMENT '材料URL JSON',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待审1通过2拒绝',
  `apply_time` int(11) NOT NULL DEFAULT '0' COMMENT '申请时间',
  `review_time` int(11) NOT NULL DEFAULT '0' COMMENT '审核时间',
  `review_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '审核说明',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG佣金分销商申请'
SQL,
    'commission_rule' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '规则ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID 0为模板',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `unique` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU唯一值',
  `template_name` varchar(64) NOT NULL DEFAULT '' COMMENT '模板名称',
  `rate` decimal(8,4) NOT NULL DEFAULT '0.0000' COMMENT '抽成比例',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG SKU佣金规则'
SQL,
    'commission_settlement' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '结算ID',
  `settlement_no` varchar(32) NOT NULL DEFAULT '' COMMENT '结算单号',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '佣金金额',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/rejected/refund_pending/refunded',
  `admin_id` int(11) NOT NULL DEFAULT '0' COMMENT '管理员ID',
  `pay_time` int(11) NOT NULL DEFAULT '0' COMMENT '结算时间',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG佣金人工结算记录'
SQL,
    'order_settlement_record' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `settlement_type` varchar(32) NOT NULL DEFAULT 'wechat_profit_sharing' COMMENT 'wechat_profit_sharing=订单款和配送费统一分账',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '订单实付分账金额，包含配送费',
  `delivery_fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '配送费快照',
  `wechat_transaction_id` varchar(64) NOT NULL DEFAULT '' COMMENT '微信支付流水号',
  `profit_sharing_no` varchar(64) NOT NULL DEFAULT '' COMMENT '微信分账单号',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/success/failed/return_pending/returned/refund_blocked',
  `fail_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG代销分账记录'
SQL,
    'distributor_share_event' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `event_type` varchar(32) NOT NULL DEFAULT 'click' COMMENT 'share/click/bind/order_paid',
  `channel` varchar(32) NOT NULL DEFAULT 'wechat' COMMENT '渠道',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '转化金额',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `event_type` (`event_type`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG分销分享事件'
SQL,
    'delivery_fee_record' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT '分销商ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `delivery_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'pickup/merchant/city/express',
  `fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '配送费',
  `receiver` varchar(64) NOT NULL DEFAULT '' COMMENT '配送费接收方',
  `settlement_method` varchar(32) NOT NULL DEFAULT 'headquarters_payable' COMMENT 'wechat_profit_sharing/headquarters_payable',
  `settlement_subject` varchar(32) NOT NULL DEFAULT 'headquarters' COMMENT 'distributor/headquarters/delivery_provider',
  `settlement_batch_no` varchar(64) NOT NULL DEFAULT '' COMMENT '配送费结算批次/应付批次',
  `payment_no` varchar(64) NOT NULL DEFAULT '' COMMENT '微信分账单号或总部付款流水',
  `fail_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '配送费结算失败原因',
  `retry_count` int(11) NOT NULL DEFAULT '0' COMMENT '失败重试次数',
  `reconcile_status` varchar(32) NOT NULL DEFAULT 'unmatched' COMMENT 'unmatched/matched/exception',
  `status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/settled/failed',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `distributor_id` (`distributor_id`),
  KEY `delivery_type` (`delivery_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG配送费记录'
SQL,
    'buy_x_get_x_campaign' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '活动ID',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '活动标题',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `buy_num` int(11) NOT NULL DEFAULT '1' COMMENT '买X',
  `gift_num` int(11) NOT NULL DEFAULT '1' COMMENT '送X',
  `quota` int(11) NOT NULL DEFAULT '0' COMMENT '活动名额',
  `used_quota` int(11) NOT NULL DEFAULT '0' COMMENT '已用名额',
  `start_time` int(11) NOT NULL DEFAULT '0' COMMENT '开始时间',
  `end_time` int(11) NOT NULL DEFAULT '0' COMMENT '结束时间',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG买X送X活动'
SQL,
    'buy_x_get_x_usage' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `@table` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '使用记录ID',
  `campaign_id` int(11) NOT NULL DEFAULT '0' COMMENT '活动ID',
  `order_id` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `product_id` int(11) NOT NULL DEFAULT '0' COMMENT '商品ID',
  `gift_num` int(11) NOT NULL DEFAULT '0' COMMENT '赠送数量',
  `status` varchar(32) NOT NULL DEFAULT 'reserved' COMMENT 'reserved/paid/confirmed/fulfilled/released/canceled',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='FMCG买X送X使用记录'
SQL,
];

$updateSql = [];
foreach ($createTables as $table => $sql) {
    $updateSql[] = [
        'type' => 1,
        'table' => $table,
        'findSql' => "SHOW TABLES LIKE '@table'",
        'sql' => $sql,
    ];
}

foreach ([
    'distributor_id' => "`distributor_id` int(11) NOT NULL DEFAULT '0' COMMENT 'FMCG绑定分销商ID'",
    'fmcg_delivery_type' => "`fmcg_delivery_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'FMCG配送方式 pickup/merchant/city/express'",
    'fmcg_inventory_locked' => "`fmcg_inventory_locked` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'FMCG二级库存是否已锁定'",
] as $field => $definition) {
    $updateSql[] = [
        'type' => 3,
        'table' => 'store_order',
        'field' => $field,
        'findSql' => "SHOW COLUMNS FROM `@table` LIKE '{$field}'",
        'sql' => "ALTER TABLE `@table` ADD {$definition}",
    ];
}

$updateSql[] = [
    'type' => 3,
    'table' => 'store_order',
    'field' => 'idx_distributor_id',
    'findSql' => "SHOW INDEX FROM `@table` WHERE Key_name = 'distributor_id'",
    'sql' => "ALTER TABLE `@table` ADD KEY `distributor_id` (`distributor_id`)",
];

$updateSql[] = [
    'type' => 6,
    'table' => 'system_menus',
    'field' => 'fmcg-distributor-entry',
    'findSql' => "SELECT `id` FROM `@table` WHERE `unique_auth` = 'fmcg-distributor-entry' LIMIT 1",
    'sql' => "INSERT INTO `@table` (`id`, `pid`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`, `mark`) VALUES
(3900, 26, '', 'FMCG分销商', 'admin', 'distributor.distributor', 'index', '', '', '[]', 120, 1, 1, 1, '/fmcg-distributor.html', '26', 1, 'user', 0, 'fmcg-distributor-entry', 0, 'FMCG分销管理入口'),
(3901, 3900, '', '分销商列表', '', '', '', 'distributor/list', 'GET', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-distributor-list', 0, '分销商列表'),
(3902, 3900, '', '保存分销商', '', '', '', 'distributor/save', 'POST', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-distributor-save', 0, '保存分销商'),
(3903, 3900, '', '佣金申请审核', '', '', '', 'distributor/commission/apply', 'GET', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-commission-apply', 0, '佣金申请审核'),
(3904, 3900, '', '订货单管理', '', '', '', 'distributor/purchase/list', 'GET', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-purchase-list', 0, '订货单管理'),
(3905, 3900, '', '买赠活动列表', '', '', '', 'distributor/buy_x_get_x/list', 'GET', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-buy-x-get-x-list', 0, '买赠活动列表'),
(3906, 3900, '', '发布买赠活动', '', '', '', 'distributor/buy_x_get_x/save', 'POST', '[]', 1, 1, 1, 1, '', '26/3900', 2, '', 0, 'fmcg-buy-x-get-x-save', 0, '发布买赠活动')",
];

return [
    'update_sql' => $updateSql,
    'data_handlers' => [],
];
