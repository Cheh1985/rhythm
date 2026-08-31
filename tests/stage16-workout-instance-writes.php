<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('APP_URL=https://rhythm.example');
putenv('WEBMCP_ENABLED=true');
putenv('WEBMCP_INSTANCE_WRITE_ENABLED=true');
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\ApiError;
use App\Core\Csrf;
use App\Core\FeatureFlags;
use App\Core\SameOrigin;
use App\Core\VersionConflictException;
use App\Repository\TrainingQueryRepository;
use App\Repository\TrainingRepository;
use App\Service\TrainingQueryService;
use App\Service\WorkoutInstanceService;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};
$throws = static function (callable $callback, string $class, string $contains, string $label) use ($check): void {
    try {
        $callback();
        $check(false, $label . ' (исключение не выброшено)');
    } catch (Throwable $exception) {
        $check($exception instanceof $class && str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')');
    }
};

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-08-31 12:00:00', 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users(
 id INTEGER PRIMARY KEY,login TEXT,email TEXT,password_hash TEXT,role TEXT,timezone TEXT,theme TEXT,
 created_at TEXT,updated_at TEXT,deleted_at TEXT NULL
);
CREATE TABLE exercises(
 exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT,category TEXT NULL,muscle_groups TEXT NULL,
 exercise_type TEXT,equipment TEXT NULL,progression_increment REAL,progression_mode TEXT,status TEXT,
 created_at TEXT,updated_at TEXT,deleted_at TEXT NULL
);
CREATE TABLE training_programs(
 id INTEGER PRIMARY KEY,user_id INTEGER,external_program_id TEXT,name TEXT,description TEXT NULL,status TEXT,
 created_at TEXT,updated_at TEXT,archived_at TEXT NULL,deleted_at TEXT NULL,active_version_id INTEGER NULL
);
CREATE TABLE program_versions(
 id INTEGER PRIMARY KEY,program_id INTEGER,version_number INTEGER,source TEXT,change_reason TEXT NULL,trainer_comment TEXT NULL,
 snapshot_json TEXT,snapshot_hash TEXT,parent_version_id INTEGER NULL,created_at TEXT,lifecycle_status TEXT,lock_version INTEGER,
 aggregate_hash TEXT,updated_at TEXT,activated_at TEXT NULL,archived_at TEXT NULL
);
CREATE TABLE workout_templates(
 id INTEGER PRIMARY KEY,user_id INTEGER,program_version_id INTEGER,code TEXT,name TEXT,workout_type TEXT,
 content_json TEXT,content_hash TEXT,created_at TEXT,updated_at TEXT,deleted_at TEXT NULL
);
CREATE TABLE workout_plans(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,external_plan_id TEXT,program_version_id INTEGER NULL,workout_template_id INTEGER NULL,
 name TEXT,workout_type TEXT,scheduled_date TEXT,goal TEXT NULL,estimated_duration_min INTEGER NULL,trainer_notes TEXT NULL,
 pre_workout_json TEXT NULL,source_json TEXT,schema_version TEXT,status TEXT,version INTEGER,created_at TEXT,updated_at TEXT,deleted_at TEXT NULL,
 UNIQUE(user_id,external_plan_id)
);
CREATE TABLE workout_exercises(
 id INTEGER PRIMARY KEY AUTOINCREMENT,workout_plan_id INTEGER,exercise_id TEXT,original_exercise_id TEXT NULL,sequence_no INTEGER,
 planned_sets INTEGER,rep_min INTEGER,rep_max INTEGER,target_rir_min REAL NULL,target_rir_max REAL NULL,rest_seconds INTEGER,
 planned_weight_kg REAL NULL,warmup_sets INTEGER,method_type TEXT,group_id TEXT NULL,instructions TEXT NULL,
 substitution_reason TEXT NULL,substituted_at TEXT NULL,version INTEGER,created_at TEXT,UNIQUE(workout_plan_id,sequence_no)
);
CREATE TABLE workout_sessions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,user_id INTEGER,workout_plan_id INTEGER,workout_type TEXT,status TEXT,
 started_at TEXT,finished_at TEXT NULL,session_rpe INTEGER NULL,wellbeing INTEGER NULL,user_comment TEXT NULL,version INTEGER,
 edited_after_completion INTEGER,edited_at TEXT NULL,created_at TEXT,updated_at TEXT,deleted_at TEXT NULL
);
CREATE TABLE readiness_logs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,workout_session_id INTEGER UNIQUE,body_weight_kg REAL NULL,
 sleep_score INTEGER,energy_score INTEGER,readiness_score INTEGER,comment TEXT NULL,logged_at TEXT
);
CREATE TABLE session_exercises(
 id INTEGER PRIMARY KEY AUTOINCREMENT,workout_session_id INTEGER,workout_exercise_id INTEGER,original_exercise_id TEXT,
 actual_exercise_id TEXT,status TEXT,skip_reason TEXT NULL,substitution_reason TEXT NULL,substituted_at TEXT NULL,
 exercise_rating TEXT NULL,comment TEXT NULL,completed_at TEXT NULL,version INTEGER,created_at TEXT,updated_at TEXT,
 UNIQUE(workout_session_id,workout_exercise_id)
);
CREATE TABLE exercise_sets(
 id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,user_id INTEGER,workout_session_id INTEGER,session_exercise_id INTEGER,
 set_number INTEGER,set_type TEXT,method_type TEXT,group_id TEXT NULL,sequence_no INTEGER,performed_weight_kg REAL NULL,
 reps INTEGER NULL,rir REAL NULL,duration_seconds INTEGER NULL,distance_m INTEGER NULL,completed_at TEXT,
 client_action_id TEXT NULL,version INTEGER,edited_at TEXT NULL,deleted_at TEXT NULL
);
CREATE TABLE offline_action_receipts(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,client_action_id TEXT,action_type TEXT,response_json TEXT,created_at TEXT,
 UNIQUE(user_id,client_action_id)
);
CREATE TABLE assistant_write_receipts(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,idempotency_key TEXT,action_type TEXT,request_hash TEXT,response_json TEXT,created_at TEXT,
 UNIQUE(user_id,idempotency_key)
);
CREATE TABLE audit_logs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,entity_type TEXT,entity_id TEXT,action TEXT,source TEXT NULL,request_id TEXT NULL,
 before_json TEXT NULL,after_json TEXT NULL,ip_address TEXT NULL,created_at TEXT
);
SQL);

