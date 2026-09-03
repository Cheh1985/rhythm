<?php

use App\Core\Auth;
use App\Core\Csrf;

$currentUser = Auth::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isLandingPage = ($landingPage ?? false) === true;
if ($currentUser) {
    header('X-Rhythm-Private: 1');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
}
?>
<!doctype html>
<html lang="<?= e(locale()) ?>" data-theme="<?= e($isLandingPage ? 'light' : ($currentUser['theme'] ?? 'system')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#193e2f">
    <meta name="description" content="<?= te($metaDescription ?? 'Ритм — мобильный дневник тренировок') ?>">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="app-url" content="<?= e(rtrim((string) env('APP_URL', ''), '/')) ?>">
    <meta name="rhythm-user-id" content="<?= $currentUser ? (int) $currentUser['id'] : '' ?>">
    <meta name="rhythm-locale" content="<?= e(locale()) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e(locale() === 'en' ? 'Rhythm' : 'Ритм') ?>">
    <link rel="manifest" href="<?= e(url(locale() === 'en' ? '/manifest.en.json' : '/manifest.json')) ?>">
    <link rel="icon" href="<?= e(url(locale() === 'en' ? '/icons/icon-en.svg' : '/icons/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(url(locale() === 'en' ? '/icons/icon-en-180.png' : '/icons/icon-180.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/workout.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/summary.css')) ?>">
    <title><?= e($title) ?> — Ритм</title>
</head>
<body>
<div class="app-shell <?= $isLandingPage ? 'landing-shell' : '' ?>">
    <?php if ($currentUser): ?>
        <header class="topbar">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="На главную"><span class="brand-mark"><?= e(locale() === 'en' ? 'R' : 'Р') ?></span><span>Ритм</span></a>
            <div class="topbar-actions">
                <a class="stage-pill" href="<?= e(url('/help')) ?>">Как пользоваться</a>
                <a class="stage-pill" href="<?= e(url('/settings')) ?>">Настройки</a>
            </div>
        </header>
    <?php elseif ($isLandingPage): ?>
        <header class="landing-header">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= te('Ритм — на главную') ?>"><span class="brand-mark"><?= e(locale() === 'en' ? 'R' : 'Р') ?></span><span>Ритм</span></a>
            <nav class="landing-nav" aria-label="Навигация по странице">
                <a href="#how-it-works">Как это работает</a>
                <a href="#data-exchange">JSON + MD</a>
                <a href="#webmcp">WebMCP</a>
                <a class="landing-login" href="<?= e(url('/login')) ?>">Войти</a>
            </nav>
            <div class="landing-header-tools"><?php require APP_ROOT . '/views/partials/language-switch.php'; ?><a class="landing-login landing-login-mobile" href="<?= e(url('/login')) ?>">Войти</a></div>
        </header>
    <?php endif; ?>
    <main class="main <?= $currentUser ? '' : ($isLandingPage ? 'landing-main' : 'auth-main') ?>">
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
<script src="<?= e(url('/assets/i18n.js')) ?>" defer></script>
<script src="<?= e(url('/assets/offline-queue.js')) ?>" defer></script>
<script src="<?= e(url('/assets/workout.js')) ?>" defer></script>
<script src="<?= e(url('/assets/swimming.js')) ?>" defer></script>
<?php if ($webMcpAdapter ?? false): ?>
    <script src="<?= e(url('/assets/webmcp.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= e(url('/assets/pwa.js')) ?>" defer></script>
</body>
</html>
