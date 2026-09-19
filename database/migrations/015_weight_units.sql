-- Back up and inspect the database before applying. Existing numbers remain kg.
ALTER TABLE exercise_sets
    MODIFY performed_weight_kg DECIMAL(13,8) NULL,
    ADD weight_value DECIMAL(7,2) NULL,
    ADD weight_unit ENUM('kg','lb') NOT NULL DEFAULT 'kg';
UPDATE exercise_sets SET weight_value=performed_weight_kg;

ALTER TABLE workout_exercises
    MODIFY planned_weight_kg DECIMAL(13,8) NULL,
    ADD planned_weight_value DECIMAL(7,2) NULL,
    ADD planned_weight_unit ENUM('kg','lb') NOT NULL DEFAULT 'kg';
UPDATE workout_exercises SET planned_weight_value=planned_weight_kg;

ALTER TABLE session_exercises ADD weight_unit ENUM('kg','lb') NOT NULL DEFAULT 'kg';

CREATE TABLE exercise_weight_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    exercise_id VARCHAR(80) NOT NULL,
    weight_unit ENUM('kg','lb') NOT NULL DEFAULT 'kg',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, exercise_id),
    CONSTRAINT fk_weight_preferences_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_weight_preferences_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE progression_suggestions
    MODIFY current_weight_kg DECIMAL(13,8) NULL,
    MODIFY suggested_next_weight_kg DECIMAL(13,8) NULL,
    MODIFY accepted_next_weight_kg DECIMAL(13,8) NULL;
