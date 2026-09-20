<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Service\PushDeliveryService;

if (!filter_var(env('PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
    fwrite(STDERR, "Push delivery is disabled. Set PUSH_ENABLED=true after configuring VAPID keys.\n");
    exit(1);
}

$loop = in_array('--loop', $argv, true);
$once = in_array('--once', $argv, true);
if (!$loop && !$once) {
    fwrite(STDERR, "Usage: php bin/send-rest-notifications.php --once|--loop\n");
    exit(2);
}

$sleep = max(1, min(60, (int) env('PUSH_WORKER_SLEEP_SECONDS', '3')));
$batch = max(1, min(200, (int) env('PUSH_BATCH_SIZE', '50')));
$service = new PushDeliveryService();

do {
    try {
        $result = $service->runOnce($batch);
        fwrite(STDOUT, sprintf("%s claimed=%d sent=%d failed=%d skipped=%d\n", gmdate('c'), $result['claimed'], $result['sent'], $result['failed'], $result['skipped']));
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("%s push worker error: %s\n", gmdate('c'), $exception->getMessage()));
        if (!$loop) exit(1);
    }
    if ($loop) sleep($sleep);
} while ($loop);
