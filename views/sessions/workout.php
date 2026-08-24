<?php
$statusLabels = ['pending' => 'Ожидает', 'active' => 'В работе', 'waiting' => 'Оборудование занято', 'completed' => 'Готово', 'skipped' => 'Пропущено'];
?>
<div class="workout-page" data-session-id="<?= (int) $session['id'] ?>" data-session-version="<?= (int) $session['version'] ?>">
<section class="workout-head">
    <div><p class="eyebrow"><?= e(date('d.m.Y', strtotime($session['scheduled_date']))) ?></p><h1><?= e($session['name']) ?></h1><small class="autosave-state" role="status">Проверяем синхронизацию…</small><div class="sync-strip"><span data-network-state>Онлайн</span><button type="button" data-sync-retry hidden>Повторить</button></div></div>
    <div class="elapsed"><span>Время</span><strong data-started-at="<?= e(gmdate('c', strtotime($session['started_at'] . ' UTC'))) ?>">00:00</strong></div>
</section>
<div class="progress-line"><span style="width:<?= $session['summary']['total_exercises'] ? round($session['summary']['completed_exercises']/$session['summary']['total_exercises']*100) : 0 ?>%"></span></div>
<p class="progress-copy"><strong id="completed-count"><?= (int) $session['summary']['completed_exercises'] ?></strong> из <span id="total-count"><?= (int) $session['summary']['total_exercises'] ?></span> упражнений</p>

<div class="exercise-stack">
<?php foreach ($session['exercises'] as $exercise):
    $working = array_values(array_filter($exercise['sets'], static fn ($set) => $set['set_type'] === 'working'));
    $warmups = array_values(array_filter($exercise['sets'], static fn ($set) => $set['set_type'] === 'warmup'));
    $lastSet = $exercise['sets'] ? end($exercise['sets']) : null;
    $previous = $exercise['previous_sets'][0] ?? null;
    $prefillWeight = $lastSet['weight_kg'] ?? $previous['weight_kg'] ?? $exercise['planned_weight_kg'] ?? '';
    $prefillReps = $lastSet['reps'] ?? $previous['reps'] ?? $exercise['rep_min'];
?>
<article class="exercise-card <?= e($exercise['status']) ?>" data-exercise-id="<?= (int) $exercise['id'] ?>" data-exercise-version="<?= (int) $exercise['version'] ?>" data-rest="<?= (int) $exercise['rest_seconds'] ?>">
    <header><div><p class="exercise-order">Упражнение <?= (int) $exercise['sequence_no'] ?></p><h2><?= e($exercise['exercise_name']) ?></h2><?php if ($exercise['actual_exercise_id'] !== $exercise['original_exercise_id']): ?><small class="replacement-note">Вместо: <?= e($exercise['original_exercise_name']) ?></small><?php endif; ?></div><span class="exercise-state"><?= e($statusLabels[$exercise['status']] ?? $exercise['status']) ?></span></header>
    <div class="prescription"><span><small>План</small><strong><?= (int) $exercise['planned_sets'] ?> × <?= (int) $exercise['rep_min'] ?>–<?= (int) $exercise['rep_max'] ?></strong></span><span><small>RIR</small><strong><?= e($exercise['target_rir_min']) ?>–<?= e($exercise['target_rir_max']) ?></strong></span><span><small>Отдых</small><strong><?= (int) $exercise['rest_seconds'] ?> сек</strong></span></div>
    <?php if ($exercise['previous_sets']): ?><p class="previous"><span>В прошлый раз</span><?php foreach ($exercise['previous_sets'] as $set): ?><b><?= e($set['weight_kg']) ?>×<?= (int) $set['reps'] ?> · RIR <?= e($set['rir']) ?></b><?php endforeach; ?></p><?php endif; ?>
    <?php if ($exercise['instructions']): ?><details class="instructions"><summary>Инструкция</summary><p><?= nl2br(e($exercise['instructions'])) ?></p></details><?php endif; ?>
    <div class="saved-sets">
    <?php foreach ($exercise['sets'] as $set): ?><div class="saved-set" data-set-id="<?= (int) $set['id'] ?>" data-set-version="<?= (int) $set['version'] ?>" data-weight="<?= e($set['weight_kg']) ?>" data-reps="<?= (int) $set['reps'] ?>" data-rir="<?= e($set['rir']) ?>"><span><?= $set['set_type']==='warmup'?'Р':'П' ?><?= (int) $set['set_number'] ?></span><strong><?= e($set['weight_kg']) ?> кг × <?= (int) $set['reps'] ?></strong><small>RIR <?= e($set['rir']) ?></small><button type="button" data-edit-set aria-label="Изменить подход">Изменить</button></div><?php endforeach; ?>
    </div>
    <?php if (!in_array($exercise['status'], ['completed', 'skipped'], true)): ?>
    <form class="set-entry" data-working-next="<?= count($working) + 1 ?>" data-warmup-next="<?= count($warmups) + 1 ?>">
        <div class="set-type-toggle"><button type="button" data-type="working" class="active">Рабочий</button><button type="button" data-type="warmup">Разминка</button></div>
        <div class="entry-grid">
            <label><span>Вес, кг</span><input class="weight-input" type="number" inputmode="decimal" min="0" max="2000" step="0.5" value="<?= e($prefillWeight) ?>" required></label>
            <label><span>Повторы</span><input class="reps-input" type="number" inputmode="numeric" min="1" max="1000" value="<?= e($prefillReps) ?>" required></label>
        </div>
        <div class="quick-row weight-quick"><button type="button" data-delta="-5">−5</button><button type="button" data-delta="-2.5">−2,5</button><button type="button" data-delta="2.5">+2,5</button><button type="button" data-delta="5">+5</button><button type="button" class="reps-delta" data-delta="-1">−1 повт.</button><button type="button" class="reps-delta" data-delta="1">+1 повт.</button></div>
        <fieldset class="rir-picker"><legend>Повторы в запасе (RIR)</legend><div><?php foreach ([0,1,2,3,4,5] as $rir): ?><button type="button" data-rir="<?= $rir ?>"><?= $rir === 5 ? '5+' : $rir ?></button><?php endforeach; ?></div><input type="hidden" class="rir-input" required></fieldset>
        <button class="button button-primary button-wide save-set" type="submit">Готово · запустить отдых</button>
    </form>
    <div class="exercise-actions"><?php if ($exercise['status'] === 'waiting'): ?><button type="button" data-status="active">Оборудование свободно</button><?php else: ?><button type="button" data-status="waiting">Оборудование занято</button><?php endif; ?><button type="button" data-open-action="skip">Пропустить</button><button type="button" data-open-action="replace">Заменить</button><button type="button" data-open-action="discomfort">Дискомфорт</button><button type="button" data-open-action="complete" class="complete-exercise">Завершить</button></div>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>

