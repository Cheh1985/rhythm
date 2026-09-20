<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = $root . '/public' . $path;

if ($path !== '/' && is_file($publicFile)) {
    $extension = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
    ][$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    if ($path === '/service-worker.js') header('Service-Worker-Allowed: /');
    readfile($publicFile);
    return;
}

$origin = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8127');
putenv('APP_ENV=test');
putenv('APP_DEBUG=true');
putenv('APP_URL=' . $origin);
require $root . '/bootstrap.php';

use App\Core\Locale;

/** @return array<string,mixed> */
function browser_fixture_session(): array
{
    $history = [];
    for ($index = 0; $index < 6; $index++) {
        $sessionId = 8100 + $index;
        $history[] = [
            'session_id' => $sessionId,
            'scheduled_date' => date('Y-m-d', strtotime('2026-08-10 +' . ($index * 7) . ' days')),
            'target_rir_min' => $index === 0 ? null : 1.5,
            'target_rir_max' => $index === 0 ? null : 2.5,
            'sets' => array_map(static fn (int $set): array => [
                'set_id' => $sessionId * 10 + $set,
                'set_number' => $set,
                'weight_kg' => 45.0 + $index * 1.25 + $set * 0.5,
                'weight_value' => $index === 2 ? 100.0 + $set * 5 : 45.0 + $index * 1.25 + $set * 0.5,
                'weight_unit' => $index === 2 ? 'lb' : 'kg',
                'reps' => 7 + $set,
                'rir' => $set === 1 ? 1.0 : ($set === 2 ? 2.0 : 3.0),
            ], range(1, ($index % 3) + 1)),
        ];
    }

    $exercise = static function (
        int $id,
        int $sequence,
        string $exerciseId,
        string $name,
        string $status,
        array $historySessions,
        array $sets = [],
    ): array {
        return [
            'id' => $id,
            'sequence_no' => $sequence,
            'original_exercise_id' => $exerciseId,
            'actual_exercise_id' => $exerciseId,
            'original_exercise_name' => $name,
            'exercise_name' => $name,
            'status' => $status,
            'version' => 1,
            'weight_unit' => 'kg',
            'planned_weight_kg' => 50.0 + $sequence * 2.5,
            'planned_sets' => 3,
            'rep_min' => 8,
            'rep_max' => 12,
            'target_rir_min' => 1.5,
            'target_rir_max' => 2.5,
            'rest_seconds' => 90,
            'instructions' => $sequence === 1 ? 'Держите устойчивый темп и сохраняйте контроль движения.' : '',
            'sets' => $sets,
            'history_sessions' => $historySessions,
        ];
    };

    return [
        'id' => 9001,
        'version' => 7,
        'status' => 'in_progress',
        'scheduled_date' => '2026-09-20',
        'started_at' => '2026-09-20 18:00:00',
        'active_duration_seconds' => 315,
        'active_segment_started_at' => gmdate('Y-m-d H:i:s'),
        'name' => 'Сквозная приёмка интерфейса тренировки',
        'summary' => ['completed_exercises' => 1, 'total_exercises' => 4],
        'session_rpe' => 7,
        'wellbeing' => 4,
        'user_comment' => '',
        'exercises' => [
            $exercise(101, 1, 'bench_press_001', 'Жим штанги лёжа с намеренно очень длинным названием для проверки переноса строки', 'pending', []),
            $exercise(102, 2, 'lat_pulldown_001', 'Тяга верхнего блока', 'waiting', [$history[5]], [[
                'id' => 501,
                'version' => 1,
                'set_number' => 1,
                'set_type' => 'working',
                'weight_kg' => 55.0,
                'weight_value' => 55.0,
                'weight_unit' => 'kg',
                'reps' => 10,
                'rir' => 2.0,
            ]]),
            $exercise(103, 3, 'leg_press_001', 'Жим ногами', 'completed', $history, [[
                'id' => 502,
                'version' => 1,
                'set_number' => 1,
                'set_type' => 'working',
                'weight_kg' => 120.0,
                'weight_value' => 120.0,
                'weight_unit' => 'kg',
                'reps' => 12,
                'rir' => 1.5,
            ]]),
            $exercise(104, 4, 'face_pull_001', 'Тяга каната к лицу', 'skipped', array_slice($history, 0, 3)),
        ],
        'available_exercises' => [
            ['exercise_id' => 'bench_press_001', 'name' => 'Жим штанги лёжа', 'weight_unit' => 'kg'],
            ['exercise_id' => 'lateral_raise_001', 'name' => 'Разведения гантелей', 'weight_unit' => 'lb'],
            ['exercise_id' => 'custom-browser-fixture', 'name' => 'Пользовательское упражнение', 'weight_unit' => 'kg'],
        ],
    ];
}

if ($path === '/__fixture/conflict') {
    $_SESSION['browser_fixture_conflict'] = true;
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    return;
}

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!empty($_SESSION['browser_fixture_conflict']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        unset($_SESSION['browser_fixture_conflict']);
        http_response_code(409);
        echo json_encode(['error' => 'Fixture version conflict', 'conflict' => true], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['data' => browser_fixture_session()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    echo json_encode(['data' => [
        'id' => 9901,
        'version' => 1,
        'session_version' => max(8, (int) ($body['session_version'] ?? 7) + 1),
        'exercise_version' => max(2, (int) ($body['exercise_version'] ?? 1) + 1),
        'actual_exercise_id' => $body['actual_exercise_id'] ?? 'bench_press_001',
        'weight_unit' => $body['weight_unit'] ?? 'kg',
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($path !== '/sessions/9001') {
    http_response_code(404);
    echo 'Not found';
    return;
}

if (($_GET['lang'] ?? '') === 'en') Locale::set('en');
else Locale::set('ru');
$session = browser_fixture_session();
ob_start();
require $root . '/views/sessions/workout.php';
$content = Locale::translateMarkup((string) ob_get_clean());
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Rhythm-Private: 1');
?>
<!doctype html>
<html lang="<?= e(locale()) ?>" data-theme="<?= e(($_GET['theme'] ?? '') === 'dark' ? 'dark' : 'light') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#193e2f">
    <meta name="csrf-token" content="fixture">
    <meta name="app-url" content="<?= e($origin) ?>">
    <meta name="rhythm-user-id" content="77">
    <meta name="rhythm-locale" content="<?= e(locale()) ?>">
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/workout.css">
    <title>Workout UI browser fixture</title>
</head>
<body>
<div class="app-shell">
    <header class="topbar"><a class="brand" href="/sessions/9001"><span class="brand-mark">Р</span><span>Ритм</span></a></header>
    <main class="main"><?= $content ?></main>
</div>
<div class="sw-update" id="sw-update" hidden><strong>Доступно обновление</strong><span>Локальные изменения уже сохранены.</span><button class="button button-primary" type="button">Обновить приложение</button></div>
<script src="/assets/i18n.js" defer></script>
<script src="/assets/offline-queue.js" defer></script>
<script src="/assets/weight.js" defer></script>
<script src="/assets/rest-timer.js" defer></script>
<script src="/assets/push.js" defer></script>
<script src="/assets/workout-card.js" defer></script>
<script src="/assets/workout-history.js" defer></script>
<script src="/assets/workout-wheel.js" defer></script>
<script src="/assets/workout-carousel.js" defer></script>
<script src="/assets/workout.js" defer></script>
<script src="/assets/pwa.js" defer></script>
</body>
</html>
