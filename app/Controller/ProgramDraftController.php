<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\ApiInput;
use App\Core\FeatureFlags;
use App\Core\SemanticWriteRequestGuard;
use App\Service\ActivationConfirmationStore;
use App\Service\ProgramActivationService;
use App\Service\ProgramDraftApplicationService;
use InvalidArgumentException;

final class ProgramDraftController
{
    public function __construct(
        private readonly ProgramDraftApplicationService $drafts = new ProgramDraftApplicationService(),
        private readonly ProgramActivationService $activation = new ProgramActivationService(),
        private readonly ActivationConfirmationStore $confirmations = new ActivationConfirmationStore(),
        private readonly SemanticWriteRequestGuard $guard = new SemanticWriteRequestGuard(),
    ) {}

    public function create(): never
    {
        $this->guard->run('program_drafts.create', FeatureFlags::WEBMCP_DRAFT_WRITE_ENABLED, function (int $userId, array $body, string $key): array {
            $mode = ApiInput::string($body, 'mode', 10);
            if ($mode === 'new') {
                $this->shape($body, ['mode', 'metadata', 'reason']);
                return $this->drafts->create($userId, ApiInput::object($body, 'metadata'), ApiInput::string($body, 'reason', 1000), $key);
            }
            if ($mode === 'clone') {
                $this->shape($body, ['mode', 'program_id', 'reason'], ['source_version']);
                $sourceVersion = $body['source_version'] ?? null;
                if ($sourceVersion !== null && (!is_int($sourceVersion) || $sourceVersion < 1 || $sourceVersion > 100000)) {
                    throw new InvalidArgumentException('source_version должен быть целым числом от 1 до 100000 или null.');
                }
                return $this->drafts->clone(
                    $userId,
                    ApiInput::string($body, 'program_id', 190),
                    $sourceVersion,
                    ApiInput::string($body, 'reason', 1000),
                    $key,
                );
            }
            throw new InvalidArgumentException('mode должен быть new или clone.');
        }, 201, true, 'program_version');
    }

    public function update(string $draftId): never
    {
        $this->guard->run('program_drafts.update', FeatureFlags::WEBMCP_DRAFT_WRITE_ENABLED, function (int $userId, array $body, string $key) use ($draftId): array {
            $id = ApiInput::positiveId($draftId, 'draft_id');
            $this->shape($body, ['lock_version', 'operation', 'payload']);
            return $this->drafts->update(
                $userId,
                $id,
                ApiInput::integer($body, 'lock_version', 1, 1000000),
                ApiInput::string($body, 'operation', 80),
                ApiInput::object($body, 'payload'),
                $key,
            );
        }, 200, true, 'program_version');
    }

    public function prepareActivation(string $draftId): never
    {
        $this->guard->run('program_activation.prepare', FeatureFlags::WEBMCP_ACTIVATION_ENABLED, function (int $userId, array $body) use ($draftId): array {
            $id = ApiInput::positiveId($draftId, 'draft_id');
            $this->shape($body, ['lock_version', 'aggregate_hash', 'effective_from', 'horizon_weeks', 'future_plan_policy']);
            $binding = [
                'user_id' => $userId,
                'draft_id' => $id,
                'lock_version' => ApiInput::integer($body, 'lock_version', 1, 1000000),
                'aggregate_hash' => ApiInput::string($body, 'aggregate_hash', 64),
                'effective_from' => ApiInput::string($body, 'effective_from', 10),
                'horizon_weeks' => ApiInput::integer($body, 'horizon_weeks', 1, 12),
                'future_plan_policy' => ApiInput::string($body, 'future_plan_policy', 20),
            ];
            $preview = $this->activation->preview(...array_values($binding));
            return $this->confirmations->prepare($userId, $binding, $preview);
        }, 202, false, 'program_version');
    }

    public function confirmActivation(string $draftId): never
    {
        $this->guard->run('program_activation.confirm', FeatureFlags::WEBMCP_ACTIVATION_ENABLED, function (int $userId, array $body) use ($draftId): array {
            $id = ApiInput::positiveId($draftId, 'draft_id');
            $this->shape($body, ['confirmation_token']);
            $confirmation = $this->confirmations->consume($userId, $this->confirmationToken($body));
            if ((int) ($confirmation['binding']['draft_id'] ?? 0) !== $id) {
                throw new InvalidArgumentException('Подтверждение не относится к выбранному draft.');
            }
            return $this->activation->activate($confirmation);
        }, 200, false, 'program_version', $draftId, false);
    }

    public function cancelActivation(string $draftId): never
    {
        $this->guard->run('program_activation.cancel', FeatureFlags::WEBMCP_ACTIVATION_ENABLED, function (int $userId, array $body) use ($draftId): array {
            $id = ApiInput::positiveId($draftId, 'draft_id');
            $this->shape($body, ['confirmation_token']);
            $confirmation = $this->confirmations->consume($userId, $this->confirmationToken($body));
            if ((int) ($confirmation['binding']['draft_id'] ?? 0) !== $id) {
                throw new InvalidArgumentException('Подтверждение не относится к выбранному draft.');
            }
            return ['code' => 'USER_CANCELLED', 'cancelled' => true, 'mutated' => false, 'confirmation_required' => true];
        }, 200, false, 'program_version', $draftId);
    }

    private function confirmationToken(array $body): string
    {
        $token = ApiInput::string($body, 'confirmation_token', 64);
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Некорректный одноразовый токен подтверждения.');
        }
        return $token;
    }

    private function shape(array $body, array $required, array $optional = []): void
    {
        $unknown = array_diff(array_keys($body), [...$required, ...$optional]);
        $missing = array_diff($required, array_keys($body));
        if ($unknown !== []) throw new InvalidArgumentException('Неизвестное поле ' . reset($unknown) . '.');
        if ($missing !== []) throw new InvalidArgumentException('Отсутствует обязательное поле ' . reset($missing) . '.');
    }
}
