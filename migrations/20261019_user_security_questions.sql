-- Optional per-user security questions for PIN confirmation.
-- Answers are stored as a bcrypt hash of the normalized value so checks
-- ignore letter case. The original answer is not stored.

CREATE TABLE IF NOT EXISTS user_security_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone_number VARCHAR(32) NOT NULL,
    question VARCHAR(200) NOT NULL,
    question_key VARCHAR(200) NOT NULL,
    answer_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_phone_question (phone_number, question_key),
    KEY idx_phone (phone_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
