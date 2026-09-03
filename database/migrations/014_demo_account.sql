-- Optional production seed: isolated English-language Rhythm demo account.
-- Prerequisite: apply database/migrations/001_initial.sql through
-- database/migrations/013_localization.sql first.
--
-- Login:    rhythm_demo
-- Password: Rhythm-Demo-2026!
-- Email uses the reserved .invalid TLD and is not personal data.
--
-- This migration is additive and safe to run again. It never deletes or resets
-- existing demo data, including a password changed after the first run. Dates
-- are anchored to the account's original creation week, so a later rerun does
-- not create another moving window of history.
--
-- WebMCP flags are application environment settings, not database records.
-- After this script prints demo_user_id, enable all tools for only this account:
--   WEBMCP_ENABLED=true
--   WEBMCP_ALLOWED_USER_IDS=<demo_user_id>
--   WEBMCP_READ_ENABLED=true
--   WEBMCP_DRAFT_WRITE_ENABLED=true
--   WEBMCP_INSTANCE_WRITE_ENABLED=true
--   WEBMCP_ACTIVATION_ENABLED=true

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

START TRANSACTION;

SET @rhythm_demo_now = UTC_TIMESTAMP();

-- The no-op duplicate branch intentionally preserves a changed password and
-- all account preferences on reruns.
INSERT INTO users
    (login, email, password_hash, role, timezone, theme, locale, created_at, updated_at, deleted_at)
VALUES
    ('rhythm_demo', 'rhythm-demo@example.invalid',
     '$2y$10$JuQPgYW9eykjoHcY.Vb38uBbr/PnHsgKsi6ZcVvrw3Qfo/iFvYFoy',
     'user', 'Europe/Moscow', 'system', 'en', @rhythm_demo_now, @rhythm_demo_now, NULL)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @rhythm_demo_user_id = (
    SELECT id
    FROM users
    WHERE login = 'rhythm_demo'
      AND email = 'rhythm-demo@example.invalid'
      AND deleted_at IS NULL
    LIMIT 1
);
SET @rhythm_demo_created_at = (
    SELECT created_at FROM users WHERE id = @rhythm_demo_user_id
);
SET @rhythm_demo_week = DATE_SUB(
    DATE(@rhythm_demo_created_at),
    INTERVAL WEEKDAY(DATE(@rhythm_demo_created_at)) DAY
);

-- A NULL user id makes the next statement fail before any shared exercise can
-- be created if either reserved credential belongs to a different account.
INSERT INTO training_programs
    (user_id, external_program_id, name, description, status, created_at, updated_at,
     archived_at, deleted_at, active_version_id)
VALUES
    (@rhythm_demo_user_id, 'rhythm-demo-strength', 'Demo Strength Foundation',
     'A balanced two-day strength program with visible load, RIR and volume progression.',
     'active', DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY),
     DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY), NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @rhythm_demo_program_id = (
    SELECT id
    FROM training_programs
    WHERE user_id = @rhythm_demo_user_id
      AND external_program_id = 'rhythm-demo-strength'
      AND deleted_at IS NULL
    LIMIT 1
);

-- Demo-owned exercise ids keep every displayed exercise name in English even
-- when the installation's shared catalogue was originally seeded in Russian.
INSERT IGNORE INTO exercises
    (exercise_id, owner_user_id, name, category, muscle_groups, exercise_type,
     equipment, progression_increment, progression_mode, status, created_at, updated_at, deleted_at)
VALUES
    ('rhythm_demo_bench_press', @rhythm_demo_user_id, 'Barbell Bench Press', 'chest',
     JSON_ARRAY('chest','triceps','front_delts'), 'strength', 'barbell', 2.50, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_lat_pulldown', @rhythm_demo_user_id, 'Lat Pulldown', 'back',
     JSON_ARRAY('lats','biceps'), 'strength', 'cable', 2.50, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_leg_press', @rhythm_demo_user_id, 'Leg Press', 'legs',
     JSON_ARRAY('quadriceps','glutes'), 'strength', 'leg_press', 5.00, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_lateral_raise', @rhythm_demo_user_id, 'Dumbbell Lateral Raise', 'shoulders',
     JSON_ARRAY('side_delts'), 'strength', 'dumbbells', 1.00, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_romanian_deadlift', @rhythm_demo_user_id, 'Romanian Deadlift', 'legs',
     JSON_ARRAY('hamstrings','glutes','lower_back'), 'strength', 'barbell', 5.00, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_shoulder_press', @rhythm_demo_user_id, 'Dumbbell Shoulder Press', 'shoulders',
     JSON_ARRAY('delts','triceps'), 'strength', 'dumbbells', 2.00, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_cable_row', @rhythm_demo_user_id, 'Seated Cable Row', 'back',
     JSON_ARRAY('lats','upper_back','biceps'), 'strength', 'cable', 2.50, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_leg_curl', @rhythm_demo_user_id, 'Lying Leg Curl', 'legs',
     JSON_ARRAY('hamstrings'), 'strength', 'machine', 2.50, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL),
    ('rhythm_demo_face_pull', @rhythm_demo_user_id, 'Cable Face Pull', 'shoulders',
     JSON_ARRAY('rear_delts','upper_back'), 'strength', 'cable', 1.00, 'absolute', 'active', @rhythm_demo_now, @rhythm_demo_now, NULL);

