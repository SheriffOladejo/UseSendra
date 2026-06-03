-- Learned slippage / shortfall from completed Sendra WhatsApp off-ramps (P90 buffers).
CREATE TABLE IF NOT EXISTS sendra_offramp_outcomes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centiiv_order_id VARCHAR(64) NOT NULL,
    user_phone VARCHAR(20) NULL,
    offramp_path VARCHAR(32) NOT NULL DEFAULT 'unknown',
    target_ngn DECIMAL(18, 2) NOT NULL,
    quoted_ngn_at_pin DECIMAL(18, 2) NULL,
    fulfillment_ngn DECIMAL(18, 2) NOT NULL,
    usdt_debit DECIMAL(18, 8) NULL,
    panora_wallet_usdc DECIMAL(18, 8) NULL,
    stellar_deposit_usdc DECIMAL(18, 8) NULL,
    centiiv_order_usdc DECIMAL(18, 8) NULL,
    haircut_ratio DECIMAL(10, 6) NULL COMMENT '1 - stellar_deposit / panora_wallet when panora > 0',
    ngn_shortfall DECIMAL(18, 2) NULL COMMENT 'max(0, target - fulfillment)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_centiiv_order (centiiv_order_id),
    KEY idx_created (created_at),
    KEY idx_path_created (offramp_path, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
