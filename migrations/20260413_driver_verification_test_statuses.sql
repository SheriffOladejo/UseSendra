-- Test / ops rider statuses (sendra_logistics_db)
-- approved_test     = KYC OK; only receives job broadcasts when LOGISTICS_TEST_COURIERS_ONLY=true
-- approved_paused   = KYC OK; temporarily out of all broadcasts (ops/testing), not a rejection

ALTER TABLE `drivers`
  MODIFY COLUMN `verification_status` ENUM(
    'pending',
    'approved',
    'rejected',
    'approved_test',
    'approved_paused'
  ) NOT NULL DEFAULT 'pending';
