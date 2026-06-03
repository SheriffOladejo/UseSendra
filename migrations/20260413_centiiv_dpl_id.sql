-- Centiiv payment.successful sends metadata.resourceId like DPL-dd4ac2 (not our DRV_ACCEPT_* ref).
-- We persist that id at link creation so webhooks can match the row without relying on GET /direct-pay.

ALTER TABLE `delivery_requests`
  ADD COLUMN `centiiv_dpl_id` VARCHAR(64) NULL DEFAULT NULL
  AFTER `acceptance_payment_reference`,
  ADD KEY `idx_delivery_requests_centiiv_dpl` (`centiiv_dpl_id`);
