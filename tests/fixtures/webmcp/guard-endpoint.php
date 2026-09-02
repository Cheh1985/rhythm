<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';

use App\Core\FeatureFlags;
use App\Core\SemanticWriteRequestGuard;
use App\Core\SiteToolRequestGuard;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/session') {
    $_SESSION['user_id'] = 1;
    $_SESSION['_csrf'] = 'stage18-http-csrf';
    json_response(['ok' => true]);
}

if (preg_match('#^/(write|read)/([a-z][a-z0-9._-]{2,79})$#D', $path, $match) !== 1) {
    json_response(['error' => ['code' => 'not_found']], 404);
}

if ($match[1] === 'write') {
    (new SemanticWriteRequestGuard())->run(
        $match[2],
        FeatureFlags::WEBMCP_DRAFT_WRITE_ENABLED,
        static fn (int $userId, array $body, string $key): array => [
            'accepted' => true,
            'user' => $userId,
            'value' => $body['value'] ?? null,
            'key_present' => $key !== '',
        ],
    );
}

if ($match[2] === 'length.missing') {
    unset($_SERVER['CONTENT_LENGTH']);
} elseif ($match[2] === 'length.empty') {
    $_SERVER['CONTENT_LENGTH'] = '';
} elseif ($match[2] === 'length.zero') {
    $_SERVER['CONTENT_LENGTH'] = '0';
} elseif ($match[2] === 'length.positive') {
    $_SERVER['CONTENT_LENGTH'] = '1';
} elseif ($match[2] === 'length.invalid') {
    $_SERVER['CONTENT_LENGTH'] = 'broken';
} elseif ($match[2] === 'length.negative') {
    $_SERVER['CONTENT_LENGTH'] = '-1';
} elseif ($match[2] === 'transfer.encoding') {
    unset($_SERVER['CONTENT_LENGTH']);
    $_SERVER['HTTP_TRANSFER_ENCODING'] = 'chunked';
}

(new SiteToolRequestGuard())->run(
    $match[2],
    [],
    static fn (int $userId): array => ['accepted' => true, 'user' => $userId],
);
