-- Revenue ledger for driver acceptance fees (Paystack charge.success)
-- Query MRR: SUM(amount_kobo)/100 per month in NGN, or filter by paid_at

CREATE TABLE IF NOT EXISTS `logistics_driver_fee_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `paystack_reference` VARCHAR(120) NOT NULL,
  `paystack_transaction_id` VARCHAR(64) DEFAULT NULL,
  `delivery_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `driver_id` BIGINT UNSIGNED DEFAULT NULL,
  `customer_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount_kobo` INT UNSIGNED NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'NGN',
  `channel` VARCHAR(64) DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `job_offered_price_ngn` DECIMAL(12,2) DEFAULT NULL,
  `fee_metadata_type` VARCHAR(64) NOT NULL DEFAULT 'driver_acceptance_fee',
  `raw_paystack_data` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_paystack_reference` (`paystack_reference`),
  KEY `idx_paid_at` (`paid_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_delivery_request` (`delivery_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
