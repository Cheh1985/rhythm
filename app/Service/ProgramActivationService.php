<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\RequestContext;
use App\Core\VersionConflictException;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ProgramActivationService
{
    public const POLICIES = ['keep', 'supersede'];

    private readonly ProgramDraftValidator $drafts;
    private readonly TrainingPlanContractValidator $plans;

    public function __construct(
        private readonly ?PDO $connection = null,
        ?ProgramDraftValidator $drafts = null,
        ?TrainingPlanContractValidator $plans = null,
    ) {
        $this->plans = $plans ?? new TrainingPlanContractValidator();
        $this->drafts = $drafts ?? new ProgramDraftValidator($this->plans);
    }

    public function preview(
        int $userId,
        int $draftId,
        int $expectedLockVersion,
        string $aggregateHash,
        string $effectiveFrom,
        int $horizonWeeks,
        string $futurePlanPolicy,
    ): array {
        $input = $this->validateInput($userId, $draftId, $expectedLockVersion, $aggregateHash, $effectiveFrom, $horizonWeeks, $futurePlanPolicy);
        return $this->buildImpact($this->pdo(), $input, false);
    }

    /** @param array{binding:array,preview:array,preview_hash:string} $confirmation */
    public function activate(array $confirmation): array
    {
        $binding = $confirmation['binding'] ?? null;
        if (!is_array($binding)) {
            throw new InvalidArgumentException('Подтверждение activation повреждено.');
        }
        $input = $this->validateInput(
            (int) ($binding['user_id'] ?? 0),
            (int) ($binding['draft_id'] ?? 0),
            (int) ($binding['lock_version'] ?? 0),
            (string) ($binding['aggregate_hash'] ?? ''),
            (string) ($binding['effective_from'] ?? ''),
            (int) ($binding['horizon_weeks'] ?? 0),
            (string) ($binding['future_plan_policy'] ?? ''),
        );
        $expectedPreviewHash = $confirmation['preview_hash'] ?? null;
        if (!is_string($expectedPreviewHash) || preg_match('/^[a-f0-9]{64}$/D', $expectedPreviewHash) !== 1) {
            throw new InvalidArgumentException('Подтверждение не содержит корректный preview hash.');
        }

        return $this->transaction(function (PDO $pdo) use ($input, $expectedPreviewHash): array {
            $userLock = $pdo->prepare('SELECT id FROM users WHERE id=?' . $this->lockSuffix($pdo));
            $userLock->execute([$input['user_id']]);
            if ($userLock->fetchColumn() === false) {
                throw new InvalidArgumentException('Пользователь не найден.');
            }
            $impact = $this->buildImpact($pdo, $input, true);
            if (!hash_equals($expectedPreviewHash, ActivationConfirmationStore::hash($impact))) {
                throw new VersionConflictException('Impact preview устарел; подготовьте activation заново.');
            }

            $draft = $this->ownedDraft($pdo, $input['user_id'], $input['draft_id'], true);
            $aggregate = $draft['aggregate'];
            $oldProgram = [
                'status' => (string) $draft['program_status'],
                'active_version_id' => $draft['active_version_id'] === null ? null : (int) $draft['active_version_id'],
            ];

            $pause = $pdo->prepare("UPDATE training_programs SET status='paused',updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND id<>? AND status='active' AND deleted_at IS NULL");
            $pause->execute([$input['user_id'], (int) $draft['program_id']]);

            $publish = $pdo->prepare("UPDATE program_versions SET lifecycle_status='published',lock_version=lock_version+1,activated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND program_id=? AND lifecycle_status='draft' AND lock_version=? AND aggregate_hash=?");
            $publish->execute([$input['draft_id'], (int) $draft['program_id'], $input['lock_version'], $input['aggregate_hash']]);
            if ($publish->rowCount() !== 1) {
                throw new VersionConflictException('Draft изменился перед activation.');
            }

            $program = $pdo->prepare("UPDATE training_programs SET name=?,description=?,status='active',active_version_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND deleted_at IS NULL");
            $program->execute([
                trim((string) $aggregate['program']['name']), $aggregate['program']['description'], $input['draft_id'],
                (int) $draft['program_id'], $input['user_id'],
            ]);
            if ($program->rowCount() !== 1) {
                throw new RuntimeException('Не удалось активировать программу.');
            }

            $supersededIds = [];
            if ($input['policy'] === 'supersede') {
                foreach ($impact['future_plans']['superseded'] as $plan) {
                    $update = $pdo->prepare("UPDATE workout_plans SET status='cancelled',deleted_at=CURRENT_TIMESTAMP,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE external_plan_id=? AND user_id=? AND status='planned' AND version=? AND deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM workout_sessions ws WHERE ws.workout_plan_id=workout_plans.id AND ws.user_id=workout_plans.user_id AND ws.status IN ('in_progress','completed') AND ws.deleted_at IS NULL)");
                    $update->execute([(string) $plan['workout_id'], $input['user_id'], (int) $plan['version']]);
                    if ($update->rowCount() !== 1) {
                        throw new VersionConflictException('Один из будущих планов изменился перед activation.');
                    }
                    $supersededIds[] = (string) $plan['workout_id'];
                }
            }

            $created = [];
            foreach ($impact['future_plans']['created'] as $item) {
                $created[] = $this->materialize($pdo, $input['user_id'], $draft, $aggregate, $item);
            }

            $after = [
                'active_version_id' => $input['draft_id'],
                'active_version' => (int) $aggregate['program']['version'],
                'aggregate_hash' => $input['aggregate_hash'],
                'effective_from' => $input['effective_from'],
                'horizon_weeks' => $input['horizon_weeks'],
                'future_plan_policy' => $input['policy'],
                'paused_program_count' => (int) $impact['programs']['will_pause_count'],
                'superseded_plan_count' => count($supersededIds),
                'created_plan_count' => count($created),
            ];
            $this->audit($pdo, $input['user_id'], (string) $input['draft_id'], $oldProgram, $after);
            (new AssistantAuditService($pdo))->record($input['user_id'], 'program_activation.confirm', 'success', [
                'entity_type' => 'program_version', 'entity_id' => (string) $input['draft_id'],
                'confirmation_required' => true, 'status' => 200, 'version' => $input['lock_version'] + 1,
            ]);
            return [
                'program_id' => (string) $draft['external_program_id'],
                'program_version' => (int) $aggregate['program']['version'],
                'draft_id' => $input['draft_id'],
                'lifecycle_status' => 'published',
                'lock_version' => $input['lock_version'] + 1,
                'aggregate_hash' => $input['aggregate_hash'],
                'effective_from' => $input['effective_from'],
                'horizon_weeks' => $input['horizon_weeks'],
                'future_plan_policy' => $input['policy'],
                'paused_program_count' => (int) $impact['programs']['will_pause_count'],
                'superseded_workout_ids' => $supersededIds,
                'created_workouts' => $created,
            ];
        });
    }

    private function buildImpact(PDO $pdo, array $input, bool $lock): array
    {
        $draft = $this->ownedDraft($pdo, $input['user_id'], $input['draft_id'], $lock);
        if ((int) $draft['lock_version'] !== $input['lock_version']) {
            throw new VersionConflictException('Draft lock_version устарел.');
        }
        if (!hash_equals((string) $draft['aggregate_hash'], $input['aggregate_hash'])) {
            throw new VersionConflictException('Draft aggregate_hash устарел.');
        }

        $end = (new DateTimeImmutable($input['effective_from']))->modify('+' . ($input['horizon_weeks'] * 7 - 1) . ' days')->format('Y-m-d');
        $activeSql = "SELECT id,external_program_id,name FROM training_programs WHERE user_id=? AND id<>? AND status='active' AND deleted_at IS NULL ORDER BY id" . ($lock ? $this->lockSuffix($pdo) : '');
        $active = $pdo->prepare($activeSql);
        $active->execute([$input['user_id'], (int) $draft['program_id']]);
        $willPause = array_map(static fn (array $row): array => [
            'program_id' => (string) $row['external_program_id'], 'name' => (string) $row['name'],
        ], $active->fetchAll(PDO::FETCH_ASSOC));

        $existingSql = <<<SQL
SELECT wp.id internal_id,wp.external_plan_id workout_id,wp.name,wp.scheduled_date,wp.status,wp.version,
       source_version.program_id source_program_internal_id,
       source_program.external_program_id source_program_id,
       CASE WHEN EXISTS (
           SELECT 1 FROM workout_sessions ws
           WHERE ws.workout_plan_id=wp.id AND ws.user_id=wp.user_id
             AND ws.status IN ('in_progress','completed') AND ws.deleted_at IS NULL
       ) THEN 1 ELSE 0 END protected_session
FROM workout_plans wp
LEFT JOIN program_versions source_version ON source_version.id=wp.program_version_id
LEFT JOIN training_programs source_program
       ON source_program.id=source_version.program_id AND source_program.user_id=wp.user_id
WHERE wp.user_id=? AND wp.scheduled_date>=? AND wp.scheduled_date<=?
  AND wp.deleted_at IS NULL AND wp.status IN ('planned','in_progress','completed')
ORDER BY wp.scheduled_date,wp.id
SQL;
        if ($lock) $existingSql .= $this->lockSuffix($pdo);
        $existingQuery = $pdo->prepare($existingSql);
        $existingQuery->execute([$input['user_id'], $input['effective_from'], $end]);
        $existing = $existingQuery->fetchAll(PDO::FETCH_ASSOC);
        $protected = [];
        $replaceable = [];
        $unrelated = [];
        foreach ($existing as $row) {
            $item = [
                'workout_id' => (string) $row['workout_id'], 'date' => (string) $row['scheduled_date'], 'name' => (string) $row['name'],
                'status' => (string) $row['status'], 'version' => (int) $row['version'],
                'program_id' => $row['source_program_id'] !== null ? (string) $row['source_program_id'] : null,
            ];
            if ((int) $row['protected_session'] === 1 || in_array($row['status'], ['in_progress', 'completed'], true)) {
                $protected[] = $item;
            } elseif ($row['source_program_internal_id'] !== null && (int) $row['source_program_internal_id'] === (int) $draft['program_id']) {
                $replaceable[] = $item;
            } else {
                $unrelated[] = $item;
            }
        }

        $blockedDates = [];
        foreach ($protected as $item) $blockedDates[$item['date']] = true;
        foreach ($unrelated as $item) $blockedDates[$item['date']] = true;
        if ($input['policy'] === 'keep') {
            foreach ($replaceable as $item) $blockedDates[$item['date']] = true;
        }

        $slotByWeekday = [];
        foreach ($draft['aggregate']['schedule_slots'] as $slot) {
            $slotByWeekday[(int) $slot['weekday']] = (string) $slot['template_id'];
        }
        $templates = [];
        foreach ($draft['aggregate']['templates'] as $template) $templates[$template['template_id']] = $template;
        $created = [];
        $blocked = [];
        for ($date = new DateTimeImmutable($input['effective_from']), $last = new DateTimeImmutable($end); $date <= $last; $date = $date->modify('+1 day')) {
            $day = (int) $date->format('N');
            if (!isset($slotByWeekday[$day])) continue;
            $value = $date->format('Y-m-d');
            $template = $templates[$slotByWeekday[$day]];
            $item = ['date' => $value, 'template_id' => $template['template_id'], 'name' => $template['name'], 'workout_type' => $template['type']];
            if (isset($blockedDates[$value])) $blocked[] = $item;
            else $created[] = $item;
        }

        return [
            'draft' => [
                'draft_id' => $input['draft_id'], 'lock_version' => $input['lock_version'],
                'aggregate_hash' => $input['aggregate_hash'], 'program_id' => (string) $draft['external_program_id'],
                'program_name' => (string) $draft['aggregate']['program']['name'],
                'version' => (int) $draft['aggregate']['program']['version'],
                'previous_active_version' => $draft['previous_active_version'] === null ? null : (int) $draft['previous_active_version'],
            ],
            'window' => [
                'effective_from' => $input['effective_from'], 'effective_to' => $end,
                'horizon_weeks' => $input['horizon_weeks'], 'future_plan_policy' => $input['policy'],
            ],
            'programs' => ['will_pause_count' => count($willPause), 'will_pause' => $willPause],
            'future_plans' => [
                'kept' => $input['policy'] === 'keep' ? [...$unrelated, ...$replaceable] : $unrelated,
                'superseded' => $input['policy'] === 'supersede' ? $replaceable : [],
                'protected' => $protected,
                'blocked_materialization' => $blocked,
                'created' => $created,
            ],
            'guarantees' => [
                'completed_and_in_progress_unchanged' => true,
                'history_unchanged' => true,
                'old_version_remains_immutable_published' => true,
                'unrelated_future_plans_unchanged' => true,
            ],
        ];
    }

    private function ownedDraft(PDO $pdo, int $userId, int $draftId, bool $lock): array
    {
        $sql = 'SELECT pv.*,p.user_id,p.external_program_id,p.status program_status,p.active_version_id,(SELECT av.version_number FROM program_versions av WHERE av.id=p.active_version_id AND av.program_id=p.id) previous_active_version FROM program_versions pv JOIN training_programs p ON p.id=pv.program_id WHERE pv.id=? AND p.user_id=? AND p.deleted_at IS NULL';
        if ($lock) $sql .= $this->lockSuffix($pdo);
        $query = $pdo->prepare($sql);
        $query->execute([$draftId, $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['lifecycle_status'] !== 'draft') {
            throw new InvalidArgumentException('Черновик программы не найден.');
        }
        try {
            $aggregate = json_decode((string) $row['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Сохранённый draft JSON повреждён.', 0, $exception);
        }
        if (!is_array($aggregate) || array_is_list($aggregate)) throw new RuntimeException('Сохранённый draft должен быть объектом.');
        $row['aggregate'] = $this->drafts->canonicalAggregate($aggregate);
        return $row;
    }

    private function materialize(PDO $pdo, int $userId, array $draft, array $aggregate, array $item): array
    {
        $template = null;
        foreach ($aggregate['templates'] as $candidate) {
            if ($candidate['template_id'] === $item['template_id']) { $template = $candidate; break; }
        }
        if (!is_array($template)) throw new RuntimeException('Шаблон materialization не найден.');
        $templateRow = $pdo->prepare('SELECT id FROM workout_templates WHERE user_id=? AND program_version_id=? AND code=? AND deleted_at IS NULL');
        $templateRow->execute([$userId, (int) $draft['id'], $template['template_id']]);
        $templateId = $templateRow->fetchColumn();
        if ($templateId === false) throw new RuntimeException('Материализованный workout template не найден.');

        $externalId = $this->externalPlanId((string) $draft['external_program_id'], (int) $aggregate['program']['version'], $item['date']);
        $source = [
            'schema' => TrainingPlanContractValidator::SCHEMA, 'schema_version' => '1.0', 'plan_id' => $externalId,
            'program' => [
                'program_id' => $aggregate['program']['program_id'], 'name' => $aggregate['program']['name'],
                'description' => $aggregate['program']['description'], 'version' => $aggregate['program']['version'],
                'change_reason' => $aggregate['program']['change_reason'], 'parent_version' => $aggregate['program']['parent_version'],
            ],
            'workout' => [
                'template_id' => $template['template_id'], 'name' => $template['name'], 'type' => $template['type'],
                'scheduled_date' => $item['date'],
            ],
            'exercises' => $template['exercises'],
        ];
        foreach (['goal', 'estimated_duration_min'] as $optional) if (array_key_exists($optional, $template)) $source['workout'][$optional] = $template[$optional];
        if (array_key_exists('trainer_notes', $template)) $source['trainer_notes'] = $template['trainer_notes'];
        if (array_key_exists('pre_workout', $template)) $source['pre_workout'] = $template['pre_workout'];
        $this->plans->validate($source);

        $insert = $pdo->prepare("INSERT INTO workout_plans (user_id,external_plan_id,program_version_id,workout_template_id,name,workout_type,scheduled_date,goal,estimated_duration_min,trainer_notes,pre_workout_json,source_json,schema_version,status,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'planned',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $insert->execute([
            $userId, $externalId, (int) $draft['id'], (int) $templateId, $template['name'], $template['type'], $item['date'],
            $template['goal'] ?? null, $template['estimated_duration_min'] ?? null, $template['trainer_notes'] ?? null,
            isset($template['pre_workout']) ? $this->plans->json($template['pre_workout']) : null,
            $this->plans->canonicalJson($source), '1.0',
        ]);
        $planId = (int) $pdo->lastInsertId();
        $exerciseInsert = $pdo->prepare('INSERT INTO workout_exercises (workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,warmup_sets,method_type,group_id,instructions,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
        foreach ($template['exercises'] as $exercise) {
            $exerciseInsert->execute([
                $planId, $exercise['exercise_id'], $exercise['order'], $exercise['sets'], $exercise['rep_range']['min'], $exercise['rep_range']['max'],
                $exercise['target_rir']['min'], $exercise['target_rir']['max'], $exercise['rest_seconds'], $exercise['weight'] ?? null,
                !empty($exercise['warmup_sets']) ? 1 : 0, $exercise['set_type'] ?? 'normal', $exercise['group_id'] ?? null, $exercise['instructions'] ?? null,
            ]);
        }
        return ['workout_id' => $externalId, 'date' => $item['date'], 'name' => $template['name']];
    }

    private function validateInput(int $userId, int $draftId, int $lockVersion, string $hash, string $effectiveFrom, int $horizon, string $policy): array
    {
        if ($userId < 1 || $draftId < 1 || $lockVersion < 1) throw new InvalidArgumentException('Некорректная activation binding.');
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new InvalidArgumentException('aggregate_hash должен быть SHA-256.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if (!$date || $date->format('Y-m-d') !== $effectiveFrom) throw new InvalidArgumentException('effective_from должен быть существующей датой YYYY-MM-DD.');
        if ($horizon < 1 || $horizon > 12) throw new InvalidArgumentException('horizon_weeks должен быть от 1 до 12.');
        if (!in_array($policy, self::POLICIES, true)) throw new InvalidArgumentException('future_plan_policy должен быть keep или supersede.');
        return ['user_id'=>$userId,'draft_id'=>$draftId,'lock_version'=>$lockVersion,'aggregate_hash'=>$hash,'effective_from'=>$effectiveFrom,'horizon_weeks'=>$horizon,'policy'=>$policy];
    }

    private function externalPlanId(string $programId, int $version, string $date): string
    {
        $suffix = '-v' . $version . '-' . str_replace('-', '', $date);
        $prefix = mb_substr($programId, 0, 190 - strlen($suffix));
        return $prefix . $suffix;
    }

    private function audit(PDO $pdo, int $userId, string $entityId, array $before, array $after): void
    {
        $insert = $pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,source,request_id,before_json,after_json,ip_address,created_at) VALUES (?,'program_version',?,'activate', 'manual_confirmation',?,?,?,?,CURRENT_TIMESTAMP)");
        $insert->execute([
            $userId, $entityId, RequestContext::requestId(),
            json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try { $result = $callback($pdo); if ($owns) $pdo->commit(); return $result; }
        catch (Throwable $exception) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
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
