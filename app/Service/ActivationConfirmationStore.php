<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class ActivationConfirmationStore
{
    private const SESSION_KEY = 'program_activation_confirmation';
    private const DEFAULT_TTL_SECONDS = 300;

    public function __construct(private readonly ?Closure $clock = null) {}

    public function prepare(int $userId, array $binding, array $preview, ?int $ttlSeconds = null): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Некорректный пользователь подтверждения.');
        }
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        if ($ttl < 1 || $ttl > 900) {
            throw new InvalidArgumentException('TTL подтверждения должен быть от 1 до 900 секунд.');
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $expiresAt = $now + $ttl;
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $userId,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'binding' => $binding,
            'preview' => $preview,
            'preview_hash' => self::hash($preview),
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ];
        return [
            'confirmation_token' => $token,
            'confirmation_required' => true,
            'expires_at_utc' => gmdate('c', $expiresAt),
            'confirmation_url' => \url('/assistant#activation-confirmation'),
            'preview' => $preview,
        ];
    }

    public function peek(int $userId): ?array
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== $userId || (int) ($stored['expires_at'] ?? 0) < $this->now()) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        if (!is_string($stored['token'] ?? null) || !is_array($stored['preview'] ?? null)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return [
            'confirmation_token' => (string) $stored['token'],
            'preview' => $stored['preview'],
            'expires_at_utc' => gmdate('c', (int) $stored['expires_at']),
        ];
    }

    /** Consumes first, then validates, so failures and replay cannot reuse it. */
    public function consume(int $userId, string $token): array
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($stored) || (int) ($stored['user_id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('Подтверждение отсутствует или уже использовано.');
        }
        if ((int) ($stored['expires_at'] ?? 0) < $this->now()) {
            throw new InvalidArgumentException('Срок подтверждения истёк. Подготовьте activation заново.');
        }
        if (!is_string($token) || !isset($stored['token_hash']) || !hash_equals((string) $stored['token_hash'], hash('sha256', $token))) {
            throw new InvalidArgumentException('Некорректный одноразовый токен подтверждения.');
        }
        if (!is_array($stored['binding'] ?? null) || !is_array($stored['preview'] ?? null) || !is_string($stored['preview_hash'] ?? null)) {
            throw new RuntimeException('Повреждено session-bound подтверждение.');
        }
        return [
            'binding' => $stored['binding'],
            'preview' => $stored['preview'],
            'preview_hash' => $stored['preview_hash'],
        ];
    }

    public function cancel(int $userId, string $token): void
    {
        $this->consume($userId, $token);
    }

    public static function hash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (array_is_list($item)) return array_map($sort, $item);
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
