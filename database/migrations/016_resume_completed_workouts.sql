-- Back up the database before applying. The original started_at is preserved.
ALTER TABLE workout_sessions
    ADD active_duration_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER finished_at,
    ADD active_segment_started_at DATETIME NULL AFTER active_duration_seconds;

UPDATE workout_sessions
SET active_duration_seconds = CASE
        WHEN finished_at IS NOT NULL THEN GREATEST(TIMESTAMPDIFF(SECOND, started_at, finished_at), 0)
        ELSE 0
    END,
    active_segment_started_at = CASE
        WHEN status = 'in_progress' THEN started_at
        ELSE NULL
    END;
