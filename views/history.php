<?php
$items = $history['items'];
$filters = $history['filters'];
$statusLabels = ['completed' => 'Завершена', 'in_progress' => 'В процессе', 'cancelled' => 'Отменена'];
$typeLabels = ['strength' => 'Силовая', 'swimming' => 'Плавание', 'cardio' => 'Кардио', 'mobility' => 'Мобильность', 'other' => 'Другое'];
$pageUrl = static function (int $page) use ($filters): string {
    return url('/history?' . http_build_query(array_filter([...$filters, 'page' => $page], static fn (mixed $value): bool => $value !== '' && $value !== null)));
};
?>
<section class="page-head"><div><p class="eyebrow">Хронология</p><h1>История</h1><p class="muted">Фильтры выполняются на сервере; загружается только текущая страница.</p></div></section>

<details class="filter-panel card" <?= array_filter($filters, static fn ($v) => $v !== '' && $v !== 'completed' && $v !== null) ? 'open' : '' ?>>
    <summary>Фильтры <span><?= (int) $history['total'] ?> записей</span></summary>
    <form method="get" action="<?= e(url('/history')) ?>" class="stack-form">
        <label>По названию<input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="80" placeholder="Например, Full Body"></label>
        <div class="field-grid">
            <label>Статус<select name="status"><option value="completed">Завершённые</option><option value="all" <?= $filters['status']==='all'?'selected':'' ?>>Все</option><option value="in_progress" <?= $filters['status']==='in_progress'?'selected':'' ?>>В процессе</option><option value="cancelled" <?= $filters['status']==='cancelled'?'selected':'' ?>>Отменённые</option></select></label>
            <label>Тип<select name="type"><option value="">Все типы</option><?php foreach($typeLabels as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['type']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>С даты<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
            <label>По дату<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        </div>
        <label>Упражнение<select name="exercise_id"><option value="">Любое упражнение</option><?php foreach($history['exercise_options'] as $option): ?><option value="<?= e($option['exercise_id']) ?>" <?= $filters['exercise_id']===$option['exercise_id']?'selected':'' ?>><?= e($option['name']) ?></option><?php endforeach; ?></select></label>
        <div class="button-row"><button class="button button-primary">Применить</button><a class="button button-quiet" href="<?= e(url('/history')) ?>">Сбросить</a></div>
    </form>
</details>

<div class="timeline">
    <?php if (!$items): ?><div class="empty-state compact"><h2>Ничего не найдено</h2><p>Измените фильтры или завершите первую тренировку.</p></div><?php endif; ?>
    <?php foreach ($items as $session): ?>
        <a class="timeline-item" href="<?= e(url($session['href'])) ?>">
            <time datetime="<?= e($session['started_at']) ?>"><?= e(local_datetime($session['started_at'], $timezone, 'd.m.Y')) ?><small><?= e(local_datetime($session['started_at'], $timezone, 'H:i')) ?></small></time>
            <div><h3><?= e($session['name']) ?></h3><p><?php if($session['workout_type']==='swimming'): ?><?= (int)$session['distance_m'] ?> м · <?= (int)$session['duration_minutes'] ?> мин<?php else: ?><?= (int)$session['working_sets'] ?> рабочих · <?= e(round((float)$session['tonnage'])) ?> кг<?php if($session['average_rir']!==null): ?> · RIR <?= e(round((float)$session['average_rir'],1)) ?><?php endif; ?><?php endif; ?></p></div>
            <span class="tag"><?= e($statusLabels[$session['status']] ?? $session['status']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if($history['pages'] > 1): ?><nav class="pagination" aria-label="Страницы истории">
    <?php if($history['page'] > 1): ?><a class="button button-quiet" rel="prev" href="<?= e($pageUrl($history['page']-1)) ?>">← Назад</a><?php else: ?><span></span><?php endif; ?>
    <span>Страница <?= (int)$history['page'] ?> из <?= (int)$history['pages'] ?></span>
    <?php if($history['page'] < $history['pages']): ?><a class="button button-quiet" rel="next" href="<?= e($pageUrl($history['page']+1)) ?>">Далее →</a><?php endif; ?>
</nav><?php endif; ?>
