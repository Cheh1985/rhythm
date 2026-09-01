<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\ApiError;
use App\Core\Csrf;
use App\Core\FeatureFlags;
use App\Core\SameOrigin;
use App\Core\VersionConflictException;
use App\Service\ActivationConfirmationStore;
use App\Service\AssistantAuditService;
use App\Service\ProgramActivationService;
use App\WebMcp\ToolCatalog;
use Throwable;
use InvalidArgumentException;

final class AssistantController
{
    public function __construct(
        private readonly ProgramActivationService $activation = new ProgramActivationService(),
        private readonly ActivationConfirmationStore $confirmations = new ActivationConfirmationStore(),
        private readonly AssistantAuditService $audit = new AssistantAuditService(),
    ) {}

    public function index(): void
    {
        $user = Auth::requireUser();
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie');

        $userId = (int) $user['id'];
        $masterEnabled = FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_ENABLED, $userId);
        $readEnabled = FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_READ_ENABLED, $userId);
        $draftWriteEnabled = FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_DRAFT_WRITE_ENABLED, $userId);
        $instanceWriteEnabled = FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_INSTANCE_WRITE_ENABLED, $userId);
        $activationEnabled = FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_ACTIVATION_ENABLED, $userId);
        $toolCatalog = ToolCatalog::enabled($readEnabled, $draftWriteEnabled, $instanceWriteEnabled, $activationEnabled);

        \render('assistant', [
            'toolCatalog' => $toolCatalog,
            'webMcpAdapter' => $toolCatalog !== [],
            'webMcpMasterEnabled' => $masterEnabled,
            'webMcpReadEnabled' => $readEnabled,
            'webMcpDraftWriteEnabled' => $draftWriteEnabled,
            'webMcpInstanceWriteEnabled' => $instanceWriteEnabled,
            'webMcpActivationEnabled' => $activationEnabled,
            'activationConfirmation' => $activationEnabled ? $this->confirmations->peek($userId) : null,
            'activationError' => $_SESSION['activation_error'] ?? null,
            'activationSuccess' => $_SESSION['activation_success'] ?? null,
        ], 'Ассистент');
        unset($_SESSION['activation_error'], $_SESSION['activation_success']);
    }

    public function confirmActivation(): never
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];
        $startedAt = hrtime(true);
        try {
            if (!FeatureFlags::enabledForUser(FeatureFlags::WEBMCP_ACTIVATION_ENABLED, $userId)) {
                throw new \RuntimeException('Activation workflow выключен.');
            }
            SameOrigin::requireValid();
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                throw new \InvalidArgumentException('Сессия формы истекла.');
            }
            $confirmation = $this->confirmations->consume($userId, (string) ($_POST['confirmation_token'] ?? ''));
            $this->activation->activate($confirmation);
            $_SESSION['activation_success'] = 'Версия программы активирована; будущие планы обновлены строго по показанному preview.';
        } catch (Throwable $exception) {
            try {
                $this->audit->record($userId, 'program_activation.confirm', 'error', [
                    'entity_type' => 'program_version', 'duration_ms' => $this->durationMs($startedAt),
                    'error_code' => 'activation_rejected', 'confirmation_required' => true, 'status' => 409,
                ]);
            } catch (Throwable) {
                // Preserve the activation error shown to the user even if audit storage is unavailable.
            }
            $_SESSION['activation_error'] = $this->publicError($exception);
        }
        \redirect('/assistant#activation-confirmation');
    }

    public function cancelActivation(): never
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];
        try {
            SameOrigin::requireValid();
            if (!Csrf::validate($_POST['_csrf'] ?? null)) throw new \InvalidArgumentException('Сессия формы истекла.');
            $this->confirmations->cancel($userId, (string) ($_POST['confirmation_token'] ?? ''));
            $this->audit->record($userId, 'program_activation.cancel', 'success', [
                'entity_type' => 'program_version', 'confirmation_required' => true, 'status' => 200,
            ]);
            $_SESSION['activation_success'] = 'Activation отменена. База данных не изменялась.';
        } catch (Throwable $exception) {
            $_SESSION['activation_error'] = $this->publicError($exception);
        }
        \redirect('/assistant#activation-confirmation');
    }

    private function durationMs(int $startedAt): int
    {
        return min(86400000, max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)));
    }

    private function publicError(Throwable $exception): string
    {
        return $exception instanceof InvalidArgumentException || $exception instanceof VersionConflictException || $exception instanceof ApiError
            ? $exception->getMessage()
            : 'Activation не выполнена. Подготовьте preview заново или повторите позже.';
    }
}
