<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'css' => file_get_contents($root . '/public/assets/workout.css'),
    'workout' => file_get_contents($root . '/public/assets/workout.js'),
    'wheel' => file_get_contents($root . '/public/assets/workout-wheel.js'),
    'worker' => file_get_contents($root . '/public/service-worker.js'),
    'client_i18n' => file_get_contents($root . '/public/assets/i18n.js'),
    'server_i18n' => file_get_contents($root . '/app/I18n/en.php'),
    'layout' => file_get_contents($root . '/views/layout.php'),
    'fixture' => file_get_contents($root . '/tests/fixtures/workout-ui-browser-router.php'),
];
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($files['wheel'], 'function restoreValuesWithin') && str_contains($files['wheel'], "if (typeof prepare === 'function') prepare();"), 'восстановление формы сначала настраивает единицу и только затем записывает draft');
$check(str_contains($files['workout'], 'RhythmWorkoutWheel.restoreValuesWithin(form, values, () => setWeightUnit('), 'интеграция использует безопасный порядок восстановления веса');
$check(!preg_match("~weight-input'\)\.value = values\.weight;\s*setWeightUnit~", $files['workout']), 'старый порядок, терявший вес после reload, удалён');

$check(str_contains($files['css'], '.workout-head>div:first-child{min-width:0}') && str_contains($files['css'], '.workout-head h1{overflow-wrap:anywhere'), 'шапка допускает сжатие и перенос длинного названия');
$check(str_contains($files['css'], '@media(max-width:23rem){.workout-head{gap:.5rem}') && str_contains($files['css'], '.elapsed{min-width:4.25rem'), 'при 200% zoom таймер в шапке сжимается без горизонтального overflow');
$check(!str_contains($files['layout'], 'user-scalable=no'), 'pinch zoom по-прежнему не запрещён');

foreach (['Синхронизировано', 'Ожидает', 'Готово', 'Пропущено', 'Ошибка · конфликт версий', 'Завершение ожидает синхронизации'] as $text) {
    $check(str_contains($files['client_i18n'], "'{$text}':") && str_contains($files['server_i18n'], "'{$text}' =>"), "динамическая строка «{$text}» локализована на сервере и клиенте");
}
$check(str_contains($files['workout'], "const tr = (text) =>") && str_contains($files['workout'], "textContent = tr('Синхронизировано')") && str_contains($files['workout'], "textContent = tr(labels[status]"), 'динамические sync/status строки проходят через клиентскую локализацию');
$check(str_contains($files['workout'], "window.RhythmI18n?.locale === 'en'") && str_contains($files['workout'], "? 'W' : 'S'"), 'optimistic-подход получает английский W/S вместо русских букв');
$check(substr_count($files['workout'], "querySelector('button[data-status]')") === 1 && substr_count($files['workout'], "closest('button[data-status]')") === 1, 'клики действий карточки не перехватываются её собственным data-status');
$check(strpos($files['workout'], "closest('button[data-status]')") < strpos($files['workout'], "closest('[data-open-action]')"), 'обработчик различает прямую кнопку статуса и кнопки диалоговых действий');

$check(str_contains($files['worker'], "rhythm-shell-v10.14") && str_contains($files['worker'], "asset('./assets/workout.js')") && str_contains($files['worker'], "asset('./assets/workout.css')"), 'изменённые UI-ассеты опубликованы новой версией app shell');
$check(str_contains($files['fixture'], "'/sessions/9001'") && str_contains($files['fixture'], "'/__fixture/conflict'") && str_contains($files['fixture'], "X-Rhythm-Private: 1"), 'браузерная фикстура покрывает страницу, 409 и private-page cache');
$check(str_contains($files['fixture'], "'pending'") && str_contains($files['fixture'], "'waiting'") && str_contains($files['fixture'], "'completed'") && str_contains($files['fixture'], "'skipped'"), 'браузерная фикстура содержит все состояния упражнения');
$check(str_contains($files['fixture'], "array_slice(\$history, 0, 3)") && str_contains($files['fixture'], "\$history[5]") && str_contains($files['fixture'], "'history_sessions' => \$historySessions"), 'браузерная фикстура содержит пустую, одиночную и шестисессионную историю');
$check(str_contains($files['fixture'], "(\$_GET['lang'] ?? '') === 'en'") && str_contains($files['fixture'], "(\$_GET['theme'] ?? '') === 'dark'"), 'браузерная фикстура позволяет проверять RU/EN и light/dark');

if ($failures !== []) {
    fwrite(STDERR, "Workout UI stage 5 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Workout UI stage 5 checks passed ({$checks}).\n");
