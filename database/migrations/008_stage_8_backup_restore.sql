SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE backup_restores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    backup_id VARCHAR(80) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    summary_json JSON NOT NULL,
    restored_at DATETIME NOT NULL,
    CONSTRAINT fk_backup_restores_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_backup_restore_checksum (user_id, checksum_sha256),
    INDEX idx_backup_restores_user_time (user_id, restored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE workout_plans
    ADD INDEX idx_plans_user_status_date (user_id, status, deleted_at, scheduled_date);

ALTER TABLE audit_logs
    ADD INDEX idx_audit_user_action_time (user_id, action, created_at);
