<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Service\ProgramVersionReconciliationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['apply', 'user-id:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Использование: php bin/reconcile-program-versions.php [--apply] [--user-id=N]\n");
    fwrite(STDOUT, "Без --apply команда выполняет только dry-run. Неоднозначные программы никогда не выбираются автоматически.\n");
    exit(0);
}

$userId = null;
if (array_key_exists('user-id', $options)) {
    $rawUserId = filter_var($options['user-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($rawUserId === false) {
        fwrite(STDERR, "--user-id должен быть положительным целым числом.\n");
        exit(2);
    }
    $userId = (int) $rawUserId;
}

try {
    $service = new ProgramVersionReconciliationService();
    $apply = isset($options['apply']);
    $result = $apply
        ? $service->reconcileUnambiguous($userId)
        : ['updated' => 0, 'programs' => $service->inspect($userId)];

    fwrite(STDOUT, ($apply ? 'APPLY' : 'DRY-RUN') . " program version reconciliation\n");
    foreach ($result['programs'] as $program) {
        $active = $program['active_version_number'] === null ? '-' : 'v' . $program['active_version_number'];
        fwrite(STDOUT, sprintf(
            "user=%d program=%s versions=%d active=%s state=%s\n",
            $program['user_id'],
            $program['external_program_id'],
            $program['version_count'],
            $active,
            $program['state']
        ));
    }
    fwrite(STDOUT, "updated=" . $result['updated'] . "\n");
    $ambiguous = count(array_filter($result['programs'], static fn (array $row): bool => $row['state'] === 'ambiguous'));
    if ($ambiguous > 0) {
        fwrite(STDOUT, "ambiguous={$ambiguous}; требуется явный выбор версии отдельным управляемым workflow.\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Reconciliation остановлен: {$exception->getMessage()}\n");
    exit(1);
}
