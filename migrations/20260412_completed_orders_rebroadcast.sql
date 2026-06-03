-- Completed orders archive + customer choice after bidding ends

ALTER TABLE `delivery_requests`
  MODIFY COLUMN `status` ENUM(
    'draft',
    'searching_driver',
    'driver_payment_pending',
    'driver_assigned',
    'in_transit',
    'delivered',
    'payment_expired',
    'cancelled',
    'awaiting_rebroadcast'
  ) NOT NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS `completed_delivery_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_request_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `driver_id` BIGINT UNSIGNED DEFAULT NULL,
  `pickup_address` TEXT,
  `dropoff_address` TEXT,
  `offered_price` DECIMAL(10,2) DEFAULT NULL,
  `item_name` VARCHAR(200) DEFAULT NULL,
  `item_description` TEXT,
  `sender_phone` VARCHAR(20) DEFAULT NULL,
  `receiver_phone` VARCHAR(20) DEFAULT NULL,
  `reviews_json` JSON DEFAULT NULL,
  `raw_snapshot` JSON DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdo_user` (`user_id`),
  KEY `idx_cdo_driver` (`driver_id`),
  KEY `idx_cdo_original` (`original_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
