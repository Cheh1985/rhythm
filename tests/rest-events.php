<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/VersionConflictException.php';
require dirname(__DIR__) . '/app/Repository/TrainingRepository.php';

use App\Repository\TrainingRepository;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-19 12:00:00', 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE workout_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,deleted_at TEXT NULL);
CREATE TABLE session_exercises(id INTEGER PRIMARY KEY,workout_session_id INTEGER);
CREATE TABLE exercise_sets(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER,session_exercise_id INTEGER,client_action_id TEXT,deleted_at TEXT NULL);
CREATE TABLE rest_events(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,user_id INTEGER,workout_session_id INTEGER,session_exercise_id INTEGER,exercise_set_id INTEGER,duration_seconds INTEGER,started_at TEXT,deadline_at TEXT,ended_at TEXT,outcome TEXT,trigger_kind TEXT,created_at TEXT,UNIQUE(user_id,public_id));
CREATE TABLE offline_action_receipts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,client_action_id TEXT,action_type TEXT,response_json TEXT,created_at TEXT,UNIQUE(user_id,client_action_id));
CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,entity_type TEXT,entity_id TEXT,action TEXT,before_json TEXT,after_json TEXT,ip_address TEXT,created_at TEXT);
INSERT INTO users VALUES(1),(2);
INSERT INTO workout_sessions VALUES(10,1,'in_progress',NULL);
INSERT INTO session_exercises VALUES(20,10);
INSERT INTO exercise_sets VALUES(30,1,10,20,'set.create:source-0001',NULL);
SQL);

$repository = new TrainingRepository($pdo);
$base = [
    'client_action_id' => 'rest.finish:action-0001',
    'timer_id' => 'rest:timer-0001',
    'session_exercise_id' => 20,
    'source_set_client_action_id' => 'set.create:source-0001',
    'duration_seconds' => 90,
    'started_at_utc' => '2026-09-19T10:00:00.000Z',
    'deadline_at_utc' => '2026-09-19T10:01:30.000Z',
    'ended_at_utc' => '2026-09-19T10:01:30.000Z',
    'outcome' => 'completed',
    'trigger' => 'timer_elapsed',
];

$first = $repository->logRestEvent(10, 1, $base);
$replayed = $repository->logRestEvent(10, 1, $base);
if ($first !== $replayed || (int) $pdo->query('SELECT COUNT(*) FROM rest_events')->fetchColumn() !== 1) throw new RuntimeException('Rest completion is not idempotent');
if ($pdo->query('SELECT ended_at FROM rest_events')->fetchColumn() !== '2026-09-19 10:01:30') throw new RuntimeException('Completed rest did not use its deadline');
if ((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='rest_event'")->fetchColumn() !== 1) throw new RuntimeException('Rest event audit was duplicated');

$sameTimer = [...$base, 'client_action_id' => 'rest.finish:action-0002'];
$same = $repository->logRestEvent(10, 1, $sameTimer);
if ($same['id'] !== $first['id'] || (int) $pdo->query('SELECT COUNT(*) FROM rest_events')->fetchColumn() !== 1) throw new RuntimeException('Timer id did not prevent a duplicate');

$early = [...$base,
    'client_action_id' => 'rest.finish:action-0003',
    'timer_id' => 'rest:timer-0002',
    'ended_at_utc' => '2026-09-19T10:00:45.000Z',
    'outcome' => 'ended_early',
    'trigger' => 'user',
];
$earlyResult = $repository->logRestEvent(10, 1, $early);
if ($earlyResult['outcome'] !== 'ended_early') throw new RuntimeException('Early rest was not recorded');

$assertRejected = static function (callable $call, string $message): void {
    try { $call(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
};
$assertRejected(fn () => $repository->logRestEvent(10, 1, [...$base, 'client_action_id' => 'rest.finish:bad-0001', 'timer_id' => 'rest:timer-bad-0001', 'outcome' => 'ended_early']), 'Mismatched outcome was accepted');
$assertRejected(fn () => $repository->logRestEvent(10, 2, [...$base, 'client_action_id' => 'rest.finish:bad-0002']), 'Cross-tenant rest event was accepted');

fwrite(STDOUT, "Rest event persistence checks passed.\n");
