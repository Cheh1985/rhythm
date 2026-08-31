<?php

declare(strict_types=1);

namespace App\Core;

final class SameOrigin
{
    public static function isValid(?string $origin = null): bool
    {
        $origin ??= $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '' || $origin === 'null') {
            return false;
        }

        $actual = self::normalize($origin);
        $expected = self::normalize(self::applicationOrigin());
        return $actual !== null && $expected !== null && hash_equals($expected, $actual);
    }

    public static function requireValid(?string $origin = null): void
    {
        if (!self::isValid($origin)) {
            throw new ApiError('cross_origin_denied', 'Запрос должен быть отправлен из этого приложения.', 403);
        }
    }

    private static function applicationOrigin(): string
    {
        $configured = trim((string) \env('APP_URL', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host) !== 1) {
            return '';
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    private static function normalize(string $value): ?string
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        return $scheme . '://' . strtolower((string) $parts['host']) . ($port === null ? '' : ':' . $port);
    }
}