-- Published version 1.
SET @rhythm_demo_active_a = JSON_OBJECT(
    'name', 'Full Body A',
    'type', 'strength',
    'goal', 'Build repeatable strength with controlled reps in reserve.',
    'estimated_duration_min', 60,
    'trainer_notes', 'Keep every rep smooth and stop inside the target RIR range.',
    'pre_workout', JSON_OBJECT(
        'instructions', 'Complete 5 minutes of easy movement and two ramp-up sets.',
        'nutrition', 'Arrive hydrated.',
        'equipment', 'Barbell, cable station and leg press.'
    ),
    'exercises', JSON_ARRAY(
        JSON_OBJECT('exercise_id','rhythm_demo_bench_press','name','Barbell Bench Press','order',1,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',150,'weight',57.5,'set_type','normal','instructions','Pause briefly on the chest.'),
        JSON_OBJECT('exercise_id','rhythm_demo_lat_pulldown','name','Lat Pulldown','order',2,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',120,'weight',52.5,'set_type','normal','instructions','Drive elbows toward the ribs.'),
        JSON_OBJECT('exercise_id','rhythm_demo_leg_press','name','Leg Press','order',3,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',150,'weight',115.0,'set_type','normal','instructions','Use a consistent depth.'),
        JSON_OBJECT('exercise_id','rhythm_demo_lateral_raise','name','Dumbbell Lateral Raise','order',4,'sets',3,'rep_range',JSON_OBJECT('min',10,'max',12),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',75,'weight',11.0,'set_type','normal','instructions','Lead with the elbows.')
    )
);

SET @rhythm_demo_active_b = JSON_OBJECT(
    'name', 'Full Body B',
    'type', 'strength',
    'goal', 'Train the posterior chain and upper-body strength with stable technique.',
    'estimated_duration_min', 60,
    'trainer_notes', 'Keep the last working set challenging but technically clean.',
    'pre_workout', JSON_OBJECT(
        'instructions', 'Complete a hip hinge drill and two ramp-up sets.',
        'nutrition', 'Arrive hydrated.',
        'equipment', 'Barbell, dumbbells, cable station and leg curl machine.'
    ),
    'exercises', JSON_ARRAY(
        JSON_OBJECT('exercise_id','rhythm_demo_romanian_deadlift','name','Romanian Deadlift','order',1,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',180,'weight',75.0,'set_type','normal','instructions','Keep the bar close and stop before the back position changes.'),
        JSON_OBJECT('exercise_id','rhythm_demo_shoulder_press','name','Dumbbell Shoulder Press','order',2,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',120,'weight',20.0,'set_type','normal','instructions','Keep ribs stacked over the pelvis.'),
        JSON_OBJECT('exercise_id','rhythm_demo_cable_row','name','Seated Cable Row','order',3,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',120,'weight',52.5,'set_type','normal','instructions','Pause when the handle reaches the torso.'),
        JSON_OBJECT('exercise_id','rhythm_demo_leg_curl','name','Lying Leg Curl','order',4,'sets',3,'rep_range',JSON_OBJECT('min',10,'max',12),'target_rir',JSON_OBJECT('min',1,'max',3),'rest_seconds',90,'weight',42.5,'set_type','normal','instructions','Control the lowering phase.')
    )
);

SET @rhythm_demo_active_snapshot = JSON_OBJECT(
    'schema', 'training-program-draft',
    'schema_version', '1.0',
    'source', 'manual',
    'program', JSON_OBJECT(
        'program_id', 'rhythm-demo-strength',
        'name', 'Demo Strength Foundation',
        'description', 'A balanced two-day strength program with visible load, RIR and volume progression.',
        'version', 1,
        'change_reason', 'Initial demonstration program.',
        'parent_version', NULL,
        'parent_aggregate_hash', NULL
    ),
    'templates', JSON_ARRAY(
        JSON_MERGE_PATCH(JSON_OBJECT('template_id','full-body-a'), @rhythm_demo_active_a),
        JSON_MERGE_PATCH(JSON_OBJECT('template_id','full-body-b'), @rhythm_demo_active_b)
    ),
    'schedule_slots', JSON_ARRAY(
        JSON_OBJECT('weekday',1,'template_id','full-body-a'),
        JSON_OBJECT('weekday',4,'template_id','full-body-b')
    )
);
SET @rhythm_demo_active_hash = SHA2(CAST(@rhythm_demo_active_snapshot AS CHAR CHARACTER SET utf8mb4), 256);

INSERT INTO program_versions
    (program_id, version_number, source, change_reason, trainer_comment,
     snapshot_json, snapshot_hash, parent_version_id, created_at, lifecycle_status,
     lock_version, aggregate_hash, updated_at, activated_at, archived_at)
VALUES
    (@rhythm_demo_program_id, 1, 'manual', 'Initial demonstration program.',
     'Loads are illustrative and intentionally progress across four weeks.',
     @rhythm_demo_active_snapshot, @rhythm_demo_active_hash, NULL,
     DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY), 'published', 1,
     @rhythm_demo_active_hash, DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY),
     DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY), NULL)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @rhythm_demo_active_version_id = (
    SELECT id FROM program_versions
    WHERE program_id = @rhythm_demo_program_id AND version_number = 1
    LIMIT 1
);

UPDATE training_programs
SET active_version_id = @rhythm_demo_active_version_id
WHERE id = @rhythm_demo_program_id
  AND user_id = @rhythm_demo_user_id
  AND active_version_id IS NULL;

INSERT INTO workout_templates
    (user_id, program_version_id, code, name, workout_type, content_json,
     content_hash, created_at, updated_at, deleted_at)
SELECT @rhythm_demo_user_id, @rhythm_demo_active_version_id, 'full-body-a',
       'Full Body A', 'strength', @rhythm_demo_active_a,
       SHA2(CAST(@rhythm_demo_active_a AS CHAR CHARACTER SET utf8mb4), 256),
       DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY),
       DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY), NULL
WHERE NOT EXISTS (
    SELECT 1 FROM workout_templates
    WHERE program_version_id = @rhythm_demo_active_version_id AND code = 'full-body-a'
);

INSERT INTO workout_templates
    (user_id, program_version_id, code, name, workout_type, content_json,
     content_hash, created_at, updated_at, deleted_at)
SELECT @rhythm_demo_user_id, @rhythm_demo_active_version_id, 'full-body-b',
       'Full Body B', 'strength', @rhythm_demo_active_b,
       SHA2(CAST(@rhythm_demo_active_b AS CHAR CHARACTER SET utf8mb4), 256),
       DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY),
       DATE_SUB(@rhythm_demo_created_at, INTERVAL 35 DAY), NULL
WHERE NOT EXISTS (
    SELECT 1 FROM workout_templates
    WHERE program_version_id = @rhythm_demo_active_version_id AND code = 'full-body-b'
);

SET @rhythm_demo_active_template_a = (
    SELECT id FROM workout_templates
    WHERE user_id = @rhythm_demo_user_id
      AND program_version_id = @rhythm_demo_active_version_id
      AND code = 'full-body-a' AND deleted_at IS NULL
    LIMIT 1
);
SET @rhythm_demo_active_template_b = (
    SELECT id FROM workout_templates
    WHERE user_id = @rhythm_demo_user_id
      AND program_version_id = @rhythm_demo_active_version_id
      AND code = 'full-body-b' AND deleted_at IS NULL
    LIMIT 1
);

INSERT IGNORE INTO program_schedule_slots
    (program_version_id, workout_template_id, weekday, created_at)
VALUES
    (@rhythm_demo_active_version_id, @rhythm_demo_active_template_a, 1, @rhythm_demo_now),
    (@rhythm_demo_active_version_id, @rhythm_demo_active_template_b, 4, @rhythm_demo_now);

INSERT IGNORE INTO schedules
    (user_id, weekday, workout_type, label, active, version, created_at, updated_at)
VALUES
    (@rhythm_demo_user_id, 1, 'strength', 'Full Body A', 1, 1, @rhythm_demo_now, @rhythm_demo_now),
    (@rhythm_demo_user_id, 4, 'strength', 'Full Body B', 1, 1, @rhythm_demo_now, @rhythm_demo_now);

-- Eight completed workouts over four weeks plus two future planned workouts.
INSERT IGNORE INTO workout_plans
    (user_id, external_plan_id, program_version_id, workout_template_id, name,
     workout_type, scheduled_date, goal, estimated_duration_min, trainer_notes,
     pre_workout_json, source_json, schema_version, status, version, created_at,
     updated_at, deleted_at)
SELECT
    @rhythm_demo_user_id,
    CONCAT('rhythm-demo-', DATE_FORMAT(d.scheduled_date, '%Y%m%d'), '-', d.template_key),
    @rhythm_demo_active_version_id,
    wt.id,
    wt.name,
    'strength',
    d.scheduled_date,
    JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.goal')),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.estimated_duration_min')) AS UNSIGNED),
    JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.trainer_notes')),
    JSON_EXTRACT(wt.content_json, '$.pre_workout'),
    JSON_OBJECT(
        'schema','training-plan',
        'schema_version','1.0',
        'plan_id',CONCAT('rhythm-demo-', DATE_FORMAT(d.scheduled_date, '%Y%m%d'), '-', d.template_key),
        'program',JSON_OBJECT(
            'program_id','rhythm-demo-strength',
            'name','Demo Strength Foundation',
            'version',1,
            'change_reason','Initial demonstration program.',
            'description','A balanced two-day strength program with visible load, RIR and volume progression.'
        ),
        'workout',JSON_OBJECT(
            'template_id',wt.code,
            'name',wt.name,
            'type','strength',
            'scheduled_date',DATE_FORMAT(d.scheduled_date, '%Y-%m-%d'),
            'goal',JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.goal')),
            'estimated_duration_min',CAST(JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.estimated_duration_min')) AS UNSIGNED)
        ),
        'exercises',JSON_EXTRACT(wt.content_json, '$.exercises'),
        'trainer_notes',JSON_UNQUOTE(JSON_EXTRACT(wt.content_json, '$.trainer_notes')),
        'pre_workout',JSON_EXTRACT(wt.content_json, '$.pre_workout')
    ),
    '1.0',
    d.plan_status,
    1,
    CASE WHEN d.plan_status = 'completed'
         THEN CAST(DATE_ADD(d.scheduled_date, INTERVAL 12 HOUR) AS DATETIME)
         ELSE CAST(@rhythm_demo_now AS DATETIME) END,
    CASE WHEN d.plan_status = 'completed'
         THEN CAST(DATE_ADD(d.scheduled_date, INTERVAL 17 HOUR) AS DATETIME)
         ELSE CAST(@rhythm_demo_now AS DATETIME) END,
    NULL
FROM (
    SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY) scheduled_date, 'a' template_key, 'completed' plan_status
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 25 DAY), 'b', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 21 DAY), 'a', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 18 DAY), 'b', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 14 DAY), 'a', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 11 DAY), 'b', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 7 DAY),  'a', 'completed'
    UNION ALL SELECT DATE_SUB(@rhythm_demo_week, INTERVAL 4 DAY),  'b', 'completed'
    UNION ALL SELECT DATE_ADD(@rhythm_demo_week, INTERVAL 7 DAY),  'a', 'planned'
    UNION ALL SELECT DATE_ADD(@rhythm_demo_week, INTERVAL 10 DAY), 'b', 'planned'
) d
JOIN workout_templates wt
  ON wt.user_id = @rhythm_demo_user_id
 AND wt.program_version_id = @rhythm_demo_active_version_id
 AND wt.code = CONCAT('full-body-', d.template_key)
 AND wt.deleted_at IS NULL;

