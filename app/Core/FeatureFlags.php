<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class FeatureFlags
{
    public const WEBMCP_ALLOWED_USER_IDS = 'WEBMCP_ALLOWED_USER_IDS';
    public const WEBMCP_ENABLED = 'WEBMCP_ENABLED';
    public const WEBMCP_READ_ENABLED = 'WEBMCP_READ_ENABLED';
    public const WEBMCP_DRAFT_WRITE_ENABLED = 'WEBMCP_DRAFT_WRITE_ENABLED';
    public const WEBMCP_INSTANCE_WRITE_ENABLED = 'WEBMCP_INSTANCE_WRITE_ENABLED';
    public const WEBMCP_ACTIVATION_ENABLED = 'WEBMCP_ACTIVATION_ENABLED';

    private const FLAGS = [
        self::WEBMCP_ENABLED,
        self::WEBMCP_READ_ENABLED,
        self::WEBMCP_DRAFT_WRITE_ENABLED,
        self::WEBMCP_INSTANCE_WRITE_ENABLED,
        self::WEBMCP_ACTIVATION_ENABLED,
    ];

    public static function enabled(string $flag): bool
    {
        if (!in_array($flag, self::FLAGS, true)) {
            throw new InvalidArgumentException('Неизвестный feature flag: ' . $flag . '.');
        }

        $configured = self::configured($flag);
        return $flag === self::WEBMCP_ENABLED
            ? $configured
            : self::configured(self::WEBMCP_ENABLED) && $configured;
    }

    public static function enabledForUser(string $flag, int $userId): bool
    {
        return self::enabled($flag) && self::userAllowed($userId);
    }

    public static function userAllowed(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $configured = trim((string) \env(self::WEBMCP_ALLOWED_USER_IDS, ''));
        if ($configured === '' || $configured === '*') {
            return true;
        }

        $allowed = [];
        foreach (explode(',', $configured) as $value) {
            $value = trim($value);
            if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
                return false;
            }
            $allowed[(int) $value] = true;
        }

        return isset($allowed[$userId]);
    }

    public static function configured(string $flag): bool
    {
        if (!in_array($flag, self::FLAGS, true)) {
            throw new InvalidArgumentException('Неизвестный feature flag: ' . $flag . '.');
        }

        return filter_var(\env($flag, 'false'), FILTER_VALIDATE_BOOL) === true;
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        $result = [];
        foreach (self::FLAGS as $flag) {
            $result[$flag] = self::enabled($flag);
        }
        return $result;
    }
}
