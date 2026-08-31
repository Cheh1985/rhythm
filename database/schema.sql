SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
    theme ENUM('light','dark','system') NOT NULL DEFAULT 'system',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_users_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts_key_time (attempt_key, attempted_at),
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_login_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exercises (
    exercise_id VARCHAR(80) PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    category VARCHAR(80) NULL,
    muscle_groups JSON NULL,
    exercise_type VARCHAR(40) NOT NULL DEFAULT 'strength',
    equipment VARCHAR(120) NULL,
    progression_increment DECIMAL(7,2) NOT NULL DEFAULT 2.50,
    progression_mode ENUM('absolute','percent') NOT NULL DEFAULT 'absolute',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_exercises_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
    INDEX idx_exercises_owner_status (owner_user_id, status),
    INDEX idx_exercises_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE training_programs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    external_program_id VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    deleted_at DATETIME NULL,
    active_version_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_programs_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_user_external_program (user_id, external_program_id),
    INDEX idx_programs_user_status (user_id, status),
    INDEX idx_programs_active_version (active_version_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    change_reason TEXT NULL,
    trainer_comment TEXT NULL,
    snapshot_json JSON NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    parent_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    lifecycle_status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    aggregate_hash CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    archived_at DATETIME NULL,
    CONSTRAINT fk_versions_program FOREIGN KEY (program_id) REFERENCES training_programs(id),
    CONSTRAINT fk_versions_parent FOREIGN KEY (parent_version_id) REFERENCES program_versions(id),
    CONSTRAINT fk_versions_parent_program FOREIGN KEY (parent_version_id, program_id) REFERENCES program_versions(id, program_id),
    UNIQUE KEY uq_program_version (program_id, version_number),
    UNIQUE KEY uq_versions_id_program (id, program_id),
    INDEX idx_versions_program_created (program_id, created_at),
    INDEX idx_versions_parent_program (parent_version_id, program_id),
    INDEX idx_versions_lifecycle (program_id, lifecycle_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    program_version_id BIGINT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(190) NOT NULL,
    workout_type ENUM('strength','swimming','cardio','mobility','other') NOT NULL DEFAULT 'strength',
    content_json JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_templates_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_templates_version FOREIGN KEY (program_version_id) REFERENCES program_versions(id),
    UNIQUE KEY uq_version_template_code (program_version_id, code),
    INDEX idx_templates_id_version (id, program_version_id),
    INDEX idx_templates_user (user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_schedule_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_version_id BIGINT UNSIGNED NOT NULL,
    workout_template_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT chk_program_slots_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT fk_program_slots_version FOREIGN KEY (program_version_id) REFERENCES program_versions(id),
    CONSTRAINT fk_program_slots_template_version FOREIGN KEY (workout_template_id, program_version_id) REFERENCES workout_templates(id, program_version_id),
    UNIQUE KEY uq_program_slots_version_weekday (program_version_id, weekday),
    INDEX idx_program_slots_template_version (workout_template_id, program_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE training_programs
    ADD CONSTRAINT fk_programs_active_version FOREIGN KEY (active_version_id, id) REFERENCES program_versions(id, program_id);

CREATE TABLE workout_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    external_plan_id VARCHAR(190) NOT NULL,
    program_version_id BIGINT UNSIGNED NULL,
    workout_template_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    workout_type ENUM('strength','swimming','cardio','mobility','other') NOT NULL,
    scheduled_date DATE NOT NULL,
    goal TEXT NULL,
    estimated_duration_min SMALLINT UNSIGNED NULL,
    trainer_notes TEXT NULL,
    pre_workout_json JSON NULL,
    source_json JSON NOT NULL,
    schema_version VARCHAR(20) NOT NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_plans_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_plans_program_version FOREIGN KEY (program_version_id) REFERENCES program_versions(id),
    CONSTRAINT fk_plans_template FOREIGN KEY (workout_template_id) REFERENCES workout_templates(id),
    UNIQUE KEY uq_user_external_plan (user_id, external_plan_id),
    INDEX idx_plans_user_date (user_id, scheduled_date),
    INDEX idx_plans_status (user_id, status),
    INDEX idx_plans_user_status_date (user_id, status, deleted_at, scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_exercises (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workout_plan_id BIGINT UNSIGNED NOT NULL,
    exercise_id VARCHAR(80) NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    planned_sets SMALLINT UNSIGNED NOT NULL,
    rep_min SMALLINT UNSIGNED NOT NULL,
    rep_max SMALLINT UNSIGNED NOT NULL,
    target_rir_min DECIMAL(3,1) NULL,
    target_rir_max DECIMAL(3,1) NULL,
    rest_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    planned_weight_kg DECIMAL(7,2) NULL,
    warmup_sets TINYINT(1) NOT NULL DEFAULT 0,
    method_type ENUM('normal','superset','dropset','rest_pause','cluster','amrap') NOT NULL DEFAULT 'normal',
    group_id VARCHAR(64) NULL,
    instructions TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_workout_exercises_plan FOREIGN KEY (workout_plan_id) REFERENCES workout_plans(id),
    CONSTRAINT fk_workout_exercises_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id),
    UNIQUE KEY uq_plan_sequence (workout_plan_id, sequence_no),
    INDEX idx_workout_exercises_exercise (exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workout_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(80) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_plan_id BIGINT UNSIGNED NOT NULL,
    workout_type ENUM('strength','swimming','cardio','mobility','other') NOT NULL,
    status ENUM('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    session_rpe TINYINT UNSIGNED NULL,
    wellbeing TINYINT UNSIGNED NULL,
    user_comment TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    edited_after_completion TINYINT(1) NOT NULL DEFAULT 0,
    edited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sessions_plan FOREIGN KEY (workout_plan_id) REFERENCES workout_plans(id),
    INDEX idx_sessions_user_started (user_id, started_at),
    INDEX idx_sessions_user_status (user_id, status),
    INDEX idx_sessions_history (user_id, status, workout_type, started_at, deleted_at),
    INDEX idx_sessions_plan (workout_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE readiness_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NOT NULL UNIQUE,
    body_weight_kg DECIMAL(6,2) NULL,
    sleep_score TINYINT UNSIGNED NULL,
    energy_score TINYINT UNSIGNED NULL,
    readiness_score TINYINT UNSIGNED NULL,
    comment TEXT NULL,
    logged_at DATETIME NOT NULL,
    CONSTRAINT fk_readiness_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_readiness_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    INDEX idx_readiness_user_logged (user_id, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_exercises (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    workout_exercise_id BIGINT UNSIGNED NOT NULL,
    original_exercise_id VARCHAR(80) NOT NULL,
    actual_exercise_id VARCHAR(80) NOT NULL,
    status ENUM('pending','active','completed','skipped','waiting') NOT NULL DEFAULT 'pending',
    skip_reason ENUM('equipment_busy','time','fatigue','discomfort','other') NULL,
    substitution_reason TEXT NULL,
    substituted_at DATETIME NULL,
    exercise_rating ENUM('too_easy','normal','too_hard') NULL,
    comment TEXT NULL,
    completed_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_session_exercises_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_session_exercises_plan_item FOREIGN KEY (workout_exercise_id) REFERENCES workout_exercises(id),
    CONSTRAINT fk_session_exercises_original FOREIGN KEY (original_exercise_id) REFERENCES exercises(exercise_id),
    CONSTRAINT fk_session_exercises_actual FOREIGN KEY (actual_exercise_id) REFERENCES exercises(exercise_id),
    UNIQUE KEY uq_session_plan_exercise (workout_session_id, workout_exercise_id),
    INDEX idx_session_exercises_session_status (workout_session_id, status),
    INDEX idx_session_exercises_actual (actual_exercise_id),
    INDEX idx_session_exercises_actual_session (actual_exercise_id, workout_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exercise_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(80) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    session_exercise_id BIGINT UNSIGNED NOT NULL,
    set_number SMALLINT UNSIGNED NOT NULL,
    set_type ENUM('warmup','working') NOT NULL DEFAULT 'working',
    method_type ENUM('normal','superset','dropset','rest_pause','cluster','amrap') NOT NULL DEFAULT 'normal',
    group_id VARCHAR(64) NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    performed_weight_kg DECIMAL(7,2) NULL,
    reps SMALLINT UNSIGNED NULL,
    rir DECIMAL(3,1) NULL,
    duration_seconds SMALLINT UNSIGNED NULL,
    distance_m SMALLINT UNSIGNED NULL,
    completed_at DATETIME NOT NULL,
    client_action_id VARCHAR(80) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_sets_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sets_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_sets_session_exercise FOREIGN KEY (session_exercise_id) REFERENCES session_exercises(id),
    UNIQUE KEY uq_set_position (session_exercise_id, set_number, set_type, sequence_no),
    UNIQUE KEY uq_client_action (user_id, client_action_id),
    INDEX idx_sets_session (workout_session_id, deleted_at),
    INDEX idx_sets_user_completed (user_id, completed_at),
    INDEX idx_sets_user_type_completed (user_id, set_type, completed_at, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE discomfort_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    session_exercise_id BIGINT UNSIGNED NULL,
    body_area VARCHAR(120) NOT NULL,
    intensity TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    logged_at DATETIME NOT NULL,
    CONSTRAINT fk_discomfort_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_discomfort_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_discomfort_exercise FOREIGN KEY (session_exercise_id) REFERENCES session_exercises(id),
    INDEX idx_discomfort_user_time (user_id, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE progression_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    exercise_id VARCHAR(80) NOT NULL,
    current_weight_kg DECIMAL(7,2) NULL,
    suggested_next_weight_kg DECIMAL(7,2) NULL,
    accepted_next_weight_kg DECIMAL(7,2) NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','accepted','rejected','exported') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    CONSTRAINT fk_suggestions_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_suggestions_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_suggestions_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id),
    UNIQUE KEY uq_suggestions_session_exercise (user_id, workout_session_id, exercise_id),
    INDEX idx_suggestions_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE personal_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    exercise_id VARCHAR(80) NULL,
    record_type ENUM('max_weight','max_reps_at_weight','best_e1rm','exercise_tonnage','session_tonnage','rep_range_completed') NOT NULL,
    value_decimal DECIMAL(12,2) NOT NULL,
    metadata_json JSON NULL,
    achieved_at DATETIME NOT NULL,
    CONSTRAINT fk_records_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_records_session FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_records_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id),
    UNIQUE KEY uq_records_session_exercise_type (user_id, workout_session_id, exercise_id, record_type),
    INDEX idx_records_user_achieved (user_id, achieved_at),
    INDEX idx_records_exercise (exercise_id, record_type),
    INDEX idx_records_user_exercise_type (user_id, exercise_id, record_type, achieved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE body_measurements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    measured_on DATE NOT NULL,
    weight_kg DECIMAL(6,2) NULL,
    waist_cm DECIMAL(6,2) NULL,
    chest_cm DECIMAL(6,2) NULL,
    shoulders_cm DECIMAL(6,2) NULL,
    biceps_left_cm DECIMAL(6,2) NULL,
    biceps_right_cm DECIMAL(6,2) NULL,
    thigh_cm DECIMAL(6,2) NULL,
    calf_cm DECIMAL(6,2) NULL,
    body_fat_percent DECIMAL(5,2) NULL,
    extra_json JSON NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_measurements_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_measurements_user_date (user_id, measured_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    workout_type ENUM('strength','swimming','cardio','mobility','other') NOT NULL,
    label VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_schedules_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_schedules_user_day (user_id, weekday),
    INDEX idx_schedules_user_day (user_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE swimming_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(80) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    workout_session_id BIGINT UNSIGNED NULL,
    schedule_id BIGINT UNSIGNED NULL,
    source ENUM('manual','schedule') NOT NULL DEFAULT 'manual',
    swim_date DATE NOT NULL,
    occurred_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    pool_length_m SMALLINT UNSIGNED NOT NULL,
    total_distance_m INT UNSIGNED NOT NULL,
    primary_style VARCHAR(60) NOT NULL,
    intensity TINYINT UNSIGNED NOT NULL,
    arms_fatigue TINYINT UNSIGNED NOT NULL,
    back_fatigue TINYINT UNSIGNED NOT NULL,
    legs_fatigue TINYINT UNSIGNED NOT NULL,
    wellbeing TINYINT UNSIGNED NOT NULL,
    intervals_json JSON NULL,
    comment TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    edited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_swimming_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_swimming_workout FOREIGN KEY (workout_session_id) REFERENCES workout_sessions(id),
    CONSTRAINT fk_swimming_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id),
    INDEX idx_swimming_user_date (user_id, swim_date),
    INDEX idx_swimming_timeline (user_id, occurred_at, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id VARCHAR(80) NOT NULL,
    action VARCHAR(60) NOT NULL,
    source VARCHAR(40) NULL,
    request_id VARCHAR(80) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_user_action_time (user_id, action, created_at),
    INDEX idx_audit_request (request_id),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
