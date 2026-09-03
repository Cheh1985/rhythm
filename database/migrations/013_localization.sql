ALTER TABLE users
    ADD COLUMN locale ENUM('ru','en') NOT NULL DEFAULT 'ru' AFTER theme;

CREATE TABLE exercise_translations (
    exercise_id VARCHAR(80) NOT NULL,
    locale ENUM('ru','en') NOT NULL,
    name VARCHAR(190) NOT NULL,
    PRIMARY KEY (exercise_id, locale),
    INDEX idx_exercise_translations_locale_name (locale, name),
    CONSTRAINT fk_exercise_translations_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(exercise_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO exercise_translations (exercise_id, locale, name) VALUES
('leg_press_001','ru','Жим ногами'),('leg_press_001','en','Leg Press'),
('bench_press_001','ru','Жим лёжа'),('bench_press_001','en','Bench Press'),
('incline_db_press_001','ru','Жим гантелей на наклонной'),('incline_db_press_001','en','Incline Dumbbell Press'),
('lat_pulldown_001','ru','Тяга верхнего блока'),('lat_pulldown_001','en','Lat Pulldown'),
('seated_cable_row_001','ru','Горизонтальная тяга блока'),('seated_cable_row_001','en','Seated Cable Row'),
('leg_curl_001','ru','Сгибание ног'),('leg_curl_001','en','Leg Curl'),
('biceps_curl_001','ru','Сгибание рук'),('biceps_curl_001','en','Biceps Curl'),
('triceps_pushdown_001','ru','Разгибание рук на блоке'),('triceps_pushdown_001','en','Triceps Pushdown'),
('db_shoulder_press_001','ru','Жим гантелей вверх'),('db_shoulder_press_001','en','Dumbbell Shoulder Press'),
('hack_squat_001','ru','Гакк-присед'),('hack_squat_001','en','Hack Squat'),
('romanian_deadlift_001','ru','Румынская тяга'),('romanian_deadlift_001','en','Romanian Deadlift'),
('calf_raise_001','ru','Подъём на носки'),('calf_raise_001','en','Calf Raise'),
('lateral_raise_001','ru','Разведение гантелей в стороны'),('lateral_raise_001','en','Lateral Raise'),
('face_pull_001','ru','Тяга каната к лицу'),('face_pull_001','en','Face Pull');
