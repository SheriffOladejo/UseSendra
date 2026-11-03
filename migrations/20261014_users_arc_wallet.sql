ALTER TABLE users
  ADD COLUMN arc_address VARCHAR(42) NULL DEFAULT NULL AFTER stellar_address,
  ADD COLUMN encrypted_arc_private_key TEXT NULL DEFAULT NULL AFTER encrypted_stellar_private_key;

CREATE UNIQUE INDEX users_arc_address_unique ON users (arc_address);

CREATE TABLE IF NOT EXISTS arc_indexer_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_block BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO arc_indexer_state (id, last_block) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS arc_deposits (
  id CHAR(32) NOT NULL PRIMARY KEY,
  tx_hash CHAR(66) NOT NULL,
  log_index INT UNSIGNED NOT NULL,
  from_address VARCHAR(42) NULL,
  to_address VARCHAR(42) NOT NULL,
  amount DECIMAL(28, 8) NOT NULL,
  user_phone VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'credited',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY arc_deposits_tx_log (tx_hash, log_index)
);