-- Planned exercises are materialized for both completed and upcoming plans.
INSERT IGNORE INTO workout_exercises
    (workout_plan_id, exercise_id, original_exercise_id, sequence_no, planned_sets,
     rep_min, rep_max, target_rir_min, target_rir_max, rest_seconds,
     planned_weight_kg, warmup_sets, method_type, group_id, instructions,
     substitution_reason, substituted_at, version, created_at)
SELECT
    p.id, x.exercise_id, NULL, x.sequence_no, 3, x.rep_min, x.rep_max,
    1.0, 3.0, x.rest_seconds,
    CASE x.exercise_id
        WHEN 'rhythm_demo_bench_press' THEN 50.0 + 2.5 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_lat_pulldown' THEN 45.0 + 2.5 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_leg_press' THEN 100.0 + 5.0 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_lateral_raise' THEN 8.0 + 1.0 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_romanian_deadlift' THEN 60.0 + 5.0 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_shoulder_press' THEN 14.0 + 2.0 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_cable_row' THEN 45.0 + 2.5 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
        WHEN 'rhythm_demo_leg_curl' THEN 35.0 + 2.5 * LEAST(4, GREATEST(0, FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)))
    END,
    x.warmup_sets, 'normal', NULL, x.instructions, NULL, NULL, 1,
    DATE_ADD(p.scheduled_date, INTERVAL 12 HOUR)
