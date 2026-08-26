<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class RequestContext
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,79}$/D';

    private static ?string $requestId = null;

    public static function initialize(?string $incomingRequestId = null): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        self::$requestId = self::isValidRequestId($incomingRequestId)
            ? $incomingRequestId
            : bin2hex(random_bytes(16));

        return self::$requestId;
    }

    public static function requestId(): string
    {
        return self::initialize();
    }

    public static function isValidRequestId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::REQUEST_ID_PATTERN, $value) === 1;
    }

    public static function exceptionLog(Throwable $exception): string
    {
        return sprintf(
            "[%s] request=%s exception=%s code=%s\n%s\n",
            gmdate('c'),
            self::requestId(),
            $exception::class,
            (string) $exception->getCode(),
            $exception->getTraceAsString()
        );
    }
}
