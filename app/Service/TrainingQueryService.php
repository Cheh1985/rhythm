<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Analytics;
use App\Domain\TrainingMetrics;
use App\Repository\TrainingQueryRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/** Stable, minimized DTO layer for read-only training use cases. */
final class TrainingQueryService
{
    public const MAX_RANGE_DAYS = 366;
    public const MAX_LIST_LIMIT = 50;
    public const MAX_HISTORY_LIMIT = 50;
    public const MAX_SEARCH_LIMIT = 30;

    public function __construct(private readonly ?TrainingQueryRepository $repository = null) {}

    private function repository(): TrainingQueryRepository
    {
        return $this->repository ?? new TrainingQueryRepository();
    }

    public function profileContext(int $userId): ?array
    {
        $profile = $this->repository()->profileRow($userId);
        if ($profile === null) {
            return null;
        }
        $timezone = $this->timezone((string) $profile['timezone']);
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $programs = $this->programs($userId);
        $active = array_values(array_filter($programs['items'], static fn (array $row): bool => $row['status'] === 'active'));

        return [
            'timezone' => $timezone,
            'local_date' => $now->format('Y-m-d'),
            'member_since' => substr((string) $profile['created_at'], 0, 10),
            'active_programs' => array_map(static fn (array $row): array => [
                'program_id' => $row['program_id'],
                'name' => $row['name'],
                'current_version' => $row['current_version'],
                'active_version_state' => $row['active_version_state'],
            ], $active),
            'data_quality' => $this->quality([], [
                'Profile context is intentionally minimized and excludes account credentials and contact fields.',
            ]),
        ];
    }

