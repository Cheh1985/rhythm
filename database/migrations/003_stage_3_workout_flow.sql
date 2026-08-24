SET time_zone = '+00:00';

ALTER TABLE session_exercises
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER completed_at;
