-- Remember which WhatsApp business number the user last messaged (for deposit routing).
ALTER TABLE users
    ADD COLUMN last_whatsapp_phone_id VARCHAR(32) NULL
        COMMENT 'Meta phone_number_id from last inbound WhatsApp message'
    AFTER chat_context;
