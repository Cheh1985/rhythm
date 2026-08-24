<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/VersionConflictException.php';
require dirname(__DIR__) . '/app/Repository/TrainingRepository.php';

use App\Repository\TrainingRepository;

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) throw new RuntimeException("FAILED: {$message}");
    $passed++;
};
$throws = static function (callable $callback, string $part, string $message) use ($check): void {
    try { $callback(); } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), $part), $message);
        return;
    }
    throw new RuntimeException("FAILED: {$message}");
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-08-24 12:00:00', 0);
$pdo->exec(<<<'SQL'
CREATE TABLE workout_plans (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, external_plan_id TEXT NOT NULL,
    scheduled_date TEXT NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL,
    updated_at TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE workout_sessions (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, workout_plan_id INTEGER NOT NULL,
    status TEXT NOT NULL, deleted_at TEXT NULL
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL,
    ip_address TEXT NULL, created_at TEXT NOT NULL
);
SQL);
$pdo->exec("INSERT INTO workout_plans VALUES
    (1,1,'plan-one','2026-08-24','planned',1,'2026-08-24',NULL),
    (2,2,'plan-other','2026-08-24','planned',1,'2026-08-24',NULL),
    (3,1,'plan-started','2026-08-24','planned',1,'2026-08-24',NULL),
    (4,1,'plan-completed','2026-08-24','completed',1,'2026-08-24',NULL);
    INSERT INTO workout_sessions VALUES (1,1,3,'in_progress',NULL)");

$repository = new TrainingRepository($pdo);
$repository->reschedulePlan(1, 1, '2026-08-27', 1);
$plan = $pdo->query('SELECT * FROM workout_plans WHERE id=1')->fetch();
$check($plan['scheduled_date'] === '2026-08-27' && (int) $plan['version'] === 2, 'перенос меняет только дату и version');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='reschedule'")->fetchColumn() === 1, 'перенос записывается в audit');
$throws(fn () => $repository->reschedulePlan(1, 1, '2026-08-28', 1), 'другой вкладке', 'устаревшая version отклоняется');
$throws(fn () => $repository->reschedulePlan(2, 1, '2026-08-28', 1), 'только свой', 'чужой план нельзя перенести');
$throws(fn () => $repository->reschedulePlan(1, 1, '2026-02-31', 2), 'корректную дату', 'несуществующая дата отклоняется');
$throws(fn () => $repository->softDeletePlan(1, 1, 2, false), 'Подтвердите', 'удаление требует подтверждения');
$throws(fn () => $repository->softDeletePlan(3, 1, 1, true), 'начатая', 'план с активной сессией удалить нельзя');
$throws(fn () => $repository->softDeletePlan(4, 1, 1, true), 'не начатый', 'завершённый план удалить нельзя');
$repository->softDeletePlan(1, 1, 2, true);
$deleted = $pdo->query('SELECT * FROM workout_plans WHERE id=1')->fetch();
$check($deleted['status'] === 'cancelled' && $deleted['deleted_at'] !== null && (int) $deleted['version'] === 3, 'план удаляется мягко');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='soft_delete'")->fetchColumn() === 1, 'soft delete записывается в audit');

$root = dirname(__DIR__);
$routes = file_get_contents($root . '/public/index.php');
$view = file_get_contents($root . '/views/plans/show.php');
$check(str_contains($routes, "'/plans/{id}/reschedule'") && str_contains($routes, "'/plans/{id}/delete'"), 'маршруты управления планом подключены');
$check(str_contains($view, 'Перенести тренировку') && str_contains($view, 'Подтверждаю мягкое удаление плана'), 'мобильный экран содержит оба действия');

fwrite(STDOUT, "Stage 9 checks passed ({$passed}).\n");
