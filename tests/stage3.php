<?php

declare(strict_types=1);

putenv('APP_ENV=test');
require dirname(__DIR__) . '/bootstrap.php';

use App\Repository\TrainingRepository;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};
$throws = static function (callable $callback, string $contains, string $label) use ($check): void {
    try {
        $callback();
        $check(false, $label . ' (исключение не выброшено)');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')');
    }
};

$sqlitePath = getenv('STAGE3_SQLITE_PATH');
if (is_string($sqlitePath) && $sqlitePath !== '' && is_file($sqlitePath)) {
    throw new RuntimeException('Файл тестовой SQLite БД уже существует: ' . $sqlitePath);
}
$pdo = new PDO(is_string($sqlitePath) && $sqlitePath !== '' ? 'sqlite:' . $sqlitePath : 'sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, login TEXT NOT NULL, email TEXT NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL, timezone TEXT NOT NULL, theme TEXT NOT NULL, deleted_at TEXT NULL);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_key TEXT NOT NULL, ip_address TEXT NOT NULL, successful INTEGER NOT NULL, attempted_at TEXT NOT NULL);
CREATE TABLE exercises (
    exercise_id TEXT PRIMARY KEY, owner_user_id INTEGER NULL, name TEXT NOT NULL, progression_increment REAL NOT NULL DEFAULT 2.5,
    progression_mode TEXT NOT NULL DEFAULT 'absolute', status TEXT NOT NULL DEFAULT 'active', deleted_at TEXT NULL
);
CREATE TABLE training_programs (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT NOT NULL);
CREATE TABLE program_versions (id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, version_number INTEGER NOT NULL);
CREATE TABLE workout_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, external_plan_id TEXT NOT NULL, program_version_id INTEGER NULL, workout_template_id INTEGER NULL, name TEXT NOT NULL,
    workout_type TEXT NOT NULL, scheduled_date TEXT NOT NULL, goal TEXT NULL, trainer_notes TEXT NULL, status TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE workout_exercises (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workout_plan_id INTEGER NOT NULL, exercise_id TEXT NOT NULL, sequence_no INTEGER NOT NULL,
    planned_sets INTEGER NOT NULL, rep_min INTEGER NOT NULL, rep_max INTEGER NOT NULL, target_rir_min REAL NULL, target_rir_max REAL NULL,
    rest_seconds INTEGER NOT NULL, planned_weight_kg REAL NULL, warmup_sets INTEGER NOT NULL DEFAULT 0,
    method_type TEXT NOT NULL DEFAULT 'normal', instructions TEXT NULL, UNIQUE(workout_plan_id, sequence_no)
);
CREATE TABLE workout_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, workout_plan_id INTEGER NOT NULL,
    workout_type TEXT NOT NULL, status TEXT NOT NULL, started_at TEXT NOT NULL, finished_at TEXT NULL, session_rpe INTEGER NULL,
    wellbeing INTEGER NULL, user_comment TEXT NULL, version INTEGER NOT NULL DEFAULT 1, edited_after_completion INTEGER NOT NULL DEFAULT 0,
    edited_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE readiness_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, workout_session_id INTEGER NOT NULL UNIQUE, body_weight_kg REAL NULL,
    sleep_score INTEGER, energy_score INTEGER, readiness_score INTEGER, comment TEXT NULL, logged_at TEXT NOT NULL
);
CREATE TABLE session_exercises (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workout_session_id INTEGER NOT NULL, workout_exercise_id INTEGER NOT NULL,
    original_exercise_id TEXT NOT NULL, actual_exercise_id TEXT NOT NULL, status TEXT NOT NULL, skip_reason TEXT NULL,
    substitution_reason TEXT NULL, substituted_at TEXT NULL, exercise_rating TEXT NULL, comment TEXT NULL, completed_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE exercise_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, workout_session_id INTEGER NOT NULL,
    session_exercise_id INTEGER NOT NULL, set_number INTEGER NOT NULL, set_type TEXT NOT NULL, method_type TEXT NOT NULL,
    sequence_no INTEGER NOT NULL, performed_weight_kg REAL NULL, reps INTEGER NULL, rir REAL NULL, completed_at TEXT NOT NULL,
    client_action_id TEXT NULL, version INTEGER NOT NULL DEFAULT 1, edited_at TEXT NULL, deleted_at TEXT NULL,
    UNIQUE(session_exercise_id, set_number, set_type, sequence_no), UNIQUE(user_id, client_action_id)
);
CREATE TABLE offline_action_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, client_action_id TEXT NOT NULL,
    action_type TEXT NOT NULL, response_json TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(user_id, client_action_id)
);
CREATE TABLE discomfort_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, workout_session_id INTEGER NOT NULL,
    session_exercise_id INTEGER NULL, body_area TEXT NOT NULL, intensity INTEGER NOT NULL, comment TEXT NULL, logged_at TEXT NOT NULL
);
CREATE TABLE progression_suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, workout_session_id INTEGER NOT NULL, exercise_id TEXT NOT NULL,
    current_weight_kg REAL NULL, suggested_next_weight_kg REAL NULL, accepted_next_weight_kg REAL NULL, reason TEXT NOT NULL,
    status TEXT NOT NULL, created_at TEXT NOT NULL, resolved_at TEXT NULL, UNIQUE(user_id, workout_session_id, exercise_id)
);
CREATE TABLE personal_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, workout_session_id INTEGER NOT NULL, exercise_id TEXT NULL,
    record_type TEXT NOT NULL, value_decimal REAL NOT NULL, metadata_json TEXT NULL, achieved_at TEXT NOT NULL,
    UNIQUE(user_id, workout_session_id, exercise_id, record_type)
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL,
    action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, ip_address TEXT NULL, created_at TEXT NOT NULL
);
SQL);
$userInsert = $pdo->prepare("INSERT INTO users VALUES (?,?,?,?, 'user','Europe/Moscow','system',NULL)");
$userInsert->execute([1, 'one', 'one@example.test', password_hash('stage3-smoke-password', PASSWORD_DEFAULT)]);
$userInsert->execute([2, 'two', 'two@example.test', password_hash('stage3-smoke-password', PASSWORD_DEFAULT)]);
$pdo->exec("INSERT INTO exercises VALUES
    ('bench',NULL,'Жим лёжа',2.5,'absolute','active',NULL),
    ('row',NULL,'Тяга',2.5,'absolute','active',NULL),
    ('secret',2,'Чужое упражнение',2.5,'absolute','active',NULL)");
$pdo->exec("INSERT INTO workout_plans (id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at) VALUES
    (1,1,'plan-one','Силовая A','strength','2026-08-24','planned',1,UTC_TIMESTAMP()),
    (2,2,'plan-two','Силовая B','strength','2026-08-24','planned',1,UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO workout_exercises (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,warmup_sets,method_type,instructions) VALUES
    (1,1,'bench',1,2,8,10,1,3,120,60,1,'normal','Контролировать траекторию'),
    (2,1,'row',2,1,10,12,1,3,90,40,0,'normal',NULL)");

$repository = new TrainingRepository($pdo);
$throws(fn () => $repository->startSession(1, 1, ['sleep' => 3, 'energy' => 3]), 'готовность', 'readiness требует три быстрые оценки');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_sessions')->fetchColumn() === 0, 'невалидный старт откатывается');
$throws(fn () => $repository->startSession(1, 2, ['sleep' => 3, 'energy' => 3, 'readiness' => 3]), 'План не найден', 'чужой план нельзя запустить');

$sessionId = $repository->startSession(1, 1, ['sleep' => 4, 'energy' => 3, 'readiness' => 4, 'body_weight_kg' => 81.5, 'comment' => 'Готов']);
$check($sessionId > 0, 'сессия создаётся');
$check($repository->startSession(1, 1, ['sleep' => 1, 'energy' => 1, 'readiness' => 1]) === $sessionId, 'повторный старт продолжает незавершённую сессию');
$session = $repository->session($sessionId, 1);
$check($session !== null && count($session['exercises']) === 2 && (int) $session['readiness_score'] === 4, 'сессия содержит readiness и снимок упражнений');
$check($repository->session($sessionId, 2) === null, 'чужая сессия не читается');
$firstExercise = (int) $session['exercises'][0]['id'];
$secondExercise = (int) $session['exercises'][1]['id'];

$workingInput = ['session_version' => 1, 'session_exercise_id' => $firstExercise, 'set_number' => 1, 'set_type' => 'working', 'weight_kg' => 60.0, 'reps' => 10, 'rir' => 2, 'client_action_id' => 'client-working-1'];
$working = $repository->addSet($sessionId, 1, $workingInput);
$check((int) $working['session_version'] === 2, 'подход увеличивает версию сессии');
$same = $repository->addSet($sessionId, 1, $workingInput);
$check((int) $same['id'] === (int) $working['id'] && (int) $pdo->query('SELECT COUNT(*) FROM exercise_sets')->fetchColumn() === 1, 'повтор client_action_id идемпотентен');
$warmup = $repository->addSet($sessionId, 1, ['session_version' => 2, 'session_exercise_id' => $firstExercise, 'set_number' => 1, 'set_type' => 'warmup', 'weight_kg' => 20.0, 'reps' => 12, 'rir' => 5, 'client_action_id' => 'client-warmup-1']);
$check((int) $warmup['session_version'] === 3 && (int) $pdo->query("SELECT COUNT(*) FROM exercise_sets WHERE set_type='warmup'")->fetchColumn() === 1, 'разминка хранится отдельно от рабочего объёма');
$throws(fn () => $repository->addSet($sessionId, 1, [...$workingInput, 'client_action_id' => 'stale-action']), 'другой вкладке', 'устаревшая версия сессии отклоняется');
$throws(fn () => $repository->updateSet((int) $working['id'], 2, ['version' => 1, 'session_version' => 3, 'reps' => 9]), 'Подход не найден', 'чужой подход нельзя изменить');

$updated = $repository->updateSet((int) $working['id'], 1, ['version' => 1, 'session_version' => 3, 'weight_kg' => 62.5, 'reps' => 9, 'rir' => 1.5]);
$check((int) $updated['version'] === 2 && (int) $updated['session_version'] === 4, 'редактирование подхода версионируется');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='exercise_set' AND action='update'")->fetchColumn() === 1, 'редактирование подхода записано в audit log');
$throws(fn () => $repository->updateSet((int) $working['id'], 1, ['version' => 1, 'session_version' => 4, 'reps' => 8]), 'другой вкладке', 'устаревшая версия подхода отклоняется');

$throws(fn () => $repository->replaceExercise($sessionId, 1, ['session_version' => 4, 'session_exercise_id' => $firstExercise, 'exercise_version' => 2, 'actual_exercise_id' => 'secret', 'reason' => 'Проверка']), 'недоступно', 'чужое пользовательское упражнение нельзя выбрать для замены');
$replacement = $repository->replaceExercise($sessionId, 1, ['session_version' => 4, 'session_exercise_id' => $firstExercise, 'exercise_version' => 2, 'actual_exercise_id' => 'row', 'reason' => 'Скамья занята']);
$check((int) $replacement['session_version'] === 5 && $pdo->query("SELECT original_exercise_id || ':' || actual_exercise_id FROM session_exercises WHERE id={$firstExercise}")->fetchColumn() === 'bench:row', 'замена сохраняет original и actual');
$check($pdo->query("SELECT substitution_reason FROM session_exercises WHERE id={$firstExercise}")->fetchColumn() === 'Скамья занята', 'замена сохраняет причину и время');

$discomfort = $repository->logDiscomfort($sessionId, 1, ['session_version' => 5, 'session_exercise_id' => $firstExercise, 'exercise_version' => 3, 'body_area' => 'Правое плечо', 'intensity' => 3, 'comment' => 'Лёгкое ощущение']);
$check((int) $discomfort['session_version'] === 6 && (int) $pdo->query('SELECT COUNT(*) FROM discomfort_logs WHERE user_id=1')->fetchColumn() === 1, 'дискомфорт сохраняется структурированно');
$throws(fn () => $repository->logDiscomfort($sessionId, 2, ['session_version' => 6, 'session_exercise_id' => $firstExercise, 'exercise_version' => 3, 'body_area' => 'Плечо', 'intensity' => 3]), 'Активная тренировка', 'чужой дискомфорт не записывается');

$waiting = $repository->setExerciseStatus($sessionId, 1, ['session_version' => 6, 'session_exercise_id' => $firstExercise, 'status' => 'waiting']);
$check($waiting['status'] === 'waiting' && (int) $waiting['session_version'] === 7, 'оборудование занято переводит упражнение в waiting');
$throws(fn () => $repository->setExerciseStatus($sessionId, 1, ['session_version' => 7, 'session_exercise_id' => $firstExercise, 'status' => 'completed']), 'Оцените', 'завершение требует оценку упражнения');
$completed = $repository->setExerciseStatus($sessionId, 1, ['session_version' => 7, 'session_exercise_id' => $firstExercise, 'status' => 'completed', 'exercise_rating' => 'normal']);
$check($completed['status'] === 'completed' && (int) $completed['session_version'] === 8, 'упражнение завершается с оценкой');
$throws(fn () => $repository->setExerciseStatus($sessionId, 1, ['session_version' => 8, 'session_exercise_id' => $secondExercise, 'status' => 'skipped']), 'причину', 'пропуск требует структурированную причину');
$skipped = $repository->setExerciseStatus($sessionId, 1, ['session_version' => 8, 'session_exercise_id' => $secondExercise, 'status' => 'skipped', 'reason' => 'time', 'comment' => 'Нужно уходить']);
$check($skipped['status'] === 'skipped' && (int) $skipped['session_version'] === 9, 'пропуск с причиной сохраняется');

$throws(fn () => $repository->finish($sessionId, 1, ['session_version' => 8, 'session_rpe' => 7, 'wellbeing' => 4]), 'другой вкладке', 'устаревшее завершение отклоняется');
$finished = $repository->finish($sessionId, 1, ['session_version' => 9, 'session_rpe' => 7, 'wellbeing' => 4, 'comment' => 'Хорошо']);
$check($finished['status'] === 'completed' && $finished['summary']['working_sets'] === 1, 'тренировка завершается и строит summary');
$check((float) $finished['summary']['tonnage_kg'] === 562.5, 'в tonnage входит только working, не warmup');
$check((float) $pdo->query('SELECT planned_weight_kg FROM workout_exercises WHERE id=1')->fetchColumn() === 60.0, 'факт не перезаписывает план');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE user_id=1")->fetchColumn() >= 8, 'существенные действия принадлежат пользователю в audit log');

if ($failures !== []) {
    fwrite(STDERR, "Stage 3 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 3 checks passed ({$checks}).\n");
