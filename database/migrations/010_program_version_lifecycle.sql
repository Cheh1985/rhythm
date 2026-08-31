SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE program_versions
    ADD COLUMN lifecycle_status ENUM('draft','published','archived') NOT NULL DEFAULT 'published' AFTER created_at,
    ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER lifecycle_status,
    ADD COLUMN aggregate_hash CHAR(64) NULL AFTER lock_version,
    ADD COLUMN updated_at DATETIME NULL AFTER aggregate_hash,
    ADD COLUMN activated_at DATETIME NULL AFTER updated_at,
    ADD COLUMN archived_at DATETIME NULL AFTER activated_at;

UPDATE program_versions
SET lifecycle_status = 'published',
    aggregate_hash = snapshot_hash,
    updated_at = created_at
WHERE aggregate_hash IS NULL OR updated_at IS NULL;

ALTER TABLE program_versions
    MODIFY aggregate_hash CHAR(64) NOT NULL,
    MODIFY updated_at DATETIME NOT NULL,
    ADD UNIQUE KEY uq_versions_id_program (id, program_id),
    ADD INDEX idx_versions_parent_program (parent_version_id, program_id),
    ADD INDEX idx_versions_lifecycle (program_id, lifecycle_status);

-- MySQL 8.0.12 cannot resolve the self-referencing composite FK when its
-- supporting unique index is created in the same ALTER TABLE statement.
ALTER TABLE program_versions
    ADD CONSTRAINT fk_versions_parent_program
        FOREIGN KEY (parent_version_id, program_id) REFERENCES program_versions(id, program_id);

ALTER TABLE training_programs
    ADD COLUMN active_version_id BIGINT UNSIGNED NULL AFTER deleted_at,
    ADD INDEX idx_programs_active_version (active_version_id, id);

ALTER TABLE workout_templates
    ADD INDEX idx_templates_id_version (id, program_version_id);

CREATE TABLE program_schedule_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_version_id BIGINT UNSIGNED NOT NULL,
    workout_template_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT chk_program_slots_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT fk_program_slots_version FOREIGN KEY (program_version_id) REFERENCES program_versions(id),
    CONSTRAINT fk_program_slots_template_version
        FOREIGN KEY (workout_template_id, program_version_id) REFERENCES workout_templates(id, program_version_id),
    UNIQUE KEY uq_program_slots_version_weekday (program_version_id, weekday),
    INDEX idx_program_slots_template_version (workout_template_id, program_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE training_programs p
JOIN (
    SELECT program_id, MIN(id) AS version_id
    FROM program_versions
    GROUP BY program_id
    HAVING COUNT(*) = 1
) single_version ON single_version.program_id = p.id
SET p.active_version_id = single_version.version_id
WHERE p.active_version_id IS NULL;

UPDATE program_versions pv
JOIN training_programs p ON p.active_version_id = pv.id AND p.id = pv.program_id
SET pv.activated_at = COALESCE(pv.activated_at, pv.created_at);

ALTER TABLE training_programs
    ADD CONSTRAINT fk_programs_active_version
        FOREIGN KEY (active_version_id, id) REFERENCES program_versions(id, program_id);
