<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\VersionConflictException;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ProgramVersionService
{
    public const OPERATIONS = [
        'set_program_metadata',
        'upsert_template',
        'remove_template',
        'upsert_exercise',
        'remove_exercise',
        'set_schedule_slot',
        'remove_schedule_slot',
    ];

    private readonly ProgramDraftValidator $drafts;
    private readonly TrainingPlanContractValidator $trainingPlan;

    public function __construct(
        private readonly ?PDO $connection = null,
        ?ProgramDraftValidator $drafts = null,
        ?TrainingPlanContractValidator $trainingPlan = null,
    ) {
        $this->trainingPlan = $trainingPlan ?? new TrainingPlanContractValidator();
        $this->drafts = $drafts ?? new ProgramDraftValidator($this->trainingPlan);
    }

    /**
     * @param array{program_id:string,name:string,description?:?string,templates?:array,schedule_slots?:array} $metadata
     * @return array{draft_id:int,lock_version:int,aggregate_hash:string,lifecycle_status:string,aggregate:array}
     */
    public function createProgramDraft(int $userId, array $metadata, string $reason, string $source = 'manual'): array
    {
        $this->positiveId($userId, 'user_id');
        $reason = $this->reason($reason);
        $source = $this->source($source);
        $allowed = ['program_id', 'name', 'description', 'templates', 'schedule_slots'];
        $this->requireKeys($metadata, ['program_id', 'name']);
        $this->rejectUnknownKeys($metadata, $allowed, 'metadata');

        $aggregate = [
            'schema' => ProgramDraftValidator::SCHEMA,
            'schema_version' => ProgramDraftValidator::VERSION,
            'source' => $source,
            'program' => [
                'program_id' => $metadata['program_id'],
                'name' => $metadata['name'],
                'description' => $metadata['description'] ?? null,
                'version' => 1,
                'change_reason' => $reason,
                'parent_version' => null,
                'parent_aggregate_hash' => null,
            ],
            'templates' => $metadata['templates'] ?? [],
            'schedule_slots' => $metadata['schedule_slots'] ?? [],
        ];
        $aggregate = $this->drafts->canonicalAggregate($aggregate);

        return $this->transaction(function (PDO $pdo) use ($userId, $aggregate): array {
            $exists = $pdo->prepare('SELECT id FROM training_programs WHERE user_id=? AND external_program_id=? LIMIT 1' . $this->lockSuffix($pdo));
            $exists->execute([$userId, $aggregate['program']['program_id']]);
            if ($exists->fetchColumn() !== false) {
                throw new RuntimeException('Программа с таким program_id уже существует.');
            }
            $this->validateExerciseReferences($pdo, $userId, $aggregate);

            $insertProgram = $pdo->prepare("INSERT INTO training_programs (user_id,external_program_id,name,description,status,created_at,updated_at) VALUES (?,?,?,?,'draft',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
            $insertProgram->execute([
                $userId,
                $aggregate['program']['program_id'],
                trim($aggregate['program']['name']),
                $aggregate['program']['description'],
            ]);
            $programId = (int) $pdo->lastInsertId();
            $draftId = $this->insertDraftVersion($pdo, $programId, null, $aggregate);
            $this->syncTemplatesAndSchedule($pdo, $userId, $draftId, $aggregate);
            return $this->envelope($draftId, 1, $aggregate);
        });
    }

    /** Alias kept deliberately small for callers that already speak in drafts. */
    public function createDraft(int $userId, array $metadata, string $reason, string $source = 'manual'): array
    {
        return $this->createProgramDraft($userId, $metadata, $reason, $source);
    }

    /**
     * Clone the active version when $sourceVersion is null, or an explicitly
     * selected immutable historical version otherwise.
     */
    public function cloneProgramDraft(
        int $userId,
        string $programExternalId,
        ?int $sourceVersion,
        string $reason,
        string $source = 'manual',
    ): array {
        $this->positiveId($userId, 'user_id');
        if ($sourceVersion !== null) {
            $this->positiveId($sourceVersion, 'source_version');
        }
        $reason = $this->reason($reason);
        $source = $this->source($source);

        return $this->transaction(function (PDO $pdo) use ($userId, $programExternalId, $sourceVersion, $reason, $source): array {
            $programQuery = $pdo->prepare('SELECT * FROM training_programs WHERE user_id=? AND external_program_id=? AND deleted_at IS NULL LIMIT 1' . $this->lockSuffix($pdo));
            $programQuery->execute([$userId, $programExternalId]);
            $program = $programQuery->fetch(PDO::FETCH_ASSOC);
            if (!$program) {
                throw new InvalidArgumentException('Программа не найдена.');
            }

            if ($sourceVersion === null) {
                if ($program['active_version_id'] === null) {
                    throw new InvalidArgumentException('У программы нет однозначной active version для клонирования.');
                }
                $versionQuery = $pdo->prepare('SELECT * FROM program_versions WHERE id=? AND program_id=?' . $this->lockSuffix($pdo));
                $versionQuery->execute([(int) $program['active_version_id'], (int) $program['id']]);
            } else {
                $versionQuery = $pdo->prepare('SELECT * FROM program_versions WHERE program_id=? AND version_number=?' . $this->lockSuffix($pdo));
                $versionQuery->execute([(int) $program['id'], $sourceVersion]);
            }
            $parent = $versionQuery->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                throw new InvalidArgumentException('Выбранная версия программы не найдена.');
            }
            if ($parent['lifecycle_status'] === 'draft') {
                throw new InvalidArgumentException('Клонировать можно только immutable published/archived version.');
            }

            $nextVersion = $this->nextVersionNumber($pdo, (int) $program['id']);
            $aggregate = $this->aggregateFromVersion($pdo, $program, $parent, $source, $reason, $nextVersion);
            $aggregate = $this->drafts->canonicalAggregate($aggregate);
            $this->validateExerciseReferences($pdo, $userId, $aggregate);
            $draftId = $this->insertDraftVersion($pdo, (int) $program['id'], (int) $parent['id'], $aggregate);
            $this->syncTemplatesAndSchedule($pdo, $userId, $draftId, $aggregate);
            return $this->envelope($draftId, 1, $aggregate);
        });
    }

    public function cloneDraft(
        int $userId,
        string $programExternalId,
        ?int $sourceVersion,
        string $reason,
        string $source = 'manual',
    ): array {
        return $this->cloneProgramDraft($userId, $programExternalId, $sourceVersion, $reason, $source);
    }

    /**
     * @return array{draft_id:int,lock_version:int,aggregate_hash:string,lifecycle_status:string,aggregate:array}
     */
    public function updateDraft(
        int $userId,
        int $draftId,
        int $expectedLockVersion,
        string $operation,
        array $payload,
    ): array {
        $this->positiveId($userId, 'user_id');
        $this->positiveId($draftId, 'draft_id');
        $this->positiveId($expectedLockVersion, 'lock_version');
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Неподдерживаемая typed operation: ' . $operation . '.');
        }

        return $this->transaction(function (PDO $pdo) use ($userId, $draftId, $expectedLockVersion, $operation, $payload): array {
            $row = $this->ownedVersion($pdo, $userId, $draftId, true);
            if (!$row) {
                throw new InvalidArgumentException('Черновик программы не найден.');
            }
            if ($row['lifecycle_status'] !== 'draft' || $row['active_version_id'] !== null && (int) $row['active_version_id'] === $draftId) {
                throw new RuntimeException('Published, active и archived versions неизменяемы.');
            }
            if ((int) $row['lock_version'] !== $expectedLockVersion) {
                throw new VersionConflictException('Черновик изменён конкурентно; перечитайте актуальный lock_version.');
            }

            $aggregate = $this->decodeStoredAggregate((string) $row['snapshot_json']);
            $aggregate = $this->applyOperation($aggregate, $operation, $payload);
            $aggregate = $this->drafts->canonicalAggregate($aggregate);
            $this->validateExerciseReferences($pdo, $userId, $aggregate);
            $json = $this->drafts->canonicalJson($aggregate);
            $hash = hash('sha256', $json);

            $update = $pdo->prepare("UPDATE program_versions SET change_reason=?,snapshot_json=?,snapshot_hash=?,aggregate_hash=?,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND program_id=? AND lifecycle_status='draft' AND lock_version=?");
            $update->execute([
                $aggregate['program']['change_reason'], $json, $hash, $hash,
                $draftId, (int) $row['program_id'], $expectedLockVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new VersionConflictException('Черновик изменён конкурентно; операция не применена.');
            }
            $this->syncTemplatesAndSchedule($pdo, $userId, $draftId, $aggregate);
            return $this->envelope($draftId, $expectedLockVersion + 1, $aggregate);
        });
    }

    public function getDraft(int $userId, int $draftId): array
    {
        $this->positiveId($userId, 'user_id');
        $this->positiveId($draftId, 'draft_id');
        $row = $this->ownedVersion($this->pdo(), $userId, $draftId, false);
        if (!$row || $row['lifecycle_status'] !== 'draft') {
            throw new InvalidArgumentException('Черновик программы не найден.');
        }
        $aggregate = $this->drafts->canonicalAggregate($this->decodeStoredAggregate((string) $row['snapshot_json']));
        return $this->envelope($draftId, (int) $row['lock_version'], $aggregate);
    }

    private function aggregateFromVersion(PDO $pdo, array $program, array $parent, string $source, string $reason, int $version): array
    {
        $name = (string) $program['name'];
        $description = $program['description'];
        try {
            $stored = json_decode((string) $parent['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            if (is_array($stored) && ($stored['schema'] ?? null) === ProgramDraftValidator::SCHEMA && is_array($stored['program'] ?? null)) {
                $name = (string) ($stored['program']['name'] ?? $name);
                $description = $stored['program']['description'] ?? $description;
            }
        } catch (JsonException) {
            // Template rows below remain the source for legacy imported versions.
        }

        $templatesQuery = $pdo->prepare('SELECT code,name,workout_type,content_json FROM workout_templates WHERE user_id=? AND program_version_id=? AND deleted_at IS NULL ORDER BY code');
        $templatesQuery->execute([(int) $program['user_id'], (int) $parent['id']]);
        $templates = [];
        foreach ($templatesQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $content = json_decode((string) $row['content_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Сохранённый template JSON повреждён.', 0, $exception);
            }
            if (!is_array($content)) {
                throw new RuntimeException('Сохранённый template JSON должен быть объектом.');
            }
            $template = [
                'template_id' => (string) $row['code'],
                'name' => (string) $row['name'],
                'type' => (string) $row['workout_type'],
                'exercises' => $content['exercises'] ?? null,
            ];
            foreach (['goal', 'estimated_duration_min', 'trainer_notes', 'pre_workout'] as $optional) {
                if (array_key_exists($optional, $content)) {
                    $template[$optional] = $content[$optional];
                }
            }
            $templates[] = $template;
        }

        $slotsQuery = $pdo->prepare('SELECT pss.weekday,wt.code template_id FROM program_schedule_slots pss JOIN workout_templates wt ON wt.id=pss.workout_template_id AND wt.program_version_id=pss.program_version_id WHERE pss.program_version_id=? ORDER BY pss.weekday');
        $slotsQuery->execute([(int) $parent['id']]);
        $slots = array_map(static fn (array $row): array => [
            'weekday' => (int) $row['weekday'],
            'template_id' => (string) $row['template_id'],
        ], $slotsQuery->fetchAll(PDO::FETCH_ASSOC));

        return [
            'schema' => ProgramDraftValidator::SCHEMA,
            'schema_version' => ProgramDraftValidator::VERSION,
            'source' => $source,
            'program' => [
                'program_id' => (string) $program['external_program_id'],
                'name' => $name,
                'description' => $description,
                'version' => $version,
                'change_reason' => $reason,
                'parent_version' => (int) $parent['version_number'],
                'parent_aggregate_hash' => (string) $parent['aggregate_hash'],
            ],
            'templates' => $templates,
            'schedule_slots' => $slots,
        ];
    }

    private function applyOperation(array $aggregate, string $operation, array $payload): array
    {
        if ($operation === 'set_program_metadata') {
            $this->rejectUnknownKeys($payload, ['name', 'description', 'change_reason'], 'payload');
            if ($payload === []) {
                throw new InvalidArgumentException('set_program_metadata требует хотя бы одно поле.');
            }
            foreach ($payload as $key => $value) {
                $aggregate['program'][$key] = $value;
            }
            return $aggregate;
        }

        if ($operation === 'upsert_template') {
            $template = isset($payload['template']) && count($payload) === 1 ? $payload['template'] : $payload;
            if (!is_array($template)) {
                throw new InvalidArgumentException('upsert_template требует объект template.');
            }
            $this->requireKeys($template, ['template_id']);
            $index = $this->templateIndex($aggregate, (string) $template['template_id']);
            if ($index === null) {
                $aggregate['templates'][] = $template;
            } else {
                $aggregate['templates'][$index] = $template;
            }
            return $aggregate;
        }

        if ($operation === 'remove_template') {
            $this->exactPayload($payload, ['template_id']);
            $index = $this->requiredTemplateIndex($aggregate, $payload['template_id']);
            array_splice($aggregate['templates'], $index, 1);
            return $aggregate;
        }

        if ($operation === 'upsert_exercise') {
            $this->exactPayload($payload, ['template_id', 'exercise']);
            if (!is_array($payload['exercise'])) {
                throw new InvalidArgumentException('payload.exercise должен быть объектом.');
            }
            $this->requireKeys($payload['exercise'], ['exercise_id']);
            $templateIndex = $this->requiredTemplateIndex($aggregate, $payload['template_id']);
            $exerciseIndex = null;
            foreach ($aggregate['templates'][$templateIndex]['exercises'] as $index => $exercise) {
                if (($exercise['exercise_id'] ?? null) === $payload['exercise']['exercise_id']) {
                    $exerciseIndex = $index;
                    break;
                }
            }
            if ($exerciseIndex === null) {
                $aggregate['templates'][$templateIndex]['exercises'][] = $payload['exercise'];
            } else {
                $aggregate['templates'][$templateIndex]['exercises'][$exerciseIndex] = $payload['exercise'];
            }
            return $aggregate;
        }

        if ($operation === 'remove_exercise') {
            $this->exactPayload($payload, ['template_id', 'exercise_id']);
            $templateIndex = $this->requiredTemplateIndex($aggregate, $payload['template_id']);
            foreach ($aggregate['templates'][$templateIndex]['exercises'] as $index => $exercise) {
                if (($exercise['exercise_id'] ?? null) === $payload['exercise_id']) {
                    array_splice($aggregate['templates'][$templateIndex]['exercises'], $index, 1);
                    return $aggregate;
                }
            }
            throw new InvalidArgumentException('Упражнение не найдено в шаблоне.');
        }

        if ($operation === 'set_schedule_slot') {
            $this->exactPayload($payload, ['weekday', 'template_id']);
            foreach ($aggregate['schedule_slots'] as $index => $slot) {
                if (($slot['weekday'] ?? null) === $payload['weekday']) {
                    $aggregate['schedule_slots'][$index] = $payload;
                    return $aggregate;
                }
            }
            $aggregate['schedule_slots'][] = $payload;
            return $aggregate;
        }

        $this->exactPayload($payload, ['weekday']);
        foreach ($aggregate['schedule_slots'] as $index => $slot) {
            if (($slot['weekday'] ?? null) === $payload['weekday']) {
                array_splice($aggregate['schedule_slots'], $index, 1);
                return $aggregate;
            }
        }
        throw new InvalidArgumentException('Schedule slot не найден.');
    }

    private function syncTemplatesAndSchedule(PDO $pdo, int $userId, int $draftId, array $aggregate): void
    {
        $deleteSlots = $pdo->prepare('DELETE FROM program_schedule_slots WHERE program_version_id=?');
        $deleteSlots->execute([$draftId]);

        $existingQuery = $pdo->prepare('SELECT id,code FROM workout_templates WHERE program_version_id=?');
        $existingQuery->execute([$draftId]);
        $existing = [];
        foreach ($existingQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(string) $row['code']] = (int) $row['id'];
        }

        $wanted = [];
        $templateIds = [];
        $update = $pdo->prepare('UPDATE workout_templates SET name=?,workout_type=?,content_json=?,content_hash=?,updated_at=CURRENT_TIMESTAMP,deleted_at=NULL WHERE id=? AND user_id=? AND program_version_id=?');
        $insert = $pdo->prepare('INSERT INTO workout_templates (user_id,program_version_id,code,name,workout_type,content_json,content_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        foreach ($aggregate['templates'] as $template) {
            $code = $template['template_id'];
            $wanted[$code] = true;
            $content = $template;
            unset($content['template_id']);
            $contentJson = $this->trainingPlan->canonicalJson($content);
            $contentHash = hash('sha256', $contentJson);
            if (isset($existing[$code])) {
                $id = $existing[$code];
                $update->execute([$template['name'], $template['type'], $contentJson, $contentHash, $id, $userId, $draftId]);
            } else {
                $insert->execute([$userId, $draftId, $code, $template['name'], $template['type'], $contentJson, $contentHash]);
                $id = (int) $pdo->lastInsertId();
            }
            $templateIds[$code] = $id;
        }

        $deleteTemplate = $pdo->prepare('DELETE FROM workout_templates WHERE id=? AND user_id=? AND program_version_id=?');
        foreach ($existing as $code => $id) {
            if (!isset($wanted[$code])) {
                $deleteTemplate->execute([$id, $userId, $draftId]);
            }
        }

        $insertSlot = $pdo->prepare('INSERT INTO program_schedule_slots (program_version_id,workout_template_id,weekday,created_at) VALUES (?,?,?,CURRENT_TIMESTAMP)');
        foreach ($aggregate['schedule_slots'] as $slot) {
            $insertSlot->execute([$draftId, $templateIds[$slot['template_id']], $slot['weekday']]);
        }
    }

    private function validateExerciseReferences(PDO $pdo, int $userId, array $aggregate): void
    {
        $ids = [];
        foreach ($aggregate['templates'] as $template) {
            foreach ($template['exercises'] as $exercise) {
                $ids[$exercise['exercise_id']] = true;
            }
        }
        if ($ids === []) {
            return;
        }
        $values = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $query = $pdo->prepare("SELECT exercise_id FROM exercises WHERE exercise_id IN ({$placeholders}) AND status='active' AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id=?)");
        $query->execute([...$values, $userId]);
        $available = array_fill_keys(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)), true);
        $missing = array_values(array_diff($values, array_keys($available)));
        sort($missing, SORT_STRING);
        if ($missing !== []) {
            throw new InvalidArgumentException('Недоступные или неизвестные exercise_id: ' . implode(', ', $missing) . '.');
        }
    }

    private function insertDraftVersion(PDO $pdo, int $programId, ?int $parentId, array $aggregate): int
    {
        $json = $this->drafts->canonicalJson($aggregate);
        $hash = hash('sha256', $json);
        $insert = $pdo->prepare("INSERT INTO program_versions (program_id,version_number,source,change_reason,trainer_comment,snapshot_json,snapshot_hash,parent_version_id,created_at,lifecycle_status,lock_version,aggregate_hash,updated_at) VALUES (?,?,?,?,NULL,?,?,?,CURRENT_TIMESTAMP,'draft',1,?,CURRENT_TIMESTAMP)");
        $insert->execute([
            $programId,
            $aggregate['program']['version'],
            $aggregate['source'],
            $aggregate['program']['change_reason'],
            $json,
            $hash,
            $parentId,
            $hash,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function nextVersionNumber(PDO $pdo, int $programId): int
    {
        $query = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM program_versions WHERE program_id=?');
        $query->execute([$programId]);
        $next = (int) $query->fetchColumn();
        if ($next < 1 || $next > 100000) {
            throw new RuntimeException('Исчерпан диапазон номеров версий программы.');
        }
        return $next;
    }

    private function ownedVersion(PDO $pdo, int $userId, int $versionId, bool $lock): array|false
    {
        $sql = 'SELECT pv.*,p.user_id,p.external_program_id,p.active_version_id FROM program_versions pv JOIN training_programs p ON p.id=pv.program_id WHERE pv.id=? AND p.user_id=? AND p.deleted_at IS NULL';
        if ($lock) {
            $sql .= $this->lockSuffix($pdo);
        }
        $query = $pdo->prepare($sql);
        $query->execute([$versionId, $userId]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function decodeStoredAggregate(string $json): array
    {
        try {
            $aggregate = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Сохранённый program draft JSON повреждён.', 0, $exception);
        }
        if (!is_array($aggregate) || array_is_list($aggregate)) {
            throw new RuntimeException('Сохранённый program draft должен быть объектом.');
        }
        $this->drafts->validate($aggregate);
        return $aggregate;
    }

    private function envelope(int $draftId, int $lockVersion, array $aggregate): array
    {
        return [
            'draft_id' => $draftId,
            'lock_version' => $lockVersion,
            'aggregate_hash' => $this->drafts->canonicalHash($aggregate),
            'lifecycle_status' => 'draft',
            'aggregate' => $aggregate,
        ];
    }

    private function templateIndex(array $aggregate, string $templateId): ?int
    {
        foreach ($aggregate['templates'] as $index => $template) {
            if (($template['template_id'] ?? null) === $templateId) {
                return $index;
            }
        }
        return null;
    }

    private function requiredTemplateIndex(array $aggregate, mixed $templateId): int
    {
        if (!is_string($templateId)) {
            throw new InvalidArgumentException('template_id должен быть строкой.');
        }
        $index = $this->templateIndex($aggregate, $templateId);
        if ($index === null) {
            throw new InvalidArgumentException('Шаблон ' . $templateId . ' не найден.');
        }
        return $index;
    }

    private function exactPayload(array $payload, array $keys): void
    {
        $this->requireKeys($payload, $keys);
        $this->rejectUnknownKeys($payload, $keys, 'payload');
    }

    private function requireKeys(array $value, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Отсутствует обязательное поле ' . $key . '.');
            }
        }
    }

    private function rejectUnknownKeys(array $value, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Неизвестное поле ' . $path . '.' . reset($unknown) . '.');
        }
    }

    private function positiveId(int $value, string $path): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($path . ' должен быть положительным целым числом.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('change reason обязателен и не должен превышать 1000 символов.');
        }
        return $reason;
    }

    private function source(string $source): string
    {
        if (!in_array($source, ['manual', 'webmcp'], true)) {
            throw new InvalidArgumentException('source должно быть manual или webmcp.');
        }
        return $source;
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($pdo);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function lockSuffix(PDO $pdo): string
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }
}
