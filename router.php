<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ][$extension] ?? (function_exists('mime_content_type') ? mime_content_type($file) : false);
    if ($mime) {
        header('Content-Type: ' . $mime);
    }
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    return true;
}
require __DIR__ . '/public/index.php';
