<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$view = (string) file_get_contents($root . '/views/sessions/workout.php');
$appCss = (string) file_get_contents($root . '/public/assets/app.css');
$workoutCss = (string) file_get_contents($root . '/public/assets/workout.css');
$workoutJs = (string) file_get_contents($root . '/public/assets/workout.js');
$summary = (string) file_get_contents($root . '/views/sessions/summary.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/016_resume_completed_workouts.sql');
$serviceWorker = (string) file_get_contents($root . '/public/service-worker.js');

$check(str_contains($view, 'class="workout-progress"') && str_contains($view, 'role="progressbar"'), 'разметка содержит отдельную панель и семантический progressbar');
$check(str_contains($view, 'aria-valuemin="0"') && str_contains($view, 'aria-valuemax=') && str_contains($view, 'aria-valuenow='), 'начальные значения прогресса доступны assistive technology');
$check(str_contains($appCss, '--topbar-sticky-offset:') && str_contains($appCss, 'env(safe-area-inset-top)'), 'отступ учитывает закреплённую шапку и safe area');
$check(str_contains($workoutCss, '.workout-progress{position:sticky') && str_contains($workoutCss, 'top:var(--topbar-sticky-offset)') && str_contains($workoutCss, 'background:var(--paper)'), 'панель закреплена под шапкой на непрозрачном фоне');
$check(str_contains($workoutJs, "querySelectorAll('.exercise-card.completed').length") && str_contains($workoutJs, "setAttribute('aria-valuenow'") && str_contains($workoutJs, "setAttribute('aria-valuetext'"), 'DOM-обновление синхронизирует счётчик, полосу и ARIA');
$check(str_contains($workoutJs, 'getBoundingClientRect().height') && str_contains($workoutJs, 'ResizeObserver'), 'фактическая высота шапки пересчитывается при изменении макета');
$check(str_contains($workoutJs, 'dataset.activeSeconds') && str_contains($workoutJs, 'dataset.segmentStartedAt'), 'таймер продолжает накопленное активное время');
$check(str_contains($summary, 'Возобновить тренировку') && str_contains($summary, "'/resume'"), 'экран итогов содержит форму возобновления');
$check(str_contains($routes, "'/sessions/{id}/resume'"), 'серверный маршрут возобновления подключён');
$check(str_contains($migration, 'active_duration_seconds') && str_contains($migration, 'active_segment_started_at'), 'миграция активного времени присутствует');
$check(str_contains($view, 'data-timer-status') && str_contains($workoutJs, "terminal ? 'Закрыть' : 'Закончить раньше'"), 'после завершения таймера доступно только нейтральное закрытие');
$check(str_contains($workoutJs, "'rest.finish'") && str_contains($routes, "'/api/sessions/{id}/rest-events'"), 'событие отдыха проходит через offline-first очередь и API');
$check(str_contains($workoutJs, 'seconds < 1') && str_contains($workoutJs, 'dismissTimer(); return;'), 'нулевая пауза не создаёт некорректный таймер');
$check(str_contains($serviceWorker, "rhythm-shell-v10.14") && str_contains($serviceWorker, "rest-timer.js") && str_contains($serviceWorker, "push.js") && str_contains($serviceWorker, "workout-history.js") && str_contains($serviceWorker, "workout-wheel.js") && str_contains($serviceWorker, "workout-carousel.js"), 'версия PWA-кеша и модули тренировки обновлены');

if ($failures !== []) {
    fwrite(STDERR, "Workout progress checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Workout progress checks passed ({$checks}).\n");
