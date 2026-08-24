<?php

declare(strict_types=1);

require __DIR__ . '/stage6.php';

use App\Core\VersionConflictException;
use App\Domain\Swimming;
use App\Service\SwimmingReportService;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void { $checks++; if (!$condition) $failures[] = $label; };
$throws = static function (callable $callback, string $contains, string $label) use ($check): void {
    try { $callback(); $check(false, $label . ' (исключение не выброшено)'); }
    catch (Throwable $exception) { $check(str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')'); }
};

$pdo->exec(<<<'SQL'
CREATE TABLE schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, weekday INTEGER NOT NULL, workout_type TEXT NOT NULL,
    label TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(user_id,weekday)
);
CREATE TABLE swimming_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, workout_session_id INTEGER NULL,
    schedule_id INTEGER NULL, source TEXT NOT NULL, swim_date TEXT NOT NULL, occurred_at TEXT NOT NULL, duration_minutes INTEGER NOT NULL,
    pool_length_m INTEGER NOT NULL, total_distance_m INTEGER NOT NULL, primary_style TEXT NOT NULL, intensity INTEGER NOT NULL,
    arms_fatigue INTEGER NOT NULL, back_fatigue INTEGER NOT NULL, legs_fatigue INTEGER NOT NULL, wellbeing INTEGER NOT NULL,
    intervals_json TEXT NULL, comment TEXT NULL, version INTEGER NOT NULL DEFAULT 1, edited_at TEXT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE swimming_intervals (
    id INTEGER PRIMARY KEY AUTOINCREMENT, swimming_session_id INTEGER NOT NULL, sequence_no INTEGER NOT NULL,
    repeat_count INTEGER NOT NULL, distance_m INTEGER NOT NULL, style TEXT NOT NULL, intensity INTEGER NULL,
    rest_seconds INTEGER NULL, note TEXT NULL, created_at TEXT NOT NULL, UNIQUE(swimming_session_id,sequence_no)
);
SQL);

$defaults = $repository->schedule(1);
$activeDefaults = array_values(array_filter($defaults, static fn (array $row): bool => (int) $row['active'] === 1));
$check(array_map(static fn (array $row): array => [(int)$row['weekday'],$row['workout_type']], $activeDefaults) === [[1,'strength'],[3,'strength'],[4,'swimming']], 'seed расписания: понедельник/среда зал, четверг бассейн');
$userTwoSchedule = $repository->schedule(2);
$check(count($userTwoSchedule) === 3 && array_column($userTwoSchedule, 'id') !== array_column($defaults, 'id'), 'расписание изолировано по user_id');
$thursday = (int) array_values(array_filter($defaults, static fn (array $row): bool => $row['workout_type'] === 'swimming'))[0]['id'];
$otherThursday = (int) array_values(array_filter($userTwoSchedule, static fn (array $row): bool => $row['workout_type'] === 'swimming'))[0]['id'];

$input = [
    'swim_date'=>'2026-08-27','duration_minutes'=>45,'pool_length_m'=>25,'total_distance_m'=>500,'primary_style'=>'Кроль','intensity'=>7,
    'arms_fatigue'=>3,'back_fatigue'=>2,'legs_fatigue'=>4,'wellbeing'=>4,'comment'=>'Ровная работа','schedule_id'=>$thursday,
    'client_action_id'=>'swimming-create-001','intervals'=>[
        ['repeat_count'=>1,'distance_m'=>200,'style'=>'Кроль','intensity'=>6,'rest_seconds'=>30],
        ['repeat_count'=>1,'distance_m'=>200,'style'=>'Брасс','intensity'=>7,'rest_seconds'=>30],
        ['repeat_count'=>1,'distance_m'=>100,'style'=>'На спине','intensity'=>5,'rest_seconds'=>null],
    ],
];
$normalized = Swimming::validate($input, 'Europe/Moscow');
$check($normalized['occurred_at'] === '2026-08-27 09:00:00' && $normalized['weekday'] === 4, 'локальная дата плавания получает устойчивую UTC-точку с учётом timezone');
$throws(fn () => Swimming::validate([...$input,'total_distance_m'=>525], 'Europe/Moscow'), 'Сумма блоков', 'общая дистанция обязана совпадать с суммой интервалов');
$throws(fn () => Swimming::validate([...$input,'intervals'=>[['repeat_count'=>1,'distance_m'=>30,'style'=>'Кроль']]], 'Europe/Moscow'), 'целых длин', 'интервал обязан состоять из целых длин бассейна');
$throws(fn () => $repository->createSwimming(1, [...$input,'schedule_id'=>$otherThursday,'client_action_id'=>'swimming-create-tenant']), 'недоступно', 'чужое расписание нельзя использовать для создания');

