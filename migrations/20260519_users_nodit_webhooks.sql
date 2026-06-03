-- Per-wallet Nodit deposit webhook subscription ids (JSON: {"USDC":"123","USDT":"456"})
ALTER TABLE users
    ADD COLUMN nodit_webhook_subscriptions JSON NULL
        COMMENT 'Nodit subscriptionId map per monitored FA store'
    AFTER aptos_address;
