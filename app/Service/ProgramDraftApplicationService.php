<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\RequestContext;
use PDO;
use Throwable;

final class ProgramDraftApplicationService
{
    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?WriteIdempotencyService $idempotency = null,
    ) {}

    public function create(int $userId, array $metadata, string $reason, string $key): array
    {
        $request = ['metadata' => $metadata, 'reason' => $reason];
        return $this->transaction(function (PDO $pdo) use ($userId, $metadata, $reason, $key, $request): array {
            $receipts = $this->idempotency ?? new WriteIdempotencyService();
            if ($saved = $receipts->replay($pdo, $userId, $key, 'program_draft.create', $request)) {
                return $saved;
            }
            $result = (new ProgramVersionService($pdo))->createProgramDraft($userId, $metadata, $reason, 'webmcp');
            $result['idempotent'] = false;
            $this->audit($pdo, $userId, (string) $result['draft_id'], 'draft_create', null, [
                'program_id' => $result['aggregate']['program']['program_id'],
                'version' => $result['aggregate']['program']['version'],
                'lock_version' => $result['lock_version'],
                'aggregate_hash' => $result['aggregate_hash'],
            ]);
            $receipts->store($pdo, $userId, $key, 'program_draft.create', $request, $result);
            return $result;
        });
    }

    public function update(int $userId, int $draftId, int $lockVersion, string $operation, array $payload, string $key): array
    {
        $request = ['draft_id' => $draftId, 'lock_version' => $lockVersion, 'operation' => $operation, 'payload' => $payload];
        return $this->transaction(function (PDO $pdo) use ($userId, $draftId, $lockVersion, $operation, $payload, $key, $request): array {
            $receipts = $this->idempotency ?? new WriteIdempotencyService();
            if ($saved = $receipts->replay($pdo, $userId, $key, 'program_draft.update', $request)) {
                return $saved;
            }
            $versions = new ProgramVersionService($pdo);
            $before = $versions->getDraft($userId, $draftId);
            $result = $versions->updateDraft($userId, $draftId, $lockVersion, $operation, $payload);
            $result['idempotent'] = false;
            $this->audit($pdo, $userId, (string) $draftId, 'draft_update', [
                'lock_version' => $before['lock_version'], 'aggregate_hash' => $before['aggregate_hash'],
            ], [
                'operation' => $operation, 'lock_version' => $result['lock_version'], 'aggregate_hash' => $result['aggregate_hash'],
            ]);
            $receipts->store($pdo, $userId, $key, 'program_draft.update', $request, $result);
            return $result;
        });
    }

    public function clone(int $userId, string $programId, ?int $sourceVersion, string $reason, string $key): array
    {
        $request = ['program_id' => $programId, 'source_version' => $sourceVersion, 'reason' => $reason];
        return $this->transaction(function (PDO $pdo) use ($userId, $programId, $sourceVersion, $reason, $key, $request): array {
            $receipts = $this->idempotency ?? new WriteIdempotencyService();
            if ($saved = $receipts->replay($pdo, $userId, $key, 'program_draft.clone', $request)) {
                return $saved;
            }
            $result = (new ProgramVersionService($pdo))->cloneProgramDraft($userId, $programId, $sourceVersion, $reason, 'webmcp');
            $result['idempotent'] = false;
            $this->audit($pdo, $userId, (string) $result['draft_id'], 'draft_clone', null, [
                'program_id' => $result['aggregate']['program']['program_id'],
                'version' => $result['aggregate']['program']['version'],
                'parent_version' => $result['aggregate']['program']['parent_version'],
                'lock_version' => $result['lock_version'],
                'aggregate_hash' => $result['aggregate_hash'],
            ]);
            $receipts->store($pdo, $userId, $key, 'program_draft.clone', $request, $result);
            return $result;
        });
    }

    private function audit(PDO $pdo, int $userId, string $entityId, string $action, ?array $before, ?array $after): void
    {
        $insert = $pdo->prepare('INSERT INTO audit_logs (user_id,entity_type,entity_id,action,source,request_id,before_json,after_json,ip_address,created_at) VALUES (?,\'program_version\',?,?,\'webmcp\',?,?,?,?,CURRENT_TIMESTAMP)');
        $insert->execute([
            $userId, $entityId, $action, RequestContext::requestId(),
            $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->connection ?? \db()->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($pdo);
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
