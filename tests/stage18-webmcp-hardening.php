<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('APP_URL=https://rhythm.example');
putenv('WEBMCP_WRITE_RATE_LIMIT=2');
putenv('WEBMCP_WRITE_RATE_WINDOW_SECONDS=60');
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\ApiError;
use App\Core\ApiInput;
use App\Core\AssistantRateLimiter;
use App\Core\FetchMetadata;
use App\Core\SameOrigin;
use App\Service\AssistantAuditPruner;
use App\Service\AssistantAuditService;
use App\WebMcp\ToolCatalog;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};
$throwsCode = static function (callable $callback, string $code, string $label) use ($check): void {
    try {
        $callback();
        $check(false, $label . ' (исключение не выброшено)');
    } catch (ApiError $error) {
        $check($error->errorCode() === $code, $label . ' (' . $error->errorCode() . ')');
    }
};

FetchMetadata::requireSameOriginIfPresent(null);
FetchMetadata::requireSameOriginIfPresent('same-origin');
$check(true, 'Fetch Metadata допускает same-origin и отсутствие заголовка для Safari');
$throwsCode(fn () => FetchMetadata::requireSameOriginIfPresent('cross-site'), 'cross_origin_denied', 'cross-site Fetch Metadata отклоняется');
$throwsCode(fn () => FetchMetadata::requireSameOriginIfPresent('same-site'), 'cross_origin_denied', 'same-site не подменяет exact same-origin');
$check(SameOrigin::isValid('https://rhythm.example') && !SameOrigin::isValid('https://evil.example'), 'Origin остаётся exact same-origin boundary');

$oneMiB = 1024 * 1024;
$check(ApiInput::jsonObject('{"ok":true}', $oneMiB) === ['ok' => true], 'bounded JSON принимает небольшой объект');
try {
    ApiInput::jsonObject('{"value":"' . str_repeat('x', $oneMiB) . '"}', $oneMiB);
    $check(false, 'oversized JSON отклоняется');
} catch (InvalidArgumentException $exception) {
    $check(str_contains($exception->getMessage(), 'размер'), 'oversized JSON отклоняется до domain layer');
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE assistant_tool_calls (
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,request_id TEXT NOT NULL,tool_name TEXT NOT NULL,
 outcome TEXT NOT NULL,entity_type TEXT NULL,entity_id TEXT NULL,error_code TEXT NULL,duration_ms INTEGER NULL,
 metadata_json TEXT NULL,created_at TEXT NOT NULL
);
INSERT INTO assistant_tool_calls VALUES
 (1,1,'rate-1','drafts.create','success',NULL,NULL,NULL,1,NULL,CURRENT_TIMESTAMP),
 (2,1,'rate-2','drafts.create','error',NULL,NULL,'validation_error',1,NULL,CURRENT_TIMESTAMP),
 (3,1,'old-1','profile.get','success',NULL,NULL,NULL,1,NULL,'2020-01-01 00:00:00'),
 (4,2,'old-2','profile.get','denied',NULL,NULL,'authentication_required',1,NULL,'2020-01-02 00:00:00');
SQL);
$limiter = new AssistantRateLimiter($pdo);
$throwsCode(fn () => $limiter->enforce(1, 'drafts.create', 'WEBMCP_WRITE_RATE_LIMIT', 'WEBMCP_WRITE_RATE_WINDOW_SECONDS', 30, 60), 'rate_limit_exceeded', 'write rate limit учитывает success и error calls');
$limiter->enforce(2, 'drafts.create', 'WEBMCP_WRITE_RATE_LIMIT', 'WEBMCP_WRITE_RATE_WINDOW_SECONDS', 30, 60);
$check(true, 'rate limit изолирован по user_id и tool_name');

$pruner = new AssistantAuditPruner($pdo);
$threshold = new DateTimeImmutable('2021-01-01 00:00:00', new DateTimeZone('UTC'));
$check($pruner->countBefore($threshold) === 2, 'audit dry-run считает только истёкшие записи');
$check($pruner->pruneBefore($threshold) === 2 && $pruner->countBefore($threshold) === 0, 'audit prune удаляет только записи старше threshold');
$check((int) $pdo->query('SELECT COUNT(*) FROM assistant_tool_calls')->fetchColumn() === 2, 'актуальный assistant audit сохранён');

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/webmcp/prompt-injection.json'), true, 16, JSON_THROW_ON_ERROR);
$redacted = AssistantAuditService::redact([
    'comment' => $fixture['comment'],
    'payload' => $fixture,
    'result_count' => 4,
]);
$redactedJson = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(!str_contains($redactedJson, $fixture['comment']) && !str_contains($redactedJson, $fixture['custom_name']), 'assistant audit не сохраняет prompt-like payload');

$catalog = ToolCatalog::enabled(true, true, true, true);
$check(count($catalog) === 16 && array_reduce($catalog, static fn (bool $ok, array $tool): bool => $ok && ($tool['annotations']['untrustedContentHint'] ?? false) === true, true), 'все tool outputs помечены как untrusted content');
$check(ToolCatalog::enabled(false, false, false, false) === [], 'capability-disabled mode не публикует tools');

$root = dirname(__DIR__);
$env = (string) file_get_contents($root . '/.env.example');
$serviceWorker = (string) file_get_contents($root . '/public/service-worker.js');
$adapter = (string) file_get_contents($root . '/public/assets/webmcp.js');
$readGuard = (string) file_get_contents($root . '/app/Core/SiteToolRequestGuard.php');
$writeGuard = (string) file_get_contents($root . '/app/Core/SemanticWriteRequestGuard.php');
$pruneCommand = (string) file_get_contents($root . '/bin/prune-assistant-audit.php');
foreach (['WEBMCP_ENABLED','WEBMCP_READ_ENABLED','WEBMCP_DRAFT_WRITE_ENABLED','WEBMCP_INSTANCE_WRITE_ENABLED','WEBMCP_ACTIVATION_ENABLED'] as $flag) {
    $check(preg_match('/^' . preg_quote($flag, '/') . '=false$/m', $env) === 1, "production example keeps {$flag}=false");
}
$check(str_contains($readGuard, 'FetchMetadata::requireSameOriginIfPresent') && str_contains($writeGuard, 'FetchMetadata::requireSameOriginIfPresent'), 'read/write boundaries применяют Fetch Metadata policy');
$check(str_contains($writeGuard, 'WEBMCP_WRITE_RATE_LIMIT') && str_contains($writeGuard, 'MAX_BODY_BYTES + 1'), 'write boundary ограничивает rate и фактически читаемые bytes');
$check(str_contains($writeGuard, 'SameOrigin::requireValid') && str_contains($writeGuard, 'Csrf::validate'), 'write boundary сохраняет Origin и CSRF проверки');
$check(str_contains($pruneCommand, "['apply', 'days:', 'help']") && str_contains($pruneCommand, 'WEBMCP_AUDIT_RETENTION_DAYS'), 'prune command имеет dry-run/apply и configurable retention');
$check(str_contains($serviceWorker, "url.pathname.includes('/api/')") && str_contains($serviceWorker, "^\\/assistant\\/?$"), 'Service Worker не кеширует assistant API/page');
$check(!str_contains($adapter, 'navigator.modelContext') && str_contains($adapter, 'document.modelContext'), 'adapter использует capability detection без deprecated API');

if ($failures !== []) {
    fwrite(STDERR, "Stage 18 WebMCP hardening checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 18 WebMCP hardening checks passed ({$checks}).\n");
