SET time_zone = '+00:00';

ALTER TABLE workout_sessions
    ADD INDEX idx_sessions_history (user_id, status, workout_type, started_at, deleted_at);

ALTER TABLE session_exercises
    ADD INDEX idx_session_exercises_actual_session (actual_exercise_id, workout_session_id);

ALTER TABLE exercise_sets
    ADD INDEX idx_sets_user_type_completed (user_id, set_type, completed_at, deleted_at);

ALTER TABLE personal_records
    ADD INDEX idx_records_user_exercise_type (user_id, exercise_id, record_type, achieved_at);
