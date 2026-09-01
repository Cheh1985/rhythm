<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/WebMcp/ToolCatalog.php';

use App\WebMcp\ToolCatalog;

$catalog = ToolCatalog::enabled(true, true, true, true);
if (($argv[1] ?? null) === '--catalog') {
    echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$readNames = array_column(ToolCatalog::enabled(true, false, false, false), 'name');
$draftNames = array_column(ToolCatalog::enabled(false, true, false, false), 'name');
$instanceNames = array_column(ToolCatalog::enabled(false, false, true, false), 'name');
$activationNames = array_column(ToolCatalog::enabled(false, false, false, true), 'name');
$writeNames = [...$draftNames, ...$instanceNames, ...$activationNames];

$check(count($readNames) === 11 && array_filter($readNames, static fn (string $name): bool => in_array($name, $writeNames, true)) === [], 'read-only mode не регистрирует writes');
$check($draftNames === ['training.create_plan_draft', 'training.update_plan_draft'], 'draft flag публикует только два draft tool');
$check($instanceNames === ['training.reschedule_workout', 'training.replace_exercise'], 'instance flag публикует только два instance tool');
$check($activationNames === ['training.activate_plan'], 'activation flag публикует только activation tool');
$check(count($catalog) === 16 && count(array_unique(array_column($catalog, 'name'))) === 16, 'полный каталог содержит 11 reads и 5 уникальных writes');

foreach ($catalog as $tool) {
    $check(strlen((string) ($tool['name'] ?? '')) <= 30 && strlen((string) ($tool['description'] ?? '')) <= 500, ($tool['name'] ?? 'tool') . ': metadata budget');
    $check(($tool['inputSchema']['type'] ?? null) === 'object' && ($tool['inputSchema']['additionalProperties'] ?? null) === false, ($tool['name'] ?? 'tool') . ': закрытая top-level schema');
}
foreach (array_filter($catalog, static fn (array $tool): bool => in_array($tool['name'], $writeNames, true)) as $tool) {
    $check(($tool['annotations']['readOnlyHint'] ?? null) === false, $tool['name'] . ': write не помечен read-only');
}

$writeJson = json_encode(array_values(array_filter($catalog, static fn (array $tool): bool => in_array($tool['name'], $writeNames, true))), JSON_THROW_ON_ERROR);
$check(!str_contains($writeJson, 'user_id'), 'write input schemas не содержат user_id');
$check(!preg_match('/archive|delete|history|backup/i', implode(',', $writeNames)), 'в каталоге нет операций вне этапа 9');
$byName = array_column($catalog, null, 'name');
$createSchema = $byName['training.create_plan_draft']['inputSchema'];
$updateSchema = $byName['training.update_plan_draft']['inputSchema'];
$metadataSchema = $createSchema['properties']['metadata'] ?? [];
$check(($metadataSchema['additionalProperties'] ?? null) === false && isset($metadataSchema['properties']['templates']['items']['properties']['exercises']), 'create draft публикует закрытую nested program schema');
$check(count($updateSchema['oneOf'] ?? []) === 7 && ($updateSchema['oneOf'][3]['properties']['payload']['properties']['exercise']['additionalProperties'] ?? null) === false, 'update draft публикует семь operation-specific closed payload schemas');

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controller/AssistantController.php');
$draftController = (string) file_get_contents($root . '/app/Controller/ProgramDraftController.php');
$view = (string) file_get_contents($root . '/views/assistant.php');
$adapter = (string) file_get_contents($root . '/public/assets/webmcp.js');
$queue = (string) file_get_contents($root . '/public/assets/offline-queue.js');
$check(str_contains($controller, 'ToolCatalog::enabled') && str_contains($controller, 'WEBMCP_DRAFT_WRITE_ENABLED') && str_contains($controller, 'WEBMCP_INSTANCE_WRITE_ENABLED'), 'assistant собирает каталог по отдельным flags');
$check(str_contains($routes, '/activation/confirm') && str_contains($routes, '/activation/cancel'), 'semantic confirmation routes подключены');
$check(str_contains($draftController, 'confirmation_token') && str_contains($draftController, "'mutated' => false"), 'confirm/cancel используют одноразовый session token и structured cancel');
$check(str_contains($view, 'webmcp-activation-dialog') && str_contains($view, 'data-activation-form') && str_contains($view, 'data-activation-items'), 'in-page activation modal отрисовывает itemized impact');
$check(str_contains($adapter, 'requestActivationDecision') && !str_contains($adapter, 'requestUserInteraction') && !str_contains($adapter, 'navigator.modelContext'), 'adapter использует app modal и актуальный WebMCP API');
$check(!str_contains($adapter, 'offlineQueue') && !str_contains($adapter, 'enqueue') && str_contains($queue, 'async function enqueue'), 'write adapter не подключён к offline outbox');

if ($failures !== []) {
    fwrite(STDERR, "Stage 17 WebMCP write checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 17 WebMCP write checks passed ({$checks}).\n");
