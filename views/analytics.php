<?php
$current = $analytics['current'];
$muscleLabels = array_map('t', ['quadriceps'=>'Квадрицепс','glutes'=>'Ягодичные','chest'=>'Грудь','upper_chest'=>'Верх груди','triceps'=>'Трицепс','front_delts'=>'Передняя дельта','lats'=>'Широчайшие','biceps'=>'Бицепс','upper_back'=>'Верх спины','hamstrings'=>'Задняя поверхность бедра','delts'=>'Дельты','lower_back'=>'Низ спины','calves'=>'Икры','side_delts'=>'Средняя дельта','rear_delts'=>'Задняя дельта','legs'=>'Ноги','arms'=>'Руки','shoulders'=>'Плечи','back'=>'Спина','not_set'=>'Не указано']);
$recordLabels = array_map('t', ['max_weight'=>'Максимальный вес','max_reps_at_weight'=>'Повторы с весом','best_e1rm'=>'Лучший e1RM','exercise_tonnage'=>'Объём упражнения','session_tonnage'=>'Объём тренировки','rep_range_completed'=>'Верх диапазона']);
$chart = static fn(string $key,string $title,string $unit='') => line_chart(array_map(static fn(array $week):array=>['label'=>$week['label'],'value'=>$week[$key]],$analytics['weeks']),$title,$unit);
?>
<section class="page-head"><div><p class="eyebrow">12 недель · <?= e($analytics['timezone']) ?></p><h1>Аналитика</h1><p class="muted">Текущая неделя начинается в понедельник по вашему часовому поясу.</p></div></section>

<div class="metric-grid analytics-metrics">
    <article class="metric-card"><span>Тренировки</span><strong><?= (int)$current['workouts'] ?></strong><small>за неделю</small></article>
    <article class="metric-card"><span>Working sets</span><strong><?= (int)$current['working_sets'] ?></strong><small>рабочих</small></article>
    <article class="metric-card"><span>Tonnage</span><strong><?= e(round((float)$current['tonnage'])) ?></strong><small>кг</small></article>
    <article class="metric-card"><span>Средний RIR</span><strong><?= $current['average_rir']!==null?e($current['average_rir']):'—' ?></strong><small>по working</small></article>
    <article class="metric-card"><span>Длительность</span><strong><?= (int)$current['duration_minutes'] ?></strong><small>минут</small></article>
</div>

<section class="section-block"><div class="section-title"><h2>По неделям</h2></div><div class="charts-grid">
    <?= $chart('workouts','Тренировки') ?><?= $chart('working_sets','Рабочие подходы') ?><?= $chart('tonnage','Tonnage',' кг') ?><?= $chart('average_rir','Средний RIR') ?><?= $chart('duration_minutes','Длительность',' мин') ?>
</div></section>

<section class="section-block"><div class="section-title"><h2>Подходы по мышцам</h2><span class="muted">текущая неделя</span></div><div class="bar-list">
<?php if(!$analytics['sets_by_muscle']): ?><div class="empty-state compact"><p>На этой неделе ещё нет рабочих подходов.</p></div><?php endif; ?>
<?php $muscleMax=max([1,...array_values($analytics['sets_by_muscle'])]); foreach($analytics['sets_by_muscle'] as $muscle=>$sets): ?><div class="bar-row"><div><strong><?= e($muscleLabels[$muscle]??str_replace('_',' ',$muscle)) ?></strong><span><?= (int)$sets ?></span></div><progress max="<?= (int)$muscleMax ?>" value="<?= (int)$sets ?>"><?= (int)$sets ?></progress></div><?php endforeach; ?>
</div></section>

<section class="section-block"><div class="section-title"><h2>Недавние PR</h2></div><div class="record-list">
<?php if(!$analytics['records']): ?><div class="empty-state compact"><p>Новые ориентиры появятся после завершённых тренировок.</p></div><?php endif; ?>
<?php foreach($analytics['records'] as $record): ?><a href="<?= e(url('/sessions/'.$record['session_id'])) ?>"><span class="record-icon">◆</span><div><strong><?= e($recordLabels[$record['record_type']]??$record['record_type']) ?></strong><small><?= !empty($record['exercise_name']) ? e($record['exercise_name']) : te('Вся тренировка') ?> · <?= e(local_datetime($record['achieved_at'],$analytics['timezone'],'d.m.Y')) ?></small></div><b><?= e(round((float)$record['value_decimal'],2)) ?></b></a><?php endforeach; ?>
</div></section>
