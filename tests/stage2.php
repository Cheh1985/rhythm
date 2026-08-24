<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('MAX_UPLOAD_BYTES=1048576');
require dirname(__DIR__) . '/bootstrap.php';

use App\Service\PlanImportService;
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};
$throws = static function (callable $callback, string $contains, string $label) use ($check): void {
    try {
        $callback();
        $check(false, $label . ' (исключение не выброшено)');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')');
    }
};
$fixture = static fn (string $name): string => (string) file_get_contents(__DIR__ . '/fixtures/training-plan/' . $name);

$sqlitePath = getenv('STAGE2_SQLITE_PATH');
if (is_string($sqlitePath) && $sqlitePath !== '' && is_file($sqlitePath)) {
    throw new RuntimeException('Файл тестовой SQLite БД уже существует: ' . $sqlitePath);
}
$pdo = new PDO(is_string($sqlitePath) && $sqlitePath !== '' ? 'sqlite:' . $sqlitePath : 'sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
    role TEXT NOT NULL, timezone TEXT NOT NULL, theme TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_key TEXT NOT NULL, ip_address TEXT NOT NULL, successful INTEGER NOT NULL, attempted_at TEXT NOT NULL
);
CREATE TABLE exercises (
    exercise_id TEXT PRIMARY KEY, owner_user_id INTEGER NULL, name TEXT NOT NULL, category TEXT NULL,
    muscle_groups TEXT NULL, exercise_type TEXT NOT NULL, equipment TEXT NULL, progression_increment REAL NOT NULL,
    progression_mode TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE training_programs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, external_program_id TEXT NOT NULL, name TEXT NOT NULL,
    description TEXT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NULL, deleted_at TEXT NULL,
    UNIQUE(user_id, external_program_id)
);
CREATE TABLE program_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INTEGER NOT NULL, version_number INTEGER NOT NULL, source TEXT NOT NULL,
    change_reason TEXT NULL, trainer_comment TEXT NULL, snapshot_json TEXT NOT NULL, snapshot_hash TEXT NOT NULL,
    parent_version_id INTEGER NULL, created_at TEXT NOT NULL, UNIQUE(program_id, version_number)
);
CREATE TABLE workout_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, program_version_id INTEGER NULL, code TEXT NOT NULL,
    name TEXT NOT NULL, workout_type TEXT NOT NULL, content_json TEXT NOT NULL, content_hash TEXT NOT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL, UNIQUE(program_version_id, code)
);
CREATE TABLE workout_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, external_plan_id TEXT NOT NULL, program_version_id INTEGER NULL,
    workout_template_id INTEGER NULL, name TEXT NOT NULL, workout_type TEXT NOT NULL, scheduled_date TEXT NOT NULL, goal TEXT NULL,
    estimated_duration_min INTEGER NULL, trainer_notes TEXT NULL, pre_workout_json TEXT NULL, source_json TEXT NOT NULL,
    schema_version TEXT NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    deleted_at TEXT NULL, UNIQUE(user_id, external_plan_id)
);
CREATE TABLE workout_exercises (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workout_plan_id INTEGER NOT NULL, exercise_id TEXT NOT NULL, sequence_no INTEGER NOT NULL,
    planned_sets INTEGER NOT NULL, rep_min INTEGER NOT NULL, rep_max INTEGER NOT NULL, target_rir_min REAL NULL, target_rir_max REAL NULL,
    rest_seconds INTEGER NOT NULL, planned_weight_kg REAL NULL, warmup_sets INTEGER NOT NULL, method_type TEXT NOT NULL,
    group_id TEXT NULL, instructions TEXT NULL, created_at TEXT NOT NULL, UNIQUE(workout_plan_id, sequence_no)
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL,
    action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, ip_address TEXT NULL, created_at TEXT NOT NULL
);
SQL);
$user = $pdo->prepare("INSERT INTO users (id,login,email,password_hash,role,timezone,theme,created_at,updated_at) VALUES (?,?,?,?, 'user','Europe/Moscow','system',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$user->execute([1, 'test-user-1', 'test1@example.test', password_hash('stage2-test-password', PASSWORD_DEFAULT)]);
$user->execute([2, 'test-user-2', 'test2@example.test', password_hash('stage2-test-password', PASSWORD_DEFAULT)]);
$user->execute([3, 'stage2-smoke', 'smoke@example.test', password_hash('stage2-smoke-password', PASSWORD_DEFAULT)]);
$seed = $pdo->prepare("INSERT INTO exercises (exercise_id,owner_user_id,name,muscle_groups,exercise_type,progression_increment,progression_mode,status,created_at,updated_at) VALUES (?,NULL,?,'[]','strength',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
foreach ([['bench_press_001', 'Жим лёжа'], ['leg_press_001', 'Жим ногами'], ['lat_pulldown_001', 'Тяга верхнего блока']] as $row) {
    $seed->execute($row);
}

$service = new PlanImportService($pdo);
$valid = $service->decode($fixture('full-body-a.json'));
$check($valid['schema'] === 'training-plan' && count($valid['exercises']) === 3, 'валидный Full Body A разбирается');
$preview = $service->preview($valid, 1);
$check($preview['unknown_exercises'] === [] && $preview['exercise_count'] === 3, 'preview распознаёт глобальные упражнения');

$throws(fn () => $service->decode($fixture('invalid-json.json')), 'Некорректный JSON', 'битый JSON отклоняется');
$throws(fn () => $service->decode($fixture('wrong-schema.json')), 'schema должно иметь значение training-plan', 'неверная схема отклоняется');
$throws(fn () => $service->decode($fixture('unexpected-field.json')), 'Неизвестное поле', 'лишнее поле отклоняется');
$throws(fn () => $service->decode($fixture('duplicate-exercise.json')), 'exercise_id повторяется', 'повтор exercise_id отклоняется');
$wrongType = $valid;
$wrongType['exercises'][0]['sets'] = '3';
$throws(fn () => $service->validate($wrongType), 'целым числом', 'число в строке не приводится неявно');

$unknown = $valid;
$unknown['plan_id'] = 'unknown-exercise-2026-08-25';
$unknown['exercises'][2]['exercise_id'] = 'custom_cable_pull_001';
$unknown['exercises'][2]['name'] = 'Пользовательская тяга';
$unknownPreview = $service->preview($unknown, 1);
$check(count($unknownPreview['unknown_exercises']) === 1, 'неизвестное упражнение явно показано');
$throws(fn () => $service->import($unknown, 1, false), 'Подтвердите создание', 'создание неизвестного требует подтверждения');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_plans')->fetchColumn() === 0, 'отказ до подтверждения ничего не сохраняет');

$planId = $service->import($unknown, 1, true);
$check($planId > 0, 'подтверждённый план импортирован');
$check((int) $pdo->query('SELECT COUNT(*) FROM training_programs')->fetchColumn() === 1, 'создана программа');
$check((int) $pdo->query('SELECT COUNT(*) FROM program_versions')->fetchColumn() === 1, 'создана неизменяемая версия');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_templates')->fetchColumn() === 1, 'создан шаблон тренировки');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_exercises')->fetchColumn() === 3, 'сохранены упражнения плана');
$owner = $pdo->query("SELECT owner_user_id FROM exercises WHERE exercise_id='custom_cable_pull_001'")->fetchColumn();
$check((int) $owner === 1, 'неизвестное упражнение создано только для владельца');

