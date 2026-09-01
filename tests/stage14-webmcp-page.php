<?php

declare(strict_types=1);

putenv('APP_URL=');
require dirname(__DIR__) . '/bootstrap.php';

use App\WebMcp\ToolCatalog;

$catalog = ToolCatalog::readOnly();
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

$expected = [
    'training.get_profile',
    'training.get_current_plan',
    'training.get_plan',
    'training.get_plan_template',
    'training.list_plan_versions',
    'training.list_workouts',
    'training.get_workout',
    'training.get_exercise_history',
    'training.get_progress_summary',
    'training.get_scheduled_workout',
    'training.search_exercises',
    'training.find_alternatives',
];
$check(array_column($catalog, 'name') === $expected, 'каталог содержит ровно 12 read-only tools в стабильном порядке');

foreach ($catalog as $tool) {
    $check(
        is_string($tool['name'] ?? null) && strlen($tool['name']) <= 30
        && is_string($tool['description'] ?? null) && $tool['description'] !== '' && strlen($tool['description']) <= 500,
        ($tool['name'] ?? 'tool') . ': metadata укладывается в рекомендуемый budget'
    );
    $check(
        ($tool['inputSchema']['type'] ?? null) === 'object'
        && ($tool['inputSchema']['additionalProperties'] ?? null) === false,
        ($tool['name'] ?? 'tool') . ': закрытая object input schema'
    );
    $check(
        ($tool['annotations']['readOnlyHint'] ?? null) === true
        && ($tool['annotations']['untrustedContentHint'] ?? null) === true,
        ($tool['name'] ?? 'tool') . ': read-only и untrusted-content annotations'
    );
}

$catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(!str_contains($catalogJson, 'user_id'), 'модель не может передать user_id');
$check(!preg_match('/create|update|delete|archive|activate|reschedule|replace/i', implode(',', $expected)), 'write tools отсутствуют');

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$layout = (string) file_get_contents($root . '/views/layout.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$worker = (string) file_get_contents($root . '/public/service-worker.js');
$adapter = (string) file_get_contents($root . '/public/assets/webmcp.js');
$check(str_contains($routes, "'/assistant'") && str_contains($routes, 'AssistantController'), 'authenticated assistant route подключён');
$check(str_contains($layout, '$webMcpAdapter') && substr_count($layout, '/assets/webmcp.js') === 1, 'adapter подключается layout только условно');
$check(str_contains($bootstrap, 'tools=(self)') && str_contains($bootstrap, 'Origin-Agent-Cluster: ?1'), 'WebMCP security headers включены');
$check(str_contains($worker, "url.pathname.includes('/api/')") && str_contains($worker, '/^\\/assistant'), 'API и /assistant исключены из Service Worker');
$check(str_contains($adapter, 'document.modelContext') && !str_contains($adapter, 'navigator.modelContext'), 'используется только актуальный document.modelContext');
$check(str_contains($adapter, "credentials: 'same-origin'") && str_contains($adapter, "cache: 'no-store'"), 'adapter выполняет no-store same-origin fetch');

if ($failures !== []) {
    fwrite(STDERR, "Stage 14 WebMCP page checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 14 WebMCP page checks passed ({$checks}).\n");
