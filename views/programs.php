<section class="page-head"><div><p class="eyebrow">Ничего не перезаписывается</p><h1>Версии программ</h1><p class="muted">Каждая версия и её шаблоны остаются неизменными.</p></div></section>
<div class="version-list">
<?php if (!$versions): ?><div class="empty-state"><h2>Версий пока нет</h2><p>Они появятся после импорта первого JSON-плана.</p><a class="button button-primary" href="<?= e(url('/plans/import')) ?>">Импортировать план</a></div><?php endif; ?>
<?php $lastProgram = null; foreach ($versions as $version): ?>
    <?php if ($lastProgram !== $version['id']): ?>
        <div class="program-heading">
            <p class="eyebrow"><code><?= e($version['external_program_id']) ?></code></p><h2><?= e($version['name']) ?></h2>
            <?php if ($version['description']): ?><p class="muted"><?= e($version['description']) ?></p><?php endif; ?>
            <?php if ($version['reconciliation_state'] === 'resolved'): ?>
                <p><strong>Активная версия: v<?= (int) $version['active_version_number'] ?></strong></p>
            <?php elseif ($version['reconciliation_state'] === 'ambiguous'): ?>
                <div class="alert alert-error"><strong>Активная версия не определена.</strong> У программы несколько исторических версий; требуется явный reconciliation, автоматический выбор не выполняется.</div>
            <?php elseif ($version['reconciliation_state'] === 'reconcilable'): ?>
                <div class="alert alert-error">Единственная версия ещё не связана как активная. Запустите безопасный reconciliation.</div>
            <?php elseif ($version['reconciliation_state'] === 'invalid_pointer'): ?>
                <div class="alert alert-error">Указатель активной версии повреждён или ссылается не на эту программу. Требуется проверка данных.</div>
            <?php else: ?>
                <p class="muted">У программы пока нет версий.</p>
            <?php endif; ?>
        </div>
        <?php $lastProgram = $version['id']; ?>
    <?php endif; ?>
    <?php if ($version['version_number'] !== null): ?><article class="version-card"><span class="version-badge">v<?= (int) $version['version_number'] ?><?= (int) $version['is_active_version'] === 1 ? ' · активна' : '' ?></span><div><strong><?= e(date('d.m.Y H:i', strtotime($version['version_created']))) ?></strong><p><?= e($version['change_reason']) ?></p><small><?= $version['parent_version'] ? 'Основана на v' . (int) $version['parent_version'] : 'Первая версия' ?> · <?= e((string) $version['lifecycle_status']) ?> · <?= (int) $version['template_count'] ?> шабл. · <?= (int) $version['plan_count'] ?> план.</small><?php if ($version['trainer_comment']): ?><small class="trainer-comment"><?= e($version['trainer_comment']) ?></small><?php endif; ?></div></article><?php endif; ?>
<?php endforeach; ?>
</div>
