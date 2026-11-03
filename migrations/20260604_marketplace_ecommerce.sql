-- Run against sendra_logistics_db
-- Multi-store marketplace (vendors, cart, orders, vendor wallet)

CREATE TABLE IF NOT EXISTS `vendors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(20) NOT NULL,
  `store_name` VARCHAR(120) NOT NULL,
  `pickup_address` TEXT DEFAULT NULL,
  `pickup_lat` DECIMAL(10,7) DEFAULT NULL,
  `pickup_lng` DECIMAL(10,7) DEFAULT NULL,
  `status` ENUM('pending','active','throttled','suspended') NOT NULL DEFAULT 'pending',
  `wallet_balance_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cancel_count_30d` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_count_30d` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vendors_phone` (`phone`),
  KEY `idx_vendors_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `vendor_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `price_ngn` DECIMAL(12,2) NOT NULL,
  `image_url` VARCHAR(512) DEFAULT NULL,
  `stock_status` ENUM('in_stock','out_of_stock') NOT NULL DEFAULT 'in_stock',
  `status` ENUM('active','hidden') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vp_vendor` (`vendor_id`),
  KEY `idx_vp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `marketplace_cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_phone` VARCHAR(20) NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price_ngn` DECIMAL(12,2) NOT NULL,
  `product_name` VARCHAR(160) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cart_buyer_product` (`buyer_phone`, `product_id`),
  KEY `idx_cart_buyer` (`buyer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `marketplace_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `buyer_phone` VARCHAR(20) NOT NULL,
  `status` ENUM(
    'payment_pending','paid','partially_fulfilled','completed','cancelled','refunded'
  ) NOT NULL DEFAULT 'payment_pending',
  `partial_fulfill_status` ENUM('none','pending','continued','cancelled_all') NOT NULL DEFAULT 'none',
  `delivery_address` TEXT DEFAULT NULL,
  `delivery_lat` DECIMAL(10,7) DEFAULT NULL,
  `delivery_lng` DECIMAL(10,7) DEFAULT NULL,
  `receiver_phone` VARCHAR(20) DEFAULT NULL,
  `subtotal_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `delivery_total_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `service_fee_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `amount_paid_ngn` DECIMAL(14,2) DEFAULT NULL,
  `payment_reference` VARCHAR(120) DEFAULT NULL,
  `centiiv_dpl_id` VARCHAR(64) DEFAULT NULL,
  `payment_status` ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `refund_total_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mo_buyer` (`buyer_phone`),
  KEY `idx_mo_status` (`status`),
  KEY `idx_mo_payment_ref` (`payment_reference`),
  KEY `idx_mo_dpl` (`centiiv_dpl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `store_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_order_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM(
    'awaiting_vendor','accepted','preparing','ready','rider_assigned',
    'picked_up','delivered','cancelled','failed','returning','returned'
  ) NOT NULL DEFAULT 'awaiting_vendor',
  `cancel_reason` VARCHAR(120) DEFAULT NULL,
  `subtotal_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `delivery_fee_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `service_fee_share_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `vendor_accept_deadline` DATETIME DEFAULT NULL,
  `delivery_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `return_delivery_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `return_fee_charged_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `refund_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_so_marketplace` (`marketplace_order_id`),
  KEY `idx_so_vendor` (`vendor_id`),
  KEY `idx_so_status` (`status`),
  KEY `idx_so_deadline` (`vendor_accept_deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `store_order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED DEFAULT NULL,
  `product_name` VARCHAR(160) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_price_ngn` DECIMAL(12,2) NOT NULL,
  `line_total_ngn` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_soi_store_order` (`store_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `vendor_wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('return_fee','payout','adjustment','refund_credit') NOT NULL,
  `amount_ngn` DECIMAL(14,2) NOT NULL,
  `balance_after_ngn` DECIMAL(14,2) NOT NULL,
  `reference` VARCHAR(120) NOT NULL,
  `store_order_id` BIGINT UNSIGNED DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vwt_reference` (`reference`),
  KEY `idx_vwt_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `delivery_requests`
  ADD COLUMN `marketplace_order_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `store_order_id` BIGINT UNSIGNED DEFAULT NULL AFTER `marketplace_order_id`,
  ADD KEY `idx_dr_marketplace` (`marketplace_order_id`),
  ADD KEY `idx_dr_store_order` (`store_order_id`);
