-- KYC PII: last-4 only for BVN/NIN; encrypted residential address at Tier 3.

ALTER TABLE users
    ADD COLUMN bvn_last4 CHAR(4) DEFAULT NULL AFTER kyc_verified_at;

ALTER TABLE users
    ADD COLUMN nin_last4 CHAR(4) DEFAULT NULL AFTER bvn_last4;

ALTER TABLE users
    ADD COLUMN kyc_address_encrypted TEXT DEFAULT NULL AFTER nin_last4;
