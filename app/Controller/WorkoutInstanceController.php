<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\ApiInput;
use App\Core\FeatureFlags;
use App\Core\SemanticWriteRequestGuard;
use App\Service\WorkoutInstanceService;
use InvalidArgumentException;

final class WorkoutInstanceController
{
    public function __construct(
        private readonly WorkoutInstanceService $instances = new WorkoutInstanceService(),
        private readonly SemanticWriteRequestGuard $guard = new SemanticWriteRequestGuard(),
    ) {}

    public function reschedule(string $instanceId): never
    {
        $this->guard->run('workout_instances.reschedule', FeatureFlags::WEBMCP_INSTANCE_WRITE_ENABLED, function (int $userId, array $body, string $key) use ($instanceId): array {
            $this->shape($body, ['scope', 'scheduled_date', 'instance_version', 'client_action_id']);
            $clientActionId = $this->clientActionId($body, $key);
            return $this->instances->reschedule(
                $userId,
                $instanceId,
                ApiInput::string($body, 'scope', 32),
                ApiInput::string($body, 'scheduled_date', 10),
                ApiInput::integer($body, 'instance_version', 1, 1000000),
                $clientActionId,
            );
        }, 200, true, 'workout_plan', $instanceId);
    }

    public function replaceExercise(string $instanceId): never
    {
        $this->guard->run('workout_instances.replace_exercise', FeatureFlags::WEBMCP_INSTANCE_WRITE_ENABLED, function (int $userId, array $body, string $key) use ($instanceId): array {
            $this->shape($body, [
                'scope', 'exercise_sequence', 'replacement_exercise_id', 'reason',
                'instance_version', 'exercise_version', 'client_action_id',
            ]);
            $clientActionId = $this->clientActionId($body, $key);
            return $this->instances->replaceExercise(
                $userId,
                $instanceId,
                ApiInput::string($body, 'scope', 32),
                ApiInput::integer($body, 'exercise_sequence', 1, 1000),
                ApiInput::string($body, 'replacement_exercise_id', 80),
                ApiInput::string($body, 'reason', 1000),
                ApiInput::integer($body, 'instance_version', 1, 1000000),
                ApiInput::integer($body, 'exercise_version', 1, 1000000),
                $clientActionId,
            );
        }, 200, true, 'workout_instance', $instanceId);
    }

    private function clientActionId(array $body, string $key): string
    {
        $clientActionId = ApiInput::string($body, 'client_action_id', 80);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/D', $clientActionId) !== 1) {
            throw new InvalidArgumentException('Некорректный client_action_id.');
        }
        if (!hash_equals($key, $clientActionId)) {
            throw new InvalidArgumentException('Idempotency-Key должен совпадать с client_action_id.');
        }
        return $clientActionId;
    }

    private function shape(array $body, array $required): void
    {
        $unknown = array_diff(array_keys($body), $required);
        $missing = array_diff($required, array_keys($body));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Неизвестное поле ' . reset($unknown) . '.');
        }
        if ($missing !== []) {
            throw new InvalidArgumentException('Отсутствует обязательное поле ' . reset($missing) . '.');
        }
    }
}
