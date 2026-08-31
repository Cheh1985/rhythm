<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\ApiError;
use App\Core\RequestContext;

define('APP_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_ROOT . '/app/helpers.php';

$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$environment = strtolower((string) env('APP_ENV', 'production'));
if (!in_array($environment, ['production', 'development', 'test'], true)) {
    $environment = 'production';
}
define('APP_ENVIRONMENT', $environment);

$timezone = env('APP_TIMEZONE', 'Europe/Moscow') ?? 'Europe/Moscow';
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Europe/Moscow';
}
date_default_timezone_set($timezone);

$debug = APP_ENVIRONMENT !== 'production' && filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL);
define('APP_DEBUG_ENABLED', $debug);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

RequestContext::initialize($_SERVER['HTTP_X_REQUEST_ID'] ?? null);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $trustProxy = filter_var(env('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($trustProxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $secureSetting = strtolower((string) env('SESSION_SECURE', 'auto'));
    $secure = $https || ($secureSetting !== 'auto' && filter_var($secureSetting, FILTER_VALIDATE_BOOL));
    session_name(env('SESSION_NAME', 'training_diary'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) max(900, (int) env('SESSION_LIFETIME_SECONDS', '43200')));
    session_start();

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: tools=(self), camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Origin-Agent-Cluster: ?1');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    $upgradeDirective = APP_ENVIRONMENT === 'production' ? '; upgrade-insecure-requests' : '';
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; object-src 'none'; media-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'{$upgradeDirective}");
    if ($https && APP_ENVIRONMENT === 'production') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if (PHP_SAPI !== 'cli') {
    header('X-Request-ID: ' . RequestContext::requestId());
}

set_exception_handler(static function (Throwable $exception): void {
    $debug = APP_DEBUG_ENABLED;
    $message = RequestContext::exceptionLog($exception);
    $logDir = APP_ROOT . '/storage/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        error_log($message, 3, $logDir . '/app.log');
    } else {
        error_log($message);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $debug ? $message : "Ошибка приложения.\n");
        return;
    }

    $apiError = $exception instanceof ApiError ? $exception : ApiError::internal();
    http_response_code($apiError->status());
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json') || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($apiError->envelope(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    echo $debug ? '<pre>' . e($message) . '</pre>' : '<h1>Что-то пошло не так</h1><p>Попробуйте ещё раз позже.</p>';
});

function db(): Database
{
    static $database;
    return $database ??= new Database();
}
