<?php

/** @var list<array<string, mixed>> $toolCatalog */
$catalogJson = json_encode(
    $toolCatalog,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<header class="page-head">
    <div>
        <p class="eyebrow">ChatGPT Site tools</p>
        <h1>Ассистент</h1>
        <p class="muted">Безопасный доступ к тренировочным данным и ручное подтверждение подготовленной activation.</p>
    </div>
</header>

<section class="card assistant-status" aria-labelledby="assistant-status-title">
    <div>
        <p class="eyebrow">Статус</p>
        <h2 id="assistant-status-title">WebMCP</h2>
    </div>
    <?php if (!$webMcpMasterEnabled): ?>
        <p class="alert" id="webmcp-capability" data-state="disabled">Site tools выключены администратором.</p>
    <?php elseif ($toolCatalog === []): ?>
        <p class="alert" id="webmcp-capability" data-state="disabled">Все классы Site tools выключены.</p>
    <?php else: ?>
        <p class="alert" id="webmcp-capability" data-state="checking" aria-live="polite">Проверяем поддержку Site tools браузером…</p>
    <?php endif; ?>
    <p class="alert" id="webmcp-operation-status" role="status" aria-live="polite" hidden></p>
    <dl class="assistant-facts">
        <div><dt>Master flag</dt><dd><?= $webMcpMasterEnabled ? 'включён' : 'выключен' ?></dd></div>
        <div><dt>Read flag</dt><dd><?= $webMcpReadEnabled ? 'включён' : 'выключен' ?></dd></div>
        <div><dt>Draft writes</dt><dd><?= $webMcpDraftWriteEnabled ? 'включены' : 'выключены' ?></dd></div>
        <div><dt>Instance writes</dt><dd><?= $webMcpInstanceWriteEnabled ? 'включены' : 'выключены' ?></dd></div>
        <div><dt>Activation flag</dt><dd><?= $webMcpActivationEnabled ? 'включён' : 'выключен' ?></dd></div>
        <div><dt>Область</dt><dd>эта страница</dd></div>
    </dl>
</section>

<?php if ($webMcpActivationEnabled): ?>
<dialog id="webmcp-activation-dialog" aria-labelledby="webmcp-activation-title">
    <form class="dialog-card activation-dialog-card" data-activation-form>
        <button class="dialog-close" type="button" data-activation-cancel aria-label="Отменить activation">×</button>
        <p class="eyebrow">Обязательное подтверждение</p>
        <h2 id="webmcp-activation-title">Активировать программу?</h2>
        <p><strong data-activation-program>—</strong>, версия <span data-activation-version>—</span></p>
        <dl class="assistant-facts activation-impact">
            <div><dt>Период</dt><dd data-activation-window>—</dd></div>
            <div><dt>Политика</dt><dd data-activation-policy>—</dd></div>
            <div><dt>Будет создано</dt><dd data-activation-created>0</dd></div>
            <div><dt>Будет отменено</dt><dd data-activation-superseded>0</dd></div>
            <div><dt>Будет сохранено</dt><dd data-activation-kept>0</dd></div>
            <div><dt>Защищено</dt><dd data-activation-protected>0</dd></div>
            <div><dt>Заблокировано дат</dt><dd data-activation-blocked>0</dd></div>
            <div><dt>Других программ paused</dt><dd data-activation-paused>0</dd></div>
            <div><dt>Истекает UTC</dt><dd data-activation-expiry>—</dd></div>
        </dl>
        <div class="activation-preview-lists">
            <?php foreach (['created' => 'Новые планы', 'superseded' => 'Отменяемые планы', 'kept' => 'Сохраняемые планы', 'protected' => 'Completed / in-progress — без изменений', 'blocked_materialization' => 'Заблокированные даты'] as $key => $label): ?>
                <details class="chart-data" data-activation-section="<?= e($key) ?>" hidden>
                    <summary><?= e($label) ?> (<span data-activation-section-count="<?= e($key) ?>">0</span>)</summary>
                    <div class="table-scroll"><table><thead><tr><th>Дата</th><th>Тренировка</th><th>Программа</th><th>Статус</th></tr></thead>
                    <tbody data-activation-items="<?= e($key) ?>"></tbody></table></div>
                </details>
            <?php endforeach; ?>
        </div>
        <p class="alert">Activation изменит active version и будущие планы только после нажатия кнопки ниже. Preview и версии повторно проверяются сервером.</p>
        <div class="dialog-actions">
            <button class="button button-secondary" type="button" data-activation-cancel>Отменить</button>
            <button class="button button-primary" type="submit">Активировать</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<section class="card" id="activation-confirmation" aria-labelledby="activation-title">
    <p class="eyebrow">Human-in-the-loop</p>
    <h2 id="activation-title">Подтверждение activation</h2>
    <?php if ($activationError): ?><p class="alert alert-error"><?= e($activationError) ?></p><?php endif; ?>
    <?php if ($activationSuccess): ?><p class="alert alert-success"><?= e($activationSuccess) ?></p><?php endif; ?>
    <?php if (!$webMcpActivationEnabled): ?>
        <p class="muted">Activation workflow выключен feature flag.</p>
    <?php elseif (!$activationConfirmation): ?>
        <p class="muted">Нет подготовленной activation. Prepare endpoint не меняет active state и только создаёт краткоживущее preview.</p>
    <?php else: $impact = $activationConfirmation['preview']; ?>
        <p><strong><?= e($impact['draft']['program_name']) ?></strong>, версия <?= (int) $impact['draft']['version'] ?></p>
        <dl class="assistant-facts">
            <div><dt>Период</dt><dd><?= e($impact['window']['effective_from']) ?> — <?= e($impact['window']['effective_to']) ?></dd></div>
            <div><dt>Политика</dt><dd><?= e($impact['window']['future_plan_policy']) ?></dd></div>
            <div><dt>Будет создано</dt><dd><?= count($impact['future_plans']['created']) ?></dd></div>
            <div><dt>Будет отменено</dt><dd><?= count($impact['future_plans']['superseded']) ?></dd></div>
            <div><dt>Сохранено</dt><dd><?= count($impact['future_plans']['kept']) ?></dd></div>
            <div><dt>Защищено</dt><dd><?= count($impact['future_plans']['protected']) ?></dd></div>
            <div><dt>Других программ paused</dt><dd><?= (int) $impact['programs']['will_pause_count'] ?></dd></div>
            <div><dt>Истекает UTC</dt><dd><?= e($activationConfirmation['expires_at_utc']) ?></dd></div>
        </dl>
        <?php foreach (['created' => 'Новые планы', 'superseded' => 'Отменяемые планы', 'kept' => 'Сохраняемые планы', 'protected' => 'Completed / in-progress — без изменений', 'blocked_materialization' => 'Заблокированные даты'] as $key => $label): ?>
            <?php if ($impact['future_plans'][$key] !== []): ?>
                <details class="chart-data"><summary><?= e($label) ?> (<?= count($impact['future_plans'][$key]) ?>)</summary>
                    <div class="table-scroll"><table><thead><tr><th>Дата</th><th>Тренировка</th><th>Программа</th><th>Статус</th></tr></thead><tbody>
                    <?php foreach ($impact['future_plans'][$key] as $plan): ?><tr><td><?= e($plan['date']) ?></td><td><?= e($plan['name'] ?? $plan['template_id'] ?? '—') ?></td><td><?= e($plan['program_id'] ?? '—') ?></td><td><?= e($plan['status'] ?? $plan['reason'] ?? $key) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
        <p class="alert">Подтвердить можно только здесь. Токен одноразовый; stale draft или изменившийся impact будут отклонены.</p>
        <div class="form-actions">
            <form method="post" action="<?= e(url('/assistant/activation/confirm')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
                <input type="hidden" name="confirmation_token" value="<?= e($activationConfirmation['confirmation_token']) ?>">
                <button class="button button-primary" type="submit">Активировать программу</button>
            </form>
            <form method="post" action="<?= e(url('/assistant/activation/cancel')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
                <input type="hidden" name="confirmation_token" value="<?= e($activationConfirmation['confirmation_token']) ?>">
                <button class="button button-secondary" type="submit">Отменить</button>
            </form>
        </div>
    <?php endif; ?>
</section>

<section class="section-block" aria-labelledby="assistant-tools-title">
    <div class="section-title">
        <h2 id="assistant-tools-title">Доступные операции</h2>
        <span class="tag"><?= count($toolCatalog) ?> tools</span>
    </div>
    <?php if ($toolCatalog === []): ?>
        <div class="empty-state compact"><p>Сервер не публикует ни одного tool при текущих feature flags.</p></div>
    <?php else: ?>
        <div class="assistant-tool-list">
            <?php foreach ($toolCatalog as $tool): ?>
                <article>
                    <code><?= e($tool['name']) ?></code>
                    <strong><?= e($tool['title']) ?></strong>
                    <small><?= e($tool['description']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<p class="muted assistant-note">Tools используют вашу текущую серверную сессию, не принимают <code>user_id</code> и исчезают при уходе с этой страницы. Writes не попадают в offline outbox, а activation требует ручного подтверждения в приложении.</p>

<script type="application/json" id="webmcp-tool-catalog"><?= $catalogJson ?></script>
