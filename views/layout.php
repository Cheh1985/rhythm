<?php

use App\Core\Auth;
use App\Core\Csrf;

$currentUser = Auth::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($currentUser) {
    header('X-Rhythm-Private: 1');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
}
?>
<!doctype html>
<html lang="ru" data-theme="<?= e($currentUser['theme'] ?? 'system') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#193e2f">
    <meta name="description" content="Ритм — мобильный дневник тренировок">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="app-url" content="<?= e(rtrim((string) env('APP_URL', ''), '/')) ?>">
    <meta name="rhythm-user-id" content="<?= $currentUser ? (int) $currentUser['id'] : '' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Ритм">
    <link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
    <link rel="icon" href="<?= e(url('/icons/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(url('/icons/icon-180.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/workout.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/summary.css')) ?>">
    <title><?= e($title) ?> — Ритм</title>
</head>
<body>
<div class="app-shell">
    <?php if ($currentUser): ?>
        <header class="topbar">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="На главную"><span class="brand-mark">Р</span><span>Ритм</span></a>
            <a class="stage-pill" href="<?= e(url('/settings')) ?>">Настройки</a>
        </header>
    <?php endif; ?>
    <main class="main <?= $currentUser ? '' : 'auth-main' ?>">
        <?= $content ?>
    </main>
    <?php if ($currentUser): ?>
        <nav class="bottom-nav" aria-label="Основная навигация">
            <a class="<?= $currentPath==='/'?'active':'' ?>" href="<?= e(url('/')) ?>"><span aria-hidden="true">⌂</span><small>Сегодня</small></a>
            <a class="<?= str_starts_with($currentPath,'/history')?'active':'' ?>" href="<?= e(url('/history')) ?>"><span aria-hidden="true">◷</span><small>История</small></a>
            <a class="nav-primary" href="<?= e(url('/plans/import')) ?>"><span aria-hidden="true">＋</span><small>План</small></a>
            <a class="<?= str_starts_with($currentPath,'/analytics')?'active':'' ?>" href="<?= e(url('/analytics')) ?>"><span aria-hidden="true">⌁</span><small>Аналитика</small></a>
            <a class="<?= str_starts_with($currentPath,'/measurements')?'active':'' ?>" href="<?= e(url('/measurements')) ?>"><span aria-hidden="true">↗</span><small>Тело</small></a>
        </nav>
    <?php endif; ?>
</div>
<div class="sw-update" id="sw-update" hidden><strong>Доступно обновление</strong><span>Локальные изменения уже сохранены.</span><button class="button button-primary" type="button">Обновить приложение</button></div>
<script src="<?= e(url('/assets/offline-queue.js')) ?>" defer></script>
<script src="<?= e(url('/assets/workout.js')) ?>" defer></script>
<script src="<?= e(url('/assets/swimming.js')) ?>" defer></script>
<script src="<?= e(url('/assets/pwa.js')) ?>" defer></script>
</body>
</html>
