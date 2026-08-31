<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use JsonException;

final class TrainingPlanContractValidator
{
    public const SCHEMA = 'training-plan';
    public const SUPPORTED_VERSIONS = ['1.0'];

    public function decode(string $json, ?int $maxBytes = null): array
    {
        $limit = $maxBytes ?? max(1, (int) \env('MAX_UPLOAD_BYTES', '1048576'));
        if ($json === '' || strlen($json) > $limit) {
            throw new InvalidArgumentException($json === '' ? 'JSON-файл пуст.' : 'Файл превышает допустимый размер.');
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
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

        $this->validateExercises($data['exercises']);
    }

    /**
     * Shared training-plan v1.0 exercise/range rules. Program drafts reuse this
     * method without pretending that their aggregate is a training-plan root.
     */
    public function validateExercises(mixed $exercises, string $rootPath = 'exercises'): void
    {
        if (!is_array($exercises) || !array_is_list($exercises) || count($exercises) < 1 || count($exercises) > 100) {
            throw new InvalidArgumentException($rootPath . ' должен быть массивом от 1 до 100 упражнений.');
        }
        $ids = [];
        $orders = [];
        foreach ($exercises as $index => $value) {
            $path = $rootPath . '[' . $index . ']';
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

    public function canonicalJson(array $value): string
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

    public function canonicalHash(array $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    public function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
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
}
