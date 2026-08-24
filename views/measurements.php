<section class="page-head"><div><p class="eyebrow">Долгая динамика</p><h1>Прогресс тела</h1><p class="muted">Графики описывают записи и не интерпретируют состояние здоровья.</p></div></section>
<?php if($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<details class="form-disclosure"><summary class="button button-primary">＋ Новое измерение</summary>
    <form method="post" action="<?= e(url('/measurements')) ?>" class="stack-form card">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <label>Дата<input type="date" name="measured_on" value="<?= e(date('Y-m-d')) ?>" required></label>
        <div class="field-grid"><?php foreach(['weight_kg'=>'Вес, кг','waist_cm'=>'Талия, см','chest_cm'=>'Грудь, см','shoulders_cm'=>'Плечи, см','biceps_left_cm'=>'Бицепс лев., см','biceps_right_cm'=>'Бицепс прав., см','thigh_cm'=>'Бедро, см','calf_cm'=>'Икра, см','body_fat_percent'=>'Жир, %'] as $name=>$label): ?><label><?= e($label) ?><input type="number" name="<?= e($name) ?>" step="0.1" min="0.1" <?= $name==='body_fat_percent'?'max="100"':'max="500"' ?> inputmode="decimal"></label><?php endforeach; ?></div>
        <label>Комментарий<textarea name="comment" maxlength="2000"></textarea></label>
        <button class="button button-primary">Добавить</button>
    </form>
</details>

<section class="section-block"><div class="section-title"><h2>Графики измерений</h2></div><div class="charts-grid"><?php foreach($charts as $chart): ?><?= line_chart($chart['points'],$chart['title'],$chart['unit']) ?><?php endforeach; ?></div></section>

<section class="section-block"><div class="section-title"><h2>Записи</h2><span class="muted"><?= count($items) ?></span></div><div class="data-list">
<?php if(!$items): ?><div class="empty-state compact"><p>Добавьте первое измерение — пустые поля можно не заполнять.</p></div><?php endif; ?>
<?php foreach($items as $item): ?><article><time><?= e(date('d.m.Y',strtotime($item['measured_on']))) ?></time><strong><?= $item['weight_kg']!==null?e($item['weight_kg']).' кг':'Измерение' ?></strong><p><?php $parts=[]; foreach(['waist_cm'=>'талия','chest_cm'=>'грудь','shoulders_cm'=>'плечи','biceps_left_cm'=>'бицепс Л','biceps_right_cm'=>'бицепс П','thigh_cm'=>'бедро','calf_cm'=>'икра','body_fat_percent'=>'жир'] as $key=>$label) if($item[$key]!==null) $parts[]=e($label).' '.e($item[$key]).($key==='body_fat_percent'?'%':' см'); echo implode(' · ',$parts); ?></p><?php if($item['comment']): ?><small><?= e($item['comment']) ?></small><?php endif; ?><details class="danger-zone"><summary>Удалить</summary><form method="post" action="<?= e(url('/measurements/'.$item['id'].'/delete')) ?>"><input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>"><label class="check-row"><input type="checkbox" name="confirm_delete" value="1" required><span>Подтверждаю мягкое удаление</span></label><button class="button button-danger">Удалить запись</button></form></details></article><?php endforeach; ?>
</div></section>
