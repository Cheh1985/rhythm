<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('WEBMCP_ENABLED=false');
putenv('WEBMCP_READ_ENABLED=false');
putenv('WEBMCP_DRAFT_WRITE_ENABLED=false');
putenv('WEBMCP_INSTANCE_WRITE_ENABLED=false');
putenv('WEBMCP_ACTIVATION_ENABLED=false');
$_SERVER['HTTP_X_REQUEST_ID'] = 'stage10-request-0001';
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\ApiError;
use App\Core\ApiInput;
use App\Core\FeatureFlags;
use App\Core\RequestContext;
use App\Service\AssistantAuditService;
use App\Service\PlanImportService;
use App\Service\TrainingPlanContractValidator;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};
$throws = static function (callable $callback, string $contains, string $label) use ($check): void {
    try {
        $callback();
        $check(false, $label . ' (исключение не выброшено)');
    } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), $contains), $label . ' (' . $exception->getMessage() . ')');
    }
};

$fixture = (string) file_get_contents(__DIR__ . '/fixtures/training-plan/full-body-a.json');
$contract = new TrainingPlanContractValidator();
$legacy = new PlanImportService(null, $contract);
$valid = $contract->decode($fixture);
$check($legacy->decode($fixture) === $valid, 'PlanImportService сохраняет decode parity с общим validator');
$check(PlanImportService::SCHEMA === TrainingPlanContractValidator::SCHEMA, 'legacy schema constant делегирован общему контракту');

$cases = [];
$unknown = $valid;
$unknown['unexpected'] = true;
$cases[] = [$unknown, 'Неизвестное поле'];
$blank = $valid;
$blank['program']['name'] = " \t ";
$cases[] = [$blank, 'непустой строкой'];
$badRepRange = $valid;
$badRepRange['exercises'][0]['rep_range'] = ['min' => 10, 'max' => 9];
$cases[] = [$badRepRange, 'целым числом от 10'];
$badRirRange = $valid;
$badRirRange['exercises'][0]['target_rir'] = ['min' => 4, 'max' => 3];
$cases[] = [$badRirRange, 'числом от 4'];
foreach ($cases as [$data, $message]) {
    $contractMessage = null;
    $legacyMessage = null;
    try { $contract->validate($data); } catch (Throwable $exception) { $contractMessage = $exception->getMessage(); }
    try { $legacy->validate($data); } catch (Throwable $exception) { $legacyMessage = $exception->getMessage(); }
    $check($contractMessage !== null && $contractMessage === $legacyMessage && str_contains($contractMessage, $message), 'legacy и общий validator отклоняют данные одинаково: ' . $message);
}

$orderedA = ['z' => 1, 'a' => ['y' => 2, 'x' => 3], 'list' => [['b' => 2, 'a' => 1]]];
$orderedB = ['list' => [['a' => 1, 'b' => 2]], 'a' => ['x' => 3, 'y' => 2], 'z' => 1];
$check($contract->canonicalJson($orderedA) === $contract->canonicalJson($orderedB), 'canonical JSON не зависит от порядка ключей объектов');
$check($contract->canonicalHash($orderedA) === hash('sha256', $contract->canonicalJson($orderedA)), 'canonical hash вычисляется из canonical JSON');

$check(ApiInput::positiveId(42) === 42 && ApiInput::positiveId('42') === 42, 'strict ID принимает положительное int и каноническую route-строку');
foreach ([0, -1, '0', '01', ' 1', '1.0', 1.0, true] as $badId) {
    $throws(static fn () => ApiInput::positiveId($badId), 'положительным целым', 'strict ID отклоняет неканоническое значение ' . var_export($badId, true));
}
$check(ApiInput::jsonObject('{"name":"Ритм"}', 64) === ['name' => 'Ритм'], 'JSON body object разбирается в пределах лимита');
$throws(static fn () => ApiInput::jsonObject('{"long":"value"}', 8), 'превышает', 'JSON body size проверяется до decode');
$throws(static fn () => ApiInput::jsonObject('[]', 8), 'объектом', 'JSON list не принимается вместо object');
$throws(static fn () => ApiInput::jsonObject('{', 8), 'Некорректный', 'битый JSON body отклоняется безопасно');
$throws(static fn () => ApiInput::integer(['version' => '1'], 'version', 1, 100), 'целым числом', 'ожидаемый integer не приводится из строки');
$throws(static fn () => ApiInput::string(['name' => '   '], 'name', 80), 'строкой', 'обязательная строка не может состоять из пробелов');

