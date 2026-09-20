<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'view' => file_get_contents($root . '/views/sessions/workout.php'),
    'css' => file_get_contents($root . '/public/assets/workout.css'),
    'workout' => file_get_contents($root . '/public/assets/workout.js'),
    'history' => file_get_contents($root . '/public/assets/workout-history.js'),
    'card' => file_get_contents($root . '/public/assets/workout-card.js'),
    'layout' => file_get_contents($root . '/views/layout.php'),
    'worker' => file_get_contents($root . '/public/service-worker.js'),
];
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($files['view'], 'class="exercise-icon"') && str_contains($files['view'], 'class="exercise-heading"') && str_contains($files['view'], 'class="rest-prescription"'), 'верх карточки содержит пиктограмму, заголовок и отдых');
$check(str_contains($files['view'], 'class="exercise-toggle"') && str_contains($files['view'], 'aria-expanded="false"') && str_contains($files['view'], 'aria-controls="<?= e($bodyId) ?>"'), 'кнопка раскрытия имеет исходное состояние и ARIA-связь');
$check(str_contains($files['view'], 'class="exercise-card-body"') && str_contains($files['view'], 'id="<?= e($bodyId) ?>" hidden'), 'форма и подходы находятся в скрываемой области без удаления DOM');
$check(str_contains($files['view'], 'data-history-chart') && str_contains($files['view'], 'class="history-data"') && !str_contains($files['view'], 'В прошлый раз'), 'старую строку заменила локальная история');
$check(str_contains($files['view'], 'history-summary sr-only') && str_contains($files['view'], 'role="status" aria-live="polite"') && str_contains($files['history'], "document.createElement('button')"), 'график имеет резюме, live-detail и настоящие кнопки подходов');
$check(str_contains($files['history'], 'for (let mark = 3; mark <= maxReps; mark += 3)') && str_contains($files['history'], "el('rect'") && str_contains($files['history'], "el('line'"), 'локальный SVG строит сетку, кирпичики и линии');
$check(str_contains($files['history'], 'visibleWindow') && str_contains($files['view'], 'data-history-previous') && str_contains($files['view'], 'data-history-next') && str_contains($files['view'], 'history-dates'), 'узкая история имеет целое окно и явную навигацию по датам');
$check(str_contains($files['workout'], 'expandedExerciseIds') && str_contains($files['workout'], 'selectedHistorySessionByExercise') && str_contains($files['workout'], 'setCardExpanded'), 'draft сохраняет раскрытия и выбранную тренировку');
$check(str_contains($files['card'], "return /^[a-z0-9_]{1,80}$/") && str_contains($files['view'], "is_file(APP_ROOT . '/public/assets/exercises/'"), 'имя пиктограммы проверяется и на клиенте, и на сервере');
$check(str_contains($files['layout'], '/assets/workout-card.js') && str_contains($files['layout'], '/assets/workout-history.js'), 'локальные модули подключены до интеграционного скрипта');
$check(str_contains($files['css'], '@media(max-width:34rem)') && str_contains($files['css'], '@media(max-width:23rem)') && str_contains($files['css'], 'minmax(0,1fr)'), 'карточка защищена от переполнения на ширинах 320–430 px');
$check(!preg_match('~(?:cdn|unpkg|jsdelivr|cdnjs)~i', $files['history'] . $files['card'] . $files['view']) && !str_contains($files['history'], 'd3.'), 'график не использует CDN или D3');
$check(str_contains($files['worker'], "rhythm-shell-v10.11") && str_contains($files['worker'], "asset('./assets/workout-history.js')") && str_contains($files['worker'], "asset('./assets/exercises/generic.svg')"), 'версия app shell актуальна, новые модули и fallback кешируются');

$icons = glob($root . '/public/assets/exercises/*.svg') ?: [];
$check(count($icons) === 15, 'добавлены 14 seed-пиктограмм и generic fallback');
foreach ($icons as $icon) {
    $svg = (string) file_get_contents($icon);
    $check(str_contains($svg, 'viewBox="0 0 96 96"'), basename($icon) . ' имеет единый viewBox');
    $check(!preg_match('~<(?:script|image)\b|(?:href|src)\s*=|data:image~i', $svg), basename($icon) . ' не содержит скриптов, ссылок или растра');
}
foreach ([
    'leg_press_001', 'bench_press_001', 'incline_db_press_001', 'lat_pulldown_001', 'seated_cable_row_001', 'leg_curl_001', 'biceps_curl_001',
    'triceps_pushdown_001', 'db_shoulder_press_001', 'hack_squat_001', 'romanian_deadlift_001', 'calf_raise_001', 'lateral_raise_001', 'face_pull_001',
] as $exerciseId) {
    $check(is_file($root . '/public/assets/exercises/' . $exerciseId . '.svg'), $exerciseId . ' имеет локальную пиктограмму');
    $check(str_contains($files['worker'], "asset('./assets/exercises/{$exerciseId}.svg')"), $exerciseId . ' включён в app shell');
}

if ($failures !== []) {
    fwrite(STDERR, "Workout UI stage 2 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Workout UI stage 2 checks passed ({$checks}).\n");
