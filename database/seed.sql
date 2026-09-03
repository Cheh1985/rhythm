INSERT IGNORE INTO exercises (exercise_id, owner_user_id, name, category, muscle_groups, exercise_type, equipment, progression_increment, progression_mode, status, created_at, updated_at) VALUES
('leg_press_001', NULL, 'Жим ногами', 'legs', JSON_ARRAY('quadriceps','glutes'), 'strength', 'leg_press', 5.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('bench_press_001', NULL, 'Жим лёжа', 'chest', JSON_ARRAY('chest','triceps','front_delts'), 'strength', 'barbell', 2.50, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('incline_db_press_001', NULL, 'Жим гантелей на наклонной', 'chest', JSON_ARRAY('upper_chest','triceps','front_delts'), 'strength', 'dumbbells', 2.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('lat_pulldown_001', NULL, 'Тяга верхнего блока', 'back', JSON_ARRAY('lats','biceps'), 'strength', 'cable', 2.50, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('seated_cable_row_001', NULL, 'Горизонтальная тяга блока', 'back', JSON_ARRAY('lats','upper_back','biceps'), 'strength', 'cable', 2.50, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('leg_curl_001', NULL, 'Сгибание ног', 'legs', JSON_ARRAY('hamstrings'), 'strength', 'machine', 2.50, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('biceps_curl_001', NULL, 'Сгибание рук', 'arms', JSON_ARRAY('biceps'), 'strength', 'dumbbells', 1.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('triceps_pushdown_001', NULL, 'Разгибание рук на блоке', 'arms', JSON_ARRAY('triceps'), 'strength', 'cable', 1.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('db_shoulder_press_001', NULL, 'Жим гантелей вверх', 'shoulders', JSON_ARRAY('delts','triceps'), 'strength', 'dumbbells', 2.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('hack_squat_001', NULL, 'Гакк-присед', 'legs', JSON_ARRAY('quadriceps','glutes'), 'strength', 'hack_squat', 5.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('romanian_deadlift_001', NULL, 'Румынская тяга', 'legs', JSON_ARRAY('hamstrings','glutes','lower_back'), 'strength', 'barbell', 5.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('calf_raise_001', NULL, 'Подъём на носки', 'legs', JSON_ARRAY('calves'), 'strength', 'machine', 5.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('lateral_raise_001', NULL, 'Разведение гантелей в стороны', 'shoulders', JSON_ARRAY('side_delts'), 'strength', 'dumbbells', 1.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('face_pull_001', NULL, 'Тяга каната к лицу', 'shoulders', JSON_ARRAY('rear_delts','upper_back'), 'strength', 'cable', 1.00, 'absolute', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP());

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
('face_pull_001','ru','Тяга каната к лицу'),('face_pull_001','en','Face Pull')
ON DUPLICATE KEY UPDATE name=VALUES(name);
