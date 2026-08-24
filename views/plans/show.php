<section class="page-head"><div><p class="eyebrow"><?= e(date('d.m.Y', strtotime($plan['scheduled_date']))) ?> · <?= e($plan['workout_type']) ?></p><h1><?= e($plan['name']) ?></h1><p class="muted"><?= e($plan['goal']) ?></p><p class="plan-origin"><?= e($plan['program_name'] ?: 'Без программы') ?> · v<?= (int) $plan['program_version'] ?> · <code><?= e($plan['external_plan_id']) ?></code></p></div></section>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($plan['trainer_notes']): ?><div class="coach-note"><strong>Заметка тренера</strong><p><?= nl2br(e($plan['trainer_notes'])) ?></p></div><?php endif; ?>
<section class="plan-list">
<?php foreach ($plan['exercises'] as $index => $exercise): ?>
    <article class="plan-item"><span class="plan-index"><?= $index + 1 ?></span><div><h3><?= e($exercise['exercise_name']) ?></h3><p><?= (int) $exercise['planned_sets'] ?> × <?= (int) $exercise['rep_min'] ?>–<?= (int) $exercise['rep_max'] ?> · RIR <?= e($exercise['target_rir_min']) ?>–<?= e($exercise['target_rir_max']) ?> · <?= (int) $exercise['rest_seconds'] ?> сек.</p><?php if ($exercise['instructions']): ?><small><?= e($exercise['instructions']) ?></small><?php endif; ?></div></article>
<?php endforeach; ?>
</section>
<?php if ($plan['active_session_id']): ?>
<a class="button button-primary button-wide start-training" href="<?= e(url('/sessions/' . $plan['active_session_id'])) ?>">Продолжить незавершённую тренировку</a>
<?php elseif ($plan['status'] === 'planned'): ?>
<section class="card plan-management">
    <p class="eyebrow">Управление планом</p>
    <h2>Изменить дату</h2>
    <p class="muted">План, упражнения и исходный JSON останутся прежними. Изменится только запланированный день.</p>
    <form method="post" action="<?= e(url('/plans/' . $plan['id'] . '/reschedule')) ?>" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <input type="hidden" name="version" value="<?= (int) $plan['version'] ?>">
        <label>Новая дата<input type="date" name="scheduled_date" value="<?= e($plan['scheduled_date']) ?>" required></label>
        <button class="button button-secondary" type="submit">Перенести тренировку</button>
    </form>
</section>
<section class="card readiness-card" data-readiness data-plan-id="<?= (int) $plan['id'] ?>">
    <p class="eyebrow">Быстрая готовность · 10–15 секунд</p>
    <h2>Как вы сегодня?</h2>
    <form id="readiness-form" class="stack-form">
        <?php foreach (['sleep' => 'Сон', 'energy' => 'Энергия', 'readiness' => 'Готовность'] as $field => $label): ?>
        <fieldset class="score-picker"><legend><?= e($label) ?></legend><div><?php foreach ([1,2,3,4,5] as $score): ?><label><input type="radio" name="<?= e($field) ?>" value="<?= $score ?>" required><span><?= $score ?></span></label><?php endforeach; ?></div></fieldset>
        <?php endforeach; ?>
        <details><summary>Вес и комментарий (необязательно)</summary><div class="optional-readiness"><label>Вес, кг<input name="body_weight_kg" type="number" inputmode="decimal" min="20" max="500" step="0.1"></label><label>Комментарий<textarea name="comment" rows="2" maxlength="2000"></textarea></label></div></details>
        <p class="form-message" role="status" hidden></p>
        <button class="button button-primary button-wide" type="submit">Начать тренировку</button>
    </form>
</section>
<details class="card danger-zone">
    <summary>Удалить импортированный план</summary>
    <p class="muted">План исчезнет с Dashboard, но запись останется в audit. Повторный импорт с тем же <code>plan_id</code> не допускается — если изменилась только дата, используйте перенос выше.</p>
    <form method="post" action="<?= e(url('/plans/' . $plan['id'] . '/delete')) ?>" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <input type="hidden" name="version" value="<?= (int) $plan['version'] ?>">
        <label class="check-row"><input type="checkbox" name="confirm_delete" value="1" required><span>Подтверждаю мягкое удаление плана</span></label>
        <button class="button button-danger" type="submit">Удалить план</button>
    </form>
</details>
<?php else: ?>
<p class="alert">Этот план уже завершён.</p>
<?php endif; ?>
