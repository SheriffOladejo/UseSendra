ALTER TABLE users
  ADD COLUMN stellar_address VARCHAR(64) NULL DEFAULT NULL AFTER aptos_address,
  ADD COLUMN encrypted_stellar_private_key TEXT NULL DEFAULT NULL AFTER encrypted_private_key;

CREATE UNIQUE INDEX users_stellar_address_unique ON users (stellar_address);
