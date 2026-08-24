SET time_zone = '+00:00';

CREATE TABLE offline_action_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    client_action_id VARCHAR(80) NOT NULL,
    action_type VARCHAR(48) NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_offline_receipts_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_offline_receipt (user_id, client_action_id),
    INDEX idx_offline_receipt_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
