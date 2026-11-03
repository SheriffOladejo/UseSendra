-- Transaction monitoring for the Aptos admin dashboard, and KYC identity
-- fields. NIN and BVN stay last-four only (nin_last4 / bvn_last4).

ALTER TABLE users
    ADD COLUMN kyc_nin_name VARCHAR(200) DEFAULT NULL AFTER kyc_full_name;

ALTER TABLE users
    ADD COLUMN kyc_bvn_name VARCHAR(200) DEFAULT NULL AFTER kyc_nin_name;

ALTER TABLE users
    ADD COLUMN kyc_nin_phone VARCHAR(20) DEFAULT NULL AFTER kyc_bvn_name;

ALTER TABLE users
    ADD COLUMN kyc_bvn_phone VARCHAR(20) DEFAULT NULL AFTER kyc_nin_phone;

CREATE TABLE IF NOT EXISTS sendra_tx_monitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_table VARCHAR(32) NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    user_phone VARCHAR(20) NOT NULL,
    activity_type VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL,
    amount DECIMAL(20, 6) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    usd_equivalent DECIMAL(20, 2) NOT NULL,
    risk_score TINYINT UNSIGNED NOT NULL,
    scuml_reportable TINYINT(1) NOT NULL DEFAULT 0,
    reasons JSON NULL,
    details JSON NULL,
    occurred_at DATETIME NOT NULL,
    email_sent_at DATETIME NULL,
    scuml_reported_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_source (source_table, source_id),
    KEY idx_score (risk_score, occurred_at),
    KEY idx_scuml (scuml_reportable, scuml_reported_at),
    KEY idx_phone (user_phone, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sendra_monitor_recipients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
