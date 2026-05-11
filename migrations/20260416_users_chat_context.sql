-- Multi-step WhatsApp flows (e.g. Centiiv sell) without consuming pending_tx_id / PIN queue.
ALTER TABLE users ADD COLUMN chat_context JSON DEFAULT NULL;