FROM workout_plans p
JOIN (
    SELECT 'a' template_key, 'rhythm_demo_bench_press' exercise_id, 1 sequence_no, 8 rep_min, 10 rep_max, 150 rest_seconds, 1 warmup_sets, 'Pause briefly on the chest.' instructions
    UNION ALL SELECT 'a','rhythm_demo_lat_pulldown',2,8,10,120,0,'Drive elbows toward the ribs.'
    UNION ALL SELECT 'a','rhythm_demo_leg_press',3,8,10,150,1,'Use a consistent depth.'
    UNION ALL SELECT 'a','rhythm_demo_lateral_raise',4,10,12,75,0,'Lead with the elbows.'
    UNION ALL SELECT 'b','rhythm_demo_romanian_deadlift',1,8,10,180,1,'Keep the bar close and stop before the back position changes.'
    UNION ALL SELECT 'b','rhythm_demo_shoulder_press',2,8,10,120,1,'Keep ribs stacked over the pelvis.'
    UNION ALL SELECT 'b','rhythm_demo_cable_row',3,8,10,120,0,'Pause when the handle reaches the torso.'
    UNION ALL SELECT 'b','rhythm_demo_leg_curl',4,10,12,90,0,'Control the lowering phase.'
) x ON x.template_key = RIGHT(p.external_plan_id, 1)
WHERE p.user_id = @rhythm_demo_user_id
  AND p.program_version_id = @rhythm_demo_active_version_id
  AND p.external_plan_id LIKE 'rhythm-demo-%'
  AND p.deleted_at IS NULL;

