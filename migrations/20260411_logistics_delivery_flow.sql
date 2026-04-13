-- Run against sendra_logistics_db (adjust DB name if needed)
-- Extends delivery flow: courier counts, handoff code, pickup/dropoff timestamps, reviews, blocks

ALTER TABLE `delivery_requests`
  ADD COLUMN `drivers_notified_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `bidding_closed`,
  ADD COLUMN `delivery_handoff_code` VARCHAR(4) DEFAULT NULL AFTER `pickup_qr_code`,
  ADD COLUMN `pickup_confirmed_at` DATETIME DEFAULT NULL AFTER `delivery_handoff_code`,
  ADD COLUMN `dropoff_confirmed_at` DATETIME DEFAULT NULL AFTER `pickup_confirmed_at`,
  ADD COLUMN `review_sender_submitted_at` DATETIME DEFAULT NULL AFTER `dropoff_confirmed_at`,
  ADD COLUMN `review_driver_submitted_at` DATETIME DEFAULT NULL AFTER `review_sender_submitted_at`;

CREATE TABLE IF NOT EXISTS `user_blocked_drivers` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `driver_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `driver_id`),
  KEY `idx_ubd_driver` (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `logistics_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_request_id` BIGINT UNSIGNED NOT NULL,
  `reviewer_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewer_driver_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewee_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewee_driver_id` BIGINT UNSIGNED DEFAULT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `comment` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lr_request` (`delivery_request_id`),
  KEY `idx_lr_driver` (`reviewee_driver_id`),
  KEY `idx_lr_user` (`reviewee_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
