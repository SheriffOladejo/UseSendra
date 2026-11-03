-- Multi-chain inspector: explicit chain on transaction_history
ALTER TABLE transaction_history
  ADD COLUMN chain VARCHAR(16) NOT NULL DEFAULT 'aptos' AFTER currency;

CREATE INDEX transaction_history_chain_idx ON transaction_history (chain);

-- Backfill from explorer_url host signals (same heuristic as SendraTxMonitorService)
UPDATE transaction_history
SET chain = 'stellar'
WHERE explorer_url IS NOT NULL
  AND LOWER(explorer_url) LIKE '%stellar%';

UPDATE transaction_history
SET chain = 'arc'
WHERE explorer_url IS NOT NULL
  AND LOWER(explorer_url) LIKE '%arc%'
  AND chain = 'aptos';

UPDATE transaction_history
SET chain = 'solana'
WHERE explorer_url IS NOT NULL
  AND LOWER(explorer_url) LIKE '%solana%'
  AND chain = 'aptos';