INSERT IGNORE INTO workout_sessions
    (public_id, user_id, workout_plan_id, workout_type, status, started_at,
     finished_at, session_rpe, wellbeing, user_comment, version,
     edited_after_completion, edited_at, created_at, updated_at, deleted_at)
SELECT
    CONCAT('rhythm-demo-session-', DATE_FORMAT(p.scheduled_date, '%Y%m%d'), '-', RIGHT(p.external_plan_id, 1)),
    @rhythm_demo_user_id, p.id, 'strength', 'completed',
    DATE_ADD(p.scheduled_date, INTERVAL 15 HOUR),
    DATE_ADD(p.scheduled_date, INTERVAL 16 HOUR),
    CASE WHEN p.scheduled_date >= DATE_SUB(@rhythm_demo_week, INTERVAL 14 DAY) THEN 8 ELSE 7 END,
    CASE WHEN RIGHT(p.external_plan_id, 1) = 'a' THEN 8 ELSE 7 END,
    'Demo session completed with consistent technique.',
    1, 0, NULL,
    DATE_ADD(p.scheduled_date, INTERVAL 15 HOUR),
    DATE_ADD(p.scheduled_date, INTERVAL 16 HOUR), NULL
FROM workout_plans p
WHERE p.user_id = @rhythm_demo_user_id
  AND p.program_version_id = @rhythm_demo_active_version_id
  AND p.external_plan_id LIKE 'rhythm-demo-%'
  AND p.status = 'completed'
  AND p.deleted_at IS NULL;

INSERT IGNORE INTO readiness_logs
    (user_id, workout_session_id, body_weight_kg, sleep_score, energy_score,
     readiness_score, comment, logged_at)
SELECT
    @rhythm_demo_user_id, ws.id, NULL,
    7 + MOD(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)), 2),
    7, 8, 'Demo readiness check-in.', ws.started_at
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
WHERE ws.user_id = @rhythm_demo_user_id
  AND ws.public_id LIKE 'rhythm-demo-session-%'
  AND ws.deleted_at IS NULL;

INSERT IGNORE INTO session_exercises
    (workout_session_id, workout_exercise_id, original_exercise_id,
     actual_exercise_id, status, skip_reason, substitution_reason,
     substituted_at, exercise_rating, comment, completed_at, version,
     created_at, updated_at)
SELECT
    ws.id, we.id, we.exercise_id, we.exercise_id, 'completed', NULL, NULL, NULL,
    'normal', 'Completed as planned.', ws.finished_at, 1, ws.started_at, ws.finished_at
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
JOIN workout_exercises we ON we.workout_plan_id = p.id
WHERE ws.user_id = @rhythm_demo_user_id
  AND ws.public_id LIKE 'rhythm-demo-session-%'
  AND ws.deleted_at IS NULL;

INSERT IGNORE INTO exercise_sets
    (public_id, user_id, workout_session_id, session_exercise_id, set_number,
     set_type, method_type, group_id, sequence_no, performed_weight_kg, reps,
     rir, duration_seconds, distance_m, completed_at, client_action_id,
     version, edited_at, deleted_at)
