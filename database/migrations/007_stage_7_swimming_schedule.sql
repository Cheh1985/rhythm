SET time_zone = '+00:00';

ALTER TABLE schedules
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER active,
    ADD UNIQUE KEY uq_schedules_user_day (user_id, weekday);

ALTER TABLE swimming_sessions
    ADD COLUMN public_id VARCHAR(80) NULL AFTER id,
    ADD COLUMN schedule_id BIGINT UNSIGNED NULL AFTER workout_session_id,
    ADD COLUMN source ENUM('manual','schedule') NOT NULL DEFAULT 'manual' AFTER schedule_id,
    ADD COLUMN occurred_at DATETIME NULL AFTER swim_date,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER comment,
    ADD COLUMN edited_at DATETIME NULL AFTER version;

UPDATE swimming_sessions
SET public_id = CONCAT('swim-', id),
    occurred_at = TIMESTAMP(swim_date, '12:00:00')
WHERE public_id IS NULL OR occurred_at IS NULL;

ALTER TABLE swimming_sessions
    MODIFY public_id VARCHAR(80) NOT NULL,
    MODIFY occurred_at DATETIME NOT NULL,
    ADD UNIQUE KEY uq_swimming_public_id (public_id),
    ADD CONSTRAINT fk_swimming_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id),
    ADD INDEX idx_swimming_timeline (user_id, occurred_at, deleted_at);

CREATE TABLE swimming_intervals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    swimming_session_id BIGINT UNSIGNED NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    repeat_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    distance_m INT UNSIGNED NOT NULL,
    style VARCHAR(60) NOT NULL,
    intensity TINYINT UNSIGNED NULL,
    rest_seconds SMALLINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_swim_intervals_session FOREIGN KEY (swimming_session_id) REFERENCES swimming_sessions(id),
    UNIQUE KEY uq_swim_interval_sequence (swimming_session_id, sequence_no),
    INDEX idx_swim_intervals_session (swimming_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
