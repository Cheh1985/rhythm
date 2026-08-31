SET time_zone = '+00:00';

CREATE TABLE assistant_write_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(80) NOT NULL,
    action_type VARCHAR(48) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_assistant_write_receipts_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_assistant_write_receipt (user_id, idempotency_key),
    INDEX idx_assistant_write_receipts_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
