-- Rider wallet (Sendra logistics): balance on drivers + transaction ledger

ALTER TABLE `drivers`
  ADD COLUMN `wallet_balance_ngn` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `last_message_at`,
  ADD COLUMN `wallet_grace_used` TINYINT(1) NOT NULL DEFAULT 0 AFTER `wallet_balance_ngn`;

CREATE TABLE IF NOT EXISTS `rider_wallet_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `driver_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('fund','withdraw','acceptance_fee','adjustment') NOT NULL,
  `amount_ngn` DECIMAL(14,2) NOT NULL,
  `balance_after_ngn` DECIMAL(14,2) NOT NULL,
  `reference` VARCHAR(120) NOT NULL,
  `status` ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `delivery_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `centiiv_resource_id` VARCHAR(64) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rwt_reference` (`reference`),
  KEY `idx_rwt_driver` (`driver_id`),
  KEY `idx_rwt_status` (`status`),
  KEY `idx_rwt_request` (`delivery_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
