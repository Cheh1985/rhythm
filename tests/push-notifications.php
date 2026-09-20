<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Repository\PushNotificationRepository;
use App\Service\PushDeliveryService;
use App\Service\PushSender;

final class FakePushSender implements PushSender
{
    public array $deliveries = [];
    public array $result = ['success' => true, 'expired' => false, 'reason' => ''];
    public function send(array $subscription, string $payload, array $options = []): array
    {
        $this->deliveries[] = ['subscription' => $subscription, 'payload' => json_decode($payload, true), 'options' => $options];
        return $this->result;
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE workout_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,deleted_at TEXT NULL);
CREATE TABLE session_exercises(id INTEGER PRIMARY KEY,workout_session_id INTEGER);
CREATE TABLE push_subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT UNIQUE,user_id INTEGER,endpoint TEXT,endpoint_hash TEXT UNIQUE,p256dh TEXT,auth_secret TEXT,content_encoding TEXT,expiration_time INTEGER NULL,locale TEXT,status TEXT,failure_count INTEGER,last_success_at TEXT NULL,last_failure_at TEXT NULL,created_at TEXT,updated_at TEXT);
CREATE TABLE rest_notification_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,workout_session_id INTEGER,session_exercise_id INTEGER,push_subscription_id INTEGER,timer_id TEXT,revision INTEGER,deadline_at TEXT,status TEXT,attempt_count INTEGER,next_attempt_at TEXT NULL,lock_token TEXT NULL,locked_at TEXT NULL,sent_at TEXT NULL,last_error TEXT NULL,created_at TEXT,updated_at TEXT,UNIQUE(push_subscription_id,timer_id));
INSERT INTO users VALUES(1),(2);
INSERT INTO workout_sessions VALUES(10,1,'in_progress',NULL),(11,2,'in_progress',NULL);
INSERT INTO session_exercises VALUES(20,10),(21,11);
SQL);

$repository = new PushNotificationRepository($pdo);
$subscription = $repository->saveSubscription(1, [
    'endpoint' => 'https://push.example.test/subscription/one',
    'expirationTime' => null,
    'keys' => ['p256dh' => str_repeat('A', 88), 'auth' => str_repeat('B', 24)],
], 'ru');
if (!preg_match('/^push:[a-f0-9]{32}$/', $subscription['subscription_id'])) throw new RuntimeException('Subscription public id is invalid');

$deadline = gmdate('Y-m-d\TH:i:s\Z', time() + 90);
$scheduled = $repository->syncRestTimer(10, 1, 'rest:timer-push-0001', [
    'revision' => 1, 'state' => 'scheduled', 'deadline_at_utc' => $deadline,
    'session_exercise_id' => 20, 'subscription_id' => $subscription['subscription_id'],
]);
if ($scheduled['status'] !== 'scheduled' || $scheduled['revision'] !== 1) throw new RuntimeException('Timer was not scheduled');

$stale = $repository->syncRestTimer(10, 1, 'rest:timer-push-0001', [
    'revision' => 1, 'state' => 'cancelled', 'deadline_at_utc' => $deadline,
    'session_exercise_id' => 20, 'subscription_id' => $subscription['subscription_id'],
]);
if ($stale['status'] !== 'scheduled' || $stale['stale']) throw new RuntimeException('Equal revision was not idempotent');

$cancelled = $repository->syncRestTimer(10, 1, 'rest:timer-push-0001', [
    'revision' => 2, 'state' => 'cancelled', 'deadline_at_utc' => $deadline,
    'session_exercise_id' => 20, 'subscription_id' => $subscription['subscription_id'],
]);
if ($cancelled['status'] !== 'cancelled') throw new RuntimeException('Timer was not cancelled');

$rescheduled = $repository->syncRestTimer(10, 1, 'rest:timer-push-0001', [
    'revision' => 3, 'state' => 'scheduled', 'deadline_at_utc' => $deadline,
    'session_exercise_id' => 20, 'subscription_id' => $subscription['subscription_id'],
]);
if ($rescheduled['status'] !== 'scheduled') throw new RuntimeException('Timer was not rescheduled');

