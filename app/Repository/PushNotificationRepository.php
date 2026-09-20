<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PushNotificationRepository
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function saveSubscription(int $userId, array $data, string $locale): array
    {
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $keys = $data['keys'] ?? null;
        $p256dh = is_array($keys) ? (string) ($keys['p256dh'] ?? '') : '';
        $auth = is_array($keys) ? (string) ($keys['auth'] ?? '') : '';
        $encoding = (string) ($data['contentEncoding'] ?? $data['content_encoding'] ?? 'aes128gcm');
        $expiration = $data['expirationTime'] ?? $data['expiration_time'] ?? null;
        $parts = parse_url($endpoint);
        if ($userId < 1 || strlen($endpoint) > 2048 || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Некорректный endpoint push-подписки.');
        }
        foreach ([$p256dh, $auth] as $key) {
            if (!preg_match('/^[A-Za-z0-9_-]{16,512}$/', $key)) throw new InvalidArgumentException('Некорректные ключи push-подписки.');
        }
        if (!in_array($encoding, ['aes128gcm', 'aesgcm'], true)) throw new InvalidArgumentException('Неподдерживаемая кодировка push-подписки.');
        if ($expiration !== null && (!is_int($expiration) && !(is_string($expiration) && ctype_digit($expiration)))) {
            throw new InvalidArgumentException('Некорректный срок push-подписки.');
        }
        $locale = in_array($locale, ['ru', 'en'], true) ? $locale : 'ru';
        $hash = hash('sha256', $endpoint);
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id,public_id FROM push_subscriptions WHERE endpoint_hash=?' . $this->lock());
            $query->execute([$hash]);
            $existing = $query->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE push_subscriptions SET user_id=?,endpoint=?,p256dh=?,auth_secret=?,content_encoding=?,expiration_time=?,locale=?,status='active',failure_count=0,updated_at=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([$userId, $endpoint, $p256dh, $auth, $encoding, $expiration === null ? null : (int) $expiration, $locale, $existing['id']]);
                $id = (int) $existing['id'];
                $publicId = (string) $existing['public_id'];
            } else {
                $publicId = 'push:' . bin2hex(random_bytes(16));
                $insert = $pdo->prepare("INSERT INTO push_subscriptions (public_id,user_id,endpoint,endpoint_hash,p256dh,auth_secret,content_encoding,expiration_time,locale,status,failure_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$publicId, $userId, $endpoint, $hash, $p256dh, $auth, $encoding, $expiration === null ? null : (int) $expiration, $locale]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE push_subscription_id=? AND user_id<>? AND status IN ('scheduled','processing')")
                ->execute([$id, $userId]);
            $pdo->commit();
            return ['subscription_id' => $publicId, 'status' => 'active'];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function disableSubscription(int $userId, string $publicId): bool
    {
        if (!preg_match('/^push:[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Некорректный идентификатор push-подписки.');
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id FROM push_subscriptions WHERE public_id=? AND user_id=?' . $this->lock());
            $query->execute([$publicId, $userId]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $pdo->commit();
                return false;
            }
            $pdo->prepare("UPDATE push_subscriptions SET status='inactive',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int) $id]);
            $pdo->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE push_subscription_id=? AND status IN ('scheduled','processing')")
                ->execute([(int) $id]);
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function syncRestTimer(int $sessionId, int $userId, string $timerId, array $data): array
    {
        $revision = $data['revision'] ?? null;
        $state = (string) ($data['state'] ?? '');
        $exerciseId = $data['session_exercise_id'] ?? null;
        $subscriptionPublicId = (string) ($data['subscription_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $timerId)
            || !is_int($revision) || $revision < 1 || $revision > 9007199254740991
            || !in_array($state, ['scheduled', 'cancelled'], true)
            || !is_int($exerciseId) || $exerciseId < 1
            || !preg_match('/^push:[a-f0-9]{32}$/', $subscriptionPublicId)) {
            throw new InvalidArgumentException('Некорректные данные push-таймера.');
        }
        $deadline = $this->clientUtc($data['deadline_at_utc'] ?? null);
        if ($deadline['timestamp'] > time() + 8 * 3600) throw new InvalidArgumentException('Окончание отдыха слишком далеко в будущем.');

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $session = $pdo->prepare("SELECT id FROM workout_sessions WHERE id=? AND user_id=? AND status='in_progress' AND deleted_at IS NULL" . $this->lock());
            $session->execute([$sessionId, $userId]);
            if (!$session->fetchColumn()) throw new InvalidArgumentException('Активная тренировка для push-таймера не найдена.');
            $exercise = $pdo->prepare('SELECT id FROM session_exercises WHERE id=? AND workout_session_id=?');
            $exercise->execute([$exerciseId, $sessionId]);
            if (!$exercise->fetchColumn()) throw new InvalidArgumentException('Упражнение push-таймера не найдено.');
            $subscription = $pdo->prepare('SELECT id,status FROM push_subscriptions WHERE public_id=? AND user_id=?' . $this->lock());
            $subscription->execute([$subscriptionPublicId, $userId]);
            $subscriptionRow = $subscription->fetch();
            if (!$subscriptionRow) throw new InvalidArgumentException('Push-подписка не найдена.');
            $subscriptionId = (int) $subscriptionRow['id'];
            if ($subscriptionRow['status'] !== 'active') {
                $pdo->commit();
                return ['timer_id' => $timerId, 'revision' => $revision, 'status' => 'cancelled', 'stale' => false, 'subscription_inactive' => true];
            }

            $existingQuery = $pdo->prepare('SELECT * FROM rest_notification_jobs WHERE push_subscription_id=? AND timer_id=?' . $this->lock());
            $existingQuery->execute([$subscriptionId, $timerId]);
            $existing = $existingQuery->fetch();
            if ($existing && $revision <= (int) $existing['revision']) {
                $pdo->commit();
                return ['timer_id' => $timerId, 'revision' => (int) $existing['revision'], 'status' => (string) $existing['status'], 'stale' => $revision < (int) $existing['revision']];
            }

            $status = $state === 'scheduled' && $deadline['timestamp'] > time() ? 'scheduled' : 'cancelled';
            $error = $state === 'scheduled' && $status === 'cancelled' ? 'deadline_elapsed_before_sync' : null;
            if ($existing) {
                $update = $pdo->prepare('UPDATE rest_notification_jobs SET user_id=?,workout_session_id=?,session_exercise_id=?,revision=?,deadline_at=?,status=?,attempt_count=0,next_attempt_at=NULL,lock_token=NULL,locked_at=NULL,sent_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
                $update->execute([$userId, $sessionId, $exerciseId, $revision, $deadline['database'], $status, $error, $existing['id']]);
            } else {
                $insert = $pdo->prepare('INSERT INTO rest_notification_jobs (user_id,workout_session_id,session_exercise_id,push_subscription_id,timer_id,revision,deadline_at,status,attempt_count,last_error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                $insert->execute([$userId, $sessionId, $exerciseId, $subscriptionId, $timerId, $revision, $deadline['database'], $status, $error]);
            }
            if ($status === 'scheduled') {
                $cancelOthers = $pdo->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE push_subscription_id=? AND workout_session_id=? AND timer_id<>? AND status IN ('scheduled','processing')");
                $cancelOthers->execute([$subscriptionId, $sessionId, $timerId]);
            }
            $pdo->commit();
            return ['timer_id' => $timerId, 'revision' => $revision, 'status' => $status, 'stale' => false];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function cancelTimer(int $userId, int $sessionId, string $timerId): void
    {
        $query = $this->pdo()->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=? AND workout_session_id=? AND timer_id=? AND status IN ('scheduled','processing')");
        $query->execute([$userId, $sessionId, $timerId]);
    }

    public function cancelSession(int $userId, int $sessionId): void
    {
        $query = $this->pdo()->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=? AND workout_session_id=? AND status IN ('scheduled','processing')");
        $query->execute([$userId, $sessionId]);
    }

    public function claimDue(int $limit, string $token): array
    {
        $limit = max(1, min(200, $limit));
        if (!preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $token)) throw new InvalidArgumentException('Некорректный token воркера.');
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE rest_notification_jobs SET status='scheduled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE status='processing' AND locked_at<?")
                ->execute([gmdate('Y-m-d H:i:s', time() - 300)]);
            $sql = "SELECT j.id FROM rest_notification_jobs j JOIN push_subscriptions ps ON ps.id=j.push_subscription_id WHERE j.status='scheduled' AND j.deadline_at<=UTC_TIMESTAMP() AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=UTC_TIMESTAMP()) AND ps.status='active' ORDER BY j.deadline_at,j.id LIMIT {$limit}" . $this->lock();
            $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $pdo->prepare("UPDATE rest_notification_jobs SET status='processing',lock_token=?,locked_at=UTC_TIMESTAMP(),attempt_count=attempt_count+1,updated_at=UTC_TIMESTAMP() WHERE id IN ({$placeholders}) AND status='scheduled'");
                $update->execute([$token, ...array_map('intval', $ids)]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $pdo->prepare("SELECT j.*,ps.endpoint,ps.p256dh,ps.auth_secret,ps.content_encoding,ps.locale,ps.status subscription_status FROM rest_notification_jobs j JOIN push_subscriptions ps ON ps.id=j.push_subscription_id WHERE j.id IN ({$placeholders}) AND j.status='processing' AND j.lock_token=? ORDER BY j.deadline_at,j.id");
        $query->execute([...array_map('intval', $ids), $token]);
        return $query->fetchAll();
    }

    public function claimIsCurrent(int $jobId, string $token, int $revision): bool
    {
        $query = $this->pdo()->prepare("SELECT 1 FROM rest_notification_jobs j JOIN push_subscriptions ps ON ps.id=j.push_subscription_id WHERE j.id=? AND j.lock_token=? AND j.revision=? AND j.status='processing' AND ps.status='active'");
        $query->execute([$jobId, $token, $revision]);
        return (bool) $query->fetchColumn();
    }

    public function markSent(int $jobId, string $token): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $job = $pdo->prepare("SELECT push_subscription_id FROM rest_notification_jobs WHERE id=? AND lock_token=? AND status='processing'" . $this->lock());
            $job->execute([$jobId, $token]);
            $subscriptionId = $job->fetchColumn();
            if ($subscriptionId !== false) {
                $pdo->prepare("UPDATE rest_notification_jobs SET status='sent',sent_at=UTC_TIMESTAMP(),lock_token=NULL,locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND lock_token=?")->execute([$jobId, $token]);
                $pdo->prepare("UPDATE push_subscriptions SET failure_count=0,last_success_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int) $subscriptionId]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function markFailed(int $jobId, string $token, string $reason, bool $expired): void
    {
        $reason = mb_substr($reason, 0, 1000);
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare("SELECT push_subscription_id,attempt_count,deadline_at FROM rest_notification_jobs WHERE id=? AND lock_token=? AND status='processing'" . $this->lock());
            $query->execute([$jobId, $token]);
            $job = $query->fetch();
            if ($job) {
                $attempt = (int) $job['attempt_count'];
                $retry = !$expired && $attempt < 5 && strtotime((string) $job['deadline_at'] . ' UTC') >= time() - 600;
                $next = $retry ? gmdate('Y-m-d H:i:s', time() + min(120, 5 * (2 ** max(0, $attempt - 1)))) : null;
                $pdo->prepare('UPDATE rest_notification_jobs SET status=?,next_attempt_at=?,lock_token=NULL,locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND lock_token=?')
                    ->execute([$retry ? 'scheduled' : 'failed', $next, $reason, $jobId, $token]);
                $pdo->prepare('UPDATE push_subscriptions SET status=?,failure_count=failure_count+1,last_failure_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')
                    ->execute([$expired ? 'inactive' : 'active', $job['push_subscription_id']]);
                if ($expired) {
                    $pdo->prepare("UPDATE rest_notification_jobs SET status='cancelled',lock_token=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE push_subscription_id=? AND status IN ('scheduled','processing')")
                        ->execute([$job['push_subscription_id']]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function cleanup(int $retentionDays = 30): int
    {
        $before = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * 86400);
        $query = $this->pdo()->prepare("DELETE FROM rest_notification_jobs WHERE status IN ('sent','cancelled','failed') AND updated_at<?");
        $query->execute([$before]);
        return $query->rowCount();
    }

    private function clientUtc(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 40) throw new InvalidArgumentException('Некорректное время окончания отдыха.');
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Некорректное время окончания отдыха.');
        }
        $timestamp = $date->getTimestamp();
        return ['timestamp' => $timestamp, 'database' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }

    private function lock(): string
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