SELECT
    CONCAT('rhythm-demo-set-', ws.id, '-', se.id, '-', n.set_number),
    @rhythm_demo_user_id, ws.id, se.id, n.set_number,
    'working', 'normal', NULL, 1,
    we.planned_weight_kg,
    CASE n.set_number WHEN 1 THEN 10 WHEN 2 THEN 9 ELSE 8 END,
    GREATEST(1.0,
        3.5
        - 0.5 * FLOOR(DATEDIFF(p.scheduled_date, DATE_SUB(@rhythm_demo_week, INTERVAL 28 DAY)) / 7)
        - 0.5 * (n.set_number - 1)
    ),
    NULL, NULL,
    DATE_ADD(ws.started_at, INTERVAL (we.sequence_no * 8 + n.set_number * 2) MINUTE),
    NULL, 1, NULL, NULL
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
JOIN session_exercises se ON se.workout_session_id = ws.id
JOIN workout_exercises we ON we.id = se.workout_exercise_id AND we.workout_plan_id = p.id
CROSS JOIN (
    SELECT 1 set_number UNION ALL SELECT 2 UNION ALL SELECT 3
) n
WHERE ws.user_id = @rhythm_demo_user_id
  AND ws.public_id LIKE 'rhythm-demo-session-%'
  AND ws.status = 'completed'
  AND ws.deleted_at IS NULL;

-- Latest sessions contain actionable progression suggestions.
INSERT IGNORE INTO progression_suggestions
    (user_id, workout_session_id, exercise_id, current_weight_kg,
     suggested_next_weight_kg, accepted_next_weight_kg, reason, status,
     created_at, resolved_at)
