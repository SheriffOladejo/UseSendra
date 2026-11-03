ALTER TABLE users
  ADD COLUMN stellar_deposit_memo VARCHAR(32) NULL DEFAULT NULL AFTER stellar_address;

CREATE UNIQUE INDEX users_stellar_deposit_memo_unique ON users (stellar_deposit_memo);
