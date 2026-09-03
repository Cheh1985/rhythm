<section class="page-head"><div><p class="eyebrow">Отдельный тип тренировки</p><h1>Плавание</h1><p class="muted">Блоки воды хранятся отдельно от силовых подходов.</p></div><a class="button button-quiet" href="<?= e(url('/schedule')) ?>">Расписание</a></section>
<?php if($error): ?><div class="alert alert-error"><?= te($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?= te($success) ?></div><?php endif; ?>
<details class="form-disclosure" open><summary class="button button-primary">＋ Записать бассейн</summary>
<?php $formAction = url('/swimming'); $session = null; $isEdit = false; require __DIR__ . '/partials/swimming-form.php'; ?>
</details>
<section class="section-block"><div class="section-title"><h2>Последние записи</h2><span class="muted"><?= count($items) ?></span></div>
<div class="data-list"><?php if(!$items): ?><div class="empty-state compact"><h2>Пока пусто</h2><p>Первая запись появится здесь и в общей истории.</p></div><?php endif; ?><?php foreach($items as $item): ?><a class="swim-list-item" href="<?= e(url('/swimming/'.$item['id'])) ?>"><time><?= e(local_date($item['swim_date'])) ?></time><strong><?= (int)$item['total_distance_m'] ?> <?= e(unit('m')) ?> · <?= (int)$item['duration_minutes'] ?> <?= e(unit('min')) ?></strong><span><?= e($item['primary_style']) ?> · интенсивность <?= (int)$item['intensity'] ?>/10</span></a><?php endforeach; ?></div></section>
