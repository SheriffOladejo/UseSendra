CREATE TABLE IF NOT EXISTS stellar_pool_indexer_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  payments_cursor VARCHAR(64) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO stellar_pool_indexer_state (id, payments_cursor) VALUES (1, NULL);

CREATE TABLE IF NOT EXISTS stellar_pool_deposits (
  id CHAR(32) NOT NULL PRIMARY KEY,
  inbound_tx_hash CHAR(64) NOT NULL,
  paging_token VARCHAR(32) NOT NULL,
  memo_id VARCHAR(32) NOT NULL DEFAULT '',
  asset VARCHAR(8) NOT NULL DEFAULT 'USDC',
  amount DECIMAL(20,7) NOT NULL,
  from_account VARCHAR(64) NULL,
  user_phone VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  wrap_tx_hash CHAR(64) NULL,
  sweep_tx_hash CHAR(64) NULL,
  explorer_url VARCHAR(255) NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY stellar_pool_deposits_inbound (inbound_tx_hash)
);
