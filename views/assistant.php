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
        <p class="muted">Безопасный read-only доступ к вашим тренировочным данным в этой вкладке.</p>
    </div>
</header>

<section class="card assistant-status" aria-labelledby="assistant-status-title">
    <div>
        <p class="eyebrow">Статус</p>
        <h2 id="assistant-status-title">WebMCP</h2>
    </div>
    <?php if (!$webMcpMasterEnabled): ?>
        <p class="alert" id="webmcp-capability" data-state="disabled">Site tools выключены администратором.</p>
    <?php elseif (!$webMcpReadEnabled): ?>
        <p class="alert" id="webmcp-capability" data-state="disabled">Read-only Site tools пока выключены.</p>
    <?php else: ?>
        <p class="alert" id="webmcp-capability" data-state="checking" aria-live="polite">Проверяем поддержку Site tools браузером…</p>
    <?php endif; ?>
    <dl class="assistant-facts">
        <div><dt>Master flag</dt><dd><?= $webMcpMasterEnabled ? 'включён' : 'выключен' ?></dd></div>
        <div><dt>Read flag</dt><dd><?= $webMcpReadEnabled ? 'включён' : 'выключен' ?></dd></div>
        <div><dt>Режим</dt><dd>только чтение</dd></div>
        <div><dt>Область</dt><dd>эта страница</dd></div>
    </dl>
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

<p class="muted assistant-note">Tools используют вашу текущую серверную сессию, не принимают <code>user_id</code> и исчезают при уходе с этой страницы. Текст из базы помечен как недоверенный контент.</p>

<script type="application/json" id="webmcp-tool-catalog"><?= $catalogJson ?></script>
