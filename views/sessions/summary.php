<?php
$summary = $report['summary'];
$comparison = $summary['comparison_with_previous'];
$csrf = \App\Core\Csrf::token();
$statusLabels = array_map('t', ['completed' => 'Выполнено', 'skipped' => 'Пропущено', 'active' => 'В работе', 'pending' => 'Не начато', 'waiting' => 'Ожидание']);
?>
<?php if ($error): ?><div class="alert alert-error"><?= te($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= te($success) ?></div><?php endif; ?>
<section class="summary-hero"><span class="summary-check">✓</span><p class="eyebrow">Тренировка завершена</p><h1><?= e($session['name']) ?></h1><p><?= e(local_date($session['scheduled_date'])) ?> · <?= (int) $report['session']['duration_minutes'] ?> <?= e(unit('min')) ?>.</p></section>
<div class="summary-grid">
    <article><span>Объём</span><strong><?= e($summary['tonnage_kg']) ?></strong><small>кг, только working</small></article>
    <article><span>Рабочих</span><strong><?= (int) $summary['working_sets'] ?></strong><small>подходов</small></article>
    <article><span>Средний RIR</span><strong><?= e($summary['average_rir'] ?? '—') ?></strong><small>в запасе</small></article>
    <article><span>Выполнено</span><strong><?= (int) $summary['completed_exercises'] ?>/<?= (int) $summary['total_exercises'] ?></strong><small>пропущено: <?= (int) $summary['skipped_exercises'] ?></small></article>
</div>
<?php if ($comparison): ?>
<section class="card comparison-card"><p class="eyebrow">К прошлой такой тренировке</p><div class="summary-grid compact"><article><span>Объём</span><strong><?= $comparison['tonnage_kg_delta'] > 0 ? '+' : '' ?><?= e($comparison['tonnage_kg_delta']) ?></strong><small>кг</small></article><article><span>Рабочие</span><strong><?= $comparison['working_sets_delta'] > 0 ? '+' : '' ?><?= e($comparison['working_sets_delta']) ?></strong><small>подходов</small></article></div></section>
<?php endif; ?>
<?php if ($summary['personal_records']): ?><div class="alert pr-note"><strong>Новый ориентир e1RM</strong><span>Расчёт Epley — аналитическая оценка, а не фактический максимум.</span></div><?php endif; ?>

<section class="section-block"><div class="section-title"><h2>Упражнения</h2></div><div class="result-list">
<?php foreach ($report['exercises'] as $index => $exercise): $fact = $exercise['fact']; ?>
    <article class="result-detail">
        <div><strong><?= e($exercise['name']) ?></strong><small><?= e($statusLabels[$fact['status']] ?? $fact['status']) ?> · <?= (int) $fact['working_sets'] ?> <?= e(unit('sets')) ?> · <?= e($fact['tonnage_kg']) ?> <?= e(unit('kg')) ?><?php if ($fact['best_e1rm']): ?> · e1RM <?= e($fact['best_e1rm']['e1rm_kg']) ?> <?= e(unit('kg')) ?><?php endif; ?></small></div>
        <span><?php foreach ($fact['sets'] as $set): if ($set['type'] === 'working'): ?><?= e($set['weight_kg']) ?>×<?= (int) $set['reps'] ?> <small>RIR <?= e($set['rir']) ?></small> <?php endif; endforeach; ?></span>
        <?php if ($fact['personal_records']): ?><em class="pr-badge">PR e1RM</em><?php endif; ?>
    </article>
    <?php if ($exercise['suggestion']): $suggestion = $exercise['suggestion']; ?>
    <div class="progression-card"><strong>Предложение: <?= e($suggestion['current_weight_kg']) ?> → <?= e($suggestion['suggested_weight_kg']) ?> <?= e(unit('kg')) ?></strong><small><?= e(system_message($suggestion['reason'])) ?> Программа не изменена.</small>
        <?php if ($suggestion['status'] === 'pending'): ?><form method="post" action="<?= e(url('/progression/' . $suggestion['id'])) ?>" class="inline-edit"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>"><label>Принять вес<input type="number" name="accepted_weight_kg" min="0.01" max="2000" step="0.01" value="<?= e($suggestion['suggested_weight_kg']) ?>"></label><button class="button button-primary" name="status" value="accepted">Принять</button><button class="button button-secondary" name="status" value="rejected">Отклонить</button></form><?php else: ?><small>Статус: <?= e(system_label('status', $suggestion['status'])) ?><?php if ($suggestion['accepted_weight_kg'] !== null): ?>, принято <?= e($suggestion['accepted_weight_kg']) ?> <?= e(unit('kg')) ?><?php endif; ?>.</small><?php endif; ?>
    </div>
    <?php endif; ?>
<?php if ($fact['sets']): ?><details class="card edit-completed"><summary>Исправить подходы</summary><?php foreach ($session['exercises'][$index]['sets'] as $set): ?><form method="post" action="<?= e(url('/sets/' . $set['id'] . '/edit')) ?>" class="inline-edit"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>"><input type="hidden" name="session_version" value="<?= (int) $session['version'] ?>"><input type="hidden" name="version" value="<?= (int) $set['version'] ?>"><label>Вес<input type="number" name="weight_kg" min="0" max="2000" step="0.01" value="<?= e($set['weight_kg']) ?>"></label><label>Повторы<input type="number" name="reps" min="1" max="1000" value="<?= (int) $set['reps'] ?>"></label><label>RIR<input type="number" name="rir" min="0" max="10" step="0.5" value="<?= e($set['rir']) ?>"></label><button class="button button-secondary">Сохранить</button></form><form method="post" action="<?= e(url('/sets/'.$set['id'].'/delete')) ?>" class="inline-delete"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>"><input type="hidden" name="session_version" value="<?= (int)$session['version'] ?>"><input type="hidden" name="version" value="<?= (int)$set['version'] ?>"><label class="check-row"><input type="checkbox" name="confirm_delete" value="1" required><span>Подтверждаю мягкое удаление</span></label><button class="button button-danger">Удалить подход</button></form><?php endforeach; ?></details><?php endif; ?>
<?php endforeach; ?>
</div></section>

<details class="card edit-completed"><summary>Исправить итоговую оценку и комментарий</summary><form method="post" action="<?= e(url('/sessions/' . $session['id'] . '/edit')) ?>" class="stack-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="session_version" value="<?= (int) $session['version'] ?>"><div class="field-row"><label>Тяжесть 1–10<input type="number" name="session_rpe" min="1" max="10" value="<?= (int) $session['session_rpe'] ?>" required></label><label>Самочувствие 1–5<input type="number" name="wellbeing" min="1" max="5" value="<?= (int) $session['wellbeing'] ?>" required></label></div><label>Комментарий<textarea name="comment" maxlength="5000"><?= e($session['user_comment'] ?? '') ?></textarea></label><button class="button button-primary">Сохранить правку</button></form></details>
<?php if ($report['session']['edited_after_completion']): ?><p class="muted-note">Отредактировано после завершения: <?= e($report['session']['edited_at_utc']) ?>. История правок включена в JSON.</p><?php endif; ?>
<section class="export-card"><p class="eyebrow">Для прямой вставки в ChatGPT</p><h2>Скачать отчёт</h2><div class="export-buttons"><a class="button button-primary" href="<?= e(url('/export/session/'.$session['id'].'.json')) ?>">JSON</a><a class="button button-secondary" href="<?= e(url('/export/session/'.$session['id'].'.md')) ?>">Markdown</a><a class="button button-secondary" href="<?= e(url('/export/session/'.$session['id'].'.zip')) ?>">Оба файла</a></div></section>
