<?php
$statusLabels = array_map('t', ['pending' => 'Ожидает', 'active' => 'В работе', 'waiting' => 'Оборудование занято', 'completed' => 'Готово', 'skipped' => 'Пропущено']);
$exerciseIcon = static function (string $exerciseId): string {
    if (!preg_match('/^[a-z0-9_]{1,80}$/D', $exerciseId)) return 'generic.svg';
    $filename = $exerciseId . '.svg';
    return is_file(APP_ROOT . '/public/assets/exercises/' . $filename) ? $filename : 'generic.svg';
};
?>
<?php if ($error ?? null): ?><div class="alert alert-error"><?= te($error) ?></div><?php endif; ?>
<?php if ($success ?? null): ?><div class="alert alert-success"><?= te($success) ?></div><?php endif; ?>
<div class="workout-page" data-session-id="<?= (int) $session['id'] ?>" data-session-version="<?= (int) $session['version'] ?>" data-icon-base="<?= e(url('/assets/exercises/')) ?>">
<section class="workout-head">
    <div><p class="eyebrow"><?= e(local_date($session['scheduled_date'])) ?></p><h1><?= e($session['name']) ?></h1><small class="autosave-state" role="status">Проверяем синхронизацию…</small><div class="sync-strip"><span data-network-state>Онлайн</span><button type="button" data-sync-retry hidden>Повторить</button></div></div>
    <div class="elapsed"><span>Время</span><strong data-active-seconds="<?= (int) ($session['active_duration_seconds'] ?? 0) ?>" data-segment-started-at="<?= e(gmdate('c', strtotime(($session['active_segment_started_at'] ?? $session['started_at']) . ' UTC'))) ?>">00:00</strong></div>
</section>
<section class="workout-progress" aria-label="Общий прогресс тренировки">
    <div class="workout-progress-copy">
        <span>Прогресс</span>
        <p class="progress-copy" aria-live="polite" aria-atomic="true"><strong id="completed-count"><?= (int) $session['summary']['completed_exercises'] ?></strong> из <span id="total-count"><?= (int) $session['summary']['total_exercises'] ?></span> упражнений</p>
    </div>
    <div class="progress-line" role="progressbar" aria-label="Завершено упражнений" aria-valuemin="0" aria-valuemax="<?= (int) $session['summary']['total_exercises'] ?>" aria-valuenow="<?= (int) $session['summary']['completed_exercises'] ?>" aria-valuetext="<?= (int) $session['summary']['completed_exercises'] ?> из <?= (int) $session['summary']['total_exercises'] ?> упражнений"><span style="width:<?= $session['summary']['total_exercises'] ? round($session['summary']['completed_exercises']/$session['summary']['total_exercises']*100) : 0 ?>%"></span></div>
</section>