$created = $repository->createSwimming(1, $input);
$same = $repository->createSwimming(1, $input);
$check($created['id'] === $same['id'] && (int)$pdo->query('SELECT COUNT(*) FROM swimming_sessions')->fetchColumn() === 1, 'offline replay создания идемпотентен');
$session = $repository->swimmingSession($created['id'], 1);
$check($session !== null && count($session['intervals']) === 3 && $session['source'] === 'schedule', 'плавание хранит отдельные структурированные интервалы и источник расписания');
$check($repository->swimmingSession($created['id'], 2) === null, 'чужая запись плавания не читается');

$updatedInput = [...$input,'version'=>1,'client_action_id'=>'swimming-update-001','comment'=>'Исправлено','intervals'=>[
    ['repeat_count'=>4,'distance_m'=>100,'style'=>'Кроль','intensity'=>7,'rest_seconds'=>20],
    ['repeat_count'=>2,'distance_m'=>50,'style'=>'На спине','intensity'=>5,'rest_seconds'=>30],
]];
$updated = $repository->updateSwimming($created['id'], 1, $updatedInput);
$check($updated['version'] === 2 && count($repository->swimmingSession($created['id'],1)['intervals']) === 2, 'редактирование атомарно заменяет блоки и повышает версию');
$throws(fn () => $repository->updateSwimming($created['id'], 1, [...$updatedInput,'version'=>1,'client_action_id'=>'swimming-update-stale']), 'другой вкладке', 'устаревшее редактирование отклоняется optimistic locking');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE user_id=1 AND entity_type='swimming_session'")->fetchColumn() === 2, 'создание и правка плавания попадают в audit log');

$history = $repository->history(1, 1, ['status'=>'completed','type'=>'swimming'], 'Europe/Moscow');
$check($history['total'] === 1 && $history['items'][0]['source_kind'] === 'swimming' && $history['items'][0]['working_sets'] === 0, 'общая история включает плавание без силовых sets');
$sequence = $repository->trainingSequence(1);
$types = array_values(array_unique(array_column($sequence, 'workout_type')));
$check(in_array('strength',$types,true) && in_array('swimming',$types,true), 'последовательность отображает силовые и плавательные тренировки');

$report = (new SwimmingReportService($repository))->build($created['id'], 1);
$check($report['schema'] === 'swimming-report' && count($report['intervals']) === 2 && $report['interpretation'] === null, 'swimming-report содержит блоки и не добавляет физиологических выводов');
$check(str_contains((new SwimmingReportService($repository))->markdown($report), 'Последовательность тренировок'), 'Markdown-отчёт содержит последовательность для внешнего анализа');

$repository->saveSchedule(1, ['days'=>[2=>['active'=>'1','workout_type'=>'swimming','label'=>'Техника'],6=>['active'=>'1','workout_type'=>'strength','label'=>'Зал выходного дня']]]);
$savedSchedule = $repository->schedule(1);
$check(count(array_filter($savedSchedule, static fn (array $row): bool => (int)$row['active'] === 1)) === 2 && (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='schedule' AND user_id=1")->fetchColumn() === 1, 'расписание недели изменяется атомарно и аудитируется');

$routes = (string)file_get_contents(dirname(__DIR__).'/public/index.php');
$js = (string)file_get_contents(dirname(__DIR__).'/public/assets/swimming.js');
$backup = (string)file_get_contents(dirname(__DIR__).'/app/Service/BackupService.php');
$check(str_contains($routes,"'/api/swimming'") && str_contains($routes,"'/schedule'") && str_contains($routes,"'/export/swimming/{id}.{format}'"), 'web/API/export маршруты этапа 7 подключены');
$check(str_contains($js,'RhythmOffline.enqueue') && str_contains($js,'saveSession') && str_contains($js,"window.addEventListener('online'"), 'плавание использует local-first draft, outbox и online replay');
$check(str_contains($backup,"'swimming_intervals'") && str_contains($backup,"'training_sequence'"), 'backup включает интервалы и общую последовательность');
$check(is_file(dirname(__DIR__).'/database/migrations/007_stage_7_swimming_schedule.sql') && is_file(dirname(__DIR__).'/docs/swimming-report-v1.0.schema.json'), 'миграция и JSON Schema этапа 7 существуют');

if ($failures !== []) { fwrite(STDERR,"Stage 7 checks failed:\n- ".implode("\n- ",$failures)."\n"); exit(1); }
fwrite(STDOUT,"Stage 7 checks passed ({$checks}).\n");
