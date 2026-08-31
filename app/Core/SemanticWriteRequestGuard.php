<?php

declare(strict_types=1);

namespace App\Core;

use App\Service\AssistantAuditService;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Strict JSON boundary for same-origin semantic write endpoints. */
final class SemanticWriteRequestGuard
{
    private const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?AssistantAuditService $audit = null,
    ) {}

    public function run(
        string $operation,
        string $featureFlag,
        callable $handler,
        int $successStatus = 200,
        bool $requireIdempotencyKey = true,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $recordSuccess = true,
    ): never {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Vary: Cookie, Origin');
        $startedAt = hrtime(true);
        $userId = null;
        try {
            if (!FeatureFlags::enabled($featureFlag)) {
                throw new ApiError('not_found', 'Маршрут не найден.', 404);
            }
            $user = Auth::requireUser(true);
            $userId = (int) $user['id'];
            SameOrigin::requireValid();
            if (!Csrf::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                throw new ApiError('csrf_failed', 'Сессия формы истекла. Обновите страницу.', 419);
            }
            if ((string) ($_SERVER['QUERY_STRING'] ?? '') !== '') {
                throw new InvalidArgumentException('Write endpoint не принимает query-параметры.');
            }
            $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
            if ($contentType !== 'application/json') {
                throw new ApiError('unsupported_media_type', 'Ожидается Content-Type application/json.', 415);
            }
            $body = ApiInput::jsonObject((string) file_get_contents('php://input'), self::MAX_BODY_BYTES);
            $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            if ($requireIdempotencyKey && $key === '') {
                throw new InvalidArgumentException('Заголовок Idempotency-Key обязателен.');
            }
            $data = $handler($userId, $body, $key);
            if (!is_array($data)) {
                throw new \RuntimeException('Semantic write handler должен вернуть объект.');
            }
            if ($recordSuccess) {
                $this->record($userId, $operation, 'success', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId ?? (isset($data['draft_id']) ? (string) $data['draft_id'] : null),
                    'duration_ms' => $this->durationMs($startedAt),
                    'idempotent' => (bool) ($data['idempotent'] ?? false),
                    'confirmation_required' => (bool) ($data['confirmation_required'] ?? false),
                    'status' => $successStatus,
                    'version' => isset($data['lock_version']) ? (int) $data['lock_version'] : (isset($data['instance_version']) ? (int) $data['instance_version'] : null),
                ]);
            }
            \json_response(['data' => $data, 'meta' => ['request_id' => RequestContext::requestId()]], $successStatus);
        } catch (VersionConflictException $exception) {
            $this->fail($userId, $operation, new ApiError('version_conflict', $exception->getMessage(), 409), $startedAt, $entityType, $entityId);
        } catch (InvalidArgumentException $exception) {
            $this->fail($userId, $operation, new ApiError('validation_error', $exception->getMessage(), 422), $startedAt, $entityType, $entityId);
        } catch (ApiError $error) {
            $this->fail($userId, $operation, $error, $startedAt, $entityType, $entityId);
        } catch (Throwable $exception) {
            if ($userId !== null) {
                $this->record($userId, $operation, 'error', [
                    'entity_type' => $entityType, 'entity_id' => $entityId,
                    'duration_ms' => $this->durationMs($startedAt), 'error_code' => 'internal_error', 'status' => 500,
                ]);
            }
            throw $exception;
        }
    }

    private function fail(?int $userId, string $operation, ApiError $error, int $startedAt, ?string $entityType, ?string $entityId): never
    {
        if ($userId !== null) {
            $this->record($userId, $operation, in_array($error->status(), [401, 403, 419, 429], true) ? 'denied' : 'error', [
                'entity_type' => $entityType, 'entity_id' => $entityId,
                'duration_ms' => $this->durationMs($startedAt), 'error_code' => $error->errorCode(), 'status' => $error->status(),
            ]);
        }
        \json_response($error->envelope(), $error->status());
    }

    private function record(int $userId, string $operation, string $outcome, array $context): void
    {
        ($this->audit ?? new AssistantAuditService($this->connection))->record($userId, $operation, $outcome, $context);
    }

    private function durationMs(int $startedAt): int
    {
        return min(86400000, max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)));
    }
}
