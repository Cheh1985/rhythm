SET time_zone = '+00:00';

ALTER TABLE progression_suggestions
    ADD UNIQUE KEY uq_suggestions_session_exercise (user_id, workout_session_id, exercise_id);

ALTER TABLE personal_records
    ADD UNIQUE KEY uq_records_session_exercise_type (user_id, workout_session_id, exercise_id, record_type);
