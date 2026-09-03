<section class="page-head dashboard-head">
    <div><p class="eyebrow"><?= e(local_date((new DateTimeImmutable('now'))->format('Y-m-d'))) ?></p><h1>Сегодня</h1><p class="muted">Привет, <?= e($user['login']) ?></p></div>
    <form method="post" action="<?= e(url('/logout')) ?>"><input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>"><button class="icon-button" aria-label="Выйти">↪</button></form>
</section>

<?php if ($unfinished): ?>
<div class="alert unfinished"><strong>Есть незавершённая тренировка</strong><span><?= e($unfinished['name']) ?></span><a class="button button-primary" href="<?= e(url('/sessions/' . $unfinished['id'])) ?>">Продолжить</a></div>
<?php endif; ?>

<section class="hero-card">
    <?php if ($next): ?>
        <div class="hero-meta"><span class="tag"><?= e(mb_strtoupper(system_label('workout_type', $next['workout_type']))) ?></span><span><?= e(local_date($next['scheduled_date'], true)) ?></span></div>
        <h2><?= e($next['name']) ?></h2>
        <p><?= $next['goal'] ? e($next['goal']) : te('Следуйте плану и фиксируйте каждый подход.') ?></p>
        <div class="hero-stats"><span><strong><?= (int) $next['exercise_count'] ?></strong> упражнений</span><span><strong><?= (int) ($next['estimated_duration_min'] ?: 0) ?></strong> <?= e(unit('min')) ?>.</span></div>
        <a class="button button-light button-wide" href="<?= e(url('/plans/' . $next['id'])) ?>">Открыть тренировку <span>→</span></a>
    <?php else: ?>
        <div class="hero-meta"><span class="tag">СЛЕДУЮЩИЙ ШАГ</span></div>
        <h2>Добавьте первый план</h2>
        <p>Импортируйте training-plan v1.0: сначала проверим файл и покажем безопасное превью.</p>
        <a class="button button-light button-wide" href="<?= e(url('/plans/import')) ?>">Импортировать JSON <span>→</span></a>
    <?php endif; ?>
</section>

<section class="section-block">
    <div class="section-title"><div><p class="eyebrow">Коротко</p><h2>Текущий ритм</h2></div></div>
    <div class="metric-grid">
        <article class="metric-card"><span>За неделю</span><strong><?= (int) ($stats['sessions_week'] ?? 0) ?></strong><small>тренировок</small></article>
        <article class="metric-card"><span>В движении</span><strong><?= (int) ($stats['minutes_week'] ?? 0) ?></strong><small>минут</small></article>
        <article class="metric-card accent"><span>Последняя</span><strong><?= $last ? (int) ($last['session_rpe'] ?? 0) . '/10' : '—' ?></strong><small><?= $last ? e($last['name']) : 'нет данных' ?></small></article>
    </div>
</section>

<section class="section-block">
    <div class="section-title"><h2>Быстрые действия</h2></div>
    <div class="action-list">
        <a href="<?= e(url('/help')) ?>"><span class="action-icon green">?</span><span><strong>Как пользоваться</strong><small>Простая инструкция по шагам</small></span><b>›</b></a>
        <a href="<?= e(url('/plans/import')) ?>"><span class="action-icon coral">＋</span><span><strong>Импортировать план</strong><small>JSON v1.0 и безопасное превью</small></span><b>›</b></a>
        <a href="<?= e(url('/history')) ?>"><span class="action-icon green">◷</span><span><strong>История тренировок</strong><small>Фильтры, подходы и объём</small></span><b>›</b></a>
        <a href="<?= e(url('/analytics')) ?>"><span class="action-icon blue">⌁</span><span><strong>Аналитика</strong><small>Недели, мышцы и PR</small></span><b>›</b></a>
        <a href="<?= e(url('/measurements')) ?>"><span class="action-icon coral">↗</span><span><strong>Измерения</strong><small>Вес и окружности</small></span><b>›</b></a>
        <a href="<?= e(url('/swimming')) ?>"><span class="action-icon blue">≈</span><span><strong>Плавание</strong><small>Дистанция, стили и блоки</small></span><b>›</b></a>
        <a href="<?= e(url('/schedule')) ?>"><span class="action-icon green">▦</span><span><strong>Расписание</strong><small>Дни зала и бассейна</small></span><b>›</b></a>
        <a href="<?= e(url('/programs')) ?>"><span class="action-icon amber">◷</span><span><strong>Версии программы</strong><small>Родители и причины изменений</small></span><b>›</b></a>
        <a href="<?= e(url('/exercises')) ?>"><span class="action-icon blue">≡</span><span><strong>Упражнения</strong><small>Глобальные и пользовательские</small></span><b>›</b></a>
        <a href="<?= e(url('/settings')) ?>"><span class="action-icon amber">⚙</span><span><strong>Настройки и backup</strong><small>Тема, экспорт и безопасный restore</small></span><b>›</b></a>
    </div>
</section>
