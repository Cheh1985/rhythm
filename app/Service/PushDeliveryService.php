<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PushNotificationRepository;

final class PushDeliveryService
{
    public function __construct(
        private readonly PushNotificationRepository $repository = new PushNotificationRepository(),
        private readonly PushSender $sender = new WebPushSender(),
    ) {}

    /** @return array{claimed:int,sent:int,failed:int,skipped:int} */
    public function runOnce(int $limit = 50): array
    {
        $token = 'worker:' . bin2hex(random_bytes(12));
        $jobs = $this->repository->claimDue($limit, $token);
        $result = ['claimed' => count($jobs), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($jobs as $job) {
            $id = (int) $job['id'];
            $revision = (int) $job['revision'];
            if (!$this->repository->claimIsCurrent($id, $token, $revision)) {
                $result['skipped']++;
                continue;
            }
            $locale = $job['locale'] === 'en' ? 'en' : 'ru';
            $payload = json_encode([
                'type' => 'rest-complete',
                'title' => $locale === 'en' ? 'Rhythm' : 'Ритм',
                'body' => $locale === 'en' ? 'Rest is complete — time for your next set' : 'Отдых завершён — пора к следующему подходу',
                'url' => \url('/sessions/' . (int) $job['workout_session_id']),
                'timerId' => (string) $job['timer_id'],
                'revision' => $revision,
                'userId' => (string) $job['user_id'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            try {
                $delivery = $this->sender->send($job, $payload, ['TTL' => 300, 'urgency' => 'high', 'topic' => substr(hash('sha256', (string) $job['timer_id']), 0, 32)]);
                if ($delivery['success']) {
                    $this->repository->markSent($id, $token);
                    $result['sent']++;
                } else {
                    $this->repository->markFailed($id, $token, $delivery['reason'], $delivery['expired']);
                    $result['failed']++;
                }
            } catch (\Throwable $exception) {
                $this->repository->markFailed($id, $token, $exception->getMessage(), false);
                $result['failed']++;
            }
        }
        return $result;
    }
}
