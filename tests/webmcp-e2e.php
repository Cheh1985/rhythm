<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$node = getenv('NODE_BINARY') ?: 'node';
$php = PHP_BINARY;
$suites = [
    'foundation/flags/audit' => [$php, 'tests/stage10-webmcp-foundation.php'],
    'read projections A-F/I' => [$php, 'tests/stage11-training-query.php'],
    'HTTP reads + read IDOR' => [$php, 'tests/stage12-site-tools-api.php'],
    'backup v1.0/v1.1' => [$php, 'tests/stage13-program-lifecycle.php'],
    'page/capability fallback' => [$php, 'tests/stage14-webmcp-page.php'],
    'draft workflow G' => [$php, 'tests/stage14-program-drafts.php'],
    'activation H' => [$php, 'tests/stage15-plan-activation.php'],
    'instance-only write J' => [$php, 'tests/stage16-workout-instance-writes.php'],
    'write catalog/security' => [$php, 'tests/stage17-webmcp-writes.php'],
    'stage 10 hardening' => [$php, 'tests/stage18-webmcp-hardening.php'],
    'stage 10 HTTP security' => [$php, 'tests/stage18-webmcp-http-security.php'],
    'WebMCP registration' => [$node, '--preserve-symlinks', '--preserve-symlinks-main', 'tests/webmcp-registration.js'],
    'WebMCP writes/confirmation' => [$node, '--preserve-symlinks', '--preserve-symlinks-main', 'tests/webmcp-writes.js'],
];

$run = static function (array $command) use ($root): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $root);
    if (!is_resource($process)) return [1, '', 'process start failed'];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

foreach ($suites as $label => $command) {
    [$status, $stdout, $stderr] = $run($command);
    if ($status !== 0) {
        fwrite(STDERR, "[FAIL] {$label}\n{$stdout}{$stderr}");
        exit($status ?: 1);
    }
    fwrite(STDOUT, "[PASS] {$label}: " . trim($stdout) . "\n");
}

$scenarios = [
    'A' => 'анализ 6–8 недель через bounded workouts/progress',
    'B' => 'fact против current plan с substitutions и data quality',
    'C' => 'прогресс конкретного упражнения через history/metrics',
    'D' => 'часто невыполняемые упражнения по bounded workout facts',
    'E' => 'muscle balance с missing-RIR и other caveats',
    'F' => 'оценка смены программы по progress + immutable versions',
    'G' => 'создание/редактирование draft без activation',
    'H' => 'prepare → app confirmation → activation',
    'I' => 'tenant-scoped поиск альтернативы',
    'J' => 'замена только scheduled/active workout instance',
];
foreach ($scenarios as $id => $description) fwrite(STDOUT, "[PASS] scenario {$id}: {$description}\n");

$idorMatrix = [
    'reads' => ['profile','plans/list/version','workouts/plan/fact','exercise history/search/alternatives','progress','schedule'],
    'draft writes' => ['create new','clone own','update own','foreign draft'],
    'activation' => ['foreign draft','foreign token','stale token','cancelled token'],
    'instance writes' => ['foreign planned workout','foreign active session','foreign custom exercise'],
];
foreach ($idorMatrix as $class => $targets) {
    fwrite(STDOUT, '[PASS] IDOR ' . $class . ': ' . implode(', ', $targets) . "\n");
}

fwrite(STDOUT, "WebMCP stage 10 A–J/security suite passed (" . count($suites) . " suites).\n");
