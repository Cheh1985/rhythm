<?php

declare(strict_types=1);

require __DIR__ . '/stage5.php';

use App\Domain\Analytics;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$moscowWindow = Analytics::weekWindow('Europe/Moscow', 1, new DateTimeImmutable('2026-08-24 12:00:00', new DateTimeZone('UTC')));
$check($moscowWindow['start_utc'] === '2026-08-23 21:00:00' && $moscowWindow['end_utc'] === '2026-08-30 21:00:00', 'граница московской недели переводится в UTC с учётом timezone');
$buckets = Analytics::weekly([
    ['started_at' => '2026-08-23 20:30:00', 'finished_at' => '2026-08-23 21:30:00', 'working_sets' => 2, 'tonnage' => 200, 'average_rir' => 2, 'rir_count' => 2],
    ['started_at' => '2026-08-23 21:30:00', 'finished_at' => '2026-08-23 22:00:00', 'working_sets' => 3, 'tonnage' => 300, 'average_rir' => 4, 'rir_count' => 3],
], 'Europe/Moscow', 2, new DateTimeImmutable('2026-08-24 12:00:00', new DateTimeZone('UTC')));
$check($buckets[0]['workouts'] === 1 && $buckets[1]['workouts'] === 1, 'воскресенье UTC корректно разделяется по локальному понедельнику');
$check($buckets[1]['working_sets'] === 3 && $buckets[1]['tonnage'] === 300.0 && $buckets[1]['duration_minutes'] === 30, 'недельные working sets, tonnage и duration агрегируются');

$signals = Analytics::softSignals([
    ['best_e1rm' => 100, 'average_rir' => 4.2, 'reps_by_weight' => ['60' => 6]],
    ['best_e1rm' => 101, 'average_rir' => 2, 'reps_by_weight' => ['60' => 10]],
    ['best_e1rm' => 100.5, 'average_rir' => 2, 'reps_by_weight' => ['60' => 10]],
]);
$check(count($signals) === 3 && array_column($signals, 'kind') === ['plateau', 'high_rir', 'reps_drop'], 'мягкие сигналы формируются как наблюдения по данным');

$pdo->exec("ALTER TABLE exercises ADD COLUMN category TEXT NULL");
$pdo->exec("ALTER TABLE exercises ADD COLUMN muscle_groups TEXT NULL");
$pdo->exec("ALTER TABLE exercises ADD COLUMN equipment TEXT NULL");
$pdo->exec("UPDATE exercises SET category='back',muscle_groups='[\"lats\",\"biceps\"]' WHERE exercise_id='row'");
$pdo->exec(<<<'SQL'
CREATE TABLE body_measurements (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, measured_on TEXT NOT NULL,
    weight_kg REAL NULL, waist_cm REAL NULL, chest_cm REAL NULL, shoulders_cm REAL NULL,
    biceps_left_cm REAL NULL, biceps_right_cm REAL NULL, thigh_cm REAL NULL, calf_cm REAL NULL,
    body_fat_percent REAL NULL, extra_json TEXT NULL, comment TEXT NULL, created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, deleted_at TEXT NULL
)
SQL);

for ($index = 1; $index <= 23; $index++) {
    $id = 100 + $index;
    $day = str_pad((string) (($index % 20) + 1), 2, '0', STR_PAD_LEFT);
    $pdo->exec("INSERT INTO workout_sessions (id,public_id,user_id,workout_plan_id,workout_type,status,started_at,finished_at,version,created_at,updated_at) VALUES ({$id},'history-{$id}',1,1,'strength','completed','2026-07-{$day} 09:00:00','2026-07-{$day} 10:00:00',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
}
$pdo->exec("INSERT INTO workout_sessions (id,public_id,user_id,workout_plan_id,workout_type,status,started_at,finished_at,version,created_at,updated_at) VALUES (199,'history-other',2,2,'strength','completed','2026-07-15 09:00:00','2026-07-15 10:00:00',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

$historyPage1 = $repository->history(1, 1, ['status' => 'completed'], 'Europe/Moscow');
$historyPage2 = $repository->history(1, 2, ['status' => 'completed'], 'Europe/Moscow');
$check(count($historyPage1['items']) === 20 && $historyPage2['page'] === 2 && $historyPage2['total'] > 20, 'история отдаёт по 20 строк и серверную пагинацию');
$check(count(array_filter($historyPage1['items'], static fn (array $row): bool => (int) $row['id'] === 199)) === 0, 'пагинируемая история изолирована по user_id');
$filtered = $repository->history(1, 1, ['status' => 'completed', 'q' => 'Offline flow'], 'Europe/Moscow');
$check($filtered['total'] >= 1 && count(array_filter($filtered['items'], static fn (array $row): bool => $row['name'] !== 'Offline flow')) === 0, 'название фильтруется на сервере');

$check($repository->exerciseAnalytics('secret', 1, 'Europe/Moscow') === null, 'чужое пользовательское упражнение не открывается');
$exercisePage = $repository->exerciseAnalytics('row', 1, 'Europe/Moscow');
$check($exercisePage !== null && count($exercisePage['sessions']) >= 1 && isset($exercisePage['charts']['tonnage'], $exercisePage['charts']['e1rm']), 'страница стабильного exercise_id содержит подходы и графики');

$repository->addMeasurement(1, ['measured_on' => '2026-08-20', 'weight_kg' => '80.2', 'waist_cm' => '83', 'chest_cm' => '101', 'biceps_left_cm' => '36', 'biceps_right_cm' => '37', 'thigh_cm' => '59', 'body_fat_percent' => '16.5']);
$repository->addMeasurement(2, ['measured_on' => '2026-08-20', 'weight_kg' => '91']);
$measurements = $repository->measurements(1);
$measurementCharts = $repository->measurementCharts($measurements);
$check(count($measurements) === 1 && (float) $measurements[0]['weight_kg'] === 80.2, 'измерения изолированы по пользователю');
$check(count($measurementCharts['weight_kg']['points']) === 1 && count($measurementCharts['biceps']['points']) === 1, 'ряды веса и среднего бицепса строятся из измерений');

$recordTypes = $pdo->query("SELECT DISTINCT record_type FROM personal_records WHERE user_id=1")->fetchAll(PDO::FETCH_COLUMN);
$check(count(array_intersect(['max_weight','best_e1rm','exercise_tonnage','session_tonnage','rep_range_completed'], $recordTypes)) >= 4, 'расширенный набор PR пересчитывается после завершения');

$routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
$check(str_contains($routes, "'/history'") && str_contains($routes, "'/analytics'") && str_contains($routes, "'/exercises/{id}'") && str_contains($routes, "'/measurements'"), 'маршруты этапа 6 подключены');
$check(str_contains($css, '@media (max-width: 34rem)') && str_contains($css, '.line-chart') && str_contains($css, 'overflow-x: auto'), 'графики и длинные подходы адаптированы для узкого viewport');
$check(is_file(dirname(__DIR__) . '/database/migrations/006_stage_6_analytics.sql'), 'миграция индексов этапа 6 существует');

if ($failures !== []) {
    fwrite(STDERR, "Stage 6 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 6 checks passed ({$checks}).\n");