$check(FeatureFlags::all() === [
    FeatureFlags::WEBMCP_ENABLED => false,
    FeatureFlags::WEBMCP_READ_ENABLED => false,
    FeatureFlags::WEBMCP_DRAFT_WRITE_ENABLED => false,
    FeatureFlags::WEBMCP_INSTANCE_WRITE_ENABLED => false,
    FeatureFlags::WEBMCP_ACTIVATION_ENABLED => false,
], 'все WebMCP feature flags по умолчанию выключены');
putenv('WEBMCP_READ_ENABLED=true');
$check(!FeatureFlags::enabled(FeatureFlags::WEBMCP_READ_ENABLED), 'дочерний flag не обходит выключенный master flag');
putenv('WEBMCP_ENABLED=true');
$check(FeatureFlags::enabled(FeatureFlags::WEBMCP_READ_ENABLED), 'дочерний flag включается только вместе с master flag');
putenv('WEBMCP_ENABLED=false');
putenv('WEBMCP_READ_ENABLED=false');

$requestId = RequestContext::requestId();
$envelope = (new ApiError('validation_error', 'Проверьте данные.', 422, ['field' => 'version']))->envelope();
$log = RequestContext::exceptionLog(new RuntimeException('prompt=не должен попасть в лог'));
$check($requestId === 'stage10-request-0001' && ($envelope['error']['request_id'] ?? null) === $requestId && str_contains($log, 'request=' . $requestId), 'один request ID используется в context, envelope и exception log');
$check(!str_contains($log, 'не должен попасть') && !str_contains($log, 'prompt='), 'exception log не сохраняет сообщение с пользовательскими данными');
$check(!RequestContext::isValidRequestId("bad\r\nid") && RequestContext::isValidRequestId('valid-request_123'), 'request ID строго валидируется перед отражением в header');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE assistant_tool_calls (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, request_id TEXT NOT NULL, tool_name TEXT NOT NULL,
    outcome TEXT NOT NULL, entity_type TEXT NULL, entity_id TEXT NULL, error_code TEXT NULL, duration_ms INTEGER NULL,
    metadata_json TEXT NULL, created_at TEXT NOT NULL
)');
$audit = new AssistantAuditService($pdo);
$auditId = $audit->record(7, 'plans.list', 'success', [
    'entity_type' => 'workout_plan',
    'entity_id' => '42',
    'duration_ms' => 17,
    'result_count' => 3,
    'status' => 'planned',
    'prompt' => 'private prompt',
    'csrf_token' => 'private csrf',
    'cookie' => 'private cookie',
    'comment' => 'private comment',
    'payload' => ['full' => 'private payload'],
]);
$saved = $pdo->query('SELECT * FROM assistant_tool_calls')->fetch();
$savedJson = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check($auditId === 1 && $saved['request_id'] === $requestId && $saved['tool_name'] === 'plans.list', 'compact assistant audit сохраняет identity и correlation');
$check($saved['metadata_json'] === '{"result_count":3,"status":"planned"}', 'assistant audit хранит только allowlist summary metadata');
$check(!str_contains($savedJson, 'private') && !str_contains($savedJson, 'csrf') && !str_contains($savedJson, 'cookie'), 'assistant audit не хранит prompt, payload или secrets');
$redacted = AssistantAuditService::redact(['safe' => 'ok', 'prompt' => 'x', 'nested' => ['comment' => 'y', 'count' => 2], 'csrf_token' => 'z']);
$check($redacted === ['safe' => 'ok', 'nested' => ['count' => 2]], 'redaction удаляет чувствительные ключи рекурсивно');

$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/009_webmcp_foundation.sql');
foreach (['assistant_tool_calls', 'source VARCHAR(40) NULL', 'request_id VARCHAR(80) NULL', 'idx_audit_request'] as $fragment) {
    $check(str_contains($schema, $fragment) && str_contains($migration, $fragment), 'schema и migration согласованы: ' . $fragment);
}
$check(!str_contains($schema, 'prompt') && !str_contains($migration, 'prompt'), 'audit schema не предусматривает raw prompts');

if ($failures !== []) {
    fwrite(STDERR, "Stage 10 WebMCP foundation checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 10 WebMCP foundation checks passed ({$checks}).\n");
