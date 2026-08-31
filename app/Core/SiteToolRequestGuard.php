<?php

declare(strict_types=1);

namespace App\Core;

use App\Service\AssistantAuditService;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Shared HTTP boundary for authenticated, read-only assistant endpoints. */
final class SiteToolRequestGuard
{
    private const DEFAULT_RATE_LIMIT = 60;
    private const DEFAULT_RATE_WINDOW_SECONDS = 60;
    private const MAX_QUERY_BYTES = 4096;
    private const MAX_QUERY_FIELDS = 12;
    private const MAX_QUERY_VALUE_BYTES = 1024;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?AssistantAuditService $audit = null,
        private readonly ?AssistantRateLimiter $rateLimiter = null,
    ) {}

    /**
     * @param list<string> $allowedQuery
     * @param callable(int, array<string, string>): ?array<string, mixed> $handler
     */
    public function run(
        string $toolName,
        array $allowedQuery,
        callable $handler,
        ?string $entityType = null,
        ?string $entityId = null
    ): never {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Vary: Cookie');

        $userId = null;
        $startedAt = hrtime(true);
        try {
            if (!FeatureFlags::enabled(FeatureFlags::WEBMCP_READ_ENABLED)) {
                throw new ApiError('not_found', 'Маршрут не найден.', 404);
            }

            $user = Auth::requireUser(true);
            $userId = (int) $user['id'];
            FetchMetadata::requireSameOriginIfPresent();
            $this->enforceRateLimit($userId, $toolName);
            $query = $this->query($allowedQuery);
            $this->requireEmptyBody();

            $data = $handler($userId, $query);
            if ($data === null) {
                throw new ApiError('not_found', 'Объект не найден.', 404);
            }

            $this->record($userId, $toolName, 'success', [
                'entity_type' => $entityType,
                'entity_id' => $this->auditableIdentifier($entityId),
                'duration_ms' => $this->durationMs($startedAt),
                'result_count' => $this->resultCount($data),
                'status' => 200,
            ]);
            \json_response([
                'data' => $data,
                'meta' => ['request_id' => RequestContext::requestId()],
            ]);
        } catch (VersionConflictException $exception) {
            $this->fail($userId, $toolName, new ApiError('version_conflict', $exception->getMessage(), 409), $startedAt, $entityType, $entityId);
        } catch (InvalidArgumentException $exception) {
            $this->fail($userId, $toolName, new ApiError('validation_error', $exception->getMessage(), 422), $startedAt, $entityType, $entityId);
        } catch (ApiError $error) {
            $this->fail($userId, $toolName, $error, $startedAt, $entityType, $entityId);
        } catch (Throwable $exception) {
            if ($userId !== null) {
                $this->record($userId, $toolName, 'error', [
                    'entity_type' => $entityType,
                    'entity_id' => $this->auditableIdentifier($entityId),
                    'duration_ms' => $this->durationMs($startedAt),
                    'error_code' => 'internal_error',
                    'status' => 500,
                ]);
            }
            throw $exception;
        }
    }

    /** @param array<string, string> $query */
    public static function optionalInteger(array $query, string $field, int $min, int $max): ?int
    {
        if (!array_key_exists($field, $query)) {
            return null;
        }
        $value = $query[$field];
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException($field . ' должен быть целым числом.');
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if (!is_int($parsed)) {
            throw new InvalidArgumentException($field . " должен быть целым числом от {$min} до {$max}.");
        }
        return $parsed;
    }

    public static function positiveRouteInteger(string $value, string $field = 'id'): int
    {
        try {
            return ApiInput::positiveId($value, $field);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException($field . ' должен быть положительным целым числом.');
        }
    }

    /** @param list<string> $allowed */
    private function query(array $allowed): array
    {
        $raw = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if (strlen($raw) > self::MAX_QUERY_BYTES) {
            throw new InvalidArgumentException('Query string превышает допустимый размер.');
        }
        if ($raw === '') {
            return [];
        }

        $parts = explode('&', $raw);
        if (count($parts) > self::MAX_QUERY_FIELDS) {
            throw new InvalidArgumentException('Слишком много query-параметров.');
        }
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException('Query string содержит пустой параметр.');
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1 || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Недопустимый query-параметр: ' . ($key !== '' ? $key : '(пустой)') . '.');
            }
            if (array_key_exists($key, $result)) {
                throw new InvalidArgumentException('Query-параметр ' . $key . ' не должен повторяться.');
            }
            if (strlen($value) > self::MAX_QUERY_VALUE_BYTES) {
                throw new InvalidArgumentException('Query-параметр ' . $key . ' превышает допустимый размер.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function requireEmptyBody(): void
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null && (!is_string($contentLength) || preg_match('/^[0-9]+$/D', $contentLength) !== 1)) {
            throw new InvalidArgumentException('Некорректный Content-Length.');
        }
        if ((int) ($contentLength ?? 0) > 0 || !empty($_SERVER['HTTP_TRANSFER_ENCODING'])) {
            throw new InvalidArgumentException('GET endpoint не принимает request body.');
        }
    }

    private function enforceRateLimit(int $userId, string $toolName): void
    {
        ($this->rateLimiter ?? new AssistantRateLimiter($this->connection))->enforce(
            $userId,
            $toolName,
            'WEBMCP_READ_RATE_LIMIT',
            'WEBMCP_READ_RATE_WINDOW_SECONDS',
            self::DEFAULT_RATE_LIMIT,
            self::DEFAULT_RATE_WINDOW_SECONDS,
        );
    }

    private function fail(?int $userId, string $toolName, ApiError $error, int $startedAt, ?string $entityType, ?string $entityId): never
    {
        if ($userId !== null) {
            $this->record($userId, $toolName, $error->status() === 401 || $error->status() === 429 ? 'denied' : 'error', [
                'entity_type' => $entityType,
                'entity_id' => $this->auditableIdentifier($entityId),
                'duration_ms' => $this->durationMs($startedAt),
                'error_code' => $error->errorCode(),
                'status' => $error->status(),
            ]);
        }
        \json_response($error->envelope(), $error->status());
    }

    /** @param array<string, mixed> $context */
    private function record(int $userId, string $toolName, string $outcome, array $context): void
    {
        ($this->audit ?? new AssistantAuditService($this->connection))->record($userId, $toolName, $outcome, $context);
    }

    private function durationMs(int $startedAt): int
    {
        return min(86400000, max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)));
    }

    private function auditableIdentifier(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private function resultCount(array $data): int
    {
        foreach (['items', 'candidates', 'concrete_plans'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return count($data[$key]);
            }
        }
        return 1;
    }
}
