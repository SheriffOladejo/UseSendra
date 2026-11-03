-- Async buy quote jobs (Centiiv + 1Click dry quote off webhook critical path).
-- whatsapp_inbound_dedup: Meta webhook retry deduplication by message id (wamid).

CREATE TABLE IF NOT EXISTS buy_quote_jobs (
    id CHAR(32) NOT NULL PRIMARY KEY,
    user_phone VARCHAR(20) NOT NULL,
    quote_mode ENUM('naira', 'crypto_target') NOT NULL DEFAULT 'naira',
    pay_ngn DECIMAL(18, 2) NULL,
    target_crypto DECIMAL(18, 8) NULL,
    asset ENUM('USDC', 'USDT') NOT NULL,
    aptos_address VARCHAR(128) NOT NULL,
    status ENUM('pending', 'processing', 'done', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    trigger_wamid VARCHAR(128) NULL,
    progress_sent TINYINT(1) NOT NULL DEFAULT 0,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    result_json JSON NULL,
    error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at DATETIME NULL,
    finished_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_phone_status (user_phone, status),
    INDEX idx_pending_queue (status, created_at),
    UNIQUE KEY uq_trigger_wamid (trigger_wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_inbound_dedup (
    wamid VARCHAR(128) NOT NULL PRIMARY KEY,
    user_phone VARCHAR(20) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
