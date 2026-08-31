<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Service\AssistantAuditPruner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['apply', 'days:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Использование: php bin/prune-assistant-audit.php [--apply] [--days=N]\n");
    fwrite(STDOUT, "Без --apply выполняется dry-run. По умолчанию используется WEBMCP_AUDIT_RETENTION_DAYS (90 дней).\n");
    exit(0);
}

$configuredDays = $options['days'] ?? env('WEBMCP_AUDIT_RETENTION_DAYS', '90');
$days = filter_var($configuredDays, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 3650],
]);
if (!is_int($days)) {
    fwrite(STDERR, "Retention должен быть целым числом от 1 до 3650 дней.\n");
    exit(2);
}

try {
    $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $days . ' days');
    $pruner = new AssistantAuditPruner();
    $eligible = $pruner->countBefore($threshold);
    $deleted = isset($options['apply']) ? $pruner->pruneBefore($threshold) : 0;

    fwrite(STDOUT, sprintf(
        "%s assistant audit prune: before_utc=%s, eligible=%d, deleted=%d\n",
        isset($options['apply']) ? 'APPLY' : 'DRY-RUN',
        $threshold->format('Y-m-d H:i:s'),
        $eligible,
        $deleted,
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, "Assistant audit prune остановлен: {$exception->getMessage()}\n");
    exit(1);
}