try {
    $repository->syncRestTimer(11, 2, 'rest:timer-push-0002', [
        'revision' => 1, 'state' => 'scheduled', 'deadline_at_utc' => $deadline,
        'session_exercise_id' => 21, 'subscription_id' => $subscription['subscription_id'],
    ]);
    throw new RuntimeException('Cross-tenant subscription was accepted');
} catch (InvalidArgumentException) {}

$pdo->exec("UPDATE rest_notification_jobs SET deadline_at='2026-01-01 00:00:00' WHERE timer_id='rest:timer-push-0001'");
$sender = new FakePushSender();
$delivery = new PushDeliveryService($repository, $sender);
$result = $delivery->runOnce(10);
if ($result['sent'] !== 1 || count($sender->deliveries) !== 1) throw new RuntimeException('Due push job was not delivered');
$payload = $sender->deliveries[0]['payload'];
if ($payload['body'] !== 'Отдых завершён — пора к следующему подходу' || $payload['revision'] !== 3 || !str_ends_with($payload['url'], '/sessions/10')) {
    throw new RuntimeException('Push payload is incorrect');
}
if ($pdo->query("SELECT status FROM rest_notification_jobs WHERE timer_id='rest:timer-push-0001'")->fetchColumn() !== 'sent') throw new RuntimeException('Delivered job was not marked sent');

$expiredSubscription = $repository->saveSubscription(1, [
    'endpoint' => 'https://push.example.test/subscription/expired',
    'keys' => ['p256dh' => str_repeat('C', 88), 'auth' => str_repeat('D', 24)],
], 'ru');
$repository->syncRestTimer(10, 1, 'rest:timer-push-expired', [
    'revision' => 1, 'state' => 'scheduled', 'deadline_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
    'session_exercise_id' => 20, 'subscription_id' => $expiredSubscription['subscription_id'],
]);
$pdo->exec("UPDATE rest_notification_jobs SET deadline_at='2026-01-01 00:00:00' WHERE timer_id='rest:timer-push-expired'");
$expiredSender = new FakePushSender();
$expiredSender->result = ['success' => false, 'expired' => true, 'reason' => '410 Gone'];
$expiredResult = (new PushDeliveryService($repository, $expiredSender))->runOnce(10);
if ($expiredResult['failed'] !== 1) throw new RuntimeException('Expired subscription failure was not recorded');
$expiredStatus = $pdo->prepare('SELECT status FROM push_subscriptions WHERE public_id=?');
$expiredStatus->execute([$expiredSubscription['subscription_id']]);
if ($expiredStatus->fetchColumn() !== 'inactive') throw new RuntimeException('Expired subscription was not deactivated');

$repository->disableSubscription(1, $subscription['subscription_id']);
if ($pdo->query('SELECT status FROM push_subscriptions')->fetchColumn() !== 'inactive') throw new RuntimeException('Subscription was not disabled');

$root = dirname(__DIR__);
$serviceWorker = (string) file_get_contents($root . '/public/service-worker.js');
$pushJs = (string) file_get_contents($root . '/public/assets/push.js');
$workoutJs = (string) file_get_contents($root . '/public/assets/workout.js');
if (!str_contains($serviceWorker, "addEventListener('push'") || !str_contains($serviceWorker, "addEventListener('notificationclick'") || !str_contains($serviceWorker, 'push-v1')) throw new RuntimeException('Service Worker push handlers are missing');
if (!str_contains($pushJs, 'Notification.requestPermission()') || !str_contains($pushJs, 'pushManager.subscribe')) throw new RuntimeException('User-triggered subscription flow is missing');
if (!str_contains($workoutJs, "'rest.notification'") || !str_contains($workoutJs, "'rest:' + sessionId")) throw new RuntimeException('Workout timer push synchronization is missing');

fwrite(STDOUT, "Push notification checks passed.\n");
