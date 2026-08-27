<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PlanImportService
{
    public const SCHEMA = TrainingPlanContractValidator::SCHEMA;
    public const SUPPORTED_VERSIONS = TrainingPlanContractValidator::SUPPORTED_VERSIONS;

    private readonly TrainingPlanContractValidator $contract;

    public function __construct(private readonly ?PDO $pdo = null, ?TrainingPlanContractValidator $contract = null)
    {
        $this->contract = $contract ?? new TrainingPlanContractValidator();
    }

    public function decode(string $json): array
    {
        return $this->contract->decode($json);
    }

    public function validate(array $data): void
    {
        $this->contract->validate($data);
    }

    public function preview(array $data, int $userId): array
    {
        $this->validate($data);
        $ids = array_column($data['exercises'], 'exercise_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->connection()->prepare("SELECT exercise_id, owner_user_id, status FROM exercises WHERE exercise_id IN ({$placeholders}) AND deleted_at IS NULL");
        $statement->execute($ids);
        $known = [];
        $conflicting = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['owner_user_id'] === null || (int) $row['owner_user_id'] === $userId) {
                $known[$row['exercise_id']] = $row;
            } else {
                $conflicting[] = $row['exercise_id'];
            }
        }
        $unknown = array_values(array_filter($data['exercises'], static fn (array $exercise): bool => !isset($known[$exercise['exercise_id']]) && !in_array($exercise['exercise_id'], $conflicting, true)));
        $inactive = array_values(array_map(static fn (array $exercise): string => $exercise['exercise_id'], array_filter(
            $data['exercises'], static fn (array $exercise): bool => isset($known[$exercise['exercise_id']]) && $known[$exercise['exercise_id']]['status'] !== 'active'
        )));
        return [
            'plan_id' => $data['plan_id'],
            'program_id' => $data['program']['program_id'],
            'program_name' => $data['program']['name'],
            'program_version' => $data['program']['version'],
            'change_reason' => $data['program']['change_reason'],
            'workout_name' => $data['workout']['name'],
            'workout_type' => $data['workout']['type'],
            'scheduled_date' => $data['workout']['scheduled_date'],
            'exercise_count' => count($data['exercises']),
            'exercises' => array_map(static fn (array $exercise): array => [
                'exercise_id' => $exercise['exercise_id'], 'name' => $exercise['name'], 'sets' => $exercise['sets'],
                'rep_min' => $exercise['rep_range']['min'], 'rep_max' => $exercise['rep_range']['max'],
            ], $data['exercises']),
            'unknown_exercises' => $unknown,
            'inactive_exercise_ids' => $inactive,
            'conflicting_exercise_ids' => $conflicting,
        ];
    }

    public function import(array $data, int $userId, bool $createUnknown): int
    {
        $preview = $this->preview($data, $userId);
        if ($preview['conflicting_exercise_ids'] !== []) {
            throw new InvalidArgumentException('Некоторые exercise_id уже заняты недоступными пользовательскими упражнениями: ' . implode(', ', $preview['conflicting_exercise_ids']) . '.');
        }
        if ($preview['inactive_exercise_ids'] !== []) {
            throw new InvalidArgumentException('План содержит неактивные упражнения: ' . implode(', ', $preview['inactive_exercise_ids']) . '.');
        }
        if ($preview['unknown_exercises'] !== [] && !$createUnknown) {
            throw new InvalidArgumentException('Подтвердите создание неизвестных упражнений.');
        }

        return $this->transaction(function (PDO $pdo) use ($data, $userId, $createUnknown): int {
            $preview = $this->preview($data, $userId);
            if ($preview['conflicting_exercise_ids'] !== []) {
                throw new InvalidArgumentException('Некоторые exercise_id уже заняты недоступными пользовательскими упражнениями: ' . implode(', ', $preview['conflicting_exercise_ids']) . '.');
            }
            if ($preview['inactive_exercise_ids'] !== []) {
                throw new InvalidArgumentException('План содержит неактивные упражнения: ' . implode(', ', $preview['inactive_exercise_ids']) . '.');
            }
            if ($preview['unknown_exercises'] !== [] && !$createUnknown) {
                throw new InvalidArgumentException('Подтвердите создание неизвестных упражнений.');
            }
            $duplicate = $pdo->prepare('SELECT id FROM workout_plans WHERE user_id = ? AND external_plan_id = ?');
            $duplicate->execute([$userId, $data['plan_id']]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('План с таким plan_id уже импортирован; история не перезаписана.');
            }
            foreach ($preview['unknown_exercises'] as $exercise) {
                $insertExercise = $pdo->prepare("INSERT INTO exercises (exercise_id, owner_user_id, name, category, muscle_groups, exercise_type, equipment, progression_increment, progression_mode, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insertExercise->execute([
                    $exercise['exercise_id'], $userId, trim($exercise['name']), $exercise['category'] ?? null,
                    $this->json($exercise['muscles'] ?? []), $exercise['exercise_type'] ?? 'strength', $exercise['equipment'] ?? null,
                    $exercise['progression_increment'] ?? 2.5, $exercise['progression_mode'] ?? 'absolute',
                ]);
            }

            $program = $data['program'];
            $programLookup = $pdo->prepare('SELECT id, name FROM training_programs WHERE user_id = ? AND external_program_id = ? AND deleted_at IS NULL LIMIT 1');
            $programLookup->execute([$userId, $program['program_id']]);
            $existingProgram = $programLookup->fetch(PDO::FETCH_ASSOC);
            $programId = (int) ($existingProgram['id'] ?? 0);
            if ($programId === 0) {
                $insertProgram = $pdo->prepare("INSERT INTO training_programs (user_id, external_program_id, name, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insertProgram->execute([$userId, $program['program_id'], trim($program['name']), $program['description'] ?? null]);
                $programId = (int) $pdo->lastInsertId();
            } elseif ($existingProgram['name'] !== $program['name']) {
                $updateProgram = $pdo->prepare('UPDATE training_programs SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
                $updateProgram->execute([$program['name'], $program['description'] ?? null, $programId, $userId]);
            }

            $versionNumber = $program['version'];
            $versionSnapshot = $this->canonicalJson($program);
            $snapshotHash = hash('sha256', $versionSnapshot);
            $versionLookup = $pdo->prepare('SELECT id, snapshot_hash FROM program_versions WHERE program_id = ? AND version_number = ?');
            $versionLookup->execute([$programId, $versionNumber]);
            $existingVersion = $versionLookup->fetch(PDO::FETCH_ASSOC);
            $versionId = (int) ($existingVersion['id'] ?? 0);
            $versionCreated = $versionId === 0;
            if ($versionId !== 0 && !hash_equals((string) $existingVersion['snapshot_hash'], $snapshotHash)) {
                throw new RuntimeException('Версия программы уже существует с другим содержимым; неизменяемая история сохранена.');
            }
            if ($versionId === 0) {
                $parentId = null;
                if ($versionNumber > 1) {
                    $parentQuery = $pdo->prepare('SELECT id FROM program_versions WHERE program_id = ? AND version_number = ?');
                    $parentQuery->execute([$programId, $program['parent_version']]);
                    $parentId = $parentQuery->fetchColumn();
                    if ($parentId === false) {
                        throw new InvalidArgumentException('Указанная родительская версия программы ещё не импортирована.');
                    }
                }
                $insertVersion = $pdo->prepare("INSERT INTO program_versions (program_id, version_number, source, change_reason, trainer_comment, snapshot_json, snapshot_hash, parent_version_id, created_at, lifecycle_status, lock_version, aggregate_hash, updated_at) VALUES (?, ?, 'json_import', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'published', 1, ?, CURRENT_TIMESTAMP)");
                $insertVersion->execute([$programId, $versionNumber, $program['change_reason'], null, $versionSnapshot, $snapshotHash, $parentId, $snapshotHash]);
                $versionId = (int) $pdo->lastInsertId();
            }

            // Legacy training-plan v1.0 may safely establish a pointer only while
            // the program has exactly one version. Multiple versions require the
            // explicit reconciliation/activation workflow and are never guessed.
            $linkSingleVersion = $pdo->prepare(<<<'SQL'
UPDATE training_programs
SET active_version_id=?,updated_at=CURRENT_TIMESTAMP
WHERE id=? AND user_id=? AND active_version_id IS NULL
  AND (SELECT COUNT(*) FROM program_versions pv WHERE pv.program_id=training_programs.id)=1
SQL);
            $linkSingleVersion->execute([$versionId, $programId, $userId]);
            if ($linkSingleVersion->rowCount() === 1) {
                $markActive = $pdo->prepare('UPDATE program_versions SET activated_at=COALESCE(activated_at,created_at) WHERE id=? AND program_id=?');
                $markActive->execute([$versionId, $programId]);
            }

            $workout = $data['workout'];
            $templateSnapshot = $workout;
            unset($templateSnapshot['scheduled_date']);
            $templateSnapshot['exercises'] = $data['exercises'];
            $templateJson = $this->canonicalJson($templateSnapshot);
            $templateHash = hash('sha256', $templateJson);
            $templateLookup = $pdo->prepare('SELECT id, content_hash FROM workout_templates WHERE user_id = ? AND program_version_id = ? AND code = ? AND deleted_at IS NULL');
            $templateLookup->execute([$userId, $versionId, $workout['template_id']]);
            $existingTemplate = $templateLookup->fetch(PDO::FETCH_ASSOC);
            $templateId = (int) ($existingTemplate['id'] ?? 0);
            if ($templateId !== 0 && !hash_equals((string) $existingTemplate['content_hash'], $templateHash)) {
                throw new RuntimeException('Шаблон тренировки уже существует с другим содержимым; версия программы не изменена.');
            }
            if ($templateId === 0) {
                $insertTemplate = $pdo->prepare('INSERT INTO workout_templates (user_id, program_version_id, code, name, workout_type, content_json, content_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                $insertTemplate->execute([$userId, $versionId, $workout['template_id'], $workout['name'], $workout['type'], $templateJson, $templateHash]);
                $templateId = (int) $pdo->lastInsertId();
            }

            $insertPlan = $pdo->prepare("INSERT INTO workout_plans (user_id, external_plan_id, program_version_id, workout_template_id, name, workout_type, scheduled_date, goal, estimated_duration_min, trainer_notes, pre_workout_json, source_json, schema_version, status, version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insertPlan->execute([
                $userId, $data['plan_id'], $versionId, $templateId, $workout['name'], $workout['type'], $workout['scheduled_date'],
                $workout['goal'] ?? null, $workout['estimated_duration_min'] ?? null, $data['trainer_notes'] ?? null,
                $this->json($data['pre_workout'] ?? []), $this->canonicalJson($data), $data['schema_version'],
            ]);
            $planId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare("INSERT INTO workout_exercises (workout_plan_id, exercise_id, sequence_no, planned_sets, rep_min, rep_max, target_rir_min, target_rir_max, rest_seconds, planned_weight_kg, warmup_sets, method_type, group_id, instructions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            foreach ($data['exercises'] as $exercise) {
                $insertItem->execute([
                    $planId, $exercise['exercise_id'], $exercise['order'], $exercise['sets'], $exercise['rep_range']['min'], $exercise['rep_range']['max'],
                    $exercise['target_rir']['min'], $exercise['target_rir']['max'], $exercise['rest_seconds'], $exercise['weight'] ?? null,
                    !empty($exercise['warmup_sets']) ? 1 : 0, $exercise['set_type'] ?? 'normal', $exercise['group_id'] ?? null, $exercise['instructions'] ?? null,
                ]);
            }
            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, ip_address, created_at) VALUES (?, 'workout_plan', ?, 'import', ?, ?, CURRENT_TIMESTAMP)");
            $audit->execute([$userId, (string) $planId, $this->json(['external_plan_id' => $data['plan_id']]), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
            if ($versionCreated) {
                $versionAudit = $pdo->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, ip_address, created_at) VALUES (?, 'program_version', ?, 'create_from_import', ?, ?, CURRENT_TIMESTAMP)");
                $versionAudit->execute([$userId, (string) $versionId, $this->json(['program_id' => $program['program_id'], 'version' => $versionNumber, 'snapshot_hash' => $snapshotHash]), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
            }
            return $planId;
        });
    }

    private function connection(): PDO
    {
        return $this->pdo ?? \db()->pdo();
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo === null) {
            return \db()->transaction($callback);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function json(array $value): string
    {
        return $this->contract->json($value);
    }

    private function canonicalJson(array $value): string
    {
        return $this->contract->canonicalJson($value);
    }
}
