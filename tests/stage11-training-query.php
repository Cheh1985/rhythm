<?php

declare(strict_types=1);

putenv('APP_ENV=test');
require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\Analytics;
use App\Domain\TrainingMetrics;
use App\Repository\TrainingQueryRepository;
use App\Service\TrainingQueryService;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};
$throws = static function (callable $callback, string $contains, string $label) use ($check): void {
    try { $callback(); $check(false, $label . ' (исключение не выброшено)'); }
    catch (Throwable $exception) { $check(str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')'); }
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users (
 id INTEGER PRIMARY KEY,login TEXT NOT NULL,email TEXT NOT NULL,password_hash TEXT NOT NULL,role TEXT NOT NULL,
 timezone TEXT NOT NULL,theme TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE exercises (
 exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NOT NULL,category TEXT NULL,muscle_groups TEXT NULL,
 exercise_type TEXT NOT NULL,equipment TEXT NULL,progression_increment REAL NOT NULL,progression_mode TEXT NOT NULL,
 status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE training_programs (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,external_program_id TEXT NOT NULL,name TEXT NOT NULL,description TEXT NULL,
 status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,archived_at TEXT NULL,deleted_at TEXT NULL,active_version_id INTEGER NULL
);
CREATE TABLE program_versions (
 id INTEGER PRIMARY KEY,program_id INTEGER NOT NULL,version_number INTEGER NOT NULL,source TEXT NOT NULL,change_reason TEXT NULL,
 trainer_comment TEXT NULL,snapshot_json TEXT NOT NULL,snapshot_hash TEXT NOT NULL,parent_version_id INTEGER NULL,created_at TEXT NOT NULL,
 lifecycle_status TEXT NOT NULL,lock_version INTEGER NOT NULL,aggregate_hash TEXT NOT NULL,updated_at TEXT NOT NULL,activated_at TEXT NULL,archived_at TEXT NULL
);
CREATE TABLE workout_templates (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,program_version_id INTEGER NULL,code TEXT NOT NULL,name TEXT NOT NULL,
 workout_type TEXT NOT NULL,content_json TEXT NOT NULL,content_hash TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE program_schedule_slots (
 id INTEGER PRIMARY KEY,program_version_id INTEGER NOT NULL,workout_template_id INTEGER NOT NULL,weekday INTEGER NOT NULL,created_at TEXT NOT NULL
);
CREATE TABLE workout_plans (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,external_plan_id TEXT NOT NULL,program_version_id INTEGER NULL,workout_template_id INTEGER NULL,
 name TEXT NOT NULL,workout_type TEXT NOT NULL,scheduled_date TEXT NOT NULL,goal TEXT NULL,estimated_duration_min INTEGER NULL,
 trainer_notes TEXT NULL,pre_workout_json TEXT NULL,source_json TEXT NOT NULL,schema_version TEXT NOT NULL,status TEXT NOT NULL,
 version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE workout_exercises (
 id INTEGER PRIMARY KEY,workout_plan_id INTEGER NOT NULL,exercise_id TEXT NOT NULL,original_exercise_id TEXT NULL,sequence_no INTEGER NOT NULL,planned_sets INTEGER NOT NULL,
 rep_min INTEGER NOT NULL,rep_max INTEGER NOT NULL,target_rir_min REAL NULL,target_rir_max REAL NULL,rest_seconds INTEGER NOT NULL,
 planned_weight_kg REAL NULL,warmup_sets INTEGER NOT NULL,method_type TEXT NOT NULL,group_id TEXT NULL,instructions TEXT NULL,
 substitution_reason TEXT NULL,substituted_at TEXT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL
);
CREATE TABLE workout_sessions (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_plan_id INTEGER NOT NULL,workout_type TEXT NOT NULL,
 status TEXT NOT NULL,started_at TEXT NOT NULL,finished_at TEXT NULL,session_rpe INTEGER NULL,wellbeing INTEGER NULL,user_comment TEXT NULL,
 version INTEGER NOT NULL,edited_after_completion INTEGER NOT NULL,edited_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE session_exercises (
 id INTEGER PRIMARY KEY,workout_session_id INTEGER NOT NULL,workout_exercise_id INTEGER NOT NULL,original_exercise_id TEXT NOT NULL,
 actual_exercise_id TEXT NOT NULL,status TEXT NOT NULL,skip_reason TEXT NULL,substitution_reason TEXT NULL,substituted_at TEXT NULL,
 exercise_rating TEXT NULL,comment TEXT NULL,completed_at TEXT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
);
CREATE TABLE exercise_sets (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_session_id INTEGER NOT NULL,session_exercise_id INTEGER NOT NULL,
 set_number INTEGER NOT NULL,set_type TEXT NOT NULL,method_type TEXT NOT NULL,group_id TEXT NULL,sequence_no INTEGER NOT NULL,
 performed_weight_kg REAL NULL,reps INTEGER NULL,rir REAL NULL,duration_seconds INTEGER NULL,distance_m INTEGER NULL,
 completed_at TEXT NOT NULL,client_action_id TEXT NULL,version INTEGER NOT NULL,edited_at TEXT NULL,deleted_at TEXT NULL
);
CREATE TABLE schedules (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,weekday INTEGER NOT NULL,workout_type TEXT NOT NULL,label TEXT NOT NULL,
 active INTEGER NOT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
);
CREATE TABLE swimming_sessions (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_session_id INTEGER NULL,schedule_id INTEGER NULL,
 source TEXT NOT NULL,swim_date TEXT NOT NULL,occurred_at TEXT NOT NULL,duration_minutes INTEGER NOT NULL,pool_length_m INTEGER NOT NULL,
 total_distance_m INTEGER NOT NULL,primary_style TEXT NOT NULL,intensity INTEGER NOT NULL,arms_fatigue INTEGER NOT NULL,
 back_fatigue INTEGER NOT NULL,legs_fatigue INTEGER NOT NULL,wellbeing INTEGER NOT NULL,intervals_json TEXT NULL,comment TEXT NULL,
 version INTEGER NOT NULL,edited_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
SQL);

$pdo->exec(<<<'SQL'
INSERT INTO users VALUES
 (1,'athlete','private@example.test','secret-hash','user','Europe/Moscow','system','2025-01-01 00:00:00','2026-08-01 00:00:00',NULL),
 (2,'other','other@example.test','other-hash','user','UTC','system','2025-02-01 00:00:00','2026-08-01 00:00:00',NULL);
INSERT INTO exercises VALUES
 ('bench',NULL,'Жим лёжа','chest','["chest","triceps"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('row',NULL,'Тяга штанги','back','["back","biceps"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('fly',NULL,'Сведение рук','chest','["chest"]','strength','cable',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('secret',2,'Чужое упражнение','back','["back"]','strength','machine',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO training_programs VALUES
 (1,1,'base','Базовый цикл','Безопасное описание','active','2026-07-01 00:00:00','2026-08-01 00:00:00',NULL,NULL,2),
 (2,2,'private-program','Чужая программа','private','active','2026-07-01 00:00:00','2026-08-01 00:00:00',NULL,NULL,3);
INSERT INTO program_versions VALUES
 (1,1,1,'manual','Старт','Комментарий','{"private":"snapshot"}','hash-1',NULL,'2026-07-01 00:00:00','published',1,'hash-1','2026-07-01 00:00:00',NULL,NULL),
 (2,1,2,'manual','Прогрессия','Проверить технику','{"private":"snapshot-v2"}','hash-2',1,'2026-08-01 00:00:00','published',1,'hash-2','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL),
 (3,2,1,'manual','Private','Private','{"private":true}','hash-3',NULL,'2026-07-01 00:00:00','published',1,'hash-3','2026-07-01 00:00:00','2026-07-01 00:00:00',NULL);
INSERT INTO workout_templates VALUES
 (1,1,2,'strength-a','Силовая A','strength','{"goal":"Объём","estimated_duration_min":60,"trainer_notes":"Техника","pre_workout":{"equipment":"Штанга"},"exercises":[{"exercise_id":"bench","name":"Жим лёжа","order":1,"sets":3,"rep_range":{"min":8,"max":10},"target_rir":{"min":1,"max":3},"rest_seconds":120,"weight":60,"muscles":["chest","triceps"],"equipment":"barbell"},{"exercise_id":"row","name":"Тяга штанги","order":2,"sets":3,"rep_range":{"min":8,"max":12},"target_rir":{"min":1,"max":3},"rest_seconds":90,"weight":50,"muscles":["back","biceps"],"equipment":"barbell"}]}','template-hash','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL),
 (2,2,3,'private','Чужой шаблон','strength','{}','private-hash','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL);
INSERT INTO program_schedule_slots VALUES
 (1,2,1,2,'2026-08-01 00:00:00');
INSERT INTO workout_plans VALUES
 (1,1,'plan-completed',2,1,'Силовая A','strength','2026-08-24','Объём',60,'Техника','{"secret":1}','{"raw":1}','1.0','completed',3,'2026-08-01 00:00:00','2026-08-24 10:00:00',NULL),
 (2,1,'plan-past-due',2,1,'Силовая B','strength','2020-01-01','Техника',45,NULL,NULL,'{"raw":2}','1.0','planned',1,'2020-01-01 00:00:00','2020-01-01 00:00:00',NULL),
 (3,2,'plan-private',3,2,'Чужая тренировка','strength','2026-08-24','Private',50,'Private',NULL,'{"private":true}','1.0','completed',2,'2026-08-01 00:00:00','2026-08-24 10:00:00',NULL),
 (4,1,'plan-today',2,1,'Сегодня','strength','2026-08-26','Лёгкая',40,NULL,NULL,'{}','1.0','planned',1,'2026-08-20 00:00:00','2026-08-20 00:00:00',NULL);
INSERT INTO workout_exercises VALUES
 (1,1,'bench','bench',1,2,8,10,1,3,120,60,1,'normal',NULL,'Контроль',NULL,NULL,1,'2026-08-01 00:00:00'),
 (2,1,'row','row',2,1,8,12,1,3,90,50,0,'normal',NULL,NULL,NULL,NULL,1,'2026-08-01 00:00:00'),
 (3,1,'fly','fly',3,1,10,15,2,4,60,20,0,'normal',NULL,NULL,NULL,NULL,1,'2026-08-01 00:00:00'),
 (4,2,'bench','bench',1,2,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,'2020-01-01 00:00:00'),
 (5,3,'secret','secret',1,1,5,8,1,2,120,100,0,'normal',NULL,NULL,NULL,NULL,1,'2026-08-01 00:00:00'),
 (6,4,'bench','bench',1,2,8,10,2,3,120,55,1,'normal',NULL,NULL,NULL,NULL,1,'2026-08-20 00:00:00');
INSERT INTO workout_sessions VALUES
 (1,'session-public',1,1,'strength','completed','2026-08-23 21:30:00','2026-08-23 22:30:00',8,4,NULL,5,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,'session-private',2,3,'strength','completed','2026-08-24 09:00:00','2026-08-24 10:00:00',9,3,'private',2,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO session_exercises VALUES
 (1,1,1,'bench','row','completed',NULL,'Скамья занята','2026-08-23 21:35:00','normal',NULL,'2026-08-23 22:00:00',3,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,1,2,'row','row','skipped','time',NULL,NULL,NULL,NULL,'2026-08-23 22:00:00',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (3,1,3,'fly','fly','pending',NULL,NULL,NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (4,2,5,'secret','secret','completed',NULL,NULL,NULL,'normal','private','2026-08-24 10:00:00',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO exercise_sets VALUES
 (1,'set-one',1,1,1,1,'working','normal',NULL,1,60,10,2,NULL,NULL,'2026-08-23 21:45:00',NULL,1,NULL,NULL),
 (2,'set-two',1,1,1,2,'working','normal',NULL,1,50,8,NULL,NULL,NULL,'2026-08-23 21:50:00',NULL,1,NULL,NULL),
 (3,'set-warmup',1,1,1,1,'warmup','normal',NULL,1,20,12,5,NULL,NULL,'2026-08-23 21:40:00',NULL,1,NULL,NULL),
 (4,'set-private',2,2,4,1,'working','normal',NULL,1,100,5,1,NULL,NULL,'2026-08-24 09:30:00',NULL,1,NULL,NULL);
INSERT INTO schedules VALUES
 (1,1,3,'strength','Зал',1,2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,2,3,'strength','Private',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO swimming_sessions VALUES
 (1,'swim-public',1,NULL,NULL,'manual','2026-08-25','2026-08-25 07:00:00',45,25,1500,'Кроль',7,3,2,4,4,NULL,NULL,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,'swim-private',2,NULL,NULL,'manual','2026-08-25','2026-08-25 08:00:00',60,25,2000,'Брасс',8,4,3,5,3,NULL,'private',1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
SQL);

$service = new TrainingQueryService(new TrainingQueryRepository($pdo));

$promptFixture = json_decode(
    (string) file_get_contents(__DIR__ . '/fixtures/webmcp/prompt-injection.json'),
    true,
    16,
    JSON_THROW_ON_ERROR,
);
$insertPromptExercise = $pdo->prepare(
    "INSERT INTO exercises VALUES ('prompt-custom',1,?,'other','[\"other\"]','strength','other',1,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)"
);
$insertPromptExercise->execute([$promptFixture['custom_name']]);
$pdo->prepare('UPDATE workout_plans SET trainer_notes=? WHERE id=1')->execute([$promptFixture['trainer_notes']]);
$pdo->prepare('UPDATE workout_exercises SET instructions=? WHERE id=1')->execute([$promptFixture['instructions']]);
$pdo->prepare('UPDATE workout_sessions SET user_comment=? WHERE id=1')->execute([$promptFixture['comment']]);

$bounds = Analytics::localDateBounds('2026-08-24', '2026-08-24', 'Europe/Moscow');
$check($bounds['from_utc'] === '2026-08-23 21:00:00' && $bounds['to_utc'] === '2026-08-24 21:00:00', 'локальная дата корректно переводится в полуинтервал UTC');
$check(TrainingMetrics::durationMinutes('2026-08-23 21:30:00', '2026-08-23 22:30:00') === 60, 'duration детерминированно считается на backend');
$metric = TrainingMetrics::summarizeSets([
    ['type'=>'working','weight_kg'=>60,'reps'=>10,'rir'=>2], ['type'=>'working','weight_kg'=>50,'reps'=>8,'rir'=>null],
    ['type'=>'warmup','weight_kg'=>20,'reps'=>12,'rir'=>5],
], 8, 10);
$check($metric['working_sets'] === 2 && $metric['tonnage_kg'] === 1000.0 && $metric['average_rir'] === 2.0, 'working sets, tonnage и RIR исключают разминку и missing RIR');
$check($metric['best_e1rm']['e1rm_kg'] === 80.0 && $metric['target_rep_range']['rate'] === 1.0, 'Epley e1RM и target rep-range compliance считаются на backend');

$profile = $service->profileContext(1);
$profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check($profile['timezone'] === 'Europe/Moscow' && $profile['active_programs'][0]['program_id'] === 'base', 'минимальный profile context содержит timezone и активную программу');
$check(!str_contains($profileJson, 'private@example') && !str_contains($profileJson, 'secret-hash') && !str_contains($profileJson, 'athlete'), 'profile DTO не раскрывает login, email и password hash');

$programs = $service->programs(1);
$version = $service->programVersion(1, 'base', 2);
$versionJson = json_encode($version, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(count($programs['items']) === 1 && $version['version'] === 2 && $version['parent_version'] === 1 && $version['templates'][0]['template_id'] === 'strength-a', 'current/specific program version возвращаются безопасными проекциями');
$check($programs['items'][0]['active_version_state'] === 'resolved' && $programs['items'][0]['current_version'] === 2, 'current version определяется active pointer');
$check($version['lifecycle_status'] === 'published' && $version['schedule_slots'][0] === ['weekday'=>2,'template_id'=>'strength-a'], 'plan projection содержит lifecycle и versioned schedule');
$check($version['templates'][0]['exercise_count'] === 2 && !array_key_exists('exercises', $version['templates'][0]), 'plan projection остаётся компактным индексом шаблонов без упражнений');
$check(!str_contains($versionJson, 'snapshot_json') && !str_contains($versionJson, 'content_json') && !str_contains($versionJson, 'private'), 'program projection не раскрывает raw snapshot/content payload');
$check($service->programVersion(1, 'private-program') === null, 'чужая программа не открывается по публичному ID');
$templatePage1 = $service->programTemplate(1, 'base', 'strength-a', 2, ['limit'=>1]);
$templatePage2 = $service->programTemplate(1, 'base', 'strength-a', 2, ['limit'=>1, 'cursor'=>$templatePage1['next_cursor']]);
$check($templatePage1['total_exercises'] === 2 && $templatePage1['exercises'][0]['exercise_id'] === 'bench' && $templatePage2['exercises'][0]['exercise_id'] === 'row' && $templatePage2['next_cursor'] === null, 'упражнения шаблона выдаются отдельными bounded cursor pages');
$check($service->programTemplate(2, 'base', 'strength-a', 2) === null, 'чужой шаблон программы скрыт tenant scope');

$pdo->exec(<<<'SQL'
INSERT INTO program_versions VALUES
 (6,1,3,'webmcp','Следующая версия',NULL,'{}','hash-6',2,'2026-08-10 00:00:00','draft',4,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','2026-08-10 00:00:00',NULL,NULL);
INSERT INTO workout_templates VALUES
 (3,1,6,'strength-b','Силовая B','strength','{"exercises":[{"exercise_id":"row","name":"Тяга штанги","order":1,"sets":3,"rep_range":{"min":8,"max":12},"target_rir":{"min":1,"max":3},"rest_seconds":90}]}','draft-template-hash','2026-08-10 00:00:00','2026-08-10 00:00:00',NULL);
INSERT INTO program_schedule_slots VALUES
 (2,6,3,4,'2026-08-10 00:00:00');
SQL);
$versions = $service->programVersions(1, 'base');
$draftProjection = $service->programVersion(1, 'base', 3);
$check($versions['items'][0]['lifecycle_status'] === 'draft' && $versions['items'][0]['draft_binding']['draft_id'] === 6, 'version list различает draft и публикует safe binding владельцу');
$draftTemplate = $service->programTemplate(1, 'base', 'strength-b', 3);
$check($draftProjection['draft_binding']['lock_version'] === 4 && $draftTemplate['exercises'][0]['exercise_id'] === 'row', 'draft можно обнаружить и продолжить в новом чате');
$throws(fn () => $service->programTemplate(1, 'base', 'strength-b', 3, ['cursor'=>$templatePage1['next_cursor']]), 'cursor', 'cursor шаблона привязан к конкретной версии и template');

$pdo->exec(<<<'SQL'
INSERT INTO training_programs VALUES
 (3,1,'ambiguous-current','Требует выбора',NULL,'paused','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL,NULL,NULL);
INSERT INTO program_versions VALUES
 (4,3,1,'manual',NULL,NULL,'{}','hash-4',NULL,'2026-08-01 00:00:00','published',1,'hash-4','2026-08-01 00:00:00',NULL,NULL),
 (5,3,2,'manual',NULL,NULL,'{}','hash-5',4,'2026-08-02 00:00:00','published',1,'hash-5','2026-08-02 00:00:00',NULL,NULL);
SQL);
$ambiguousPrograms = $service->programs(1);
$ambiguous = array_values(array_filter($ambiguousPrograms['items'], static fn (array $item): bool => $item['program_id'] === 'ambiguous-current'))[0];
$check($ambiguous['current_version'] === null && $ambiguous['active_version_state'] === 'ambiguous', 'multiple-version программа без pointer возвращает явный ambiguous state');

$page1 = $service->workouts(1, ['from'=>'2026-08-24','to'=>'2026-08-26','limit'=>1]);
$page2 = $service->workouts(1, ['from'=>'2026-08-24','to'=>'2026-08-26','limit'=>1,'cursor'=>$page1['next_cursor']]);
$check(count($page1['items']) === 1 && $page1['next_cursor'] !== null && count($page2['items']) === 1 && $page1['items'][0]['workout_id'] !== $page2['items'][0]['workout_id'], 'workout list использует устойчивую cursor pagination');
$all = $service->workouts(1, ['from'=>'2020-01-01','to'=>'2020-01-01','limit'=>10]);
$check($all['items'][0]['schedule_state'] === 'past_due_planned' && str_contains(implode(' ', $all['data_quality']['caveats']), 'not proof'), 'past-due concrete plan не называется доказанным missed');
$workoutsJson = json_encode($service->workouts(1, ['from'=>'2026-08-24','to'=>'2026-08-26','limit'=>20]), JSON_THROW_ON_ERROR);
$check(!str_contains($workoutsJson, 'swim-private') && !str_contains($workoutsJson, 'session-private'), 'workout list tenant-scoped для strength и swimming');
$throws(fn () => $service->workouts(1, ['from'=>'2020-01-01','to'=>'2026-08-26']), '366', 'oversized range отклоняется hard limit');
$throws(fn () => $service->workouts(1, ['from'=>'2026-08-27','to'=>'2026-08-26']), 'позже', 'пустой обратный range отклоняется');
$throws(fn () => $service->workouts(1, ['from'=>'2026-08-24','to'=>'2026-08-26','limit'=>100]), '50', 'oversized limit отклоняется');

$plan = $service->plannedWorkout(1, 'plan-completed');
$fact = $service->workoutFact(1, 'session-public');
$check($plan['exercises'][0]['exercise_id'] === 'bench' && $fact['exercises'][0]['substitution']['original_exercise_id'] === 'bench' && $fact['exercises'][0]['exercise_id'] === 'row', 'planned-vs-actual сохраняет исходное упражнение и замену отдельно');
$check($fact['metrics']['working_sets'] === 2 && $fact['metrics']['tonnage_kg'] === 1000.0 && $fact['metrics']['duration_minutes'] === 60 && $fact['session_rpe'] === 8, 'fact detail считает tonnage, duration и session RPE');
$check($fact['metrics']['completed_exercises'] === 1 && $fact['metrics']['skipped_exercises'] === 1 && $fact['metrics']['pending_exercises'] === 1 && $fact['metrics']['substitutions'] === 1, 'substituted/skipped/pending считаются раздельно');
$check($fact['data_quality']['complete'] === false && str_contains(implode(' ', $fact['data_quality']['issues']), 'RIR'), 'missing RIR явно отражён в data_quality');
$check($service->plannedWorkout(2, 'plan-completed') === null && $service->workoutFact(2, 'session-public') === null, 'cross-user plan/session IDs не раскрываются');
$promptPayload = json_encode([$plan, $fact, $service->searchExercises(1, 'IGNORE', ['limit' => 5])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(str_contains($promptPayload, $promptFixture['custom_name']), 'prompt-like custom name остаётся обычной DB-строкой в untrusted DTO');
$check(str_contains($promptPayload, $promptFixture['instructions']), 'prompt-like exercise instructions остаются обычной DB-строкой в untrusted DTO');
$check(!str_contains($promptPayload, $promptFixture['trainer_notes']), 'prompt-like trainer notes исключены из minimized DTO');
$check(!str_contains($promptPayload, $promptFixture['comment']), 'prompt-like session comment исключён из minimized DTO');
$check($pdo->query('SELECT trainer_notes FROM workout_plans WHERE id=1')->fetchColumn() === $promptFixture['trainer_notes'], 'prompt-like notes хранятся как данные и не исполняются');
$check($pdo->query('SELECT user_comment FROM workout_sessions WHERE id=1')->fetchColumn() === $promptFixture['comment'], 'prompt-like comments хранятся как данные и не исполняются');

$history = $service->exerciseHistory(1, 'row', ['from'=>'2026-08-24','to'=>'2026-08-24','limit'=>10]);
$historySubstitution = array_values(array_filter($history['items'], static fn (array $item): bool => $item['substituted_into']));
$check(count($history['items']) === 2 && $historySubstitution[0]['metrics']['tonnage_kg'] === 1000.0, 'exercise history учитывает timezone range и отдельные появления/замены упражнения');
$historyPage1 = $service->exerciseHistory(1, 'row', ['from'=>'2026-08-24','to'=>'2026-08-24','limit'=>1]);
$historyPage2 = $service->exerciseHistory(1, 'row', ['from'=>'2026-08-24','to'=>'2026-08-24','limit'=>1,'cursor'=>$historyPage1['next_cursor']]);
$check($historyPage1['next_cursor'] !== null && $historyPage1['items'][0]['substituted_into'] !== $historyPage2['items'][0]['substituted_into'], 'exercise history cursor стабилен для повторов упражнения в одной сессии');
$check($service->exerciseHistory(1, 'secret', ['from'=>'2026-08-24','to'=>'2026-08-24']) === null, 'чужой exercise ID не открывается');

$progress = $service->progressSummary(1, ['from'=>'2026-08-24','to'=>'2026-08-25']);
$check($progress['strength']['sessions'] === 1 && $progress['strength']['tonnage_kg'] === 1000.0 && $progress['strength']['average_session_rpe'] === 8.0, 'progress summary агрегирует strength на backend');
$check($progress['swimming']['sessions'] === 1 && $progress['swimming']['distance_m'] === 1500 && $progress['swimming']['duration_minutes'] === 45, 'swimming агрегируется отдельно от strength');
$check($progress['strength']['working_sets_by_muscle']['back'] === 2 && $progress['strength']['working_sets_by_muscle']['biceps'] === 2, 'per-muscle aggregate построен по working sets');
$check(str_contains(implode(' ', $progress['data_quality']['caveats']), 'double-count') && str_contains(implode(' ', $progress['data_quality']['caveats']), 'never enter strength'), 'muscle double-count и разделение swimming явно оговорены');

$scheduled = $service->scheduledWorkout(1, '2026-08-26');
$check($scheduled['concrete_plans'][0]['workout_id'] === 'plan-today' && $scheduled['recurring_schedule'][0]['label'] === 'Зал', 'scheduled workout объединяет concrete plan и recurring expectation по локальной дате');
$check($service->scheduledWorkout(2, '2026-08-26')['concrete_plans'] === [], 'scheduled workout tenant-scoped');

$search1 = $service->searchExercises(1, 'и', ['limit'=>1]);
$search2 = $service->searchExercises(1, 'и', ['limit'=>1,'cursor'=>$search1['next_cursor']]);
$searchJson = json_encode([$search1,$search2], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check($search1['next_cursor'] !== null && $search1['items'][0]['exercise_id'] !== $search2['items'][0]['exercise_id'], 'exercise search имеет cursor/limit');
$check(!str_contains($searchJson, 'secret'), 'поиск упражнений не раскрывает чужой catalogue row');
$insertAlternative = $pdo->prepare("INSERT INTO exercises VALUES (?,NULL,?,'other','[\"other\"]','strength','machine',1,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");
for ($index = 0; $index < 105; $index++) {
    $insertAlternative->execute(['distractor-' . $index, sprintf('Альфа %03d', $index)]);
}
$pdo->exec("INSERT INTO exercises VALUES ('zz-perfect',NULL,'Ягодный идеальный жим','chest','[\"chest\",\"triceps\"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");
$alternatives = $service->exerciseAlternatives(1, 'bench');
$check($alternatives['candidates'][0]['exercise_id'] === 'zz-perfect' && in_array('same_category', $alternatives['candidates'][0]['match_reasons'], true), 'кандидаты ранжируются по всему видимому каталогу, а не по первым 100 именам');

$combined = json_encode([$profile,$programs,$version,$plan,$fact,$history,$progress,$scheduled,$search1,$alternatives], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (['password_hash','private@example.test','source_json','snapshot_json','audit_logs','internal_plan_id','internal_session_id','internal_session_exercise_id'] as $forbidden) {
    $check(!str_contains($combined, $forbidden), 'публичные DTO не содержат ' . $forbidden);
}

if ($failures !== []) {
    fwrite(STDERR, "Stage 11 training query checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 11 training query checks passed ({$checks}).\n");