<div class="exercise-stack">
<?php foreach ($session['exercises'] as $exercise):
    $working = array_values(array_filter($exercise['sets'], static fn ($set) => $set['set_type'] === 'working'));
    $warmups = array_values(array_filter($exercise['sets'], static fn ($set) => $set['set_type'] === 'warmup'));
    $lastSet = $exercise['sets'] ? end($exercise['sets']) : null;
    $historySessions = $exercise['history_sessions'];
    $previousSession = $historySessions ? end($historySessions) : null;
    $previous = $previousSession && $previousSession['sets'] ? end($previousSession['sets']) : null;
    $prefillWeight = $lastSet['weight_kg'] ?? $previous['weight_kg'] ?? $exercise['planned_weight_kg'] ?? '';
    $weightUnit = $exercise['weight_unit'] ?? 'kg';
    $prefillWeight = $prefillWeight === '' ? '' : \App\Domain\Weight::fromKg((float) $prefillWeight, $weightUnit);
    $weightStep = $weightUnit === 'lb' ? 5 : 0.5;
    $prefillReps = $lastSet['reps'] ?? $previous['reps'] ?? $exercise['rep_min'];
    $bodyId = 'exercise-body-' . (int) $exercise['id'];
    $iconFile = $exerciseIcon((string) $exercise['actual_exercise_id']);
    $historyJson = json_encode($historySessions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<article class="exercise-card <?= e($exercise['status']) ?>" data-exercise-id="<?= (int) $exercise['id'] ?>" data-actual-exercise-id="<?= e($exercise['actual_exercise_id']) ?>" data-weight-unit="<?= e($weightUnit) ?>" data-exercise-version="<?= (int) $exercise['version'] ?>" data-planned-kg="<?= e($exercise['planned_weight_kg'] ?? '') ?>" data-rest="<?= (int) $exercise['rest_seconds'] ?>">
    <header class="exercise-card-head">
        <img class="exercise-icon" src="<?= e(url('/assets/exercises/' . $iconFile)) ?>" alt="<?= e(t('Пиктограмма упражнения') . ' ' . $exercise['exercise_name']) ?>" width="96" height="96">
        <div class="exercise-heading"><p class="exercise-order">Упражнение <?= (int) $exercise['sequence_no'] ?></p><h2><?= e($exercise['exercise_name']) ?></h2><?php if ($exercise['actual_exercise_id'] !== $exercise['original_exercise_id']): ?><small class="replacement-note">Вместо: <?= e($exercise['original_exercise_name']) ?></small><?php endif; ?></div>
        <span class="exercise-state"><?= e($statusLabels[$exercise['status']] ?? $exercise['status']) ?></span>
        <button class="exercise-toggle" type="button" aria-expanded="false" aria-controls="<?= e($bodyId) ?>" aria-label="<?= e(t('Развернуть упражнение') . ' ' . $exercise['exercise_name']) ?>"><span aria-hidden="true"></span></button>
        <div class="prescription"><?php if ($exercise['planned_weight_kg'] !== null): ?><span><small>Вес</small><strong data-planned-weight><?= e(\App\Domain\Weight::fromKg((float) $exercise['planned_weight_kg'], $weightUnit)) ?> <?= e(unit($weightUnit)) ?></strong></span><?php endif; ?><span><small>План</small><strong><?= (int) $exercise['planned_sets'] ?> × <?= (int) $exercise['rep_min'] ?>–<?= (int) $exercise['rep_max'] ?></strong></span><span><small>RIR</small><strong><?= e($exercise['target_rir_min']) ?>–<?= e($exercise['target_rir_max']) ?></strong></span></div>
        <p class="rest-prescription"><span>Отдых</span> <strong><?= (int) $exercise['rest_seconds'] ?> сек</strong></p>
    </header>
    <div class="exercise-card-body" id="<?= e($bodyId) ?>" hidden>
    <section class="history-panel" data-history-chart aria-labelledby="history-title-<?= (int) $exercise['id'] ?>">
        <div class="history-head"><h3 id="history-title-<?= (int) $exercise['id'] ?>">Прошлые тренировки</h3><div class="history-navigation"><?php if ($historySessions): ?><button type="button" data-history-previous aria-label="Предыдущие даты">‹</button><button type="button" data-history-next aria-label="Следующие даты">›</button><?php endif; ?></div></div>
        <p class="history-summary sr-only"></p>
        <?php if ($historySessions): ?>
            <div class="history-dates" aria-label="Выбор даты тренировки"></div>
            <div class="history-canvas"></div>
            <p class="history-detail" role="status" aria-live="polite">Нажмите на подход, чтобы увидеть точные значения.</p>
            <div class="history-legend" aria-label="Легенда цветов"><span class="below">Ниже RIR</span><span class="within">В цели</span><span class="above">Выше RIR</span><span class="unknown">Цель неизвестна</span></div>
        <?php else: ?><p class="history-empty">Завершённых тренировок с этим упражнением пока нет.</p><?php endif; ?>
        <script type="application/json" class="history-data"><?= $historyJson ?: '[]' ?></script>
    </section>
    <?php if ($exercise['instructions']): ?><details class="instructions"><summary>Инструкция</summary><p><?= nl2br(e($exercise['instructions'])) ?></p></details><?php endif; ?>
    <div class="saved-sets">
    <?php foreach ($exercise['sets'] as $set): ?><div class="saved-set" data-set-id="<?= (int) $set['id'] ?>" data-set-version="<?= (int) $set['version'] ?>" data-weight="<?= e($set['weight_value'] ?? $set['weight_kg']) ?>" data-weight-unit="<?= e($set['weight_unit'] ?? 'kg') ?>" data-set-type="<?= e($set['set_type']) ?>" data-reps="<?= (int) $set['reps'] ?>" data-rir="<?= e($set['rir']) ?>"><span><?= $set['set_type']==='warmup'?(locale()==='en'?'W':'Р'):(locale()==='en'?'S':'П') ?><?= (int) $set['set_number'] ?></span><strong><?= e(\App\Domain\Weight::text($set)) ?> × <?= (int) $set['reps'] ?></strong><small>RIR <?= e($set['rir']) ?></small><button type="button" data-edit-set aria-label="Изменить подход">Изменить</button></div><?php endforeach; ?>
    </div>
    <?php if (!in_array($exercise['status'], ['completed', 'skipped'], true)): ?>
    <form class="set-entry" data-working-next="<?= count($working) + 1 ?>" data-warmup-next="<?= count($warmups) + 1 ?>">
        <div class="set-type-toggle"><button type="button" data-type="working" class="active">Рабочий</button><button type="button" data-type="warmup">Разминка</button></div>
        <div class="wheel-inputs" role="group" aria-label="Параметры подхода">
            <section class="workout-wheel" data-workout-wheel data-wheel-kind="weight" data-min="0" data-max="2000" data-step="<?= e($weightStep) ?>" data-wheel-unit="<?= e(unit($weightUnit)) ?>">
                <div class="wheel-label"><span id="weight-label-<?= (int) $exercise['id'] ?>">Вес</span><select class="weight-unit" aria-label="Единица веса"><option value="kg" <?= $weightUnit === 'kg' ? 'selected' : '' ?>><?= e(unit('kg')) ?></option><option value="lb" <?= $weightUnit === 'lb' ? 'selected' : '' ?>>lb</option></select></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="-1" aria-label="Уменьшить вес">−</button>
                <div class="wheel-window"><div class="wheel-values" data-wheel-values></div><button class="wheel-selected" type="button" role="spinbutton" aria-labelledby="weight-label-<?= (int) $exercise['id'] ?>" aria-label="Ввести вес вручную" data-wheel-selected></button><input class="weight-input wheel-manual-input" data-wheel-input aria-label="Вес" type="number" inputmode="decimal" min="0" max="2000" step="0.01" value="<?= e($prefillWeight) ?>" required hidden></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="1" aria-label="Увеличить вес">+</button>
            </section>
            <section class="workout-wheel" data-workout-wheel data-wheel-kind="reps" data-min="1" data-max="1000" data-step="1">
                <div class="wheel-label"><span id="reps-label-<?= (int) $exercise['id'] ?>">Повторы</span></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="-1" aria-label="Уменьшить повторы">−</button>
                <div class="wheel-window"><div class="wheel-values" data-wheel-values></div><button class="wheel-selected" type="button" role="spinbutton" aria-labelledby="reps-label-<?= (int) $exercise['id'] ?>" aria-label="Ввести повторы вручную" data-wheel-selected></button><input class="reps-input wheel-manual-input" data-wheel-input aria-label="Повторы" type="number" inputmode="numeric" min="1" max="1000" step="1" value="<?= e($prefillReps) ?>" required hidden></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="1" aria-label="Увеличить повторы">+</button>
            </section>
            <section class="workout-wheel" data-workout-wheel data-wheel-kind="rir" data-min="0" data-max="10" data-step="0.5" data-allow-empty="true">
                <div class="wheel-label"><span id="rir-label-<?= (int) $exercise['id'] ?>">RIR</span></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="-1" aria-label="Уменьшить RIR">−</button>
                <div class="wheel-window"><div class="wheel-values" data-wheel-values></div><button class="wheel-selected" type="button" role="spinbutton" aria-labelledby="rir-label-<?= (int) $exercise['id'] ?>" aria-label="Ввести RIR вручную" data-wheel-selected></button><input class="rir-input wheel-manual-input" data-wheel-input aria-label="RIR" type="number" inputmode="decimal" min="0" max="10" step="0.5" hidden></div>
                <button class="wheel-adjust" type="button" data-wheel-adjust="1" aria-label="Увеличить RIR">+</button>
                <span class="wheel-error">Выберите значение</span>
            </section>
        </div>
        <button class="button button-primary button-wide save-set" type="submit">Готово · запустить отдых</button>
    </form>
    <div class="exercise-actions"><?php if ($exercise['status'] === 'waiting'): ?><button type="button" data-status="active">Оборудование свободно</button><?php else: ?><button type="button" data-status="waiting">Оборудование занято</button><?php endif; ?><button type="button" data-open-action="skip">Пропустить</button><button type="button" data-open-action="replace">Заменить</button><button type="button" data-open-action="discomfort">Дискомфорт</button><button type="button" data-open-action="complete" class="complete-exercise">Завершить</button></div>
    <?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
</div>

<section class="finish-card"><p class="eyebrow">Когда всё готово</p><h2>Завершить тренировку</h2><div class="field-row"><label>Общая тяжесть 1–10<input id="session-rpe" type="number" inputmode="numeric" min="1" max="10" value="<?= (int) ($session['session_rpe'] ?? 7) ?>"></label><label>Самочувствие 1–5<input id="session-wellbeing" type="number" inputmode="numeric" min="1" max="5" value="<?= (int) ($session['wellbeing'] ?? 4) ?>"></label></div><label>Комментарий<textarea id="session-comment" rows="3" maxlength="5000" placeholder="Что важно учесть в следующий раз?"><?= e($session['user_comment'] ?? '') ?></textarea></label><button id="finish-workout" class="button button-danger button-wide">Завершить и показать итоги</button></section>
<details class="card danger-zone"><summary>Отменить незавершённую тренировку</summary><p class="muted">Подходы останутся в audit/backup, сессия получит статус «отменена», а план снова станет доступен.</p><form method="post" action="<?= e(url('/sessions/'.$session['id'].'/cancel')) ?>" class="stack-form"><input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>"><input type="hidden" name="version" value="<?= (int)$session['version'] ?>"><label class="check-row"><input type="checkbox" name="confirm_cancel" value="1" required><span>Подтверждаю отмену</span></label><button class="button button-danger">Отменить тренировку</button></form></details>

<aside class="rest-timer" id="rest-timer" aria-live="polite" hidden><div><small>Отдых</small><strong>02:00</strong><span data-timer-status>Таймер продолжит считать по времени окончания</span></div><div class="timer-actions"><button data-timer="pause">Пауза</button><button data-timer="reset">Сброс</button><button data-timer="add">+30 сек</button><button data-timer="stop">Закончить раньше</button></div></aside>

<dialog id="workout-action-dialog"><form class="dialog-card" id="workout-action-form"><button class="dialog-close" type="button" data-dialog-cancel aria-label="Закрыть">×</button><h2 data-dialog-title></h2><div data-dialog-fields></div><p class="form-message" role="status" hidden></p><div class="dialog-actions"><button class="button button-secondary" type="button" data-dialog-cancel>Отмена</button><button class="button button-primary" type="submit">Сохранить</button></div></form></dialog>
<template id="skip-fields"><label>Причина<select name="reason" required><option value="equipment_busy">Оборудование занято</option><option value="time">Не хватает времени</option><option value="fatigue">Усталость</option><option value="discomfort">Дискомфорт</option><option value="other">Другая</option></select></label><label>Комментарий<textarea name="comment" maxlength="2000"></textarea></label></template>
<template id="complete-fields"><label>Сложность<select name="exercise_rating" required><option value="too_easy">Слишком легко</option><option value="normal" selected>Нормально</option><option value="too_hard">Слишком тяжело</option></select></label><label>Комментарий<textarea name="comment" maxlength="2000"></textarea></label></template>
<template id="replace-fields"><label>Новое упражнение<select name="actual_exercise_id" required><?php foreach ($session['available_exercises'] as $item): ?><option data-weight-unit="<?= e($item['weight_unit']) ?>" value="<?= e($item['exercise_id']) ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label>Причина замены<textarea name="reason" maxlength="1000" required></textarea></label></template>
<template id="discomfort-fields"><p class="medical-note">Запись помогает вести дневник и не является медицинским выводом.</p><label>Область<input name="body_area" maxlength="120" required placeholder="Например, правое плечо"></label><label>Интенсивность 1–10<input name="intensity" type="number" inputmode="numeric" min="1" max="10" required></label><label>Комментарий<textarea name="comment" maxlength="1000"></textarea></label></template>
<template id="edit-fields"><div class="entry-grid"><label>Вес <select name="weight_unit" aria-label="Единица веса"><option value="kg"><?= e(unit('kg')) ?></option><option value="lb">lb</option></select><input name="weight_value" aria-label="Вес" type="number" inputmode="decimal" min="0" max="2000" step="0.01" required></label><label>Повторы<input name="reps" type="number" inputmode="numeric" min="1" max="1000" required></label></div><label>RIR<input name="rir" type="number" inputmode="decimal" min="0" max="10" step="0.5" required></label></template>

<div class="conflict-banner" id="conflict-banner" hidden><strong>Есть конфликт с серверной версией</strong><span>Локальное действие сохранено. Свежая серверная версия загружена; выберите явно, как продолжить.</span><div><button class="button button-danger" type="button" data-conflict-retry>Повторить мои данные поверх серверных</button><button class="button button-secondary" type="button" data-conflict-refresh>Обновить экран, сохранив очередь</button></div></div>
</div>
