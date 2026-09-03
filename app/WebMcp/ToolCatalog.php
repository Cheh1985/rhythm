<?php

declare(strict_types=1);

namespace App\WebMcp;

/** Server-owned metadata for the page-scoped WebMCP registry. */
final class ToolCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function enabled(
        bool $readEnabled,
        bool $draftWriteEnabled,
        bool $instanceWriteEnabled,
        bool $activationEnabled,
    ): array {
        return [
            ...($readEnabled ? self::readOnly() : []),
            ...($draftWriteEnabled ? self::draftWrites() : []),
            ...($instanceWriteEnabled ? self::instanceWrites() : []),
            ...($activationEnabled ? self::activationWrites() : []),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function readOnly(): array
    {
        $readAnnotations = ['readOnlyHint' => true, 'untrustedContentHint' => true];

        return [
            self::tool(
                'training.get_profile',
                'Профиль тренировок',
                'Get the signed-in athlete’s minimized training context, local date, timezone, and active program references.',
                self::objectSchema(),
                $readAnnotations
            ),
            self::tool(
                'training.get_current_plan',
                'Текущая программа',
                'Get the safely resolved current active program summary with lifecycle, weekly schedule, and template references, or an explicit ambiguous/empty state. Use get_plan_template for exercises.',
                self::objectSchema(),
                $readAnnotations
            ),
            self::tool(
                'training.get_plan',
                'Версия программы',
                'Get one owned program-version summary with lifecycle, schedule, template references, and draft binding when mutable. Omit version for active; use get_plan_template for exercises.',
                self::objectSchema([
                    'program_id' => self::identifier('Stable program ID returned by profile or current-plan tools.'),
                    'version' => self::positiveInteger('Optional immutable program version number.'),
                ], ['program_id']),
                $readAnnotations
            ),
            self::tool(
                'training.get_plan_template',
                'Упражнения шаблона',
                'Get one owned program template and a bounded cursor-paginated page of its exercises. Omit version for the active program version.',
                self::objectSchema([
                    'program_id' => self::identifier('Stable program ID returned by profile or plan tools.'),
                    'template_id' => self::identifier('Stable template ID returned by a plan tool.'),
                    'version' => self::positiveInteger('Optional program version number.', 100000),
                    'limit' => self::positiveInteger('Exercise page size from 1 to 50.', 50),
                    'cursor' => self::cursor(),
                ], ['program_id', 'template_id']),
                $readAnnotations
            ),
            self::tool(
                'training.list_plan_versions',
                'Версии программы',
                'List all owned versions of one program with draft, published, or archived lifecycle and safe draft bindings; raw snapshots are never exposed.',
                self::objectSchema([
                    'program_id' => self::identifier('Stable program ID returned by profile or current-plan tools.'),
                ], ['program_id']),
                $readAnnotations
            ),
            self::tool(
                'training.list_workouts',
                'Список тренировок',
                'List the signed-in athlete’s workouts in a bounded local-date range with optional type, status, limit, and cursor filters.',
                self::objectSchema([
                    'from' => self::date('First local date to include. Defaults to a bounded server-selected range.'),
                    'to' => self::date('Last local date to include. Defaults to the athlete’s current local date.'),
                    'type' => self::enum(['strength', 'swimming', 'cardio', 'mobility', 'other'], 'Workout type filter.'),
                    'status' => self::enum(['planned', 'in_progress', 'completed', 'cancelled'], 'Workout status filter.'),
                    'limit' => self::positiveInteger('Page size from 1 to 50.', 50),
                    'cursor' => self::cursor(),
                ]),
                $readAnnotations
            ),
            self::tool(
                'training.get_workout',
                'Детали тренировки',
                'Get a planned strength workout by workout_id, recorded strength facts by session_id, or both when both IDs are supplied.',
                [
                    'type' => 'object',
                    'properties' => [
                        'workout_id' => self::identifier('Stable planned workout ID returned by list_workouts.'),
                        'session_id' => self::identifier('Stable strength session ID returned by list_workouts.'),
                    ],
                    'anyOf' => [
                        ['required' => ['workout_id']],
                        ['required' => ['session_id']],
                    ],
                    'additionalProperties' => false,
                ],
                $readAnnotations
            ),
            self::tool(
                'training.get_exercise_history',
                'История упражнения',
                'Get bounded, cursor-paginated completed strength history and deterministic signals for one visible exercise.',
                self::objectSchema([
                    'exercise_id' => self::identifier('Stable exercise ID returned by workout or search tools.'),
                    'from' => self::date('First local date to include.'),
                    'to' => self::date('Last local date to include.'),
                    'limit' => self::positiveInteger('Page size from 1 to 50.', 50),
                    'cursor' => self::cursor(),
                ], ['exercise_id']),
                $readAnnotations
            ),
            self::tool(
                'training.get_progress_summary',
                'Сводка прогресса',
                'Get bounded strength and swimming aggregates for the signed-in athlete without medical or causal conclusions.',
                self::objectSchema([
                    'from' => self::date('First local date to include.'),
                    'to' => self::date('Last local date to include.'),
                ]),
                $readAnnotations
            ),
            self::tool(
                'training.get_scheduled_workout',
                'Тренировка на дату',
                'Get concrete workout plans and recurring schedule expectations for one date in the athlete’s timezone.',
                self::objectSchema([
                    'date' => self::date('Local calendar date to inspect.'),
                ], ['date']),
                $readAnnotations
            ),
            self::tool(
                'training.search_exercises',
                'Поиск упражнений',
                'Search the exercise catalogue visible to the signed-in athlete with bounded cursor pagination.',
                self::objectSchema([
                    'query' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 120,
                        'description' => 'Exercise name or phrase to search for.',
                    ],
                    'limit' => self::positiveInteger('Page size from 1 to 30.', 30),
                    'cursor' => self::cursor(),
                ], ['query']),
                $readAnnotations
            ),
            self::tool(
                'training.find_alternatives',
                'Альтернативы упражнения',
                'Find deterministic catalogue matches for one exercise; results are not medical or coaching recommendations.',
                self::objectSchema([
                    'exercise_id' => self::identifier('Stable exercise ID returned by workout or search tools.'),
                    'limit' => self::positiveInteger('Maximum candidates from 1 to 20.', 20),
                ], ['exercise_id']),
                $readAnnotations
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function draftWrites(): array
    {
        $writeAnnotations = ['readOnlyHint' => false, 'untrustedContentHint' => true];

        return [
            self::tool(
                'training.create_plan_draft',
                'Создать черновик программы',
                'Create a new training-program draft or clone an immutable version for the signed-in athlete. Returns the draft lock version and aggregate hash.',
                self::createDraftSchema(),
                $writeAnnotations
            ),
            self::tool(
                'training.update_plan_draft',
                'Изменить черновик программы',
                'Apply one typed operation to a mutable training-program draft using optimistic locking. Returns the next lock version and aggregate hash.',
                self::updateDraftSchema(),
                $writeAnnotations
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function instanceWrites(): array
    {
        $writeAnnotations = ['readOnlyHint' => false, 'untrustedContentHint' => true];

        return [
            self::tool(
                'training.reschedule_workout',
                'Перенести тренировку',
                'Reschedule one not-started workout instance for the signed-in athlete without changing its immutable program template.',
                self::objectSchema([
                    'instance_id' => self::identifier('Planned workout ID returned by a workout tool.'),
                    'scope' => self::enum(['scheduled_instance'], 'Explicitly limits the change to one scheduled instance.'),
                    'scheduled_date' => self::date('New local calendar date.'),
                    'instance_version' => self::positiveInteger('Current workout instance version.'),
                    'client_action_id' => self::actionId(),
                ], ['instance_id', 'scope', 'scheduled_date', 'instance_version', 'client_action_id']),
                $writeAnnotations
            ),
            self::tool(
                'training.replace_exercise',
                'Заменить упражнение',
                'Replace one exercise in a scheduled workout or active session while preserving original exercise provenance and optimistic versions.',
                self::objectSchema([
                    'instance_id' => self::identifier('Planned workout ID or active session ID returned by workout tools.'),
                    'scope' => self::enum(['scheduled_instance', 'active_session'], 'Select the concrete mutable instance type.'),
                    'exercise_sequence' => self::positiveInteger('Exercise sequence number in the instance.', 1000),
                    'replacement_exercise_id' => self::identifier('Visible replacement exercise ID returned by search or alternatives.'),
                    'reason' => self::boundedString('Reason for the replacement.', 1, 1000),
                    'instance_version' => self::positiveInteger('Current workout or session version.'),
                    'exercise_version' => self::positiveInteger('Current exercise instance version.'),
                    'client_action_id' => self::actionId(),
                ], [
                    'instance_id', 'scope', 'exercise_sequence', 'replacement_exercise_id', 'reason',
                    'instance_version', 'exercise_version', 'client_action_id',
                ]),
                $writeAnnotations
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function activationWrites(): array
    {
        return [
            self::tool(
                'training.activate_plan',
                'Активировать программу',
                'Prepare an impact preview, then wait for the signed-in person to confirm it in the page before activating the draft and updating future workouts.',
                self::objectSchema([
                    'draft_id' => self::positiveInteger('Draft ID returned by create_plan_draft or update_plan_draft.'),
                    'lock_version' => self::positiveInteger('Current draft lock version.'),
                    'aggregate_hash' => [
                        'type' => 'string',
                        'pattern' => '^[a-f0-9]{64}$',
                        'description' => 'Current lowercase SHA-256 aggregate hash.',
                    ],
                    'effective_from' => self::date('First local date affected by activation.'),
                    'horizon_weeks' => self::positiveInteger('Materialization horizon from 1 to 12 weeks.', 12),
                    'future_plan_policy' => self::enum(['keep', 'supersede'], 'How eligible future planned workouts are handled.'),
                ], ['draft_id', 'lock_version', 'aggregate_hash', 'effective_from', 'horizon_weeks', 'future_plan_policy']),
                ['readOnlyHint' => false, 'untrustedContentHint' => true]
            ),
        ];
    }

    /** @param array<string, mixed> $inputSchema @param array<string, bool> $annotations */
    private static function tool(string $name, string $title, string $description, array $inputSchema, array $annotations): array
    {
        // The catalog is also loaded directly by its contract tests, without the
        // application bootstrap that defines the translation helper.
        if (function_exists('t')) {
            $title = \t($title);
        }
        return compact('name', 'title', 'description', 'inputSchema', 'annotations');
    }

    /** @param array<string, array<string, mixed>> $properties @param list<string> $required */
    private static function objectSchema(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function createDraftSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => self::enum(['new', 'clone'], 'Use new for a new program or clone for a draft based on an immutable version.'),
                'metadata' => self::draftMetadataSchema(),
                'program_id' => self::identifier('For mode=clone: program ID returned by a plan tool.'),
                'source_version' => self::positiveInteger('For mode=clone: optional immutable source version.', 100000),
                'reason' => self::boundedString('Required change reason.', 1, 1000),
                'client_action_id' => self::actionId(),
            ],
            'required' => ['mode', 'reason', 'client_action_id'],
            'oneOf' => [
                [
                    'properties' => ['mode' => ['const' => 'new'], 'metadata' => self::draftMetadataSchema()],
                    'required' => ['metadata'],
                    'not' => ['anyOf' => [['required' => ['program_id']], ['required' => ['source_version']]]],
                ],
                [
                    'properties' => ['mode' => ['const' => 'clone']],
                    'required' => ['program_id'],
                    'not' => ['required' => ['metadata']],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function updateDraftSchema(): array
    {
        $operations = [
            ['set_program_metadata', self::objectSchema([
                'name' => self::boundedString('New program name.', 1, 190),
                'description' => self::nullableString('New description or null.', 10000),
                'change_reason' => self::boundedString('Updated change reason.', 1, 1000),
            ]) + ['minProperties' => 1]],
            ['upsert_template', self::objectSchema(['template' => self::templateSchema()], ['template'])],
            ['remove_template', self::objectSchema(['template_id' => self::draftIdentifier('Template ID to remove.', 80)], ['template_id'])],
            ['upsert_exercise', self::objectSchema([
                'template_id' => self::draftIdentifier('Target template ID.', 80),
                'exercise' => self::exerciseSchema(),
            ], ['template_id', 'exercise'])],
            ['remove_exercise', self::objectSchema([
                'template_id' => self::draftIdentifier('Target template ID.', 80),
                'exercise_id' => self::draftIdentifier('Exercise ID to remove.', 80),
            ], ['template_id', 'exercise_id'])],
            ['set_schedule_slot', self::scheduleSlotSchema()],
            ['remove_schedule_slot', self::objectSchema([
                'weekday' => self::boundedInteger('ISO weekday from 1 Monday to 7 Sunday.', 1, 7),
            ], ['weekday'])],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'draft_id' => self::positiveInteger('Draft ID returned by create_plan_draft or a draft_binding.'),
                'lock_version' => self::positiveInteger('Current draft lock version.'),
                'operation' => self::enum(array_column($operations, 0), 'Single typed draft operation.'),
                'payload' => ['type' => 'object', 'description' => 'Operation-specific closed payload.'],
                'client_action_id' => self::actionId(),
            ],
            'required' => ['draft_id', 'lock_version', 'operation', 'payload', 'client_action_id'],
            'oneOf' => array_map(static fn (array $operation): array => [
                'properties' => [
                    'operation' => ['const' => $operation[0]],
                    'payload' => $operation[1],
                ],
            ], $operations),
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function draftMetadataSchema(): array
    {
        return self::objectSchema([
            'program_id' => self::draftIdentifier('Stable lowercase program ID.', 190),
            'name' => self::boundedString('Program name.', 1, 190),
            'description' => self::nullableString('Optional program description.', 10000),
            'templates' => ['type' => 'array', 'maxItems' => 50, 'items' => self::templateSchema()],
            'schedule_slots' => ['type' => 'array', 'maxItems' => 7, 'items' => self::scheduleSlotSchema()],
        ], ['program_id', 'name']);
    }

    /** @return array<string, mixed> */
    private static function templateSchema(): array
    {
        return self::objectSchema([
            'template_id' => self::draftIdentifier('Stable lowercase template ID.', 80),
            'name' => self::boundedString('Workout template name.', 1, 190),
            'type' => self::enum(['strength', 'swimming', 'cardio', 'mobility', 'other'], 'Workout type.'),
            'goal' => self::nullableString('Optional workout goal.', 5000),
            'estimated_duration_min' => self::boundedInteger('Estimated duration in minutes.', 1, 1440),
            'trainer_notes' => self::nullableString('Optional trainer notes.', 10000),
            'pre_workout' => self::objectSchema([
                'instructions' => self::nullableString('Optional pre-workout instructions.', 2000),
                'nutrition' => self::nullableString('Optional nutrition note.', 2000),
                'equipment' => self::nullableString('Optional equipment note.', 2000),
            ]),
            'exercises' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => self::exerciseSchema()],
        ], ['template_id', 'name', 'type', 'exercises']);
    }

    /** @return array<string, mixed> */
    private static function exerciseSchema(): array
    {
        return self::objectSchema([
            'exercise_id' => self::draftIdentifier('Visible exercise ID returned by search tools.', 80),
            'name' => self::boundedString('Exercise display name.', 1, 190),
            'order' => self::boundedInteger('Unique exercise order in the template.', 1, 100),
            'sets' => self::boundedInteger('Planned sets.', 1, 20),
            'rep_range' => self::rangeSchema('integer', 1, 1000, 'Planned repetition range.'),
            'target_rir' => self::rangeSchema('number', 0, 10, 'Target RIR range.'),
            'rest_seconds' => self::boundedInteger('Rest between sets in seconds.', 0, 3600),
            'category' => self::nullableString('Optional category.', 80),
            'muscles' => ['type' => 'array', 'maxItems' => 30, 'items' => self::boundedString('Muscle group.', 1, 80)],
            'exercise_type' => self::nullableString('Optional exercise type.', 40),
            'equipment' => self::nullableString('Optional equipment.', 120),
            'progression_increment' => ['type' => 'number', 'minimum' => 0.01, 'maximum' => 1000],
            'progression_mode' => self::enum(['absolute', 'percent'], 'Progression mode.'),
            'weight' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 2000],
            'warmup_sets' => ['type' => 'boolean'],
            'set_type' => self::enum(['normal', 'superset', 'dropset', 'rest_pause', 'cluster', 'amrap'], 'Set method.'),
            'group_id' => self::nullableString('Optional superset/group ID.', 64),
            'instructions' => self::nullableString('Optional exercise instructions.', 5000),
        ], ['exercise_id', 'name', 'order', 'sets', 'rep_range', 'target_rir', 'rest_seconds']);
    }

    /** @return array<string, mixed> */
    private static function scheduleSlotSchema(): array
    {
        return self::objectSchema([
            'weekday' => self::boundedInteger('ISO weekday from 1 Monday to 7 Sunday.', 1, 7),
            'template_id' => self::draftIdentifier('Template scheduled on this weekday.', 80),
        ], ['weekday', 'template_id']);
    }

    /** @return array<string, mixed> */
    private static function rangeSchema(string $type, int|float $minimum, int|float $maximum, string $description): array
    {
        return self::objectSchema([
            'min' => ['type' => $type, 'minimum' => $minimum, 'maximum' => $maximum],
            'max' => ['type' => $type, 'minimum' => $minimum, 'maximum' => $maximum],
        ], ['min', 'max']) + ['description' => $description];
    }

    /** @return array<string, mixed> */
    private static function boundedInteger(string $description, int $minimum, int $maximum): array
    {
        return ['type' => 'integer', 'minimum' => $minimum, 'maximum' => $maximum, 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function nullableString(string $description, int $maximum): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => $maximum, 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function draftIdentifier(string $description, int $maximum): array
    {
        return [
            'type' => 'string',
            'minLength' => 3,
            'maxLength' => $maximum,
            'pattern' => '^[a-z0-9][a-z0-9._-]{2,' . ($maximum - 1) . '}$',
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function identifier(string $description): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 80,
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$',
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function positiveInteger(string $description, ?int $maximum = null): array
    {
        $schema = ['type' => 'integer', 'minimum' => 1, 'description' => $description];
        if ($maximum !== null) {
            $schema['maximum'] = $maximum;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function date(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description];
    }

    /** @param list<string> $values @return array<string, mixed> */
    private static function enum(array $values, string $description): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function cursor(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 1024,
            'description' => 'Opaque next_cursor returned by the previous page of the same tool.',
        ];
    }

    /** @return array<string, mixed> */
    private static function boundedString(string $description, int $minimum, int $maximum): array
    {
        return [
            'type' => 'string',
            'minLength' => $minimum,
            'maxLength' => $maximum,
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function actionId(): array
    {
        return [
            'type' => 'string',
            'minLength' => 8,
            'maxLength' => 80,
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$',
            'description' => 'Unique action ID. Reuse it only when retrying the exact same write.',
        ];
    }
}
