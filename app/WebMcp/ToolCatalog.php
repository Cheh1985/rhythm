<?php

declare(strict_types=1);

namespace App\WebMcp;

/** Server-owned metadata for the page-scoped, read-only WebMCP registry. */
final class ToolCatalog
{
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
                'Get the safely resolved current active training program for the signed-in athlete, or an explicit ambiguous/empty state.',
                self::objectSchema(),
                $readAnnotations
            ),
            self::tool(
                'training.get_plan',
                'Версия программы',
                'Get a safe projection of one training program. Omit version for its server-resolved active version.',
                self::objectSchema([
                    'program_id' => self::identifier('Stable program ID returned by profile or current-plan tools.'),
                    'version' => self::positiveInteger('Optional immutable program version number.'),
                ], ['program_id']),
                $readAnnotations
            ),
            self::tool(
                'training.list_plan_versions',
                'Версии программы',
                'List immutable versions of one training program without exposing stored source or snapshot payloads.',
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

    /** @param array<string, mixed> $inputSchema @param array<string, bool> $annotations */
    private static function tool(string $name, string $title, string $description, array $inputSchema, array $annotations): array
    {
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
}
