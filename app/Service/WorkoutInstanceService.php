<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\ApiError;
use App\Repository\TrainingRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

final class WorkoutInstanceService
{
    public const SCHEDULED_SCOPE = 'scheduled_instance';
    public const ACTIVE_SCOPE = 'active_session';

    public function __construct(
        private readonly ?PDO $connection = null,
        private readonly ?TrainingRepository $training = null,
        private readonly ?WriteIdempotencyService $idempotency = null,
    ) {}

    public function reschedule(
        int $userId,
        string $instanceId,
        string $scope,
        string $scheduledDate,
        int $instanceVersion,
        string $clientActionId,
    ): array {
        if ($scope !== self::SCHEDULED_SCOPE) {
            throw new InvalidArgumentException('scope для переноса должен быть scheduled_instance.');
        }
        $instanceId = $this->identifier($instanceId, 190, 'instance_id');
        $request = [
            'scope' => $scope,
            'instance_id' => $instanceId,
            'scheduled_date' => $scheduledDate,
            'instance_version' => $instanceVersion,
            'client_action_id' => $clientActionId,
        ];

        return $this->transaction(function (PDO $pdo) use ($userId, $instanceId, $scope, $scheduledDate, $instanceVersion, $clientActionId, $request): array {
            $receipt = $this->idempotency()->replay($pdo, $userId, $clientActionId, 'instance.reschedule', $request);
            if ($receipt !== null) {
                return $receipt;
            }
            $training = $this->training($pdo);
            $instance = $training->workoutInstanceByExternalId($userId, $instanceId, true);
            if ($instance === null) {
                throw new ApiError('not_found', 'Workout instance не найден.', 404);
            }
            $changed = (string) $instance['scheduled_date'] !== $scheduledDate;
            $training->reschedulePlan((int) $instance['id'], $userId, $scheduledDate, $instanceVersion, 'webmcp');
            $result = [
                'scope' => $scope,
                'workout_id' => $instanceId,
                'scheduled_date' => $scheduledDate,
                'instance_version' => $instanceVersion + ($changed ? 1 : 0),
                'changed' => $changed,
                'idempotent' => false,
            ];
            $this->idempotency()->store($pdo, $userId, $clientActionId, 'instance.reschedule', $request, $result);
            return $result;
        });
    }

    public function replaceExercise(
        int $userId,
        string $instanceId,
        string $scope,
        int $exerciseSequence,
        string $replacementExerciseId,
        string $reason,
        int $instanceVersion,
        int $exerciseVersion,
        string $clientActionId,
    ): array {
        if (!in_array($scope, [self::SCHEDULED_SCOPE, self::ACTIVE_SCOPE], true)) {
            throw new InvalidArgumentException('scope должен быть scheduled_instance или active_session.');
        }
        $instanceId = $this->identifier($instanceId, $scope === self::SCHEDULED_SCOPE ? 190 : 80, 'instance_id');
        $replacementExerciseId = $this->identifier($replacementExerciseId, 80, 'replacement_exercise_id');
        if ($exerciseSequence < 1 || $exerciseSequence > 1000) {
            throw new InvalidArgumentException('exercise_sequence должен быть целым числом от 1 до 1000.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('reason должен быть непустой строкой до 1000 символов.');
        }
        $request = [
            'scope' => $scope,
            'instance_id' => $instanceId,
            'exercise_sequence' => $exerciseSequence,
            'replacement_exercise_id' => $replacementExerciseId,
            'reason' => $reason,
            'instance_version' => $instanceVersion,
            'exercise_version' => $exerciseVersion,
            'client_action_id' => $clientActionId,
        ];

        return $this->transaction(function (PDO $pdo) use ($userId, $instanceId, $scope, $exerciseSequence, $replacementExerciseId, $reason, $instanceVersion, $exerciseVersion, $clientActionId, $request): array {
            $receipt = $this->idempotency()->replay($pdo, $userId, $clientActionId, 'instance.replace', $request);
            if ($receipt !== null) {
                return $receipt;
            }
            $training = $this->training($pdo);
            if ($scope === self::SCHEDULED_SCOPE) {
                $instance = $training->workoutInstanceByExternalId($userId, $instanceId, true);
                if ($instance === null) {
                    throw new ApiError('not_found', 'Workout instance не найден.', 404);
                }
                $result = $training->replacePlannedExercise((int) $instance['id'], $userId, [
                    'instance_version' => $instanceVersion,
                    'exercise_sequence' => $exerciseSequence,
                    'exercise_version' => $exerciseVersion,
                    'actual_exercise_id' => $replacementExerciseId,
                    'reason' => $reason,
                ]);
                $result = [
                    'scope' => $scope,
                    ...$result,
                    'substituted_at_utc' => $this->utc($result['substituted_at'] ?? null),
                    'idempotent' => false,
                ];
                unset($result['substituted_at']);
            } else {
                $target = $training->activeSessionExerciseByPublicId($userId, $instanceId, $exerciseSequence, true);
                if ($target === null) {
                    throw new ApiError('not_found', 'Active session instance не найден.', 404);
                }
                $mutation = $training->replaceExercise((int) $target['session_id'], $userId, [
                    'session_version' => $instanceVersion,
                    'session_exercise_id' => (int) $target['session_exercise_id'],
                    'exercise_version' => $exerciseVersion,
                    'actual_exercise_id' => $replacementExerciseId,
                    'reason' => $reason,
                ], 'webmcp');
                $result = [
                    'scope' => $scope,
                    'session_id' => $instanceId,
                    'workout_id' => (string) $target['external_plan_id'],
                    'exercise_sequence' => $exerciseSequence,
                    'original_exercise_id' => (string) $target['original_exercise_id'],
                    'actual_exercise_id' => (string) $mutation['actual_exercise_id'],
                    'reason' => $reason,
                    'instance_version' => (int) $mutation['session_version'],
                    'exercise_version' => (int) $mutation['exercise_version'],
                    'idempotent' => false,
                ];
            }
            $this->idempotency()->store($pdo, $userId, $clientActionId, 'instance.replace', $request, $result);
            return $result;
        });
    }

    private function identifier(string $value, int $maxLength, string $field): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($field . ' имеет некорректный формат.');
        }
        return $value;
    }

    private function utc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return str_replace(' ', 'T', $value) . 'Z';
    }

    private function idempotency(): WriteIdempotencyService
    {
        return $this->idempotency ?? new WriteIdempotencyService();
    }

    private function training(PDO $pdo): TrainingRepository
    {
        return $this->training ?? new TrainingRepository($pdo);
    }

    private function transaction(callable $callback): mixed
    {
        $pdo = $this->connection ?? \db()->pdo();
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
}
