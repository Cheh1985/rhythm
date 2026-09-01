<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\SiteToolRequestGuard;
use App\Service\TrainingQueryService;
use InvalidArgumentException;

final class SiteToolsApiController
{
    public function __construct(
        private readonly TrainingQueryService $queries = new TrainingQueryService(),
        private readonly SiteToolRequestGuard $guard = new SiteToolRequestGuard()
    ) {}

    public function profile(): never
    {
        $this->guard->run('profile.get', [], fn (int $userId): ?array => $this->queries->profileContext($userId), 'profile');
    }

    public function plans(): never
    {
        $this->guard->run('plans.list', [], fn (int $userId): array => $this->queries->programs($userId), 'program');
    }

    public function plan(string $programId): never
    {
        $this->guard->run('plans.get', [], fn (int $userId): ?array => $this->queries->programVersion($userId, $programId), 'program', $programId);
    }

    public function planVersions(string $programId): never
    {
        $this->guard->run('plans.versions.list', [], fn (int $userId): ?array => $this->queries->programVersions($userId, $programId), 'program', $programId);
    }

    public function planVersion(string $programId, string $version): never
    {
        $this->guard->run('plans.versions.get', [], function (int $userId) use ($programId, $version): ?array {
            $number = SiteToolRequestGuard::positiveRouteInteger($version, 'version');
            return $this->queries->programVersion($userId, $programId, $number);
        }, 'program', $programId);
    }

    public function planTemplate(string $programId, string $templateId): never
    {
        $this->guard->run('plans.templates.get', ['version', 'limit', 'cursor'], function (int $userId, array $query) use ($programId, $templateId): ?array {
            $version = SiteToolRequestGuard::optionalInteger($query, 'version', 1, 100000);
            $limit = SiteToolRequestGuard::optionalInteger($query, 'limit', 1, TrainingQueryService::MAX_TEMPLATE_EXERCISE_LIMIT);
            $options = [];
            if ($limit !== null) $options['limit'] = $limit;
            if (array_key_exists('cursor', $query)) $options['cursor'] = $query['cursor'];
            return $this->queries->programTemplate($userId, $programId, $templateId, $version, $options);
        }, 'program_template', $programId . ':' . $templateId);
    }

    public function workouts(): never
    {
        $this->guard->run('workouts.list', ['from', 'to', 'type', 'status', 'limit', 'cursor'], function (int $userId, array $query): array {
            $filters = $query;
            $limit = SiteToolRequestGuard::optionalInteger($query, 'limit', 1, TrainingQueryService::MAX_LIST_LIMIT);
            if ($limit !== null) {
                $filters['limit'] = $limit;
            }
            return $this->queries->workouts($userId, $filters);
        }, 'workout');
    }

    public function workout(string $workoutId): never
    {
        $this->guard->run('workouts.get', [], fn (int $userId): ?array => $this->queries->plannedWorkout($userId, $workoutId), 'workout_plan', $workoutId);
    }

    public function session(string $sessionId): never
    {
        $this->guard->run('workout_facts.get', [], fn (int $userId): ?array => $this->queries->workoutFact($userId, $sessionId), 'workout_session', $sessionId);
    }

    public function exerciseHistory(string $exerciseId): never
    {
        $this->guard->run('exercise_history.list', ['from', 'to', 'limit', 'cursor'], function (int $userId, array $query) use ($exerciseId): ?array {
            $filters = $query;
            $limit = SiteToolRequestGuard::optionalInteger($query, 'limit', 1, TrainingQueryService::MAX_HISTORY_LIMIT);
            if ($limit !== null) {
                $filters['limit'] = $limit;
            }
            return $this->queries->exerciseHistory($userId, $exerciseId, $filters);
        }, 'exercise', $exerciseId);
    }

    public function progress(): never
    {
        $this->guard->run('progress.get', ['from', 'to'], fn (int $userId, array $query): array => $this->queries->progressSummary($userId, $query), 'progress');
    }

    public function scheduledWorkout(string $date): never
    {
        $this->guard->run('schedule.get', [], fn (int $userId): array => $this->queries->scheduledWorkout($userId, $date), 'schedule', $date);
    }

    public function exerciseSearch(): never
    {
        $this->guard->run('exercises.search', ['query', 'limit', 'cursor'], function (int $userId, array $query): array {
            if (!array_key_exists('query', $query)) {
                throw new InvalidArgumentException('query обязателен.');
            }
            $options = $query;
            unset($options['query']);
            $limit = SiteToolRequestGuard::optionalInteger($query, 'limit', 1, TrainingQueryService::MAX_SEARCH_LIMIT);
            if ($limit !== null) {
                $options['limit'] = $limit;
            }
            return $this->queries->searchExercises($userId, $query['query'], $options);
        }, 'exercise');
    }

    public function exerciseAlternatives(string $exerciseId): never
    {
        $this->guard->run('exercises.alternatives', ['limit'], function (int $userId, array $query) use ($exerciseId): ?array {
            $limit = SiteToolRequestGuard::optionalInteger($query, 'limit', 1, 20) ?? 8;
            return $this->queries->exerciseAlternatives($userId, $exerciseId, $limit);
        }, 'exercise', $exerciseId);
    }
}