$hash = str_repeat('a', 64);
$password = password_hash('stage16-password', PASSWORD_DEFAULT);
$user = $pdo->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?,NULL)');
$user->execute([1,'athlete','athlete@example.test',$password,'user','Europe/Moscow','system','2026-01-01','2026-08-31']);
$user->execute([2,'other','other@example.test',$password,'user','UTC','system','2026-01-01','2026-08-31']);
$pdo->exec(<<<'SQL'
INSERT INTO exercises VALUES
 ('bench',NULL,'Жим лёжа','chest','["chest"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('row',NULL,'Тяга','back','["back"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('fly',NULL,'Сведение','chest','["chest"]','strength','cable',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('secret',2,'Чужое','back','["back"]','strength','machine',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO training_programs VALUES
 (1,1,'program-main','Основная','Immutable','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL,NULL,1),
 (2,2,'program-private','Чужая','Private','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL,NULL,2);
SQL);
$version = $pdo->prepare("INSERT INTO program_versions VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,'published',1,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");
$version->execute([1,1,1,'manual','Initial',null,'{"immutable":true}',$hash,null,$hash]);
$version->execute([2,2,1,'manual','Private',null,'{}',$hash,null,$hash]);
$template = $pdo->prepare('INSERT INTO workout_templates VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)');
$template->execute([1,1,1,'strength-a','Силовая A','strength','{"immutable":true}',$hash]);
$template->execute([2,2,2,'private','Чужая','strength','{}',$hash]);
$pdo->exec(<<<'SQL'
INSERT INTO workout_plans VALUES
 (1,1,'workout-today',1,1,'Сегодня','strength','2026-08-31','Работа',60,NULL,NULL,'{}','1.0','planned',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,2,'workout-private',2,2,'Чужая','strength','2026-08-31',NULL,60,NULL,NULL,'{}','1.0','planned',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (3,1,'workout-completed',1,1,'Завершённая','strength','2026-08-20',NULL,60,NULL,NULL,'{}','1.0','completed',4,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (4,1,'workout-active',1,1,'Активная','strength','2026-08-31',NULL,60,NULL,NULL,'{}','1.0','in_progress',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (5,1,'workout-finished-session',1,1,'История','strength','2026-08-19',NULL,60,NULL,NULL,'{}','1.0','completed',3,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (6,2,'workout-private-active',2,2,'Чужая активная','strength','2026-08-31',NULL,60,NULL,NULL,'{}','1.0','in_progress',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO workout_exercises VALUES
 (1,1,'bench','bench',1,3,8,10,1,3,120,60,1,'normal',NULL,'Техника',NULL,NULL,1,CURRENT_TIMESTAMP),
 (2,2,'secret','secret',1,3,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP),
 (3,3,'bench','bench',1,3,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP),
 (4,4,'row','row',1,3,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP),
 (5,5,'bench','bench',1,3,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP),
 (6,6,'secret','secret',1,3,8,10,1,3,120,60,1,'normal',NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP);
INSERT INTO workout_sessions VALUES
 (1,'session-active',1,4,'strength','in_progress','2026-08-31 10:00:00',NULL,NULL,NULL,NULL,3,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,'session-completed',1,5,'strength','completed','2026-08-19 10:00:00','2026-08-19 11:00:00',8,4,NULL,5,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (3,'session-private',2,6,'strength','in_progress','2026-08-31 11:00:00',NULL,NULL,NULL,NULL,2,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO session_exercises VALUES
 (1,1,4,'row','row','pending',NULL,NULL,NULL,NULL,NULL,NULL,2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,2,5,'bench','bench','completed',NULL,NULL,NULL,'normal',NULL,'2026-08-19 10:50:00',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (3,3,6,'secret','secret','pending',NULL,NULL,NULL,NULL,NULL,NULL,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
SQL);

$repository = new TrainingRepository($pdo);
$service = new WorkoutInstanceService($pdo, $repository);
$immutableBefore = $pdo->query("SELECT (SELECT snapshot_hash FROM program_versions WHERE id=1) snapshot_hash,(SELECT aggregate_hash FROM program_versions WHERE id=1) aggregate_hash,(SELECT content_hash FROM workout_templates WHERE id=1) content_hash,(SELECT active_version_id FROM training_programs WHERE id=1) active_version_id")->fetch();

$check(FeatureFlags::enabled(FeatureFlags::WEBMCP_INSTANCE_WRITE_ENABLED), 'instance write flag включается только вместе с WEBMCP_ENABLED');
$throws(fn () => $service->reschedule(1,'workout-today','active_session','2026-09-01',1,'action-reschedule-wrong-scope'), InvalidArgumentException::class, 'scheduled_instance', 'reschedule требует однозначный scheduled scope');
$throws(fn () => $service->reschedule(1,'workout-private','scheduled_instance','2026-09-01',1,'action-reschedule-foreign'), ApiError::class, 'не найден', 'чужой external workout id скрыт как 404');
$throws(fn () => $service->reschedule(1,'workout-completed','scheduled_instance','2026-09-01',4,'action-reschedule-completed'), InvalidArgumentException::class, 'ещё не начатый', 'completed workout нельзя перенести');
$throws(fn () => $service->reschedule(1,'workout-today','scheduled_instance','2026-09-01',99,'action-reschedule-stale'), VersionConflictException::class, 'изменён', 'stale workout version отклоняется');

$rescheduled = $service->reschedule(1,'workout-today','scheduled_instance','2026-09-01',1,'action-reschedule-0001');
$rescheduledReplay = $service->reschedule(1,'workout-today','scheduled_instance','2026-09-01',1,'action-reschedule-0001');
$check($rescheduled['instance_version'] === 2 && !$rescheduled['idempotent'] && $rescheduledReplay['idempotent'], 'reschedule выполняется один раз и повторяет receipt');
$check($pdo->query("SELECT scheduled_date||':'||version FROM workout_plans WHERE id=1")->fetchColumn() === '2026-09-01:2', 'reschedule меняет только дату конкретного instance и его version');
$throws(fn () => $service->reschedule(1,'workout-today','scheduled_instance','2026-09-02',2,'action-reschedule-0001'), InvalidArgumentException::class, 'другого запроса', 'client_action_id payload-bound и не переиспользуется');

$planned = $service->replaceExercise(1,'workout-today','scheduled_instance',1,'fly','Скамья занята',2,1,'action-planned-replace-0001');
$plannedReplay = $service->replaceExercise(1,'workout-today','scheduled_instance',1,'fly','Скамья занята',2,1,'action-planned-replace-0001');
$check($planned['original_exercise_id'] === 'bench' && $planned['actual_exercise_id'] === 'fly' && $planned['instance_version'] === 3 && $planned['exercise_version'] === 2, 'planned replacement сохраняет original/actual provenance и обе версии');
$check($plannedReplay['idempotent'] && (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='replace_instance'")->fetchColumn() === 1, 'duplicate planned action не повторяет mutation или audit');
$plannedAgain = $service->replaceExercise(1,'workout-today','scheduled_instance',1,'row','Нужна другая вариация',3,2,'action-planned-replace-0002');
$check($plannedAgain['original_exercise_id'] === 'bench' && $plannedAgain['actual_exercise_id'] === 'row', 'повторная planned замена не теряет первоначальное упражнение');
$throws(fn () => $service->replaceExercise(1,'workout-today','scheduled_instance',1,'fly','Stale',3,2,'action-planned-replace-stale'), VersionConflictException::class, 'конкурентно', 'planned replacement защищён optimistic instance version');
$throws(fn () => $service->replaceExercise(1,'workout-private','scheduled_instance',1,'fly','Foreign',1,1,'action-planned-foreign'), ApiError::class, 'не найден', 'foreign planned target всегда 404');
$throws(fn () => $service->replaceExercise(1,'workout-completed','scheduled_instance',1,'fly','History',4,1,'action-planned-history'), InvalidArgumentException::class, 'не начатой', 'planned API не редактирует completed history');
$throws(fn () => $service->replaceExercise(1,'workout-today','wrong_scope',1,'fly','Wrong',4,3,'action-planned-scope'), InvalidArgumentException::class, 'scope', 'replace требует explicit scope');

$active = $service->replaceExercise(1,'session-active','active_session',1,'fly','Тренажёр занят',3,2,'action-active-replace-0001');
$activeReplay = $service->replaceExercise(1,'session-active','active_session',1,'fly','Тренажёр занят',3,2,'action-active-replace-0001');
$check($active['original_exercise_id'] === 'row' && $active['actual_exercise_id'] === 'fly' && $active['instance_version'] === 4 && $active['exercise_version'] === 3, 'active scope использует существующую replaceExercise operation');
$check($activeReplay['idempotent'] && $pdo->query("SELECT status||':'||actual_exercise_id||':'||version FROM session_exercises WHERE id=1")->fetchColumn() === 'active:fly:3', 'active duplicate не дублирует replacement');
$throws(fn () => $service->replaceExercise(1,'session-active','active_session',1,'bench','Stale',3,2,'action-active-stale'), VersionConflictException::class, 'другой вкладке', 'active replacement сохраняет optimistic session/exercise checks');
$throws(fn () => $service->replaceExercise(1,'session-completed','active_session',1,'row','History',5,2,'action-active-completed'), InvalidArgumentException::class, 'Активная', 'completed session не редактируется');
$throws(fn () => $service->replaceExercise(1,'session-private','active_session',1,'row','Foreign',2,1,'action-active-foreign'), ApiError::class, 'не найден', 'foreign public session id всегда 404');
$throws(fn () => $service->replaceExercise(1,'session-active','active_session',1,'secret','Foreign exercise',4,3,'action-active-secret'), InvalidArgumentException::class, 'недоступно', 'чужое custom exercise недоступно');

$plannedProjection = (new TrainingQueryService(new TrainingQueryRepository($pdo)))->plannedWorkout(1, 'workout-today');
$check(($plannedProjection['exercises'][0]['version'] ?? null) === 3 && ($plannedProjection['exercises'][0]['substitution']['original_exercise_id'] ?? null) === 'bench', 'read DTO публикует exercise version и planned provenance без внутренних id');
$sessionId = $repository->startSession(1, 1, ['sleep'=>4,'energy'=>4,'readiness'=>4,'client_action_id'=>'action-start-session-0001']);
$snapshot = $pdo->query("SELECT original_exercise_id,actual_exercise_id,substitution_reason,substituted_at FROM session_exercises WHERE workout_session_id={$sessionId}")->fetch();
$check($snapshot['original_exercise_id'] === 'bench' && $snapshot['actual_exercise_id'] === 'row' && $snapshot['substitution_reason'] === 'Нужна другая вариация' && $snapshot['substituted_at'] !== null, 'startSession наследует planned provenance в snapshot');
$sessionPublicId = $pdo->query("SELECT public_id FROM workout_sessions WHERE id={$sessionId}")->fetchColumn();
$fact = (new TrainingQueryService(new TrainingQueryRepository($pdo)))->workoutFact(1, (string)$sessionPublicId);
$check(($fact['exercises'][0]['substitution']['original_exercise_id'] ?? null) === 'bench' && ($fact['exercises'][0]['exercise_id'] ?? null) === 'row', 'provenance доступен в последующей session/history projection');

$immutableAfter = $pdo->query("SELECT (SELECT snapshot_hash FROM program_versions WHERE id=1) snapshot_hash,(SELECT aggregate_hash FROM program_versions WHERE id=1) aggregate_hash,(SELECT content_hash FROM workout_templates WHERE id=1) content_hash,(SELECT active_version_id FROM training_programs WHERE id=1) active_version_id")->fetch();
$check($immutableBefore === $immutableAfter, 'instance mutations не меняют program version/template/hash/active pointer');
$check((int)$pdo->query("SELECT COUNT(*) FROM assistant_write_receipts WHERE user_id=1")->fetchColumn() === 4, 'четыре успешных client_action_id сохранены как payload-bound receipts');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE source='webmcp' AND request_id IS NOT NULL AND action IN ('reschedule','replace_instance','replace')")->fetchColumn() === 4, 'domain audit связывает semantic reschedule/replacements с request id');

$_SESSION['_csrf'] = 'stage16-csrf-token';
$check(SameOrigin::isValid('https://rhythm.example') && !SameOrigin::isValid('https://evil.example') && !SameOrigin::isValid(null), 'semantic boundary принимает только exact same Origin');
$check(Csrf::validate('stage16-csrf-token') && !Csrf::validate('wrong-token'), 'semantic boundary привязан к session CSRF');

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controller/WorkoutInstanceController.php');
$adapter = (string) file_get_contents($root . '/public/assets/webmcp.js');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/database/migrations/012_workout_instance_substitutions.sql');
$repositorySource = (string) file_get_contents($root . '/app/Repository/TrainingRepository.php');
$backupSource = (string) file_get_contents($root . '/app/Service/BackupService.php');
$check(str_contains($routes, '/workout-instances/{instanceId}/reschedule') && str_contains($routes, '/workout-instances/{instanceId}/replace-exercise'), 'два semantic instance endpoint подключены');
$check(str_contains($controller, 'WEBMCP_INSTANCE_WRITE_ENABLED') && str_contains($controller, 'client_action_id') && str_contains($controller, 'Idempotency-Key'), 'controller требует feature flag и совпадающие action/idempotency keys');
$check(!str_contains($adapter, 'workout_instances.reschedule') && !str_contains($adapter, 'workout_instances.replace_exercise'), 'WebMCP write registration не добавлена до этапа 9');
foreach (['original_exercise_id','substitution_reason','substituted_at','version'] as $fragment) {
    $check(str_contains($schema, $fragment) && str_contains($migration, $fragment), "schema и additive migration содержат {$fragment}");
}
$check(str_contains($repositorySource, 'COALESCE(original_exercise_id,exercise_id)') && str_contains($backupSource, "'original_exercise_id'=>\$original"), 'snapshot inheritance и backup restore учитывают planned provenance');

if ($failures !== []) {
    fwrite(STDERR, "Stage 16 workout instance checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 16 workout instance checks passed ({$checks}).\n");
