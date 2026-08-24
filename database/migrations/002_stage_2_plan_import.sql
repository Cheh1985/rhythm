SET time_zone = '+00:00';

ALTER TABLE training_programs
    ADD COLUMN external_program_id VARCHAR(190) NULL AFTER user_id;

UPDATE training_programs
SET external_program_id = CONCAT('legacy-program-', id)
WHERE external_program_id IS NULL;

ALTER TABLE training_programs
    MODIFY external_program_id VARCHAR(190) NOT NULL,
    ADD UNIQUE KEY uq_user_external_program (user_id, external_program_id);

ALTER TABLE program_versions
    ADD COLUMN snapshot_hash CHAR(64) NULL AFTER snapshot_json;

UPDATE program_versions
SET snapshot_hash = SHA2(CAST(snapshot_json AS CHAR), 256)
WHERE snapshot_hash IS NULL;

ALTER TABLE program_versions
    MODIFY snapshot_hash CHAR(64) NOT NULL;

ALTER TABLE workout_templates
    ADD COLUMN content_hash CHAR(64) NULL AFTER content_json;

UPDATE workout_templates
SET code = CONCAT('legacy-template-', id)
WHERE code IS NULL OR code = '';

UPDATE workout_templates
SET content_json = JSON_OBJECT()
WHERE content_json IS NULL;

UPDATE workout_templates
SET content_hash = SHA2(CAST(content_json AS CHAR), 256)
WHERE content_hash IS NULL;

ALTER TABLE workout_templates
    MODIFY code VARCHAR(80) NOT NULL,
    MODIFY content_json JSON NOT NULL,
    MODIFY content_hash CHAR(64) NOT NULL,
    ADD UNIQUE KEY uq_version_template_code (program_version_id, code);