$throws(fn () => $service->import($unknown, 1, true), 'уже импортирован', 'дубликат plan_id отклоняется');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_plans')->fetchColumn() === 1, 'дубликат не перезаписал историю');

$otherUser = $valid;
$otherUser['plan_id'] = $unknown['plan_id'];
$otherPlanId = $service->import($otherUser, 2, false);
$check($otherPlanId > $planId && (int) $pdo->query('SELECT COUNT(*) FROM workout_plans')->fetchColumn() === 2, 'одинаковый plan_id допустим у другого пользователя');
$conflictData = $unknown;
$conflictData['plan_id'] = 'other-user-private-id';
$conflictPreview = $service->preview($conflictData, 2);
$check($conflictPreview['conflicting_exercise_ids'] === ['custom_cable_pull_001'], 'чужое упражнение не считается доступным');
$throws(fn () => $service->import($conflictData, 2, true), 'заняты недоступными', 'чужой exercise_id нельзя присвоить');

$versionTwo = $unknown;
$versionTwo['plan_id'] = 'unknown-exercise-v2-2026-09-01';
$versionTwo['program']['version'] = 2;
$versionTwo['program']['parent_version'] = 1;
$versionTwo['program']['change_reason'] = 'Добавлен второй рабочий подход к тяге';
$versionTwo['workout']['template_id'] = 'full-body-a-v2';
$versionTwo['exercises'][2]['sets'] = 4;
$service->import($versionTwo, 1, false);
$parent = $pdo->query('SELECT parent_version_id FROM program_versions WHERE version_number=2 AND program_id=(SELECT id FROM training_programs WHERE user_id=1)')->fetchColumn();
$firstVersion = $pdo->query('SELECT id FROM program_versions WHERE version_number=1 AND program_id=(SELECT id FROM training_programs WHERE user_id=1)')->fetchColumn();
$check((int) $parent === (int) $firstVersion, 'parent_version связан с реальной родительской версией');

$changedImmutable = $unknown;
$changedImmutable['plan_id'] = 'changed-immutable-version';
$changedImmutable['program']['change_reason'] = 'Попытка изменить v1';
$throws(fn () => $service->import($changedImmutable, 1, false), 'другим содержимым', 'существующая версия не перезаписывается');
$check((int) $pdo->query("SELECT COUNT(*) FROM workout_plans WHERE external_plan_id='changed-immutable-version'")->fetchColumn() === 0, 'конфликт версии откатывает план');

$rollback = $versionTwo;
$rollback['plan_id'] = 'rollback-missing-parent';
$rollback['program']['version'] = 4;
$rollback['program']['parent_version'] = 3;
$rollback['program']['change_reason'] = 'Проверка rollback';
$rollback['workout']['template_id'] = 'rollback-template';
$rollback['exercises'][2]['exercise_id'] = 'rollback_unknown_001';
$rollback['exercises'][2]['name'] = 'Не должно сохраниться';
$throws(fn () => $service->import($rollback, 1, true), 'родительская версия', 'ошибка после вставки запускает rollback');
$check((int) $pdo->query("SELECT COUNT(*) FROM exercises WHERE exercise_id='rollback_unknown_001'")->fetchColumn() === 0, 'rollback удалил промежуточное упражнение');
$check((int) $pdo->query("SELECT COUNT(*) FROM workout_plans WHERE external_plan_id='rollback-missing-parent'")->fetchColumn() === 0, 'rollback не оставил план');

if ($failures !== []) {
    fwrite(STDERR, "Stage 2 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 2 checks passed ({$checks}).\n");