    public function programs(int $userId): array
    {
        $items = array_map(static fn (array $row): array => [
            'program_id' => (string) $row['external_program_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'status' => (string) $row['status'],
            'current_version' => $row['version_number'] !== null ? (int) $row['version_number'] : null,
            'active_version_state' => (string) $row['active_version_state'],
            'version_source' => $row['source'],
            'parent_version' => $row['parent_version'] !== null ? (int) $row['parent_version'] : null,
            'change_reason' => $row['change_reason'],
            'trainer_comment' => $row['trainer_comment'],
            'template_count' => (int) $row['template_count'],
            'workout_count' => (int) $row['workout_count'],
            'created_at_utc' => self::utc($row['created_at']),
            'updated_at_utc' => self::utc($row['updated_at']),
            'version_created_at_utc' => self::utc($row['version_created_at']),
        ], $this->repository()->programRows($userId));

        return ['items' => $items, 'count' => count($items), 'data_quality' => $this->quality()];
    }

    public function programVersion(int $userId, string $programId, ?int $version = null): ?array
    {
        $programId = $this->identifier($programId, 'program_id');
        if ($version !== null && $version < 1) {
            throw new InvalidArgumentException('version должен быть положительным целым числом.');
        }
        $row = $this->repository()->programVersionRow($userId, $programId, $version);
        if ($row === null) {
            return null;
        }
        $issues = [];
        $templates = array_map(
            function (array $template) use (&$issues): array {
                return $this->programTemplate($template, $issues);
            },
            $this->repository()->templateRows($userId, (int) $row['internal_version_id'])
        );
        $scheduleSlots = array_map(static fn (array $slot): array => [
            'weekday' => (int) $slot['weekday'],
            'template_id' => (string) $slot['template_code'],
        ], $this->repository()->scheduleSlotRows($userId, (int) $row['internal_version_id']));
        $lifecycle = (string) $row['lifecycle_status'];

        return [
            'program_id' => (string) $row['external_program_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version_number'],
            'lifecycle_status' => $lifecycle,
            'parent_version' => $row['parent_version'] !== null ? (int) $row['parent_version'] : null,
            'source' => (string) $row['source'],
            'change_reason' => $row['change_reason'],
            'trainer_comment' => $row['trainer_comment'],
            'created_at_utc' => self::utc($row['created_at']),
            'activated_at_utc' => self::utc($row['activated_at']),
            'archived_at_utc' => self::utc($row['archived_at']),
            'draft_binding' => $lifecycle === 'draft' ? [
                'draft_id' => (int) $row['internal_version_id'],
                'lock_version' => (int) $row['lock_version'],
                'aggregate_hash' => (string) $row['aggregate_hash'],
            ] : null,
            'templates' => $templates,
            'schedule_slots' => $scheduleSlots,
            'data_quality' => $this->quality(array_values(array_unique($issues)), [
                'Raw storage payloads are never exposed; templates are mapped to a bounded training DTO.',
            ]),
        ];
    }

    public function programVersions(int $userId, string $programId): ?array
    {
        $programId = $this->identifier($programId, 'program_id');
        $rows = $this->repository()->programVersionRows($userId, $programId);
        if ($rows === []) {
            return null;
        }

        $items = array_map(static fn (array $row): array => [
            'version' => (int) $row['version_number'],
            'lifecycle_status' => (string) $row['lifecycle_status'],
            'parent_version' => $row['parent_version'] !== null ? (int) $row['parent_version'] : null,
            'source' => (string) $row['source'],
            'change_reason' => $row['change_reason'],
            'trainer_comment' => $row['trainer_comment'],
            'template_count' => (int) $row['template_count'],
            'workout_count' => (int) $row['workout_count'],
            'created_at_utc' => self::utc($row['created_at']),
            'activated_at_utc' => self::utc($row['activated_at']),
            'archived_at_utc' => self::utc($row['archived_at']),
            'draft_binding' => $row['lifecycle_status'] === 'draft' ? [
                'draft_id' => (int) $row['internal_version_id'],
                'lock_version' => (int) $row['lock_version'],
                'aggregate_hash' => (string) $row['aggregate_hash'],
            ] : null,
        ], $rows);

        return [
            'program_id' => (string) $rows[0]['external_program_id'],
            'name' => (string) $rows[0]['name'],
            'status' => (string) $rows[0]['status'],
            'items' => $items,
            'count' => count($items),
            'data_quality' => $this->quality([], ['Use lifecycle_status to distinguish mutable drafts from immutable published or archived versions.']),
        ];
    }

    /** @param list<string> $issues */
    private function programTemplate(array $row, array &$issues): array
    {
        $content = [];
        try {
            $decoded = json_decode((string) $row['content_json'], true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && !array_is_list($decoded)) {
                $content = $decoded;
            } else {
                $issues[] = 'One program template has an unavailable structured payload.';
            }
        } catch (JsonException) {
            $issues[] = 'One program template has invalid stored JSON and was returned without exercise details.';
        }

        $exerciseRows = $content['exercises'] ?? [];
        if (!is_array($exerciseRows) || !array_is_list($exerciseRows)) {
            $exerciseRows = [];
            $issues[] = 'One program template has no valid exercise list.';
        }
        $exercises = [];
        foreach ($exerciseRows as $exercise) {
            if (!is_array($exercise) || !isset($exercise['exercise_id'], $exercise['name'], $exercise['order'], $exercise['sets'])) {
                $issues[] = 'One malformed template exercise was omitted.';
                continue;
            }
            $exercises[] = [
                'exercise_id' => (string) $exercise['exercise_id'],
                'name' => (string) $exercise['name'],
                'sequence' => (int) $exercise['order'],
                'sets' => (int) $exercise['sets'],
                'rep_range' => is_array($exercise['rep_range'] ?? null) ? [
                    'min' => (int) ($exercise['rep_range']['min'] ?? 0),
                    'max' => (int) ($exercise['rep_range']['max'] ?? 0),
                ] : null,
                'target_rir' => is_array($exercise['target_rir'] ?? null) ? [
                    'min' => isset($exercise['target_rir']['min']) ? (float) $exercise['target_rir']['min'] : null,
                    'max' => isset($exercise['target_rir']['max']) ? (float) $exercise['target_rir']['max'] : null,
                ] : null,
                'weight_kg' => isset($exercise['weight']) ? (float) $exercise['weight'] : null,
                'rest_seconds' => isset($exercise['rest_seconds']) ? (int) $exercise['rest_seconds'] : null,
                'method' => isset($exercise['set_type']) ? (string) $exercise['set_type'] : 'normal',
                'warmup_sets' => (bool) ($exercise['warmup_sets'] ?? false),
                'group_id' => isset($exercise['group_id']) ? (string) $exercise['group_id'] : null,
                'category' => isset($exercise['category']) ? (string) $exercise['category'] : null,
                'muscle_groups' => is_array($exercise['muscles'] ?? null) ? array_values(array_map('strval', $exercise['muscles'])) : [],
                'exercise_type' => isset($exercise['exercise_type']) ? (string) $exercise['exercise_type'] : null,
                'equipment' => isset($exercise['equipment']) ? (string) $exercise['equipment'] : null,
                'instructions' => isset($exercise['instructions']) ? (string) $exercise['instructions'] : null,
            ];
        }

        return [
            'template_id' => (string) $row['code'],
            'name' => (string) $row['name'],
            'workout_type' => (string) $row['workout_type'],
            'workout_count' => (int) $row['workout_count'],
            'goal' => isset($content['goal']) ? (string) $content['goal'] : null,
            'estimated_duration_minutes' => isset($content['estimated_duration_min']) ? (int) $content['estimated_duration_min'] : null,
            'trainer_notes' => isset($content['trainer_notes']) ? (string) $content['trainer_notes'] : null,
            'pre_workout' => is_array($content['pre_workout'] ?? null) ? $content['pre_workout'] : null,
            'exercises' => $exercises,
        ];
    }

    public function workouts(int $userId, array $filters = []): array
    {
        $timezone = $this->userTimezone($userId);
        $range = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null, $timezone, 30);
        $limit = $this->limit($filters['limit'] ?? 20, self::MAX_LIST_LIMIT);
        $type = $this->enum($filters['type'] ?? null, ['strength', 'swimming', 'cardio', 'mobility', 'other'], 'type');
        $status = $this->enum($filters['status'] ?? null, ['planned', 'in_progress', 'completed', 'cancelled'], 'status');
        $cursor = $this->decodeCursor($filters['cursor'] ?? null, 'workouts');
        $rows = $this->repository()->workoutRows($userId, $range['from'], $range['to'], $type, $status, $cursor, $limit);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
        $items = array_map(function (array $row) use ($today): array {
            if ($row['source_kind'] === 'swimming') {
                return [
                    'workout_id' => (string) $row['item_key'], 'kind' => 'swimming', 'date' => (string) $row['local_date'],
                    'name' => (string) $row['name'], 'workout_type' => 'swimming', 'status' => 'completed',
                    'metrics' => ['duration_minutes' => (int) $row['swim_duration_minutes'], 'distance_m' => (int) $row['total_distance_m'], 'intensity' => (int) $row['intensity']],
                    'swimming' => ['primary_style' => (string) $row['primary_style']],
                ];
            }
            $effectiveStatus = (string) ($row['session_status'] ?: $row['planned_status']);
            return [
                'workout_id' => (string) $row['item_key'], 'session_id' => $row['session_key'], 'kind' => (string) $row['workout_type'],
                'date' => (string) $row['local_date'], 'name' => (string) $row['name'], 'workout_type' => (string) $row['workout_type'],
                'status' => $effectiveStatus,
                'schedule_state' => $effectiveStatus === 'planned' && $row['local_date'] < $today ? 'past_due_planned' : 'on_schedule',
                'version' => (int) $row['plan_version'],
                'metrics' => [
                    'working_sets' => (int) $row['working_sets'], 'tonnage_kg' => round((float) $row['tonnage_kg'], 2),
                    'average_rir' => $row['average_rir'] !== null ? round((float) $row['average_rir'], 1) : null,
                    'completed_exercises' => (int) $row['completed_exercises'], 'skipped_exercises' => (int) $row['skipped_exercises'],
                    'pending_exercises' => (int) $row['pending_exercises'], 'substitutions' => (int) $row['substitutions'],
                    'duration_minutes' => TrainingMetrics::durationMinutes($row['started_at'], $row['finished_at']),
                    'session_rpe' => $row['session_rpe'] !== null ? (int) $row['session_rpe'] : null,
                ],
            ];
        }, $rows);
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $next = $this->encodeCursor('workouts', ['date' => $last['local_date'], 'kind' => $last['source_kind'], 'key' => $last['item_key']]);
        }
        return [
            'items' => $items, 'next_cursor' => $next, 'range' => $range,
            'filters' => ['type' => $type, 'status' => $status],
            'data_quality' => $this->quality([], [
                'past_due_planned means a concrete plan is still planned after its date; it is not proof that the workout was missed.',
                'Strength tonnage and RIR are never calculated from swimming sessions.',
            ]),
        ];
    }

    public function plannedWorkout(int $userId, string $planId): ?array
    {
        $row = $this->repository()->planRow($userId, $this->identifier($planId, 'workout_id'));
        if ($row === null) {
            return null;
        }
        $exercises = array_map(fn (array $exercise): array => $this->plannedExercise($exercise), $this->repository()->planExerciseRows($userId, (int) $row['internal_plan_id']));
        return [
            'workout_id' => (string) $row['external_plan_id'], 'name' => (string) $row['name'], 'workout_type' => (string) $row['workout_type'],
            'scheduled_date' => (string) $row['scheduled_date'], 'status' => (string) $row['status'], 'version' => (int) $row['version'],
            'goal' => $row['goal'], 'estimated_duration_minutes' => $row['estimated_duration_min'] !== null ? (int) $row['estimated_duration_min'] : null,
            'trainer_notes' => $row['trainer_notes'],
            'program' => $row['external_program_id'] !== null ? ['program_id' => $row['external_program_id'], 'name' => $row['program_name'], 'version' => (int) $row['version_number']] : null,
            'template_code' => $row['template_code'], 'exercises' => $exercises,
            'data_quality' => $this->quality([], ['This projection excludes pre-workout and source JSON payloads.']),
        ];
    }

    public function workoutFact(int $userId, string $sessionId): ?array
    {
        $session = $this->repository()->sessionRow($userId, $this->identifier($sessionId, 'session_id'));
        if ($session === null) {
            return null;
        }
        $exerciseRows = $this->repository()->sessionExerciseRows($userId, (int) $session['internal_session_id']);
        $setsByExercise = [];
        foreach ($this->repository()->setRows($userId, (int) $session['internal_session_id']) as $set) {
            $setsByExercise[(int) $set['internal_session_exercise_id']][] = $this->setDto($set);
        }
        $issues = [];
        $exercises = [];
        $allSets = [];
        foreach ($exerciseRows as $exercise) {
            $sets = $setsByExercise[(int) $exercise['internal_session_exercise_id']] ?? [];
            $allSets = [...$allSets, ...$sets];
            $metrics = TrainingMetrics::summarizeSets($sets, (int) $exercise['rep_min'], (int) $exercise['rep_max']);
            if ($metrics['working_sets'] > 0 && $metrics['rir_observations'] < $metrics['working_sets']) {
                $issues['missing_rir'] = 'Some working sets have no RIR, so average RIR is based on partial data.';
            }
            $exercises[] = [
                'exercise_id' => (string) $exercise['actual_exercise_id'], 'name' => (string) $exercise['actual_name'],
                'status' => (string) $exercise['status'], 'version' => (int) $exercise['version'],
                'planned' => $this->plannedExercise($exercise, true),
                'substitution' => $exercise['original_exercise_id'] !== $exercise['actual_exercise_id'] ? [
                    'original_exercise_id' => (string) $exercise['original_exercise_id'], 'original_name' => (string) $exercise['original_name'],
                    'reason' => $exercise['substitution_reason'], 'at_utc' => self::utc($exercise['substituted_at']),
                ] : null,
                'skip' => $exercise['status'] === 'skipped' ? ['reason' => $exercise['skip_reason']] : null,
                'sets' => $sets, 'metrics' => $metrics, 'exercise_rating' => $exercise['exercise_rating'],
            ];
        }
        $statuses = TrainingMetrics::exerciseStatusCounts($exerciseRows);
        return [
            'session_id' => (string) $session['public_id'], 'workout_id' => (string) $session['external_plan_id'],
            'name' => (string) $session['name'], 'workout_type' => (string) $session['workout_type'], 'status' => (string) $session['status'],
            'scheduled_date' => (string) $session['scheduled_date'], 'started_at_utc' => self::utc($session['started_at']),
            'finished_at_utc' => self::utc($session['finished_at']), 'version' => (int) $session['version'],
            'session_rpe' => $session['session_rpe'] !== null ? (int) $session['session_rpe'] : null,
            'wellbeing' => $session['wellbeing'] !== null ? (int) $session['wellbeing'] : null,
            'edited_after_completion' => (bool) $session['edited_after_completion'],
            'metrics' => [
                ...TrainingMetrics::summarizeSets($allSets), ...$statuses,
                'duration_minutes' => TrainingMetrics::durationMinutes($session['started_at'], $session['finished_at']),
            ],
            'exercises' => $exercises,
            'data_quality' => $this->quality(array_values($issues), [
                'pending, active, and waiting exercises are reported as pending; they are never inferred to be skipped.',
                'e1RM uses the Epley estimate and is not a measured one-repetition maximum.',
            ]),
        ];
    }

    public function exerciseHistory(int $userId, string $exerciseId, array $filters = []): ?array
    {
        $exerciseId = $this->identifier($exerciseId, 'exercise_id');
        $exercise = $this->repository()->exerciseRow($userId, $exerciseId);
        if ($exercise === null) {
            return null;
        }
        $timezone = $this->userTimezone($userId);
        $range = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null, $timezone, 180);
        $bounds = Analytics::localDateBounds($range['from'], $range['to'], $timezone);
        $limit = $this->limit($filters['limit'] ?? 20, self::MAX_HISTORY_LIMIT);
        $cursor = $this->decodeCursor($filters['cursor'] ?? null, 'exercise_history');
        $rows = $this->repository()->exerciseHistoryRows($userId, $exerciseId, $bounds['from_utc'], $bounds['to_utc'], $cursor, $limit);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $ids = array_map(static fn (array $row): int => (int) $row['internal_session_exercise_id'], $rows);
        $setsById = [];
        foreach ($this->repository()->setsForSessionExercises($userId, $ids) as $set) {
            $setsById[(int) $set['internal_session_exercise_id']][] = $this->setDto($set);
        }
        $issues = [];
        $items = [];
        foreach ($rows as $row) {
            $sets = $setsById[(int) $row['internal_session_exercise_id']] ?? [];
            $metrics = TrainingMetrics::summarizeSets($sets, (int) $row['rep_min'], (int) $row['rep_max']);
            if ($metrics['working_sets'] > 0 && $metrics['rir_observations'] < $metrics['working_sets']) $issues['missing_rir'] = 'Some working sets have no RIR.';
            $items[] = [
                'session_id' => (string) $row['session_key'], 'workout_id' => (string) $row['external_plan_id'],
                'workout_name' => (string) $row['workout_name'], 'started_at_utc' => self::utc($row['started_at']),
                'duration_minutes' => TrainingMetrics::durationMinutes($row['started_at'], $row['finished_at']),
                'session_rpe' => $row['session_rpe'] !== null ? (int) $row['session_rpe'] : null,
                'status' => (string) $row['status'], 'substituted_into' => $row['original_exercise_id'] !== $row['actual_exercise_id'],
                'planned' => ['sets' => (int) $row['planned_sets'], 'rep_range' => ['min' => (int) $row['rep_min'], 'max' => (int) $row['rep_max']]],
                'sets' => $sets, 'metrics' => $metrics,
            ];
        }
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $next = $this->encodeCursor('exercise_history', ['at' => $last['started_at'], 'key' => $last['session_key'], 'sequence' => (int) $last['sequence_no']]);
        }
        $signalInput = array_map(static fn (array $item): array => [
            'best_e1rm' => $item['metrics']['best_e1rm']['e1rm_kg'] ?? null,
            'average_rir' => $item['metrics']['average_rir'],
            'reps_by_weight' => TrainingMetrics::repsByWeight($item['sets']),
        ], $items);
        return [
            'exercise' => $this->exerciseDto($exercise), 'items' => $items, 'next_cursor' => $next, 'range' => $range,
            'signals' => Analytics::softSignals($signalInput),
            'data_quality' => $this->quality(array_values($issues), ['Plateau signals are deterministic heuristics, not diagnoses or explanations.']),
        ];
    }

    public function progressSummary(int $userId, array $filters = []): array
    {
        $timezone = $this->userTimezone($userId);
        $range = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null, $timezone, 84);
        $bounds = Analytics::localDateBounds($range['from'], $range['to'], $timezone);
        $sessions = $this->repository()->completedSessionRows($userId, $bounds['from_utc'], $bounds['to_utc']);
        $setRows = $this->repository()->completedSetRows($userId, $bounds['from_utc'], $bounds['to_utc']);
        $setsBySession = [];
        $exerciseStatuses = [];
        $exerciseAggregates = [];
        $muscles = [];
        $issues = [];
        foreach ($setRows as $row) {
            $sessionKey = (int) $row['internal_session_id'];
            $exerciseKey = $sessionKey . ':' . (int) $row['internal_session_exercise_id'];
            $exerciseStatuses[$exerciseKey] = ['status' => $row['status'], 'substituted' => (bool) $row['substituted']];
            if ($row['set_type'] === null) continue;
            $set = ['set_type' => $row['set_type'], 'performed_weight_kg' => $row['performed_weight_kg'], 'reps' => $row['reps'], 'rir' => $row['rir']];
            $setsBySession[$sessionKey][] = $set;
            if ($row['set_type'] !== 'working') continue;
            $exerciseId = (string) $row['actual_exercise_id'];
            $exerciseAggregates[$exerciseId] ??= ['exercise_id' => $exerciseId, 'name' => (string) $row['exercise_name'], 'sets' => []];
            $exerciseAggregates[$exerciseId]['sets'][] = $set;
            $groups = $this->muscles($row['muscle_groups'], $row['category']);
            foreach ($groups as $group) $muscles[$group] = ($muscles[$group] ?? 0) + 1;
            if ($row['rir'] === null) $issues['missing_rir'] = 'Some working sets have no RIR; averages use available observations only.';
        }
        $analyticsRows = [];
        $allSets = [];
        $rpe = [];
        $duration = 0;
        foreach ($sessions as $session) {
            $sets = $setsBySession[(int) $session['internal_session_id']] ?? [];
            $metrics = TrainingMetrics::summarizeSets($sets);
            $allSets = [...$allSets, ...$sets];
            $minutes = TrainingMetrics::durationMinutes($session['started_at'], $session['finished_at']) ?? 0;
            $duration += $minutes;
            if ($session['session_rpe'] !== null) $rpe[] = (int) $session['session_rpe'];
            $analyticsRows[] = [
                'started_at' => $session['started_at'], 'finished_at' => $session['finished_at'],
                'working_sets' => $metrics['working_sets'], 'tonnage' => $metrics['tonnage_kg'],
                'average_rir' => $metrics['average_rir'], 'rir_count' => $metrics['rir_observations'],
            ];
        }
        $exerciseItems = [];
        foreach ($exerciseAggregates as $aggregate) {
            $metrics = TrainingMetrics::summarizeSets($aggregate['sets']);
            $exerciseItems[] = ['exercise_id' => $aggregate['exercise_id'], 'name' => $aggregate['name'], ...$metrics];
        }
        usort($exerciseItems, static fn (array $a, array $b): int => $b['working_sets'] <=> $a['working_sets'] ?: strcmp($a['name'], $b['name']));
        arsort($muscles);
        $statusCounts = TrainingMetrics::exerciseStatusCounts(array_values($exerciseStatuses));
        $plans = $this->repository()->planStatusRows($userId, $range['from'], $range['to']);
        $planCounts = ['planned' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'past_due_planned' => 0];
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
        foreach ($plans as $plan) {
            if ($plan['workout_type'] !== 'strength') continue;
            $planCounts[$plan['status']]++;
            if ($plan['status'] === 'planned' && $plan['scheduled_date'] < $today) $planCounts['past_due_planned']++;
        }
        $swimming = $this->repository()->swimmingProgressRows($userId, $range['from'], $range['to']);
        $trendNow = (new DateTimeImmutable($range['to'] . ' 12:00:00', new DateTimeZone($timezone)))->setTimezone(new DateTimeZone('UTC'));
        $weekly = Analytics::weekly($analyticsRows, $timezone, min(52, max(1, (int) ceil(($range['days'] + 1) / 7))), $trendNow);
        return [
            'range' => $range,
            'strength' => [
                'sessions' => count($sessions), 'duration_minutes' => $duration,
                'average_session_rpe' => $rpe === [] ? null : round(array_sum($rpe) / count($rpe), 1),
                ...TrainingMetrics::summarizeSets($allSets), ...$statusCounts,
                'plan_statuses' => $planCounts, 'weekly' => $weekly,
                'weekly_trend' => Analytics::weeklyTrend($weekly), 'by_exercise' => $exerciseItems,
                'working_sets_by_muscle' => $muscles,
            ],
            'swimming' => [
                'sessions' => count($swimming),
                'duration_minutes' => array_sum(array_map(static fn (array $row): int => (int) $row['duration_minutes'], $swimming)),
                'distance_m' => array_sum(array_map(static fn (array $row): int => (int) $row['total_distance_m'], $swimming)),
                'average_intensity' => $swimming === [] ? null : round(array_sum(array_map(static fn (array $row): int => (int) $row['intensity'], $swimming)) / count($swimming), 1),
            ],
            'data_quality' => $this->quality(array_values($issues), [
                'Muscle totals may double-count a working set when an exercise has multiple muscle groups.',
                'Swimming distance and duration are reported separately and never enter strength tonnage, RIR, or e1RM.',
                'past_due_planned is not evidence that a workout was missed.',
            ]),
        ];
    }

    public function scheduledWorkout(int $userId, string $localDate): array
    {
        $timezone = $this->userTimezone($userId);
        $date = $this->date($localDate, 'date', $timezone);
        $weekday = (int) (new DateTimeImmutable($date, new DateTimeZone($timezone)))->format('N');
        $plans = array_map(static fn (array $row): array => [
            'workout_id' => (string) $row['external_plan_id'], 'name' => (string) $row['name'],
            'workout_type' => (string) $row['workout_type'], 'date' => (string) $row['scheduled_date'],
            'goal' => $row['goal'], 'estimated_duration_minutes' => $row['estimated_duration_min'] !== null ? (int) $row['estimated_duration_min'] : null,
            'status' => (string) $row['status'], 'version' => (int) $row['version'],
        ], $this->repository()->scheduledPlanRows($userId, $date));
        $schedule = array_map(static fn (array $row): array => [
            'weekday' => (int) $row['weekday'], 'workout_type' => (string) $row['workout_type'],
            'label' => (string) $row['label'], 'version' => (int) $row['version'],
        ], $this->repository()->scheduleRows($userId, $weekday));
        return [
            'date' => $date, 'timezone' => $timezone, 'concrete_plans' => $plans, 'recurring_schedule' => $schedule,
            'data_quality' => $this->quality([], ['Recurring schedule entries are expectations, not concrete workout instances or proof of completion.']),
        ];
    }

    public function searchExercises(int $userId, string $query, array $options = []): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 1 || mb_strlen($query) > 120) throw new InvalidArgumentException('query должен содержать от 1 до 120 символов.');
        $limit = $this->limit($options['limit'] ?? 15, self::MAX_SEARCH_LIMIT);
        $cursor = $this->decodeCursor($options['cursor'] ?? null, 'exercise_search');
        $rows = $this->repository()->exerciseSearchRows($userId, $query, $cursor, $limit);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $items = array_map(fn (array $row): array => $this->exerciseDto($row), $rows);
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $next = $this->encodeCursor('exercise_search', ['name' => $last['name'], 'key' => $last['exercise_id']]);
        }
        return ['items' => $items, 'next_cursor' => $next, 'data_quality' => $this->quality()];
    }

    public function exerciseAlternatives(int $userId, string $exerciseId, int $limit = 8): ?array
    {
        $source = $this->repository()->exerciseRow($userId, $this->identifier($exerciseId, 'exercise_id'));
        if ($source === null) return null;
        $limit = $this->limit($limit, 20);
        $rows = $this->repository()->exerciseSearchRows($userId, '', null, 100);
        $sourceMuscles = $this->muscles($source['muscle_groups'], $source['category']);
        $candidates = [];
        foreach ($rows as $row) {
            if ($row['exercise_id'] === $source['exercise_id'] || $row['exercise_type'] !== $source['exercise_type']) continue;
            $muscles = $this->muscles($row['muscle_groups'], $row['category']);
            $shared = array_values(array_intersect($sourceMuscles, $muscles));
            $score = count($shared) * 3;
            if ($source['category'] !== null && $row['category'] === $source['category']) $score += 2;
            if ($source['equipment'] !== null && $row['equipment'] === $source['equipment']) $score += 1;
            if ($score === 0) continue;
            $candidates[] = [
                ...$this->exerciseDto($row), 'match_score' => $score,
                'match_reasons' => array_values(array_filter([
                    $shared !== [] ? 'shared_muscles:' . implode(',', $shared) : null,
                    $row['category'] === $source['category'] ? 'same_category' : null,
                    $source['equipment'] !== null && $row['equipment'] === $source['equipment'] ? 'same_equipment' : null,
                ])),
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score'] ?: strcmp($a['name'], $b['name']));
        return [
            'exercise' => $this->exerciseDto($source), 'candidates' => array_slice($candidates, 0, $limit),
            'data_quality' => $this->quality([], ['Alternatives are deterministic catalogue matches, not medical or coaching recommendations.']),
        ];
    }

    private function plannedExercise(array $row, bool $sessionProjection = false): array
    {
        $exerciseId = (string) ($sessionProjection ? $row['original_exercise_id'] : $row['exercise_id']);
        $name = (string) ($sessionProjection ? $row['original_name'] : $row['name']);
        $result = [
            'exercise_id' => $exerciseId, 'name' => $name,
            'sequence' => (int) $row['sequence_no'], 'sets' => (int) $row['planned_sets'],
            'rep_range' => ['min' => (int) $row['rep_min'], 'max' => (int) $row['rep_max']],
            'target_rir' => ['min' => $row['target_rir_min'] !== null ? (float) $row['target_rir_min'] : null, 'max' => $row['target_rir_max'] !== null ? (float) $row['target_rir_max'] : null],
            'weight_kg' => $row['planned_weight_kg'] !== null ? (float) $row['planned_weight_kg'] : null,
            'rest_seconds' => (int) $row['rest_seconds'], 'method' => (string) $row['method_type'],
            'instructions' => $sessionProjection ? null : $row['instructions'],
        ];
        if (!$sessionProjection) {
            $originalId = (string) ($row['original_exercise_id'] ?? $row['exercise_id']);
            $result['version'] = (int) ($row['version'] ?? 1);
            $result['substitution'] = $originalId !== (string) $row['exercise_id'] ? [
                'original_exercise_id' => $originalId,
                'original_name' => (string) ($row['original_name'] ?? $row['name']),
                'reason' => $row['substitution_reason'] ?? null,
                'at_utc' => self::utc($row['substituted_at'] ?? null),
            ] : null;
        }
        return $result;
    }

    private function setDto(array $row): array
    {
        return [
            'set_id' => (string) $row['public_id'], 'set_number' => (int) $row['set_number'],
            'type' => (string) $row['set_type'], 'method' => (string) $row['method_type'],
            'weight_kg' => $row['performed_weight_kg'] !== null ? (float) $row['performed_weight_kg'] : null,
            'reps' => $row['reps'] !== null ? (int) $row['reps'] : null,
            'rir' => $row['rir'] !== null ? (float) $row['rir'] : null,
            'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'distance_m' => $row['distance_m'] !== null ? (int) $row['distance_m'] : null,
            'performed_at_utc' => self::utc($row['completed_at']),
        ];
    }

    private function exerciseDto(array $row): array
    {
        return [
            'exercise_id' => (string) $row['exercise_id'], 'name' => (string) $row['name'],
            'exercise_type' => (string) $row['exercise_type'], 'category' => $row['category'],
            'muscle_groups' => $this->muscles($row['muscle_groups'], $row['category']), 'equipment' => $row['equipment'],
        ];
    }

    private function muscles(mixed $json, mixed $fallback): array
    {
        $groups = is_array($json) ? $json : json_decode((string) ($json ?? ''), true);
        if (!is_array($groups)) $groups = [];
        $groups = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $groups))));
        if ($groups === [] && is_string($fallback) && trim($fallback) !== '') $groups[] = trim($fallback);
        return $groups;
    }

    private function dateRange(mixed $from, mixed $to, string $timezone, int $defaultDays): array
    {
        $zone = new DateTimeZone($timezone);
        $today = new DateTimeImmutable('today', $zone);
        $toDate = $to === null ? $today : new DateTimeImmutable($this->date($to, 'to', $timezone), $zone);
        $fromDate = $from === null ? $toDate->modify('-' . $defaultDays . ' days') : new DateTimeImmutable($this->date($from, 'from', $timezone), $zone);
        if ($fromDate > $toDate) throw new InvalidArgumentException('from не может быть позже to.');
        $days = (int) $fromDate->diff($toDate)->days;
        if ($days > self::MAX_RANGE_DAYS) throw new InvalidArgumentException('Диапазон дат не может превышать ' . self::MAX_RANGE_DAYS . ' дней.');
        return ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d'), 'days' => $days];
    }

    private function date(mixed $value, string $field, string $timezone): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) throw new InvalidArgumentException($field . ' должен быть датой YYYY-MM-DD.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone));
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($field . ' должен быть корректной датой.');
        return $value;
    }

    private function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$/D', $value)) throw new InvalidArgumentException($field . ' имеет некорректный формат.');
        return $value;
    }

    private function limit(mixed $value, int $max): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) throw new InvalidArgumentException('limit должен быть целым числом от 1 до ' . $max . '.');
        return $value;
    }

    private function enum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException($field . ' содержит недопустимое значение.');
        return $value;
    }

    private function userTimezone(int $userId): string
    {
        $profile = $this->repository()->profileRow($userId);
        if ($profile === null) throw new InvalidArgumentException('Пользователь не найден.');
        return $this->timezone((string) $profile['timezone']);
    }

    private function timezone(string $timezone): string
    {
        try { new DateTimeZone($timezone); return $timezone; } catch (\Throwable) { return 'Europe/Moscow'; }
    }

    private function quality(array $issues = [], array $caveats = []): array
    {
        return ['complete' => $issues === [], 'issues' => array_values($issues), 'caveats' => array_values($caveats)];
    }

    private function encodeCursor(string $scope, array $payload): string
    {
        $json = json_encode(['v' => 1, 'scope' => $scope, ...$payload], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeCursor(mixed $cursor, string $scope): ?array
    {
        if ($cursor === null || $cursor === '') return null;
        if (!is_string($cursor) || strlen($cursor) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) throw new InvalidArgumentException('cursor имеет некорректный формат.');
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['scope'] ?? null) !== $scope) throw new InvalidArgumentException('cursor недействителен для этого запроса.');
        $required = match ($scope) {
            'workouts' => ['date', 'kind', 'key'], 'exercise_history' => ['at', 'key', 'sequence'], 'exercise_search' => ['name', 'key'], default => [],
        };
        foreach ($required as $key) {
            if (!isset($data[$key]) || ($key === 'sequence' ? !is_int($data[$key]) : !is_string($data[$key]))) throw new InvalidArgumentException('cursor повреждён.');
        }
        return $data;
    }

    private static function utc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') return null;
        return str_replace(' ', 'T', substr($value, 0, 19)) . 'Z';
    }
}
