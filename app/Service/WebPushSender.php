<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class WebPushSender implements PushSender
{
    public function send(array $subscription, string $payload, array $options = []): array
    {
        if (!class_exists(\Minishlink\WebPush\WebPush::class) || !class_exists(\Minishlink\WebPush\Subscription::class)) {
            throw new RuntimeException('Установите Composer-зависимости перед запуском push-воркера.');
        }
        $public = (string) \env('VAPID_PUBLIC_KEY', '');
        $private = (string) \env('VAPID_PRIVATE_KEY', '');
        $subject = (string) \env('VAPID_SUBJECT', '');
        if ($public === '' || $private === '' || !preg_match('#^(mailto:|https://)#', $subject)) {
            throw new RuntimeException('VAPID-настройки не заполнены.');
        }
        $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => $subject, 'publicKey' => $public, 'privateKey' => $private]], [
            'TTL' => (int) ($options['TTL'] ?? 300),
            'urgency' => (string) ($options['urgency'] ?? 'high'),
        ]);
        $target = \Minishlink\WebPush\Subscription::create([
            'endpoint' => (string) $subscription['endpoint'],
            'publicKey' => (string) $subscription['p256dh'],
            'authToken' => (string) $subscription['auth_secret'],
            'contentEncoding' => (string) ($subscription['content_encoding'] ?? 'aes128gcm'),
        ]);
        $report = $webPush->sendOneNotification($target, $payload, [
            'TTL' => (int) ($options['TTL'] ?? 300),
            'urgency' => (string) ($options['urgency'] ?? 'high'),
            'topic' => (string) ($options['topic'] ?? ''),
        ]);
        return [
            'success' => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
            'reason' => $report->isSuccess() ? '' : (string) $report->getReason(),
        ];
    }
}
