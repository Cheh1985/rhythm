<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PlanImportService
{
    public const SCHEMA = 'training-plan';
    public const SUPPORTED_VERSIONS = ['1.0'];

    public function __construct(private readonly ?PDO $pdo = null) {}

    public function decode(string $json): array
    {
        $limit = max(1, (int) \env('MAX_UPLOAD_BYTES', '1048576'));
        if ($json === '' || strlen($json) > $limit) {
            throw new InvalidArgumentException($json === '' ? 'JSON-файл пуст.' : 'Файл превышает допустимый размер.');
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Некорректный JSON: ' . $exception->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Корень JSON должен быть объектом.');
        }
        $this->validate($data);
        return $data;
    }

    public function validate(array $data): void
    {
        $this->object($data, 'корень', ['schema', 'schema_version', 'plan_id', 'program', 'workout', 'exercises'], ['trainer_notes', 'pre_workout']);
        $this->exactString($data['schema'], 'schema', self::SCHEMA);
        if (!is_string($data['schema_version']) || !in_array($data['schema_version'], self::SUPPORTED_VERSIONS, true)) {
            throw new InvalidArgumentException('schema_version должна быть строкой с поддерживаемым значением 1.0.');
        }
        $this->identifier($data['plan_id'], 'plan_id', 190);

        $program = $this->objectValue($data['program'], 'program');
        $this->object($program, 'program', ['program_id', 'name', 'version', 'change_reason'], ['description', 'parent_version']);
        $this->identifier($program['program_id'], 'program.program_id', 190);
        $this->text($program['name'], 'program.name', 190);
        $this->integer($program['version'], 'program.version', 1, 100000);
        $this->text($program['change_reason'], 'program.change_reason', 1000);
        $this->nullableText($program['description'] ?? null, 'program.description', 10000);
        $parentVersion = $program['parent_version'] ?? null;
        if ($parentVersion !== null) {
            $this->integer($parentVersion, 'program.parent_version', 1, 99999);
        }
        if ($program['version'] === 1 && $parentVersion !== null) {
            throw new InvalidArgumentException('program.parent_version не задаётся для версии 1.');
        }
        if ($program['version'] > 1 && $parentVersion === null) {
            throw new InvalidArgumentException('Для версии программы выше 1 требуется program.parent_version.');
        }
        if ($parentVersion !== null && $parentVersion >= $program['version']) {
            throw new InvalidArgumentException('program.parent_version должна быть меньше program.version.');
        }

        $workout = $this->objectValue($data['workout'], 'workout');
        $this->object($workout, 'workout', ['template_id', 'name', 'type', 'scheduled_date'], ['goal', 'estimated_duration_min']);
        $this->identifier($workout['template_id'], 'workout.template_id', 80);
        $this->text($workout['name'], 'workout.name', 190);
        if (!is_string($workout['type']) || !in_array($workout['type'], ['strength', 'swimming', 'cardio', 'mobility', 'other'], true)) {
            throw new InvalidArgumentException('workout.type содержит неподдерживаемый тип тренировки.');
        }
        $this->date($workout['scheduled_date'], 'workout.scheduled_date');
        $this->nullableText($workout['goal'] ?? null, 'workout.goal', 5000);
        if (array_key_exists('estimated_duration_min', $workout)) {
            $this->integer($workout['estimated_duration_min'], 'workout.estimated_duration_min', 1, 1440);
        }
        $this->nullableText($data['trainer_notes'] ?? null, 'trainer_notes', 10000);

        if (array_key_exists('pre_workout', $data)) {
            $preWorkout = $this->objectValue($data['pre_workout'], 'pre_workout');
            $this->object($preWorkout, 'pre_workout', [], ['instructions', 'nutrition', 'equipment']);
            foreach ($preWorkout as $key => $value) {
                $this->nullableText($value, 'pre_workout.' . $key, 2000);
            }
        }

        if (!is_array($data['exercises']) || !array_is_list($data['exercises']) || count($data['exercises']) < 1 || count($data['exercises']) > 100) {
            throw new InvalidArgumentException('exercises должен быть массивом от 1 до 100 упражнений.');
        }
        $ids = [];
        $orders = [];
        foreach ($data['exercises'] as $index => $value) {
            $path = 'exercises[' . $index . ']';
            $exercise = $this->objectValue($value, $path);
            $this->object($exercise, $path, ['exercise_id', 'name', 'order', 'sets', 'rep_range', 'target_rir', 'rest_seconds'], [
                'category', 'muscles', 'exercise_type', 'equipment', 'progression_increment', 'progression_mode',
                'weight', 'warmup_sets', 'set_type', 'group_id', 'instructions',
            ]);
            $this->identifier($exercise['exercise_id'], $path . '.exercise_id', 80);
            $this->text($exercise['name'], $path . '.name', 190);
            $this->integer($exercise['order'], $path . '.order', 1, 100);
            $this->integer($exercise['sets'], $path . '.sets', 1, 20);
            if (isset($ids[$exercise['exercise_id']])) {
                throw new InvalidArgumentException('exercise_id повторяется: ' . $exercise['exercise_id'] . '.');
            }
            if (isset($orders[$exercise['order']])) {
                throw new InvalidArgumentException('Порядок упражнений повторяется: ' . $exercise['order'] . '.');
            }
            $ids[$exercise['exercise_id']] = true;
            $orders[$exercise['order']] = true;

            $repRange = $this->objectValue($exercise['rep_range'], $path . '.rep_range');
            $this->object($repRange, $path . '.rep_range', ['min', 'max']);
            $this->integer($repRange['min'], $path . '.rep_range.min', 1, 1000);
            $this->integer($repRange['max'], $path . '.rep_range.max', $repRange['min'], 1000);
            $targetRir = $this->objectValue($exercise['target_rir'], $path . '.target_rir');
            $this->object($targetRir, $path . '.target_rir', ['min', 'max']);
            $this->number($targetRir['min'], $path . '.target_rir.min', 0, 10);
            $this->number($targetRir['max'], $path . '.target_rir.max', (float) $targetRir['min'], 10);
            $this->integer($exercise['rest_seconds'], $path . '.rest_seconds', 0, 3600);

            $this->nullableText($exercise['category'] ?? null, $path . '.category', 80);
            $this->nullableText($exercise['exercise_type'] ?? null, $path . '.exercise_type', 40);
            $this->nullableText($exercise['equipment'] ?? null, $path . '.equipment', 120);
            if (array_key_exists('muscles', $exercise)) {
                if (!is_array($exercise['muscles']) || !array_is_list($exercise['muscles']) || count($exercise['muscles']) > 30) {
                    throw new InvalidArgumentException($path . '.muscles должен быть массивом строк.');
                }
                foreach ($exercise['muscles'] as $muscleIndex => $muscle) {
                    $this->text($muscle, $path . '.muscles[' . $muscleIndex . ']', 80);
                }
            }
            if (array_key_exists('progression_increment', $exercise)) {
                $this->number($exercise['progression_increment'], $path . '.progression_increment', 0.01, 1000);
            }
            if (array_key_exists('progression_mode', $exercise) && (!is_string($exercise['progression_mode']) || !in_array($exercise['progression_mode'], ['absolute', 'percent'], true))) {
                throw new InvalidArgumentException($path . '.progression_mode должно быть absolute или percent.');
            }
            if (array_key_exists('weight', $exercise) && $exercise['weight'] !== null) {
                $this->number($exercise['weight'], $path . '.weight', 0, 2000);
            }
            if (array_key_exists('warmup_sets', $exercise) && !is_bool($exercise['warmup_sets'])) {
                throw new InvalidArgumentException($path . '.warmup_sets должно быть boolean.');
            }
            if (array_key_exists('set_type', $exercise) && (!is_string($exercise['set_type']) || !in_array($exercise['set_type'], ['normal', 'superset', 'dropset', 'rest_pause', 'cluster', 'amrap'], true))) {
                throw new InvalidArgumentException($path . '.set_type содержит неподдерживаемый метод.');
            }
            $this->nullableText($exercise['group_id'] ?? null, $path . '.group_id', 64);
            $this->nullableText($exercise['instructions'] ?? null, $path . '.instructions', 5000);
        }
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
                $insertVersion = $pdo->prepare("INSERT INTO program_versions (program_id, version_number, source, change_reason, trainer_comment, snapshot_json, snapshot_hash, parent_version_id, created_at) VALUES (?, ?, 'json_import', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $insertVersion->execute([$programId, $versionNumber, $program['change_reason'], null, $versionSnapshot, $snapshotHash, $parentId]);
                $versionId = (int) $pdo->lastInsertId();
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

    private function object(mixed $value, string $path, array $required, array $optional = []): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($path . ' должен быть объектом.');
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Отсутствует обязательное поле ' . $path . '.' . $key . '.');
            }
        }
        $unknown = array_diff(array_keys($value), [...$required, ...$optional]);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Неизвестное поле ' . $path . '.' . reset($unknown) . '.');
        }
    }

    private function objectValue(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($path . ' должен быть объектом.');
        }
        return $value;
    }

    private function identifier(mixed $value, string $path, int $max): void
    {
        if (!is_string($value) || mb_strlen($value) > $max || !preg_match('/^[a-z0-9][a-z0-9._-]{2,' . ($max - 1) . '}$/', $value)) {
            throw new InvalidArgumentException($path . ' должен быть стабильным идентификатором из строчных латинских букв, цифр, точки, _ или -.');
        }
    }

    private function text(mixed $value, string $path, int $max): void
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException($path . ' должен быть непустой строкой до ' . $max . ' символов.');
        }
    }

    private function nullableText(mixed $value, string $path, int $max): void
    {
        if ($value !== null && (!is_string($value) || mb_strlen($value) > $max)) {
            throw new InvalidArgumentException($path . ' должен быть строкой до ' . $max . ' символов или null.');
        }
    }

    private function exactString(mixed $value, string $path, string $expected): void
    {
        if (!is_string($value) || $value !== $expected) {
            throw new InvalidArgumentException($path . ' должно иметь значение ' . $expected . '.');
        }
    }

    private function integer(mixed $value, string $path, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($path . " должно быть целым числом от {$min} до {$max}.");
        }
    }

    private function number(mixed $value, string $path, float $min, float $max): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($path . " должно быть числом от {$min} до {$max}.");
        }
    }

    private function date(mixed $value, string $path): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($path . ' должна быть строкой YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($path . ' должна иметь формат YYYY-MM-DD и существующую дату.');
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($sort, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        return $this->json($sort($value));
    }
}
