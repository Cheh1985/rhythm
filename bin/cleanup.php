<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pdo = db()->pdo();
$beforeAttempts = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$beforeReceipts = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
$attempts = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
$attempts->execute([$beforeAttempts]);
$receipts = $pdo->prepare('DELETE FROM offline_action_receipts WHERE created_at < ?');
$receipts->execute([$beforeReceipts]);

$cacheDir = APP_ROOT . '/storage/cache';
$cacheFiles = 0;
foreach (glob($cacheDir . '/*') ?: [] as $file) {
    if (is_file($file) && filemtime($file) !== false && filemtime($file) < time() - 7 * 86400 && unlink($file)) $cacheFiles++;
}

fwrite(STDOUT, sprintf("Cleanup complete: login_attempts=%d, offline_receipts=%d, cache_files=%d\n", $attempts->rowCount(), $receipts->rowCount(), $cacheFiles));