<section class="finish-card"><p class="eyebrow">Когда всё готово</p><h2>Завершить тренировку</h2><div class="field-row"><label>Общая тяжесть 1–10<input id="session-rpe" type="number" inputmode="numeric" min="1" max="10" value="7"></label><label>Самочувствие 1–5<input id="session-wellbeing" type="number" inputmode="numeric" min="1" max="5" value="4"></label></div><label>Комментарий<textarea id="session-comment" rows="3" maxlength="5000" placeholder="Что важно учесть в следующий раз?"></textarea></label><button id="finish-workout" class="button button-danger button-wide">Завершить и показать итоги</button></section>
<details class="card danger-zone"><summary>Отменить незавершённую тренировку</summary><p class="muted">Подходы останутся в audit/backup, сессия получит статус «отменена», а план снова станет доступен.</p><form method="post" action="<?= e(url('/sessions/'.$session['id'].'/cancel')) ?>" class="stack-form"><input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>"><input type="hidden" name="version" value="<?= (int)$session['version'] ?>"><label class="check-row"><input type="checkbox" name="confirm_cancel" value="1" required><span>Подтверждаю отмену</span></label><button class="button button-danger">Отменить тренировку</button></form></details>

<aside class="rest-timer" id="rest-timer" hidden><div><small>Отдых</small><strong>02:00</strong><span>Таймер продолжит считать по времени окончания</span></div><div class="timer-actions"><button data-timer="pause">Пауза</button><button data-timer="reset">Сброс</button><button data-timer="add">+30 сек</button><button data-timer="stop">Закончить раньше</button></div></aside>

<dialog id="workout-action-dialog"><form class="dialog-card" id="workout-action-form"><button class="dialog-close" type="button" data-dialog-cancel aria-label="Закрыть">×</button><h2 data-dialog-title></h2><div data-dialog-fields></div><p class="form-message" role="status" hidden></p><div class="dialog-actions"><button class="button button-secondary" type="button" data-dialog-cancel>Отмена</button><button class="button button-primary" type="submit">Сохранить</button></div></form></dialog>
<template id="skip-fields"><label>Причина<select name="reason" required><option value="equipment_busy">Оборудование занято</option><option value="time">Не хватает времени</option><option value="fatigue">Усталость</option><option value="discomfort">Дискомфорт</option><option value="other">Другая</option></select></label><label>Комментарий<textarea name="comment" maxlength="2000"></textarea></label></template>
<template id="complete-fields"><label>Сложность<select name="exercise_rating" required><option value="too_easy">Слишком легко</option><option value="normal" selected>Нормально</option><option value="too_hard">Слишком тяжело</option></select></label><label>Комментарий<textarea name="comment" maxlength="2000"></textarea></label></template>
<template id="replace-fields"><label>Новое упражнение<select name="actual_exercise_id" required><?php foreach ($session['available_exercises'] as $item): ?><option value="<?= e($item['exercise_id']) ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label>Причина замены<textarea name="reason" maxlength="1000" required></textarea></label></template>
<template id="discomfort-fields"><p class="medical-note">Запись помогает вести дневник и не является медицинским выводом.</p><label>Область<input name="body_area" maxlength="120" required placeholder="Например, правое плечо"></label><label>Интенсивность 1–10<input name="intensity" type="number" inputmode="numeric" min="1" max="10" required></label><label>Комментарий<textarea name="comment" maxlength="1000"></textarea></label></template>
<template id="edit-fields"><div class="entry-grid"><label>Вес, кг<input name="weight_kg" type="number" inputmode="decimal" min="0" max="2000" step="0.5" required></label><label>Повторы<input name="reps" type="number" inputmode="numeric" min="1" max="1000" required></label></div><label>RIR<input name="rir" type="number" inputmode="decimal" min="0" max="10" step="0.5" required></label></template>

<div class="conflict-banner" id="conflict-banner" hidden><strong>Есть конфликт с серверной версией</strong><span>Локальное действие сохранено. Свежая серверная версия загружена; выберите явно, как продолжить.</span><div><button class="button button-danger" type="button" data-conflict-retry>Повторить мои данные поверх серверных</button><button class="button button-secondary" type="button" data-conflict-refresh>Обновить экран, сохранив очередь</button></div></div>
</div>
