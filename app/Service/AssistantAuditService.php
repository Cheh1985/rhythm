<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\RequestContext;
use InvalidArgumentException;
use PDO;

final class AssistantAuditService
{
    private const OUTCOMES = ['success', 'error', 'denied'];
    private const METADATA_KEYS = [
        'confirmation_required', 'duration_ms', 'filter_count', 'idempotent',
        'result_count', 'status', 'version',
    ];
    private const SENSITIVE_KEY_PATTERN = '/prompt|csrf|cookie|authorization|password|secret|token|comment|input|output|payload/i';

    public function __construct(private readonly ?PDO $pdo = null) {}

    /** @param array<string, mixed> $context */
    public function record(int $userId, string $toolName, string $outcome, array $context = []): int
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Некорректный user_id для assistant audit.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{2,79}$/D', $toolName) !== 1) {
            throw new InvalidArgumentException('Некорректное имя assistant tool.');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Некорректный outcome assistant tool.');
        }

        $entityType = $this->optionalIdentifier($context['entity_type'] ?? null, 60, 'entity_type');
        $entityId = $this->optionalIdentifier($context['entity_id'] ?? null, 80, 'entity_id');
        $errorCode = $this->optionalIdentifier($context['error_code'] ?? null, 64, 'error_code');
        $durationMs = $context['duration_ms'] ?? null;
        if ($durationMs !== null && (!is_int($durationMs) || $durationMs < 0 || $durationMs > 86400000)) {
            throw new InvalidArgumentException('Некорректный duration_ms для assistant audit.');
        }

        $metadata = [];
        foreach (self::METADATA_KEYS as $key) {
            if ($key === 'duration_ms' || !array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && mb_strlen($value) <= 80) || $value === null) {
                $metadata[$key] = $value;
            }
        }
        $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        $statement = $this->connection()->prepare(
            'INSERT INTO assistant_tool_calls (user_id,request_id,tool_name,outcome,entity_type,entity_id,error_code,duration_ms,metadata_json,created_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            $userId,
            RequestContext::requestId(),
            $toolName,
            $outcome,
            $entityType,
            $entityId,
            $errorCode,
            $durationMs,
            $metadataJson,
        ]);
        return (int) $this->connection()->lastInsertId();
    }

    /** @return array<string, mixed> */
    public static function redact(array $value): array
    {
        $redact = static function (array $items, int $depth = 0) use (&$redact): array {
            if ($depth >= 4) {
                return [];
            }
            $result = [];
            foreach (array_slice($items, 0, 50, true) as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                    continue;
                }
                if (is_array($item)) {
                    $result[$key] = $redact($item, $depth + 1);
                } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                    $result[$key] = $item;
                } elseif (is_string($item)) {
                    $result[$key] = mb_substr($item, 0, 160);
                }
            }
            return $result;
        };
        return $redact($value);
    }

    private function optionalIdentifier(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || mb_strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный ' . $field . ' для assistant audit.');
        }
        return $value;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? \db()->pdo();
    }
}
