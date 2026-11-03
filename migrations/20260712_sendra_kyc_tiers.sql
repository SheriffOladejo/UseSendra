-- Sendra KYC tier fields for NGN on/off-ramp limits.
-- Tier 0: < ₦50k | Tier 1: ₦50k–₦200k | Tier 2: ₦201k–₦2M | Tier 3: ₦2M+

ALTER TABLE users
    ADD COLUMN kyc_tier TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0=free,1=BVN,2=NIN+liveness,3=address'
        AFTER smiled_verified;

ALTER TABLE users
    ADD COLUMN kyc_full_name VARCHAR(200) DEFAULT NULL AFTER kyc_tier;

ALTER TABLE users
    ADD COLUMN liveness_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER kyc_full_name;

ALTER TABLE users
    ADD COLUMN address_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER liveness_verified;

ALTER TABLE users
    ADD COLUMN kyc_verified_at DATETIME DEFAULT NULL AFTER address_verified;
