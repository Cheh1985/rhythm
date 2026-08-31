<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use JsonException;

final class ProgramDraftValidator
{
    public const SCHEMA = 'training-program-draft';
    public const VERSION = '1.0';

    private readonly TrainingPlanContractValidator $trainingPlan;

    public function __construct(?TrainingPlanContractValidator $trainingPlan = null)
    {
        $this->trainingPlan = $trainingPlan ?? new TrainingPlanContractValidator();
    }

    public function decode(string $json, ?int $maxBytes = null): array
    {
        $limit = $maxBytes ?? max(1, (int) \env('MAX_UPLOAD_BYTES', '1048576'));
        if ($json === '' || strlen($json) > $limit) {
            throw new InvalidArgumentException($json === '' ? 'JSON черновика пуст.' : 'JSON черновика превышает допустимый размер.');
        }
        try {
            $aggregate = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Некорректный JSON черновика: ' . $exception->getMessage());
        }
        if (!is_array($aggregate) || array_is_list($aggregate)) {
            throw new InvalidArgumentException('Корень training-program-draft должен быть объектом.');
        }
        $this->validate($aggregate);
        return $aggregate;
    }

    public function validate(array $aggregate): void
    {
        $this->object($aggregate, 'корень', ['schema', 'schema_version', 'source', 'program', 'templates', 'schedule_slots']);
        if ($aggregate['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('schema должно иметь значение ' . self::SCHEMA . '.');
        }
        if ($aggregate['schema_version'] !== self::VERSION) {
            throw new InvalidArgumentException('schema_version должна иметь значение ' . self::VERSION . '.');
        }
        if (!is_string($aggregate['source']) || !in_array($aggregate['source'], ['manual', 'webmcp'], true)) {
            throw new InvalidArgumentException('source должно быть manual или webmcp.');
        }

        $program = $this->objectValue($aggregate['program'], 'program');
        $this->object($program, 'program', [
            'program_id', 'name', 'description', 'version', 'change_reason', 'parent_version', 'parent_aggregate_hash',
        ]);
        $this->identifier($program['program_id'], 'program.program_id', 190);
        $this->text($program['name'], 'program.name', 190);
        $this->nullableText($program['description'], 'program.description', 10000);
        $this->integer($program['version'], 'program.version', 1, 100000);
        $this->text($program['change_reason'], 'program.change_reason', 1000);

        $parentVersion = $program['parent_version'];
        $parentHash = $program['parent_aggregate_hash'];
        if (($parentVersion === null) !== ($parentHash === null)) {
            throw new InvalidArgumentException('parent_version и parent_aggregate_hash должны задаваться вместе.');
        }
        if ($parentVersion !== null) {
            $this->integer($parentVersion, 'program.parent_version', 1, 99999);
            if ($parentVersion >= $program['version']) {
                throw new InvalidArgumentException('program.parent_version должна быть меньше program.version.');
            }
            if (!is_string($parentHash) || !preg_match('/^[a-f0-9]{64}$/', $parentHash)) {
                throw new InvalidArgumentException('program.parent_aggregate_hash должен быть SHA-256 в нижнем регистре.');
            }
        } elseif ($program['version'] !== 1) {
            throw new InvalidArgumentException('Версия программы выше 1 требует parent provenance.');
        }

        if (!is_array($aggregate['templates']) || !array_is_list($aggregate['templates']) || count($aggregate['templates']) > 50) {
            throw new InvalidArgumentException('templates должен быть массивом не более чем из 50 шаблонов.');
        }
        $templateIds = [];
        foreach ($aggregate['templates'] as $index => $value) {
            $path = 'templates[' . $index . ']';
            $template = $this->objectValue($value, $path);
            $this->object($template, $path, ['template_id', 'name', 'type', 'exercises'], [
                'goal', 'estimated_duration_min', 'trainer_notes', 'pre_workout',
            ]);
            $this->identifier($template['template_id'], $path . '.template_id', 80);
            if (isset($templateIds[$template['template_id']])) {
                throw new InvalidArgumentException('template_id повторяется: ' . $template['template_id'] . '.');
            }
            $templateIds[$template['template_id']] = true;
            $this->text($template['name'], $path . '.name', 190);
            if (!is_string($template['type']) || !in_array($template['type'], ['strength', 'swimming', 'cardio', 'mobility', 'other'], true)) {
                throw new InvalidArgumentException($path . '.type содержит неподдерживаемый тип тренировки.');
            }
            $this->nullableText($template['goal'] ?? null, $path . '.goal', 5000);
            if (array_key_exists('estimated_duration_min', $template)) {
                $this->integer($template['estimated_duration_min'], $path . '.estimated_duration_min', 1, 1440);
            }
            $this->nullableText($template['trainer_notes'] ?? null, $path . '.trainer_notes', 10000);
            if (array_key_exists('pre_workout', $template)) {
                $preWorkout = $this->objectValue($template['pre_workout'], $path . '.pre_workout');
                $this->object($preWorkout, $path . '.pre_workout', [], ['instructions', 'nutrition', 'equipment']);
                foreach ($preWorkout as $key => $text) {
                    $this->nullableText($text, $path . '.pre_workout.' . $key, 2000);
                }
            }
            $this->trainingPlan->validateExercises($template['exercises'], $path . '.exercises');
        }

        if (!is_array($aggregate['schedule_slots']) || !array_is_list($aggregate['schedule_slots']) || count($aggregate['schedule_slots']) > 7) {
            throw new InvalidArgumentException('schedule_slots должен быть массивом не более чем из 7 слотов.');
        }
        $weekdays = [];
        foreach ($aggregate['schedule_slots'] as $index => $value) {
            $path = 'schedule_slots[' . $index . ']';
            $slot = $this->objectValue($value, $path);
            $this->object($slot, $path, ['weekday', 'template_id']);
            $this->integer($slot['weekday'], $path . '.weekday', 1, 7);
            $this->identifier($slot['template_id'], $path . '.template_id', 80);
            if (isset($weekdays[$slot['weekday']])) {
                throw new InvalidArgumentException('weekday повторяется: ' . $slot['weekday'] . '.');
            }
            if (!isset($templateIds[$slot['template_id']])) {
                throw new InvalidArgumentException($path . ' ссылается на отсутствующий template_id ' . $slot['template_id'] . '.');
            }
            $weekdays[$slot['weekday']] = true;
        }
    }

    public function canonicalAggregate(array $aggregate): array
    {
        $this->validate($aggregate);
        foreach ($aggregate['templates'] as &$template) {
            usort($template['exercises'], static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
        }
        unset($template);
        usort($aggregate['templates'], static fn (array $left, array $right): int => strcmp($left['template_id'], $right['template_id']));
        usort($aggregate['schedule_slots'], static fn (array $left, array $right): int => $left['weekday'] <=> $right['weekday']);
        return $aggregate;
    }

    public function canonicalJson(array $aggregate): string
    {
        return $this->trainingPlan->canonicalJson($this->canonicalAggregate($aggregate));
    }

    public function canonicalHash(array $aggregate): string
    {
        return hash('sha256', $this->canonicalJson($aggregate));
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

    private function integer(mixed $value, string $path, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($path . " должно быть целым числом от {$min} до {$max}.");
        }
    }
}
