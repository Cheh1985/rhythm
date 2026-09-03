<?php
$exercise=$analytics['exercise'];
$recordLabels=array_map('t', ['max_weight'=>'Максимальный вес','max_reps_at_weight'=>'Повторы с весом','best_e1rm'=>'Лучший e1RM','exercise_tonnage'=>'Объём упражнения','rep_range_completed'=>'Верх диапазона']);
?>
<section class="page-head"><div><p class="eyebrow">Упражнение · стабильный ID</p><h1><?= e($exercise['name']) ?></h1><p class="stable-id"><code><?= e($exercise['exercise_id']) ?></code></p></div></section>
<?php if($exercise['muscle_groups']): ?><div class="chip-row"><?php foreach($exercise['muscle_groups'] as $group): ?><span class="tag"><?= e(str_replace('_',' ',$group)) ?></span><?php endforeach; ?></div><?php endif; ?>

<?php if($analytics['signals']): ?><section class="signal-list" aria-label="Мягкие аналитические сигналы"><?php foreach($analytics['signals'] as $signal): ?><article class="signal <?= e($signal['kind']) ?>"><strong><?= e($signal['title']) ?></strong><p><?= e($signal['text']) ?></p></article><?php endforeach; ?><p class="muted signal-disclaimer">Сигналы описывают данные и не являются диагнозом или категоричным тренировочным выводом.</p></section><?php endif; ?>

<section class="section-block"><div class="section-title"><h2>Динамика</h2><span class="muted">до 52 записей</span></div><div class="charts-grid"><?= line_chart($analytics['charts']['e1rm'],'e1RM по Epley',' кг') ?><?= line_chart($analytics['charts']['tonnage'],'Tonnage упражнения',' кг') ?></div></section>

<section class="section-block"><div class="section-title"><h2>Личные ориентиры</h2></div><div class="record-list"><?php if(!$analytics['records']): ?><div class="empty-state compact"><p>Пока нет новых PR.</p></div><?php endif; ?><?php foreach($analytics['records'] as $record): ?><a href="<?= e(url('/sessions/'.$record['session_id'])) ?>"><span class="record-icon">◆</span><div><strong><?= e($recordLabels[$record['record_type']]??$record['record_type']) ?></strong><small><?= e(local_datetime($record['achieved_at'],$timezone,'d.m.Y')) ?></small></div><b><?= e(round((float)$record['value_decimal'],2)) ?></b></a><?php endforeach; ?></div></section>

<section class="section-block"><div class="section-title"><h2>Подходы по датам</h2></div><div class="exercise-history">
<?php if(!$analytics['sessions']): ?><div class="empty-state compact"><p>Упражнение ещё не встречалось в завершённых тренировках.</p></div><?php endif; ?>
<?php foreach($analytics['sessions'] as $session): ?><article class="exercise-session card"><div class="exercise-session-head"><div><time><?= e($session['local_date']) ?></time><h3><a href="<?= e(url('/sessions/'.$session['session_id'])) ?>"><?= e($session['workout_name']) ?></a></h3></div><div><strong><?= e(round((float)$session['tonnage'])) ?> <?= e(unit('kg')) ?></strong><small><?= (int)$session['working_sets'] ?> <?= e(unit('sets')) ?> · RIR <?= $session['average_rir']!==null?e($session['average_rir']):'—' ?></small></div></div>
<div class="set-pills"><?php foreach($session['sets'] as $set): ?><span><b><?= e($set['weight_kg']) ?>×<?= (int)$set['reps'] ?></b><small>RIR <?= e($set['rir']) ?></small></span><?php endforeach; ?></div>
<?php if($session['best_set']): ?><p class="best-set">Лучший set: <?= e($session['best_set']['weight_kg']) ?> <?= e(unit('kg')) ?> × <?= (int)$session['best_set']['reps'] ?> · e1RM <?= e($session['best_set']['e1rm_kg']) ?> <?= e(unit('kg')) ?></p><?php endif; ?></article><?php endforeach; ?>
</div></section>
