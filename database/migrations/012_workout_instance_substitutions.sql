SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE workout_exercises
    ADD COLUMN original_exercise_id VARCHAR(80) NULL AFTER exercise_id,
    ADD COLUMN substitution_reason TEXT NULL AFTER instructions,
    ADD COLUMN substituted_at DATETIME NULL AFTER substitution_reason,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER substituted_at;

UPDATE workout_exercises
SET original_exercise_id = exercise_id
WHERE original_exercise_id IS NULL;

ALTER TABLE workout_exercises
    ADD CONSTRAINT fk_workout_exercises_original
        FOREIGN KEY (original_exercise_id) REFERENCES exercises(exercise_id),
    ADD INDEX idx_workout_exercises_original (original_exercise_id);
