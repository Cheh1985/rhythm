<section class="page-head"><div><p class="eyebrow">Безопасный импорт</p><h1>Импорт плана</h1><p class="muted">training-plan v1.0 · до <?= e(number_format((int) env('MAX_UPLOAD_BYTES', '1048576') / 1048576, 1, ',', ' ')) ?> МБ</p></div></section>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$preview): ?>
<form class="upload-card" method="post" enctype="multipart/form-data" action="<?= e(url('/plans/import/preview')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
    <label class="file-drop"><span class="upload-glyph">⇧</span><strong>Выберите JSON-план</strong><small>Файл проверяется на сервере, а перед сохранением показывается превью</small><input type="file" name="plan" accept="application/json,.json" required></label>
    <button class="button button-primary button-wide">Проверить файл</button>
</form>
<p class="format-help">Пример: <code>tests/fixtures/training-plan/full-body-a.json</code>. Точный контракт: <code>docs/json-format.md</code>.</p>
<?php else: ?>
<section class="card preview-card">
    <div class="hero-meta"><span class="tag"><?= e(mb_strtoupper($preview['workout_type'])) ?></span><span><?= e(date('d.m.Y', strtotime($preview['scheduled_date']))) ?></span></div>
    <h2><?= e($preview['workout_name']) ?></h2>
    <p><?= e($preview['program_name']) ?> · v<?= (int) $preview['program_version'] ?></p>
    <p class="version-reason"><strong>Причина версии:</strong> <?= e($preview['change_reason']) ?></p>
    <div class="hero-stats dark"><span><strong><?= (int) $preview['exercise_count'] ?></strong> упражнений</span><span><strong><?= count($preview['unknown_exercises']) ?></strong> новых</span></div>
    <div class="preview-exercises" aria-label="Упражнения плана">
        <?php foreach ($preview['exercises'] as $index => $exercise): ?><div><span><?= $index + 1 ?></span><p><strong><?= e($exercise['name']) ?></strong><small><?= (int) $exercise['sets'] ?> × <?= (int) $exercise['rep_min'] ?>–<?= (int) $exercise['rep_max'] ?> · <code><?= e($exercise['exercise_id']) ?></code></small></p></div><?php endforeach; ?>
    </div>
    <?php if ($preview['conflicting_exercise_ids']): ?><div class="alert alert-error"><strong>Недоступные идентификаторы</strong><span><?= e(implode(', ', $preview['conflicting_exercise_ids'])) ?></span><small>Эти ID заняты упражнениями другого пользователя. Такой план сохранить нельзя.</small></div><?php endif; ?>
    <?php if ($preview['inactive_exercise_ids']): ?><div class="alert alert-error"><strong>Неактивные упражнения</strong><span><?= e(implode(', ', $preview['inactive_exercise_ids'])) ?></span><small>Сначала активируйте их в справочнике.</small></div><?php endif; ?>
    <?php if ($preview['unknown_exercises']): ?><div class="unknown-list"><strong>Будут созданы в вашем справочнике</strong><?php foreach ($preview['unknown_exercises'] as $exercise): ?><span><code><?= e($exercise['exercise_id']) ?></code> — <?= e($exercise['name']) ?></span><?php endforeach; ?></div><?php endif; ?>
    <?php if (!$preview['conflicting_exercise_ids'] && !$preview['inactive_exercise_ids']): ?><form method="post" action="<?= e(url('/plans/import/confirm')) ?>" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <?php if ($preview['unknown_exercises']): ?><label class="check-row"><input type="checkbox" name="create_unknown" value="1" required><span>Я подтверждаю создание перечисленных неизвестных упражнений</span></label><?php endif; ?>
        <button class="button button-primary button-wide">Сохранить план и версию</button>
    </form><?php endif; ?>
    <form method="post" action="<?= e(url('/plans/import/cancel')) ?>" class="cancel-form"><input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>"><button class="button button-quiet button-wide">Выбрать другой файл</button></form>
</section>
<?php endif; ?>
