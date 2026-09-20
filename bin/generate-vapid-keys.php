<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
    fwrite(STDERR, "Install Composer dependencies first: composer install\n");
    exit(1);
}

try {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    fwrite(STDOUT, "VAPID_PUBLIC_KEY={$keys['publicKey']}\nVAPID_PRIVATE_KEY={$keys['privateKey']}\n");
} catch (\Throwable $exception) {
    fwrite(STDERR, "Unable to generate VAPID keys: {$exception->getMessage()}\n");
    if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false) {
        fwrite(STDERR, "Set OPENSSL_CONF before starting PHP, for example:\n");
        fwrite(STDERR, "  \$env:OPENSSL_CONF='C:\\Program Files\\PHP\\current\\extras\\ssl\\openssl.cnf'; php bin/generate-vapid-keys.php\n");
    }
    exit(1);
}
