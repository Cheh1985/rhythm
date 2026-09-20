<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Repository\PushNotificationRepository;
use InvalidArgumentException;

final class PushController
{
    public function __construct(private readonly PushNotificationRepository $push = new PushNotificationRepository()) {}

    public function config(): never
    {
        Auth::requireUser(true);
        $publicKey = trim((string) \env('VAPID_PUBLIC_KEY', ''));
        $enabled = $this->enabled() && $publicKey !== '';
        \json_response(['data' => ['enabled' => $enabled, 'public_key' => $enabled ? $publicKey : null, 'worker_capability' => 'push-v1']]);
    }

    public function subscribe(): never
    {
        $user = Auth::requireUser(true);
        try {
            $this->requireEnabled();
            $data = $this->input();
            $subscription = $data['subscription'] ?? null;
            if (!is_array($subscription)) throw new InvalidArgumentException('Push-подписка не передана.');
            \json_response(['data' => $this->push->saveSubscription((int) $user['id'], $subscription, (string) ($user['locale'] ?? 'ru'))], 201);
        } catch (InvalidArgumentException $exception) {
            \json_response(['error' => $exception->getMessage()], 422);
        }
    }

    public function unsubscribe(string $subscriptionId): never
    {
        $user = Auth::requireUser(true);
        try {
            $subscriptionId = rawurldecode($subscriptionId);
            $this->input();
            $removed = $this->push->disableSubscription((int) $user['id'], $subscriptionId);
            \json_response(['data' => ['subscription_id' => $subscriptionId, 'status' => 'inactive', 'existed' => $removed]]);
        } catch (InvalidArgumentException $exception) {
            \json_response(['error' => $exception->getMessage()], 422);
        }
    }

    public function syncRestTimer(string $id, string $timerId): never
    {
        $user = Auth::requireUser(true);
        try {
            $timerId = rawurldecode($timerId);
            if (!$this->enabled()) {
                $data = $this->input(true);
                \json_response(['data' => ['timer_id' => $timerId, 'revision' => (int) ($data['revision'] ?? 0), 'status' => 'cancelled', 'disabled' => true]]);
            }
            $data = $this->input(true);
            \json_response(['data' => $this->push->syncRestTimer((int) $id, (int) $user['id'], $timerId, $data)]);
        } catch (InvalidArgumentException $exception) {
            \json_response(['error' => $exception->getMessage()], 422);
        }
    }

    private function input(bool $requireActionId = false): array
    {
        $data = \json_body();
        Csrf::requireValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['_csrf'] ?? null));
        if ($requireActionId && (!is_string($data['client_action_id'] ?? null) || !preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $data['client_action_id']))) {
            throw new InvalidArgumentException('Для изменяющего действия нужен корректный client_action_id.');
        }
        return $data;
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled()) {
            throw new InvalidArgumentException('Push-уведомления пока не настроены на сервере.');
        }
    }

    private function enabled(): bool
    {
        return filter_var(\env('PUSH_ENABLED', 'false'), FILTER_VALIDATE_BOOL)
            && trim((string) \env('VAPID_PUBLIC_KEY', '')) !== ''
            && trim((string) \env('VAPID_PRIVATE_KEY', '')) !== ''
            && preg_match('#^(mailto:|https://)#', trim((string) \env('VAPID_SUBJECT', ''))) === 1;
    }
}
