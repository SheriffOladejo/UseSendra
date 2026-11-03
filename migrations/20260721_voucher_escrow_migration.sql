ALTER TABLE `vouchers`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'escrow_funding',
        'escrowed',
        'claiming',
        'claimed',
        'expired',
        'refunding',
        'refunded',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN `creator_address` VARCHAR(100) NULL AFTER `created_by`,
    ADD COLUMN `funding_pending_id` VARCHAR(36) NULL AFTER `recipient_phone`,
    ADD COLUMN `escrow_address` VARCHAR(100) NULL AFTER `funding_pending_id`,
    ADD COLUMN `recipient_address` VARCHAR(100) NULL AFTER `claimed_by`,
    ADD COLUMN `escrow_funding_tx_hash` VARCHAR(100) NULL AFTER `tx_hash`,
    ADD COLUMN `claim_tx_hash` VARCHAR(100) NULL AFTER `escrow_funding_tx_hash`,
    ADD COLUMN `refund_tx_hash` VARCHAR(100) NULL AFTER `claim_tx_hash`,
    ADD COLUMN `funded_at` DATETIME NULL AFTER `created_at`,
    ADD COLUMN `refunded_at` DATETIME NULL AFTER `funded_at`;

CREATE INDEX `idx_vouchers_recipient_status` ON `vouchers` (`recipient_phone`, `status`);
CREATE INDEX `idx_vouchers_status_expiry` ON `vouchers` (`status`, `expires_at`);