SELECT
    @rhythm_demo_user_id, ws.id, se.actual_exercise_id,
    MAX(es.performed_weight_kg),
    MAX(es.performed_weight_kg) + e.progression_increment,
    NULL,
    'All working sets reached the target range while staying inside the RIR target.',
    'pending', ws.finished_at, NULL
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
JOIN session_exercises se ON se.workout_session_id = ws.id
JOIN exercises e ON e.exercise_id = se.actual_exercise_id AND e.owner_user_id = ws.user_id
JOIN exercise_sets es ON es.session_exercise_id = se.id AND es.workout_session_id = ws.id
WHERE ws.user_id = @rhythm_demo_user_id
  AND ((p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 7 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_bench_press')
    OR (p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 4 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_romanian_deadlift'))
  AND es.set_type = 'working'
  AND es.deleted_at IS NULL
GROUP BY ws.id, se.actual_exercise_id, e.progression_increment, ws.finished_at;

-- Explicit PR rows make the newest benchmarks visible in session summaries and
-- analytics. The values are derived from the seeded working sets.
INSERT IGNORE INTO personal_records
    (user_id, workout_session_id, exercise_id, record_type, value_decimal,
     metadata_json, achieved_at)
SELECT
    @rhythm_demo_user_id, ws.id, se.actual_exercise_id, 'best_e1rm',
    ROUND(MAX(es.performed_weight_kg * (1 + es.reps / 30.0)), 2),
    JSON_OBJECT('method','Epley','source','demo_seed','note','Estimated benchmark, not a tested one-rep maximum.'),
    ws.finished_at
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
JOIN session_exercises se ON se.workout_session_id = ws.id
JOIN exercise_sets es ON es.session_exercise_id = se.id AND es.workout_session_id = ws.id
WHERE ws.user_id = @rhythm_demo_user_id
  AND ((p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 7 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_bench_press')
    OR (p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 4 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_romanian_deadlift'))
  AND es.set_type = 'working'
  AND es.deleted_at IS NULL
GROUP BY ws.id, se.actual_exercise_id, ws.finished_at;

INSERT IGNORE INTO personal_records
    (user_id, workout_session_id, exercise_id, record_type, value_decimal,
     metadata_json, achieved_at)
SELECT
    @rhythm_demo_user_id, ws.id, se.actual_exercise_id, 'max_weight',
    MAX(es.performed_weight_kg),
    JSON_OBJECT('source','demo_seed','note','Heaviest completed working set in the demonstration block.'),
    ws.finished_at
FROM workout_sessions ws
JOIN workout_plans p ON p.id = ws.workout_plan_id AND p.user_id = ws.user_id
JOIN session_exercises se ON se.workout_session_id = ws.id
JOIN exercise_sets es ON es.session_exercise_id = se.id AND es.workout_session_id = ws.id
WHERE ws.user_id = @rhythm_demo_user_id
  AND ((p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 7 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_bench_press')
    OR (p.scheduled_date = DATE_SUB(@rhythm_demo_week, INTERVAL 4 DAY)
        AND se.actual_exercise_id = 'rhythm_demo_romanian_deadlift'))
  AND es.set_type = 'working'
  AND es.deleted_at IS NULL
GROUP BY ws.id, se.actual_exercise_id, ws.finished_at;

-- Editable version 2 draft. It demonstrates a next block with one added
-- accessory exercise and tighter target RIR guidance.
SET @rhythm_demo_draft_a = JSON_OBJECT(
    'name', 'Full Body A - Next Block',
    'type', 'strength',
    'goal', 'Continue strength progression and add upper-back volume.',
    'estimated_duration_min', 65,
    'trainer_notes', 'Draft only: review recovery before activation.',
    'pre_workout', JSON_OBJECT(
        'instructions', 'Complete 5 minutes of easy movement and two ramp-up sets.',
        'nutrition', 'Arrive hydrated.',
        'equipment', 'Barbell, cable station, dumbbells and leg press.'
    ),
    'exercises', JSON_ARRAY(
        JSON_OBJECT('exercise_id','rhythm_demo_bench_press','name','Barbell Bench Press','order',1,'sets',4,'rep_range',JSON_OBJECT('min',6,'max',8),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',180,'weight',60.0,'set_type','normal','instructions','Pause briefly on the chest.'),
        JSON_OBJECT('exercise_id','rhythm_demo_lat_pulldown','name','Lat Pulldown','order',2,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',120,'weight',55.0,'set_type','normal','instructions','Drive elbows toward the ribs.'),
        JSON_OBJECT('exercise_id','rhythm_demo_leg_press','name','Leg Press','order',3,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',150,'weight',120.0,'set_type','normal','instructions','Use a consistent depth.'),
        JSON_OBJECT('exercise_id','rhythm_demo_lateral_raise','name','Dumbbell Lateral Raise','order',4,'sets',3,'rep_range',JSON_OBJECT('min',10,'max',12),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',75,'weight',12.0,'set_type','normal','instructions','Lead with the elbows.'),
        JSON_OBJECT('exercise_id','rhythm_demo_face_pull','name','Cable Face Pull','order',5,'sets',2,'rep_range',JSON_OBJECT('min',12,'max',15),'target_rir',JSON_OBJECT('min',2,'max',3),'rest_seconds',60,'weight',20.0,'set_type','normal','instructions','Finish with external rotation.')
    )
);

SET @rhythm_demo_draft_b = JSON_OBJECT(
    'name', 'Full Body B - Next Block',
    'type', 'strength',
    'goal', 'Continue posterior-chain progression with controlled fatigue.',
    'estimated_duration_min', 60,
    'trainer_notes', 'Draft only: review recovery before activation.',
    'pre_workout', JSON_OBJECT(
        'instructions', 'Complete a hip hinge drill and two ramp-up sets.',
        'nutrition', 'Arrive hydrated.',
        'equipment', 'Barbell, dumbbells, cable station and leg curl machine.'
    ),
    'exercises', JSON_ARRAY(
        JSON_OBJECT('exercise_id','rhythm_demo_romanian_deadlift','name','Romanian Deadlift','order',1,'sets',4,'rep_range',JSON_OBJECT('min',6,'max',8),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',180,'weight',80.0,'set_type','normal','instructions','Keep the bar close and stop before the back position changes.'),
        JSON_OBJECT('exercise_id','rhythm_demo_shoulder_press','name','Dumbbell Shoulder Press','order',2,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',120,'weight',22.0,'set_type','normal','instructions','Keep ribs stacked over the pelvis.'),
        JSON_OBJECT('exercise_id','rhythm_demo_cable_row','name','Seated Cable Row','order',3,'sets',3,'rep_range',JSON_OBJECT('min',8,'max',10),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',120,'weight',55.0,'set_type','normal','instructions','Pause when the handle reaches the torso.'),
        JSON_OBJECT('exercise_id','rhythm_demo_leg_curl','name','Lying Leg Curl','order',4,'sets',3,'rep_range',JSON_OBJECT('min',10,'max',12),'target_rir',JSON_OBJECT('min',1,'max',2),'rest_seconds',90,'weight',45.0,'set_type','normal','instructions','Control the lowering phase.')
    )
);

SET @rhythm_demo_draft_snapshot = JSON_OBJECT(
    'schema', 'training-program-draft',
    'schema_version', '1.0',
    'source', 'webmcp',
    'program', JSON_OBJECT(
        'program_id', 'rhythm-demo-strength',
        'name', 'Demo Strength Foundation',
        'description', 'Draft for the next training block with slightly higher loads and tighter RIR targets.',
        'version', 2,
        'change_reason', 'Prepare the next block after four consistent weeks.',
        'parent_version', 1,
        'parent_aggregate_hash', @rhythm_demo_active_hash
    ),
    'templates', JSON_ARRAY(
        JSON_MERGE_PATCH(JSON_OBJECT('template_id','full-body-a'), @rhythm_demo_draft_a),
        JSON_MERGE_PATCH(JSON_OBJECT('template_id','full-body-b'), @rhythm_demo_draft_b)
    ),
    'schedule_slots', JSON_ARRAY(
        JSON_OBJECT('weekday',1,'template_id','full-body-a'),
        JSON_OBJECT('weekday',4,'template_id','full-body-b')
    )
);
SET @rhythm_demo_draft_hash = SHA2(CAST(@rhythm_demo_draft_snapshot AS CHAR CHARACTER SET utf8mb4), 256);

INSERT INTO program_versions
    (program_id, version_number, source, change_reason, trainer_comment,
     snapshot_json, snapshot_hash, parent_version_id, created_at, lifecycle_status,
     lock_version, aggregate_hash, updated_at, activated_at, archived_at)
VALUES
    (@rhythm_demo_program_id, 2, 'webmcp',
     'Prepare the next block after four consistent weeks.',
     'Review the proposed load increases before activation.',
     @rhythm_demo_draft_snapshot, @rhythm_demo_draft_hash,
     @rhythm_demo_active_version_id, @rhythm_demo_now, 'draft', 1,
     @rhythm_demo_draft_hash, @rhythm_demo_now, NULL, NULL)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @rhythm_demo_draft_version_id = (
    SELECT id FROM program_versions
    WHERE program_id = @rhythm_demo_program_id AND version_number = 2
    LIMIT 1
);

INSERT INTO workout_templates
    (user_id, program_version_id, code, name, workout_type, content_json,
     content_hash, created_at, updated_at, deleted_at)
SELECT @rhythm_demo_user_id, @rhythm_demo_draft_version_id, 'full-body-a',
       'Full Body A - Next Block', 'strength', @rhythm_demo_draft_a,
       SHA2(CAST(@rhythm_demo_draft_a AS CHAR CHARACTER SET utf8mb4), 256),
       @rhythm_demo_now, @rhythm_demo_now, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM workout_templates
    WHERE program_version_id = @rhythm_demo_draft_version_id AND code = 'full-body-a'
);

INSERT INTO workout_templates
    (user_id, program_version_id, code, name, workout_type, content_json,
     content_hash, created_at, updated_at, deleted_at)
SELECT @rhythm_demo_user_id, @rhythm_demo_draft_version_id, 'full-body-b',
       'Full Body B - Next Block', 'strength', @rhythm_demo_draft_b,
       SHA2(CAST(@rhythm_demo_draft_b AS CHAR CHARACTER SET utf8mb4), 256),
       @rhythm_demo_now, @rhythm_demo_now, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM workout_templates
    WHERE program_version_id = @rhythm_demo_draft_version_id AND code = 'full-body-b'
);

SET @rhythm_demo_draft_template_a = (
    SELECT id FROM workout_templates
    WHERE user_id = @rhythm_demo_user_id
      AND program_version_id = @rhythm_demo_draft_version_id
      AND code = 'full-body-a' AND deleted_at IS NULL
    LIMIT 1
);
SET @rhythm_demo_draft_template_b = (
    SELECT id FROM workout_templates
    WHERE user_id = @rhythm_demo_user_id
      AND program_version_id = @rhythm_demo_draft_version_id
      AND code = 'full-body-b' AND deleted_at IS NULL
    LIMIT 1
);

INSERT IGNORE INTO program_schedule_slots
    (program_version_id, workout_template_id, weekday, created_at)
VALUES
    (@rhythm_demo_draft_version_id, @rhythm_demo_draft_template_a, 1, @rhythm_demo_now),
    (@rhythm_demo_draft_version_id, @rhythm_demo_draft_template_b, 4, @rhythm_demo_now);

COMMIT;

-- Keep the final result: its numeric id is the value for WEBMCP_ALLOWED_USER_IDS.
SELECT
    @rhythm_demo_user_id AS demo_user_id,
    'rhythm_demo' AS login,
    'Rhythm-Demo-2026!' AS initial_password,
    (SELECT COUNT(*) FROM workout_sessions
     WHERE user_id = @rhythm_demo_user_id
       AND public_id LIKE 'rhythm-demo-session-%'
       AND status = 'completed' AND deleted_at IS NULL) AS completed_sessions,
    (SELECT COUNT(*) FROM exercise_sets
     WHERE user_id = @rhythm_demo_user_id
       AND public_id LIKE 'rhythm-demo-set-%'
       AND deleted_at IS NULL) AS working_sets,
    (SELECT COUNT(*) FROM personal_records
     WHERE user_id = @rhythm_demo_user_id) AS personal_records,
    (SELECT COUNT(*) FROM program_versions
     WHERE program_id = @rhythm_demo_program_id
       AND lifecycle_status = 'draft') AS program_drafts,
    CONCAT('WEBMCP_ALLOWED_USER_IDS=', @rhythm_demo_user_id) AS webmcp_allowlist;
