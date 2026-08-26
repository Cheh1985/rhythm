SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE audit_logs
    ADD COLUMN source VARCHAR(40) NULL AFTER action,
    ADD COLUMN request_id VARCHAR(80) NULL AFTER source,
    ADD INDEX idx_audit_request (request_id);

CREATE TABLE assistant_tool_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(80) NOT NULL,
    tool_name VARCHAR(80) NOT NULL,
    outcome ENUM('success','error','denied') NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id VARCHAR(80) NULL,
    error_code VARCHAR(64) NULL,
    duration_ms INT UNSIGNED NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_assistant_calls_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_assistant_calls_user_time (user_id, created_at),
    INDEX idx_assistant_calls_request (request_id),
    INDEX idx_assistant_calls_tool_time (tool_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
