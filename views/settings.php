<?php $csrf=\App\Core\Csrf::token(); ?>
<section class="page-head"><div><p class="eyebrow">Production polish</p><h1>Настройки и данные</h1><p class="muted">Тема, полная резервная копия и безопасное восстановление.</p></div></section>
<?php if($error): ?><div class="alert alert-error" role="alert"><?= te($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success" role="status"><?= te($success) ?></div><?php endif; ?>

<section class="card settings-card"><h2>Нужна помощь?</h2><p class="muted">Откройте короткую инструкцию: от первого плана до итогов тренировки.</p><a class="button button-quiet" href="<?= e(url('/help')) ?>">Как пользоваться</a></section>

<section class="card settings-card section-block"><h2>Тема</h2><form method="post" action="<?= e(url('/settings/theme')) ?>" class="stack-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Оформление<select name="theme"><option value="system" <?= $user['theme']==='system'?'selected':'' ?>>Как в системе</option><option value="light" <?= $user['theme']==='light'?'selected':'' ?>>Светлое</option><option value="dark" <?= $user['theme']==='dark'?'selected':'' ?>>Тёмное</option></select></label><button class="button button-primary">Сохранить тему</button></form></section>

<section class="card settings-card section-block"><h2>Язык</h2><p class="muted">Язык интерфейса сохраняется в аккаунте и применяется на всех устройствах после входа.</p><?php require APP_ROOT . '/views/partials/language-switch.php'; ?></section>

<section class="card settings-card section-block" data-push-settings>
    <h2>Уведомления об отдыхе</h2>
    <p class="muted">После окончания таймера устройство покажет: «Отдых завершён — пора к следующему подходу». Разрешение запрашивается только по кнопке и действует на этом устройстве.</p>
    <p class="push-status" data-push-status role="status">Проверяем поддержку уведомлений…</p>
    <div class="button-row"><button type="button" class="button button-primary" data-push-enable disabled>Включить уведомления</button><button type="button" class="button button-quiet" data-push-disable hidden>Отключить на этом устройстве</button></div>
    <small class="muted">На iPhone требуется iOS 16.4 или новее и приложение «Ритм», добавленное на экран «Домой». Доставка зависит от сети, режима фокусирования и системных настроек.</small>
</section>

<section class="card settings-card section-block"><h2>Резервная копия</h2><p class="muted">Экспорт v1.4 содержит полную историю текущего пользователя, версии программ, их расписание, активное время тренировок и события отдыха — без пароля, сессий входа и technical assistant audit. JSON подписан SHA-256 checksum; restore также читает v1.0–v1.3.</p><div class="button-row"><a class="button button-primary" href="<?= e(url('/backup')) ?>">Скачать JSON</a><a class="button button-quiet" href="<?= e(url('/backup?format=zip')) ?>">Скачать ZIP</a></div></section>

<section class="card settings-card section-block"><h2>Восстановление</h2><p class="muted">Сначала файл строго проверяется и показывается превью. Restore работает транзакционно: только merge, без перезаписи и удаления существующих данных; внутренние ID переназначаются.</p>
<?php if(!$preview): ?><form method="post" action="<?= e(url('/restore/preview')) ?>" enctype="multipart/form-data" class="stack-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label>Backup JSON или ZIP<input type="file" name="backup" accept=".json,.zip,application/json,application/zip" required></label><button class="button button-primary">Проверить и показать превью</button></form>
<?php else: ?><div class="restore-preview"><p><strong><?= e($preview['backup_id']) ?></strong></p><p>Экспорт: <?= e($preview['exported_at_utc']) ?> · записей: <?= (int)$preview['total_rows'] ?></p><p class="stable-id"><code><?= e($preview['checksum_sha256']) ?></code></p><?php if($preview['already_restored']): ?><div class="alert">Эта контрольная сумма уже восстанавливалась. Повтор будет идемпотентным.</div><?php endif; ?><details><summary>Количество по секциям</summary><ul><?php foreach($preview['counts'] as $table=>$count): ?><li><code><?= e($table) ?></code>: <?= (int)$count ?></li><?php endforeach; ?></ul></details></div>
<form method="post" action="<?= e(url('/restore/confirm')) ?>" class="stack-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><label class="check-row"><input type="checkbox" name="confirm_merge" value="1" required><span>Подтверждаю безопасный merge без перезаписи и удаления</span></label><button class="button button-primary">Восстановить транзакционно</button></form><form method="post" action="<?= e(url('/restore/cancel')) ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="button button-quiet">Отменить превью</button></form><?php endif; ?>
<?php if($result): ?><details open><summary>Итог restore</summary><p>Режим: merge · повтор: <?= $result['idempotent']?'да':'нет' ?></p><ul><?php foreach(($result['tables']??[]) as $table=>$stats): ?><li><code><?= e($table) ?></code>: добавлено <?= (int)$stats['inserted'] ?>, пропущено <?= (int)$stats['skipped'] ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
