<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Analytics;
use App\Core\VersionConflictException;
use App\Domain\Swimming;
use App\Domain\TrainingMetrics;
use App\Service\ProgressionService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TrainingRepository
{
    public function __construct(private readonly ?PDO $connection = null) {}

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->connection === null) {
            return \db()->transaction($callback);
        }
        $this->connection->beginTransaction();
        try {
            $result = $callback($this->connection);
            $this->connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function lock(): string
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function dashboard(int $userId): array
    {
        $pdo = $this->pdo();
        $next = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM workout_exercises we WHERE we.workout_plan_id=p.id) exercise_count FROM workout_plans p WHERE p.user_id=? AND p.status='planned' AND p.deleted_at IS NULL ORDER BY p.scheduled_date, p.id LIMIT 1");
        $next->execute([$userId]);
        $last = $pdo->prepare("SELECT s.*, p.name, p.external_plan_id FROM workout_sessions s JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id WHERE s.user_id=? AND s.status='completed' AND s.deleted_at IS NULL ORDER BY s.finished_at DESC LIMIT 1");
        $last->execute([$userId]);
        $timezoneQuery = $pdo->prepare('SELECT timezone FROM users WHERE id=? AND deleted_at IS NULL');
        $timezoneQuery->execute([$userId]);
        $timezone = (string) ($timezoneQuery->fetchColumn() ?: 'Europe/Moscow');
        $window = Analytics::weekWindow($timezone, 1);
        $weekQuery = $pdo->prepare("SELECT started_at,finished_at FROM workout_sessions WHERE user_id=? AND status='completed' AND started_at>=? AND started_at<? AND deleted_at IS NULL");
        $weekQuery->execute([$userId, $window['start_utc'], $window['end_utc']]);
        $weekSessions = $weekQuery->fetchAll();
        $minutes = 0;
        foreach ($weekSessions as $row) {
            $minutes += max(0, (int) round((strtotime((string) $row['finished_at'] . ' UTC') - strtotime((string) $row['started_at'] . ' UTC')) / 60));
        }
        $stats = ['sessions_week' => count($weekSessions), 'minutes_week' => $minutes];
        $unfinished = $pdo->prepare("SELECT s.id, p.name, s.started_at FROM workout_sessions s JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id WHERE s.user_id=? AND s.status='in_progress' AND s.deleted_at IS NULL ORDER BY s.started_at DESC LIMIT 1");
        $unfinished->execute([$userId]);
        return ['next' => $next->fetch() ?: null, 'last' => $last->fetch() ?: null, 'stats' => $stats, 'unfinished' => $unfinished->fetch() ?: null];
    }

    public function plan(int $planId, int $userId): ?array
    {
        $statement = $this->pdo()->prepare('SELECT p.*, tp.name program_name, pv.version_number program_version FROM workout_plans p LEFT JOIN program_versions pv ON pv.id=p.program_version_id LEFT JOIN training_programs tp ON tp.id=pv.program_id WHERE p.id=? AND p.user_id=? AND p.deleted_at IS NULL');
        $statement->execute([$planId, $userId]);
        $plan = $statement->fetch();
        if (!$plan) {
            return null;
        }
        $items = $this->pdo()->prepare('SELECT we.*, e.name exercise_name, e.progression_increment, e.progression_mode FROM workout_exercises we JOIN exercises e ON e.exercise_id=we.exercise_id WHERE we.workout_plan_id=? AND (e.owner_user_id IS NULL OR e.owner_user_id=?) ORDER BY we.sequence_no');
        $items->execute([$planId, $userId]);
        $plan['exercises'] = $items->fetchAll();
        $active = $this->pdo()->prepare("SELECT id FROM workout_sessions WHERE workout_plan_id=? AND user_id=? AND status='in_progress' AND deleted_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $active->execute([$planId, $userId]);
        $plan['active_session_id'] = $active->fetchColumn() ?: null;
        return $plan;
    }

    public function reschedulePlan(int $planId, int $userId, string $scheduledDate, int $version): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $scheduledDate);
        if (!$date || $date->format('Y-m-d') !== $scheduledDate) {
            throw new InvalidArgumentException('Укажите корректную дату тренировки.');
        }

        $this->transaction(function (PDO $pdo) use ($planId, $userId, $scheduledDate, $version): void {
            $query = $pdo->prepare("SELECT * FROM workout_plans WHERE id=? AND user_id=? AND status='planned' AND deleted_at IS NULL" . $this->lock());
            $query->execute([$planId, $userId]);
            $before = $query->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Перенести можно только свой ещё не начатый план.');
            }
            if ($version !== (int) $before['version']) {
                throw new VersionConflictException('План уже изменён в другой вкладке. Обновите страницу.');
            }
            if ((string) $before['scheduled_date'] === $scheduledDate) {
                return;
            }

            $update = $pdo->prepare('UPDATE workout_plans SET scheduled_date=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?');
            $update->execute([$scheduledDate, $planId, $userId]);
            $this->audit($pdo, $userId, 'workout_plan', (string) $planId, 'reschedule', [
                'scheduled_date' => $before['scheduled_date'],
                'version' => (int) $before['version'],
            ], [
                'scheduled_date' => $scheduledDate,
                'version' => (int) $before['version'] + 1,
            ]);
        });
    }

    public function softDeletePlan(int $planId, int $userId, int $version, bool $confirmed): void
    {
        if (!$confirmed) {
            throw new InvalidArgumentException('Подтвердите мягкое удаление плана.');
        }

        $this->transaction(function (PDO $pdo) use ($planId, $userId, $version): void {
            $query = $pdo->prepare("SELECT * FROM workout_plans WHERE id=? AND user_id=? AND status='planned' AND deleted_at IS NULL" . $this->lock());
            $query->execute([$planId, $userId]);
            $before = $query->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Удалить можно только свой ещё не начатый план.');
            }
            if ($version !== (int) $before['version']) {
                throw new VersionConflictException('План уже изменён в другой вкладке. Обновите страницу.');
            }

            $sessions = $pdo->prepare("SELECT COUNT(*) FROM workout_sessions WHERE workout_plan_id=? AND user_id=? AND status IN ('in_progress','completed') AND deleted_at IS NULL");
            $sessions->execute([$planId, $userId]);
            if ((int) $sessions->fetchColumn() > 0) {
                throw new InvalidArgumentException('У плана уже есть начатая или завершённая тренировка; удалить его нельзя.');
            }

            $update = $pdo->prepare("UPDATE workout_plans SET status='cancelled',deleted_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?");
            $update->execute([$planId, $userId]);
            $this->audit($pdo, $userId, 'workout_plan', (string) $planId, 'soft_delete', [
                'status' => $before['status'],
                'scheduled_date' => $before['scheduled_date'],
                'version' => (int) $before['version'],
            ], [
                'status' => 'cancelled',
                'deleted_at' => gmdate('Y-m-d H:i:s'),
                'version' => (int) $before['version'] + 1,
            ]);
        });
    }

    public function startSession(int $planId, int $userId, array $readiness): int
    {
        return $this->transaction(function (PDO $pdo) use ($planId, $userId, $readiness): int {
            $receipt = $this->beginAction($pdo, $userId, $readiness, 'session.start');
            if ($receipt !== null) {
                return (int) $receipt['session_id'];
            }
            $planQuery = $pdo->prepare('SELECT id, workout_type, status FROM workout_plans WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $planQuery->execute([$planId, $userId]);
            $plan = $planQuery->fetch();
            if (!$plan) {
                throw new InvalidArgumentException('План не найден.');
            }
            $existing = $pdo->prepare("SELECT id FROM workout_sessions WHERE workout_plan_id=? AND user_id=? AND status='in_progress' AND deleted_at IS NULL LIMIT 1");
            $existing->execute([$planId, $userId]);
            if ($id = $existing->fetchColumn()) {
                $this->completeAction($pdo, $userId, $readiness, 'session.start', ['session_id' => (int) $id]);
                return (int) $id;
            }
            if ($plan['status'] !== 'planned') {
                throw new InvalidArgumentException('Этот план уже завершён или недоступен для старта.');
            }
            $sleep = $this->requiredScore($readiness['sleep'] ?? null, 'сон');
            $energy = $this->requiredScore($readiness['energy'] ?? null, 'энергию');
            $readyScore = $this->requiredScore($readiness['readiness'] ?? null, 'готовность');
            $bodyWeight = ($readiness['body_weight_kg'] ?? null) === '' ? null : ($readiness['body_weight_kg'] ?? null);
            if ($bodyWeight !== null && ((!is_int($bodyWeight) && !is_float($bodyWeight)) || $bodyWeight < 20 || $bodyWeight > 500)) {
                throw new InvalidArgumentException('Масса тела должна быть числом от 20 до 500 кг.');
            }
            $comment = $this->text($readiness['comment'] ?? null, 2000, 'Комментарий');
            $publicId = 'session-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $insert = $pdo->prepare("INSERT INTO workout_sessions (public_id,user_id,workout_plan_id,workout_type,status,started_at,created_at,updated_at) VALUES (?,?,?,?,'in_progress',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $insert->execute([$publicId, $userId, $planId, $plan['workout_type']]);
            $sessionId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO session_exercises (workout_session_id,workout_exercise_id,original_exercise_id,actual_exercise_id,status,version,created_at,updated_at) SELECT ?,id,exercise_id,exercise_id,'pending',1,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM workout_exercises WHERE workout_plan_id=? ORDER BY sequence_no")->execute([$sessionId, $planId]);
            $ready = $pdo->prepare('INSERT INTO readiness_logs (user_id,workout_session_id,body_weight_kg,sleep_score,energy_score,readiness_score,comment,logged_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $ready->execute([$userId, $sessionId, $bodyWeight, $sleep, $energy, $readyScore, $comment]);
            $pdo->prepare("UPDATE workout_plans SET status='in_progress',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?")->execute([$planId, $userId]);
            $this->audit($pdo, $userId, 'workout_session', (string) $sessionId, 'start', null, ['plan_id' => $planId, 'readiness' => ['sleep' => $sleep, 'energy' => $energy, 'readiness' => $readyScore]]);
            $this->completeAction($pdo, $userId, $readiness, 'session.start', ['session_id' => $sessionId]);
            return $sessionId;
        });
    }

    public function session(int $sessionId, int $userId): ?array
    {
        $pdo = $this->pdo();
        $query = $pdo->prepare('SELECT s.*, p.name, p.external_plan_id, p.scheduled_date, p.goal, p.trainer_notes, p.workout_template_id, p.program_version_id, r.body_weight_kg, r.sleep_score, r.energy_score, r.readiness_score, r.comment readiness_comment FROM workout_sessions s JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id LEFT JOIN readiness_logs r ON r.workout_session_id=s.id AND r.user_id=s.user_id WHERE s.id=? AND s.user_id=? AND s.deleted_at IS NULL');
        $query->execute([$sessionId, $userId]);
        $session = $query->fetch();
        if (!$session) {
            return null;
        }
        $exerciseQuery = $pdo->prepare('SELECT se.*, we.sequence_no,we.planned_sets,we.rep_min,we.rep_max,we.target_rir_min,we.target_rir_max,we.rest_seconds,we.planned_weight_kg,we.warmup_sets,we.instructions,we.method_type,e.name exercise_name,original.name original_exercise_name,e.progression_increment,e.progression_mode FROM session_exercises se JOIN workout_exercises we ON we.id=se.workout_exercise_id JOIN exercises e ON e.exercise_id=se.actual_exercise_id JOIN exercises original ON original.exercise_id=se.original_exercise_id WHERE se.workout_session_id=? ORDER BY we.sequence_no');
        $exerciseQuery->execute([$sessionId]);
        $session['exercises'] = $exerciseQuery->fetchAll();
        $sets = $pdo->prepare('SELECT id,public_id,session_exercise_id,set_number,set_type,method_type,performed_weight_kg weight_kg,reps,rir,completed_at,version,edited_at FROM exercise_sets WHERE workout_session_id=? AND user_id=? AND deleted_at IS NULL ORDER BY session_exercise_id,completed_at,set_number,sequence_no');
        $sets->execute([$sessionId, $userId]);
        $byExercise = [];
        foreach ($sets->fetchAll() as $set) {
            $byExercise[$set['session_exercise_id']][] = $set;
        }
        $historyQuery = $pdo->prepare("SELECT es.performed_weight_kg weight_kg,es.reps,es.rir,se.actual_exercise_id FROM exercise_sets es JOIN session_exercises se ON se.id=es.session_exercise_id JOIN workout_sessions ws ON ws.id=es.workout_session_id WHERE ws.user_id=? AND ws.id<>? AND ws.status='completed' AND es.set_type='working' AND es.deleted_at IS NULL ORDER BY ws.finished_at DESC,es.set_number LIMIT 100");
        $historyQuery->execute([$userId, $sessionId]);
        $history = [];
        foreach ($historyQuery->fetchAll() as $set) {
            $history[$set['actual_exercise_id']] ??= [];
            if (count($history[$set['actual_exercise_id']]) < 5) {
                $history[$set['actual_exercise_id']][] = $set;
            }
        }
        foreach ($session['exercises'] as &$exercise) {
            $exercise['sets'] = $byExercise[$exercise['id']] ?? [];
            $exercise['previous_sets'] = $history[$exercise['actual_exercise_id']] ?? [];
        }
        unset($exercise);
        $available = $pdo->prepare("SELECT exercise_id,name FROM exercises WHERE status='active' AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id=?) ORDER BY name");
        $available->execute([$userId]);
        $session['available_exercises'] = $available->fetchAll();
        $session['summary'] = $this->summarize($session);
        return $session;
    }

    public function addSet(int $sessionId, int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'set.create');
            if ($receipt !== null) {
                return $receipt;
            }
            $session = $pdo->prepare("SELECT id,status,version FROM workout_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL" . $this->lock());
            $session->execute([$sessionId, $userId]);
            $sessionRow = $session->fetch();
            if (!$sessionRow || $sessionRow['status'] !== 'in_progress') {
                throw new InvalidArgumentException('Активная тренировка не найдена.');
            }
            $clientId = $data['client_action_id'] ?? null;
            if ($clientId) {
                $duplicate = $pdo->prepare('SELECT id,public_id,version,performed_weight_kg weight_kg,reps,rir,set_type,set_number FROM exercise_sets WHERE user_id=? AND workout_session_id=? AND client_action_id=? AND deleted_at IS NULL');
                $duplicate->execute([$userId, $sessionId, $clientId]);
                if ($row = $duplicate->fetch()) {
                    $row['session_version'] = (int) $sessionRow['version'];
                    $this->completeAction($pdo, $userId, $data, 'set.create', $row);
                    return $row;
                }
            }
            if (!is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $sessionRow['version']) {
                throw new VersionConflictException('Данные изменены в другой вкладке. Обновите тренировку.');
            }
            $exerciseId = $data['session_exercise_id'] ?? null;
            if (!is_int($exerciseId) || $exerciseId < 1) {
                throw new InvalidArgumentException('Некорректный идентификатор упражнения.');
            }
            $exercise = $pdo->prepare('SELECT se.id,se.status,se.version,we.method_type FROM session_exercises se JOIN workout_exercises we ON we.id=se.workout_exercise_id WHERE se.id=? AND se.workout_session_id=?');
            $exercise->execute([$exerciseId, $sessionId]);
            $exerciseRow = $exercise->fetch();
            if (!$exerciseRow) {
                throw new InvalidArgumentException('Упражнение не принадлежит этой тренировке.');
            }
            $weight = $data['weight_kg'] ?? null;
            $reps = $data['reps'] ?? null;
            $rir = $data['rir'] ?? null;
            if ((!is_int($weight) && !is_float($weight)) || $weight < 0 || $weight > 2000 || !is_int($reps) || $reps < 1 || $reps > 1000 || (!is_int($rir) && !is_float($rir)) || $rir < 0 || $rir > 10) {
                throw new InvalidArgumentException('Проверьте вес, повторы и RIR.');
            }
            $setType = (string) ($data['set_type'] ?? '');
            $setNumber = $data['set_number'] ?? null;
            if (!in_array($setType, ['warmup', 'working'], true) || !is_int($setNumber) || $setNumber < 1 || $setNumber > 100) {
                throw new InvalidArgumentException('Проверьте тип и номер подхода.');
            }
            $publicId = 'set-' . bin2hex(random_bytes(8));
            $insert = $pdo->prepare("INSERT INTO exercise_sets (public_id,user_id,workout_session_id,session_exercise_id,set_number,set_type,method_type,sequence_no,performed_weight_kg,reps,rir,completed_at,client_action_id,version) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,1)");
            $insert->execute([$publicId, $userId, $sessionId, $exerciseId, $setNumber, $setType, $exerciseRow['method_type'], 1, $weight, $reps, $rir, $clientId]);
            $setId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE session_exercises SET status='active',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','waiting')")->execute([$exerciseId]);
            $pdo->prepare('UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$sessionId, $userId]);
            $this->audit($pdo, $userId, 'exercise_set', (string) $setId, 'create', null, ['weight_kg' => $weight, 'reps' => $reps, 'rir' => $rir]);
            $exerciseVersion = (int) $exerciseRow['version'] + (in_array($exerciseRow['status'], ['pending', 'waiting'], true) ? 1 : 0);
            $result = ['id' => $setId, 'public_id' => $publicId, 'version' => 1, 'weight_kg' => $weight, 'reps' => $reps, 'rir' => $rir, 'set_type' => $setType, 'set_number' => $setNumber, 'session_version' => (int) $sessionRow['version'] + 1, 'exercise_version' => $exerciseVersion];
            $this->completeAction($pdo, $userId, $data, 'set.create', $result);
            return $result;
        });
    }

    public function updateSet(int $setId, int $userId, array $data): array
    {
        $completedSessionId = null;
        $result = $this->transaction(function (PDO $pdo) use ($setId, $userId, $data, &$completedSessionId): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'set.update');
            if ($receipt !== null) {
                return $receipt;
            }
            $query = $pdo->prepare('SELECT * FROM exercise_sets WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $query->execute([$setId, $userId]);
            $before = $query->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Подход не найден.');
            }
            if (!is_int($data['version'] ?? null) || $data['version'] !== (int) $before['version']) {
                throw new VersionConflictException('Подход уже изменён в другой вкладке.');
            }
            $sessionQuery = $pdo->prepare('SELECT version,status FROM workout_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $sessionQuery->execute([$before['workout_session_id'], $userId]);
            $session = $sessionQuery->fetch();
            if (!$session || !is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $session['version']) {
                throw new VersionConflictException('Тренировка изменена в другой вкладке. Обновите страницу.');
            }
            if ((isset($data['weight_kg']) && !is_int($data['weight_kg']) && !is_float($data['weight_kg'])) || (isset($data['reps']) && !is_int($data['reps'])) || (isset($data['rir']) && !is_int($data['rir']) && !is_float($data['rir']))) {
                throw new InvalidArgumentException('Вес, повторы и RIR должны быть числами.');
            }
            $weight = isset($data['weight_kg']) ? (float) $data['weight_kg'] : (float) $before['performed_weight_kg'];
            $reps = isset($data['reps']) ? $data['reps'] : (int) $before['reps'];
            $rir = isset($data['rir']) ? (float) $data['rir'] : (float) $before['rir'];
            if ($weight < 0 || $weight > 2000 || $reps < 1 || $reps > 1000 || $rir < 0 || $rir > 10) {
                throw new InvalidArgumentException('Проверьте данные подхода.');
            }
            $pdo->prepare('UPDATE exercise_sets SET performed_weight_kg=?,reps=?,rir=?,version=version+1,edited_at=UTC_TIMESTAMP() WHERE id=?')->execute([$weight, $reps, $rir, $setId]);
            $pdo->prepare("UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP(),edited_after_completion=CASE WHEN status='completed' THEN 1 ELSE edited_after_completion END,edited_at=CASE WHEN status='completed' THEN UTC_TIMESTAMP() ELSE edited_at END WHERE id=? AND user_id=?")->execute([$before['workout_session_id'], $userId]);
            $after = ['weight_kg' => $weight, 'reps' => $reps, 'rir' => $rir];
            $this->audit($pdo, $userId, 'exercise_set', (string) $setId, $session['status'] === 'completed' ? 'update_after_completion' : 'update', $before, $after);
            if ($session['status'] === 'completed') {
                $completedSessionId = (int) $before['workout_session_id'];
            }
            $result = ['id' => $setId, 'version' => (int) $before['version'] + 1, 'weight_kg' => $weight, 'reps' => $reps, 'rir' => $rir, 'session_version' => (int) $session['version'] + 1];
            $this->completeAction($pdo, $userId, $data, 'set.update', $result);
            return $result;
        });
        if ($completedSessionId !== null) {
            $this->rebuildDerivedData($completedSessionId, $userId);
        }
        return $result;
    }

    public function setExerciseStatus(int $sessionId, int $userId, array $data): array
    {
        $sessionExerciseId = $data['session_exercise_id'] ?? null;
        if (!is_int($sessionExerciseId) || $sessionExerciseId < 1) {
            throw new InvalidArgumentException('Некорректный идентификатор упражнения.');
        }
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['pending', 'active', 'completed', 'skipped', 'waiting'], true)) {
            throw new InvalidArgumentException('Некорректный статус упражнения.');
        }
        return $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $sessionExerciseId, $status, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'exercise.status');
            if ($receipt !== null) {
                return $receipt;
            }
            $sessionQuery = $pdo->prepare('SELECT version,status FROM workout_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $sessionQuery->execute([$sessionId, $userId]);
            $session = $sessionQuery->fetch();
            if (!$session || $session['status'] !== 'in_progress') {
                throw new InvalidArgumentException('Активная тренировка не найдена.');
            }
            if (!is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $session['version']) {
                throw new VersionConflictException('Данные изменены в другой вкладке. Обновите тренировку.');
            }
            $check = $pdo->prepare('SELECT * FROM session_exercises WHERE id=? AND workout_session_id=?' . $this->lock());
            $check->execute([$sessionExerciseId, $sessionId]);
            $before = $check->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Упражнение не найдено.');
            }
            if (isset($data['exercise_version']) && (!is_int($data['exercise_version']) || $data['exercise_version'] !== (int) $before['version'])) {
                throw new VersionConflictException('Упражнение уже изменено в другой вкладке.');
            }
            $transitions = [
                'pending' => ['active', 'waiting', 'skipped', 'completed'],
                'active' => ['waiting', 'completed', 'skipped'],
                'waiting' => ['active', 'completed', 'skipped'],
                'completed' => ['completed'],
                'skipped' => ['skipped'],
            ];
            if (!in_array($status, $transitions[$before['status']] ?? [], true)) {
                throw new InvalidArgumentException('Недопустимый переход статуса упражнения.');
            }
            $reason = $status === 'waiting' ? 'equipment_busy' : ($data['reason'] ?? null);
            if ($status === 'skipped' && !in_array($reason, ['equipment_busy', 'time', 'fatigue', 'discomfort', 'other'], true)) {
                throw new InvalidArgumentException('Укажите причину пропуска.');
            }
            $rating = $data['exercise_rating'] ?? null;
            if ($rating !== null && !in_array($rating, ['too_easy', 'normal', 'too_hard'], true)) {
                throw new InvalidArgumentException('Некорректная оценка упражнения.');
            }
            if ($status === 'completed' && $rating === null) {
                throw new InvalidArgumentException('Оцените сложность упражнения.');
            }
            $comment = $this->text($data['comment'] ?? null, 2000, 'Комментарий');
            $completedAt = $status === 'completed' ? 'UTC_TIMESTAMP()' : 'completed_at';
            $update = $pdo->prepare("UPDATE session_exercises SET status=?,skip_reason=?,exercise_rating=?,comment=?,completed_at={$completedAt},version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?");
            $update->execute([$status, in_array($status, ['skipped', 'waiting'], true) ? $reason : null, $rating, $comment, $sessionExerciseId]);
            $pdo->prepare('UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$sessionId, $userId]);
            $this->audit($pdo, $userId, 'session_exercise', (string) $sessionExerciseId, 'status', $before, ['status' => $status, 'reason' => $reason, 'exercise_rating' => $rating]);
            $result = ['session_version' => (int) $session['version'] + 1, 'exercise_version' => (int) $before['version'] + 1, 'status' => $status];
            $this->completeAction($pdo, $userId, $data, 'exercise.status', $result);
            return $result;
        });
    }

    public function replaceExercise(int $sessionId, int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'exercise.replace');
            if ($receipt !== null) {
                return $receipt;
            }
            [$session, $exercise] = $this->lockedExercise($pdo, $sessionId, $userId, $data);
            $actualId = (string) ($data['actual_exercise_id'] ?? '');
            $reason = $this->text($data['reason'] ?? null, 1000, 'Причина замены');
            if ($actualId === '' || $reason === null) {
                throw new InvalidArgumentException('Выберите замену и укажите причину.');
            }
            $available = $pdo->prepare("SELECT 1 FROM exercises WHERE exercise_id=? AND status='active' AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id=?)");
            $available->execute([$actualId, $userId]);
            if (!$available->fetchColumn()) {
                throw new InvalidArgumentException('Упражнение для замены недоступно.');
            }
            $pdo->prepare("UPDATE session_exercises SET actual_exercise_id=?,substitution_reason=?,substituted_at=UTC_TIMESTAMP(),status=CASE WHEN status='pending' THEN 'active' ELSE status END,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$actualId, $reason, $exercise['id']]);
            $pdo->prepare('UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$sessionId, $userId]);
            $this->audit($pdo, $userId, 'session_exercise', (string) $exercise['id'], 'replace', $exercise, ['original_exercise_id' => $exercise['original_exercise_id'], 'actual_exercise_id' => $actualId, 'reason' => $reason]);
            $result = ['session_version' => (int) $session['version'] + 1, 'exercise_version' => (int) $exercise['version'] + 1, 'actual_exercise_id' => $actualId];
            $this->completeAction($pdo, $userId, $data, 'exercise.replace', $result);
            return $result;
        });
    }

    public function logDiscomfort(int $sessionId, int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'discomfort.create');
            if ($receipt !== null) {
                return $receipt;
            }
            [$session, $exercise] = $this->lockedExercise($pdo, $sessionId, $userId, $data);
            $area = $this->text($data['body_area'] ?? null, 120, 'Область дискомфорта');
            $intensity = $data['intensity'] ?? null;
            if ($area === null || !is_int($intensity) || $intensity < 1 || $intensity > 10) {
                throw new InvalidArgumentException('Укажите область и интенсивность дискомфорта от 1 до 10.');
            }
            $comment = $this->text($data['comment'] ?? null, 1000, 'Комментарий');
            $insert = $pdo->prepare('INSERT INTO discomfort_logs (user_id,workout_session_id,session_exercise_id,body_area,intensity,comment,logged_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
            $insert->execute([$userId, $sessionId, $exercise['id'], $area, $intensity, $comment]);
            $pdo->prepare('UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$sessionId, $userId]);
            $id = (int) $pdo->lastInsertId();
            $this->audit($pdo, $userId, 'discomfort_log', (string) $id, 'create', null, ['session_exercise_id' => $exercise['id'], 'body_area' => $area, 'intensity' => $intensity, 'comment' => $comment]);
            $result = ['id' => $id, 'session_version' => (int) $session['version'] + 1];
            $this->completeAction($pdo, $userId, $data, 'discomfort.create', $result);
            return $result;
        });
    }

    public function finish(int $sessionId, int $userId, array $data): array
    {
        $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $data): void {
            $receipt = $this->beginAction($pdo, $userId, $data, 'session.finish');
            if ($receipt !== null) {
                return;
            }
            $query = $pdo->prepare("SELECT * FROM workout_sessions WHERE id=? AND user_id=? AND status='in_progress' AND deleted_at IS NULL" . $this->lock());
            $query->execute([$sessionId, $userId]);
            $session = $query->fetch();
            if (!$session) {
                throw new InvalidArgumentException('Незавершённая тренировка не найдена.');
            }
            if (!is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $session['version']) {
                throw new VersionConflictException('Тренировка изменена в другой вкладке. Обновите страницу.');
            }
            $rpe = $data['session_rpe'] ?? null;
            $wellbeing = $data['wellbeing'] ?? null;
            if (!is_int($rpe) || $rpe < 1 || $rpe > 10 || !is_int($wellbeing) || $wellbeing < 1 || $wellbeing > 5) {
                throw new InvalidArgumentException('Проверьте общую тяжесть и самочувствие.');
            }
            $comment = $this->text($data['comment'] ?? null, 5000, 'Комментарий');
            $pdo->prepare("UPDATE workout_sessions SET status='completed',finished_at=UTC_TIMESTAMP(),session_rpe=?,wellbeing=?,user_comment=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$rpe, $wellbeing, $comment, $sessionId]);
            $pdo->prepare("UPDATE workout_plans SET status='completed',updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?")->execute([$session['workout_plan_id'], $userId]);
            $this->audit($pdo, $userId, 'workout_session', (string) $sessionId, 'finish', null, ['session_rpe' => $rpe, 'wellbeing' => $wellbeing]);
            $this->completeAction($pdo, $userId, $data, 'session.finish', ['session_id' => $sessionId, 'status' => 'completed']);
        });
        $session = $this->session($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Не удалось сформировать итог тренировки.');
        }
        $this->rebuildDerivedData($sessionId, $userId);
        return $this->session($sessionId, $userId) ?? $session;
    }

    public function updateCompletedSession(int $sessionId, int $userId, array $data): array
    {
        $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $data): void {
            $query = $pdo->prepare("SELECT * FROM workout_sessions WHERE id=? AND user_id=? AND status='completed' AND deleted_at IS NULL" . $this->lock());
            $query->execute([$sessionId, $userId]);
            $before = $query->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Завершённая тренировка не найдена.');
            }
            if (!is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $before['version']) {
                throw new VersionConflictException('Тренировка уже изменена в другой вкладке.');
            }
            $rpe = $data['session_rpe'] ?? null;
            $wellbeing = $data['wellbeing'] ?? null;
            if (!is_int($rpe) || $rpe < 1 || $rpe > 10 || !is_int($wellbeing) || $wellbeing < 1 || $wellbeing > 5) {
                throw new InvalidArgumentException('Проверьте общую тяжесть и самочувствие.');
            }
            $comment = $this->text($data['comment'] ?? null, 5000, 'Комментарий');
            $pdo->prepare('UPDATE workout_sessions SET session_rpe=?,wellbeing=?,user_comment=?,version=version+1,edited_after_completion=1,edited_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$rpe, $wellbeing, $comment, $sessionId, $userId]);
            $this->audit($pdo, $userId, 'workout_session', (string) $sessionId, 'update_after_completion', $before, ['session_rpe' => $rpe, 'wellbeing' => $wellbeing, 'user_comment' => $comment]);
        });
        $session = $this->session($sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Не удалось перечитать тренировку.');
        }
        return $session;
    }

    public function resolveProgression(int $suggestionId, int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($suggestionId, $userId, $data): array {
            $query = $pdo->prepare('SELECT * FROM progression_suggestions WHERE id=? AND user_id=?' . $this->lock());
            $query->execute([$suggestionId, $userId]);
            $before = $query->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Предложение прогрессии не найдено.');
            }
            $status = (string) ($data['status'] ?? '');
            if (!in_array($status, ['accepted', 'rejected'], true)) {
                throw new InvalidArgumentException('Выберите принятие или отклонение предложения.');
            }
            $accepted = null;
            if ($status === 'accepted') {
                $accepted = $data['accepted_weight_kg'] ?? (float) $before['suggested_next_weight_kg'];
                if ((!is_int($accepted) && !is_float($accepted)) || $accepted <= 0 || $accepted > 2000) {
                    throw new InvalidArgumentException('Принятый вес должен быть числом от 0 до 2000 кг.');
                }
                $accepted = round((float) $accepted, 2);
            }
            $pdo->prepare('UPDATE progression_suggestions SET accepted_next_weight_kg=?,status=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$accepted, $status, $suggestionId, $userId]);
            $after = ['accepted_next_weight_kg' => $accepted, 'status' => $status];
            $this->audit($pdo, $userId, 'progression_suggestion', (string) $suggestionId, 'resolve', $before, $after);
            return ['id' => $suggestionId, ...$after, 'program_changed' => false];
        });
    }

    public function rebuildDerivedData(int $sessionId, int $userId): void
    {
        $session = $this->session($sessionId, $userId);
        if (!$session || $session['status'] !== 'completed') {
            throw new InvalidArgumentException('Завершённая тренировка не найдена.');
        }
        $this->transaction(function (PDO $pdo) use ($session, $sessionId, $userId): void {
            $pdo->prepare("DELETE FROM progression_suggestions WHERE workout_session_id=? AND user_id=? AND status='pending'")->execute([$sessionId, $userId]);
            $pdo->prepare('DELETE FROM personal_records WHERE workout_session_id=? AND user_id=?')->execute([$sessionId, $userId]);
            $progression = new ProgressionService();
            foreach ($session['exercises'] as $exercise) {
                $suggestion = $progression->suggest($exercise, $exercise['sets']);
                if ($suggestion) {
                    $existing = $pdo->prepare('SELECT id,status FROM progression_suggestions WHERE user_id=? AND workout_session_id=? AND exercise_id=?');
                    $existing->execute([$userId, $sessionId, $exercise['actual_exercise_id']]);
                    $row = $existing->fetch();
                    if (!$row) {
                        $insert = $pdo->prepare("INSERT INTO progression_suggestions (user_id,workout_session_id,exercise_id,current_weight_kg,suggested_next_weight_kg,accepted_next_weight_kg,reason,status,created_at,resolved_at) VALUES (?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP(),NULL)");
                        $insert->execute([$userId, $sessionId, $exercise['actual_exercise_id'], $suggestion['current_weight_kg'], $suggestion['suggested_weight_kg'], null, $suggestion['reason']]);
                    }
                }

                $working = array_values(array_filter($exercise['sets'], static fn (array $set): bool => ($set['set_type'] ?? '') === 'working' && empty($set['deleted_at'])));
                if ($working === []) {
                    continue;
                }

                $priorBest = $pdo->prepare("SELECT MAX(es.performed_weight_kg) max_weight,MAX(es.performed_weight_kg*(1+es.reps/30.0)) best_e1rm FROM exercise_sets es JOIN session_exercises se ON se.id=es.session_exercise_id JOIN workout_sessions ws ON ws.id=es.workout_session_id WHERE es.user_id=? AND ws.user_id=? AND se.actual_exercise_id=? AND ws.status='completed' AND ws.id<>? AND ws.deleted_at IS NULL AND es.set_type='working' AND es.deleted_at IS NULL");
                $priorBest->execute([$userId, $userId, $exercise['actual_exercise_id'], $sessionId]);
                $previous = $priorBest->fetch() ?: ['max_weight' => null, 'best_e1rm' => null];
                $maxWeight = max(array_map(static fn (array $set): float => (float) $set['weight_kg'], $working));
                if ($previous['max_weight'] === null || $maxWeight > (float) $previous['max_weight']) {
                    $this->insertRecord($pdo, $userId, $sessionId, $exercise['actual_exercise_id'], 'max_weight', $maxWeight, ['previous_kg' => $previous['max_weight'] !== null ? (float) $previous['max_weight'] : null]);
                }

                $best = TrainingMetrics::bestEpley($working);
                if ($best !== null && ($previous['best_e1rm'] === null || $best['e1rm_kg'] > round((float) $previous['best_e1rm'], 2))) {
                    $this->insertRecord($pdo, $userId, $sessionId, $exercise['actual_exercise_id'], 'best_e1rm', $best['e1rm_kg'], [
                        'formula' => 'Epley', 'weight_kg' => $best['weight_kg'], 'reps' => $best['reps'],
                        'previous_e1rm_kg' => $previous['best_e1rm'] !== null ? round((float) $previous['best_e1rm'], 2) : null,
                    ]);
                }

                $tonnage = TrainingMetrics::tonnage($working);
                $priorTonnage = $pdo->prepare("SELECT MAX(t.total) FROM (SELECT SUM(es.performed_weight_kg*es.reps) total FROM exercise_sets es JOIN session_exercises se ON se.id=es.session_exercise_id JOIN workout_sessions ws ON ws.id=es.workout_session_id WHERE es.user_id=? AND ws.user_id=? AND se.actual_exercise_id=? AND ws.status='completed' AND ws.id<>? AND ws.deleted_at IS NULL AND es.set_type='working' AND es.deleted_at IS NULL GROUP BY es.workout_session_id) t");
                $priorTonnage->execute([$userId, $userId, $exercise['actual_exercise_id'], $sessionId]);
                $previousTonnage = $priorTonnage->fetchColumn();
                if ($previousTonnage === false || $previousTonnage === null || $tonnage > (float) $previousTonnage) {
                    $this->insertRecord($pdo, $userId, $sessionId, $exercise['actual_exercise_id'], 'exercise_tonnage', $tonnage, ['previous_kg' => $previousTonnage !== false && $previousTonnage !== null ? (float) $previousTonnage : null]);
                }

                $repsByWeight = [];
                foreach ($working as $set) {
                    $key = number_format((float) $set['weight_kg'], 2, '.', '');
                    $repsByWeight[$key] = max($repsByWeight[$key] ?? 0, (int) $set['reps']);
                }
                $weights = array_map('floatval', array_keys($repsByWeight));
                $placeholders = implode(',', array_fill(0, count($weights), '?'));
                $priorReps = $pdo->prepare("SELECT es.performed_weight_kg weight_kg,MAX(es.reps) max_reps FROM exercise_sets es JOIN session_exercises se ON se.id=es.session_exercise_id JOIN workout_sessions ws ON ws.id=es.workout_session_id WHERE es.user_id=? AND ws.user_id=? AND se.actual_exercise_id=? AND ws.status='completed' AND ws.id<>? AND ws.deleted_at IS NULL AND es.set_type='working' AND es.deleted_at IS NULL AND es.performed_weight_kg IN ({$placeholders}) GROUP BY es.performed_weight_kg");
                $priorReps->execute([$userId, $userId, $exercise['actual_exercise_id'], $sessionId, ...$weights]);
                $improvements = [];
                foreach ($priorReps->fetchAll() as $row) {
                    $key = number_format((float) $row['weight_kg'], 2, '.', '');
                    if (($repsByWeight[$key] ?? 0) > (int) $row['max_reps']) {
                        $improvements[] = ['weight_kg' => (float) $row['weight_kg'], 'reps' => $repsByWeight[$key], 'previous_reps' => (int) $row['max_reps']];
                    }
                }
                if ($improvements !== []) {
                    $this->insertRecord($pdo, $userId, $sessionId, $exercise['actual_exercise_id'], 'max_reps_at_weight', max(array_column($improvements, 'reps')), ['improvements' => $improvements]);
                }

                $plannedSets = (int) ($exercise['planned_sets'] ?? 0);
                $repMax = (int) ($exercise['rep_max'] ?? 0);
                if ($plannedSets > 0 && count($working) >= $plannedSets && $repMax > 0 && count(array_filter($working, static fn (array $set): bool => (int) $set['reps'] < $repMax)) === 0) {
                    $this->insertRecord($pdo, $userId, $sessionId, $exercise['actual_exercise_id'], 'rep_range_completed', $repMax, ['planned_sets' => $plannedSets, 'completed_working_sets' => count($working), 'upper_reps' => $repMax]);
                }
            }

            $sessionTonnage = (float) $session['summary']['tonnage_kg'];
            if ($sessionTonnage > 0) {
                $priorSession = $pdo->prepare("SELECT MAX(t.total) FROM (SELECT SUM(es.performed_weight_kg*es.reps) total FROM exercise_sets es JOIN workout_sessions ws ON ws.id=es.workout_session_id WHERE es.user_id=? AND ws.user_id=? AND ws.status='completed' AND ws.id<>? AND ws.deleted_at IS NULL AND es.set_type='working' AND es.deleted_at IS NULL GROUP BY es.workout_session_id) t");
                $priorSession->execute([$userId, $userId, $sessionId]);
                $previousSessionTonnage = $priorSession->fetchColumn();
                if ($previousSessionTonnage === false || $previousSessionTonnage === null || $sessionTonnage > (float) $previousSessionTonnage) {
                    $this->insertRecord($pdo, $userId, $sessionId, null, 'session_tonnage', $sessionTonnage, ['previous_kg' => $previousSessionTonnage !== false && $previousSessionTonnage !== null ? (float) $previousSessionTonnage : null]);
                }
            }
        });
    }

    public function history(int $userId, int $page = 1, array $filters = [], string $timezone = 'Europe/Moscow'): array
    {
        $pdo = $this->pdo();
        $limit = 20;
        $status = in_array(($filters['status'] ?? 'completed'), ['completed', 'in_progress', 'cancelled', 'all'], true) ? (string) ($filters['status'] ?? 'completed') : 'completed';
        $type = in_array(($filters['type'] ?? ''), ['strength', 'swimming', 'cardio', 'mobility', 'other'], true) ? (string) $filters['type'] : '';
        $exerciseId = preg_match('/^[a-zA-Z0-9._-]{1,80}$/', (string) ($filters['exercise_id'] ?? '')) ? (string) $filters['exercise_id'] : '';
        $search = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 80);
        $dates = Analytics::localDateBounds($filters['from'] ?? null, $filters['to'] ?? null, $timezone);
        $strengthWhere = ['s.user_id=?', 's.deleted_at IS NULL'];
        $strengthParams = [$userId];
        if ($status !== 'all') { $strengthWhere[] = 's.status=?'; $strengthParams[] = $status; }
        if ($type !== '') { $strengthWhere[] = 's.workout_type=?'; $strengthParams[] = $type; }
        if ($dates['from_utc']) { $strengthWhere[] = 's.started_at>=?'; $strengthParams[] = $dates['from_utc']; }
        if ($dates['to_utc']) { $strengthWhere[] = 's.started_at<?'; $strengthParams[] = $dates['to_utc']; }
        if ($exerciseId !== '') {
            $strengthWhere[] = 'EXISTS (SELECT 1 FROM session_exercises filter_se WHERE filter_se.workout_session_id=s.id AND filter_se.actual_exercise_id=?)';
            $strengthParams[] = $exerciseId;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        if ($search !== '') { $strengthWhere[] = 'p.name LIKE ?'; $strengthParams[] = $like; }

        $swimWhere = ['sw.user_id=?', 'sw.deleted_at IS NULL'];
        $swimParams = [$userId];
        if ($status !== 'all' && $status !== 'completed') $swimWhere[] = '1=0';
        if ($type !== '' && $type !== 'swimming') $swimWhere[] = '1=0';
        if ($exerciseId !== '') $swimWhere[] = '1=0';
        if ($dates['from_utc']) { $swimWhere[] = 'sw.occurred_at>=?'; $swimParams[] = $dates['from_utc']; }
        if ($dates['to_utc']) { $swimWhere[] = 'sw.occurred_at<?'; $swimParams[] = $dates['to_utc']; }
        if ($search !== '') { $swimWhere[] = "('Плавание' LIKE ? OR sw.primary_style LIKE ?)"; array_push($swimParams, $like, $like); }

        $strengthSql = "SELECT 'strength' source_kind,s.id,s.public_id,s.status,s.started_at,s.finished_at,s.session_rpe,p.name,s.workout_type,COUNT(es.id) working_sets,COALESCE(SUM(es.performed_weight_kg*es.reps),0) tonnage,AVG(es.rir) average_rir,NULL distance_m,NULL duration_minutes FROM workout_sessions s JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id LEFT JOIN exercise_sets es ON es.workout_session_id=s.id AND es.user_id=s.user_id AND es.set_type='working' AND es.deleted_at IS NULL WHERE " . implode(' AND ', $strengthWhere) . ' GROUP BY s.id,s.public_id,s.status,s.started_at,s.finished_at,s.session_rpe,p.name,s.workout_type';
        $swimSql = "SELECT 'swimming' source_kind,sw.id,sw.public_id,'completed' status,sw.occurred_at started_at,NULL finished_at,sw.intensity session_rpe,sw.primary_style name,'swimming' workout_type,0 working_sets,0 tonnage,NULL average_rir,sw.total_distance_m distance_m,sw.duration_minutes FROM swimming_sessions sw WHERE " . implode(' AND ', $swimWhere);
        $hasSwimming = $this->tableExists($pdo, 'swimming_sessions');
        $union = $hasSwimming ? $strengthSql . ' UNION ALL ' . $swimSql : $strengthSql;
        $params = $hasSwimming ? [...$strengthParams, ...$swimParams] : $strengthParams;
        $count = $pdo->prepare("SELECT COUNT(*) FROM ({$union}) timeline");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $limit;
        $query = $pdo->prepare("SELECT * FROM ({$union}) timeline ORDER BY started_at DESC,id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        $items = $query->fetchAll();
        foreach ($items as &$item) {
            if ($item['source_kind'] === 'swimming') $item['name'] = 'Плавание · ' . $item['name'];
            $item['href'] = $item['source_kind'] === 'swimming' ? '/swimming/' . $item['id'] : '/sessions/' . $item['id'];
        }
        unset($item);

        $exerciseOptions = $pdo->prepare("SELECT DISTINCT e.exercise_id,e.name FROM session_exercises se JOIN workout_sessions s ON s.id=se.workout_session_id JOIN exercises e ON e.exercise_id=se.actual_exercise_id WHERE s.user_id=? AND s.deleted_at IS NULL AND (e.owner_user_id IS NULL OR e.owner_user_id=?) ORDER BY e.name");
        $exerciseOptions->execute([$userId, $userId]);
        return [
            'items' => $items, 'page' => $page, 'per_page' => $limit, 'total' => $total, 'pages' => $pages,
            'filters' => ['status' => $status, 'type' => $type, 'exercise_id' => $exerciseId, 'q' => $search, 'from' => $dates['from'], 'to' => $dates['to']],
            'exercise_options' => $exerciseOptions->fetchAll(),
        ];
    }

    public function exerciseAnalytics(string $exerciseId, int $userId, string $timezone): ?array
    {
        $pdo = $this->pdo();
        $exerciseQuery = $pdo->prepare('SELECT exercise_id,name,category,muscle_groups,equipment FROM exercises WHERE exercise_id=? AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id=?)');
        $exerciseQuery->execute([$exerciseId, $userId]);
        $exercise = $exerciseQuery->fetch();
        if (!$exercise) return null;

        $sessionsQuery = $pdo->prepare("SELECT se.id session_exercise_id,ws.id session_id,ws.started_at,ws.finished_at,p.name workout_name,we.planned_sets,we.rep_max FROM session_exercises se JOIN workout_sessions ws ON ws.id=se.workout_session_id JOIN workout_plans p ON p.id=ws.workout_plan_id AND p.user_id=ws.user_id JOIN workout_exercises we ON we.id=se.workout_exercise_id WHERE ws.user_id=? AND se.actual_exercise_id=? AND ws.status='completed' AND ws.deleted_at IS NULL ORDER BY ws.started_at DESC,ws.id DESC LIMIT 52");
        $sessionsQuery->execute([$userId, $exerciseId]);
        $sessions = $sessionsQuery->fetchAll();
        if ($sessions !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['session_exercise_id'], $sessions);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $setsQuery = $pdo->prepare("SELECT session_exercise_id,performed_weight_kg weight_kg,reps,rir,set_number,completed_at FROM exercise_sets WHERE user_id=? AND set_type='working' AND deleted_at IS NULL AND session_exercise_id IN ({$placeholders}) ORDER BY completed_at,set_number,sequence_no");
            $setsQuery->execute([$userId, ...$ids]);
            $setsByExercise = [];
            foreach ($setsQuery->fetchAll() as $set) $setsByExercise[(int) $set['session_exercise_id']][] = $set;
            foreach ($sessions as &$row) {
                $row['sets'] = $setsByExercise[(int) $row['session_exercise_id']] ?? [];
                $row['working_sets'] = count($row['sets']);
                $row['tonnage'] = TrainingMetrics::tonnage($row['sets']);
                $row['average_rir'] = TrainingMetrics::averageRir($row['sets']);
                $best = TrainingMetrics::bestEpley($row['sets']);
                $row['best_e1rm'] = $best['e1rm_kg'] ?? null;
                $row['best_set'] = $best;
                $row['reps_by_weight'] = [];
                foreach ($row['sets'] as $set) {
                    $key = rtrim(rtrim(number_format((float) $set['weight_kg'], 2, '.', ''), '0'), '.');
                    $row['reps_by_weight'][$key] = max($row['reps_by_weight'][$key] ?? 0, (int) $set['reps']);
                }
                $row['local_date'] = \local_datetime($row['started_at'], $timezone, 'd.m.Y');
            }
            unset($row);
        }
        $recordsQuery = $pdo->prepare('SELECT pr.record_type,pr.value_decimal,pr.metadata_json,pr.achieved_at,ws.id session_id FROM personal_records pr JOIN workout_sessions ws ON ws.id=pr.workout_session_id AND ws.user_id=pr.user_id WHERE pr.user_id=? AND pr.exercise_id=? ORDER BY pr.achieved_at DESC,pr.id DESC LIMIT 30');
        $recordsQuery->execute([$userId, $exerciseId]);
        $records = $recordsQuery->fetchAll();
        foreach ($records as &$record) $record['metadata'] = json_decode((string) ($record['metadata_json'] ?? ''), true) ?: [];
        unset($record);
        $chronological = array_reverse($sessions);
        $exercise['muscle_groups'] = json_decode((string) ($exercise['muscle_groups'] ?? ''), true) ?: [];
        return [
            'exercise' => $exercise,
            'sessions' => $sessions,
            'records' => $records,
            'signals' => Analytics::softSignals($sessions),
            'charts' => [
                'tonnage' => array_map(static fn (array $row): array => ['label' => $row['local_date'], 'value' => $row['tonnage']], $chronological),
                'e1rm' => array_values(array_filter(array_map(static fn (array $row): array => ['label' => $row['local_date'], 'value' => $row['best_e1rm']], $chronological), static fn (array $point): bool => $point['value'] !== null)),
            ],
        ];
    }

    public function programs(int $userId): array
    {
        $query = \db()->pdo()->prepare(<<<'SQL'
SELECT p.id,p.external_program_id,p.name,p.description,p.status,p.created_at,p.active_version_id,
       pv.version_number,pv.lifecycle_status,pv.created_at version_created,pv.change_reason,pv.trainer_comment,
       parent.version_number parent_version,active.version_number active_version_number,
       CASE
           WHEN p.active_version_id IS NOT NULL AND active.id IS NULL THEN 'invalid_pointer'
           WHEN p.active_version_id IS NOT NULL THEN 'resolved'
           WHEN (SELECT COUNT(*) FROM program_versions vc WHERE vc.program_id=p.id)=0 THEN 'no_versions'
           WHEN (SELECT COUNT(*) FROM program_versions vc WHERE vc.program_id=p.id)=1 THEN 'reconcilable'
           ELSE 'ambiguous'
       END reconciliation_state,
       CASE WHEN pv.id=p.active_version_id THEN 1 ELSE 0 END is_active_version,
       (SELECT COUNT(*) FROM workout_templates wt WHERE wt.program_version_id=pv.id AND wt.user_id=p.user_id AND wt.deleted_at IS NULL) template_count,
       (SELECT COUNT(*) FROM workout_plans wp WHERE wp.program_version_id=pv.id AND wp.user_id=p.user_id AND wp.deleted_at IS NULL) plan_count
FROM training_programs p
LEFT JOIN program_versions pv ON pv.program_id=p.id
LEFT JOIN program_versions parent ON parent.id=pv.parent_version_id AND parent.program_id=p.id
LEFT JOIN program_versions active ON active.id=p.active_version_id AND active.program_id=p.id
WHERE p.user_id=? AND p.deleted_at IS NULL
ORDER BY p.id,pv.version_number DESC
SQL);
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function exercises(int $userId): array
    {
        $query = \db()->pdo()->prepare("SELECT exercise_id,owner_user_id,name,category,equipment,progression_increment,progression_mode,status FROM exercises WHERE deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id=?) ORDER BY owner_user_id IS NULL DESC,status='active' DESC,name");
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function addExercise(int $userId, array $data): void
    {
        $id = trim((string) ($data['exercise_id'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $increment = filter_var($data['progression_increment'] ?? null, FILTER_VALIDATE_FLOAT);
        $mode = (string) ($data['progression_mode'] ?? 'absolute');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $id) || $name === '' || mb_strlen($name) > 190) {
            throw new InvalidArgumentException('Проверьте стабильный exercise_id и название.');
        }
        if ($increment === false || $increment <= 0 || $increment > 1000 || !in_array($mode, ['absolute', 'percent'], true)) {
            throw new InvalidArgumentException('Проверьте шаг и режим прогрессии.');
        }
        $query = \db()->pdo()->prepare("INSERT INTO exercises (exercise_id,owner_user_id,name,category,muscle_groups,exercise_type,equipment,progression_increment,progression_mode,status,created_at,updated_at) VALUES (?,?,?,?,JSON_ARRAY(),'strength',?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        try {
            $query->execute([$id, $userId, $name, mb_substr(trim((string) ($data['category'] ?? '')), 0, 80) ?: null, mb_substr(trim((string) ($data['equipment'] ?? '')), 0, 120) ?: null, $increment, $mode]);
        } catch (\PDOException $exception) {
            throw new InvalidArgumentException('exercise_id уже используется. Выберите другой стабильный идентификатор.', 0, $exception);
        }
    }

    public function updateExercise(int $userId, string $exerciseId, array $data): void
    {
        $increment = filter_var($data['progression_increment'] ?? null, FILTER_VALIDATE_FLOAT);
        $mode = (string) ($data['progression_mode'] ?? 'absolute');
        $status = (string) ($data['status'] ?? 'active');
        if ($increment === false || $increment <= 0 || $increment > 1000 || !in_array($mode, ['absolute', 'percent'], true) || !in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Проверьте шаг прогрессии и статус упражнения.');
        }
        $query = \db()->pdo()->prepare('UPDATE exercises SET progression_increment=?,progression_mode=?,status=?,updated_at=UTC_TIMESTAMP() WHERE exercise_id=? AND owner_user_id=? AND deleted_at IS NULL');
        $query->execute([$increment, $mode, $status, $exerciseId, $userId]);
        if ($query->rowCount() === 0) {
            $owned = \db()->pdo()->prepare('SELECT 1 FROM exercises WHERE exercise_id=? AND owner_user_id=? AND deleted_at IS NULL');
            $owned->execute([$exerciseId, $userId]);
            if (!$owned->fetchColumn()) {
                throw new InvalidArgumentException('Пользовательское упражнение не найдено.');
            }
        }
    }

    public function measurements(int $userId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM body_measurements WHERE user_id=? AND deleted_at IS NULL ORDER BY measured_on DESC,id DESC LIMIT 365');
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function measurementCharts(array $items): array
    {
        $items = array_reverse($items);
        $definitions = [
            'weight_kg' => ['title' => 'Вес', 'unit' => ' кг'],
            'waist_cm' => ['title' => 'Талия', 'unit' => ' см'],
            'chest_cm' => ['title' => 'Грудь', 'unit' => ' см'],
            'biceps' => ['title' => 'Бицепс (среднее)', 'unit' => ' см'],
            'thigh_cm' => ['title' => 'Бедро', 'unit' => ' см'],
            'body_fat_percent' => ['title' => 'Доля жира', 'unit' => '%'],
        ];
        $charts = [];
        foreach ($definitions as $key => $definition) {
            $points = [];
            foreach ($items as $item) {
                $value = $key === 'biceps'
                    ? (($item['biceps_left_cm'] !== null || $item['biceps_right_cm'] !== null) ? round(((float) ($item['biceps_left_cm'] ?? $item['biceps_right_cm']) + (float) ($item['biceps_right_cm'] ?? $item['biceps_left_cm'])) / 2, 2) : null)
                    : $item[$key];
                if ($value !== null) $points[] = ['label' => date('d.m.y', strtotime((string) $item['measured_on'])), 'value' => (float) $value];
            }
            $charts[$key] = [...$definition, 'points' => $points];
        }
        return $charts;
    }

    public function addMeasurement(int $userId, array $data): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($data['measured_on'] ?? ''));
        if (!$date) {
            throw new InvalidArgumentException('Укажите дату измерения.');
        }
        $fields = ['weight_kg','waist_cm','chest_cm','shoulders_cm','biceps_left_cm','biceps_right_cm','thigh_cm','calf_cm','body_fat_percent'];
        $values = [];
        $hasValue = false;
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if ($value === '' || $value === null) {
                $values[] = null;
                continue;
            }
            if (!is_numeric($value)) throw new InvalidArgumentException('Проверьте числовые значения измерений.');
            $number = (float) $value;
            $maximum = $field === 'body_fat_percent' ? 100 : 500;
            if ($number <= 0 || $number > $maximum) throw new InvalidArgumentException('Измерения должны быть больше нуля и в допустимом диапазоне.');
            $values[] = round($number, 2);
            $hasValue = true;
        }
        if (!$hasValue) throw new InvalidArgumentException('Заполните хотя бы одно измерение.');
        $query = $this->pdo()->prepare('INSERT INTO body_measurements (user_id,measured_on,weight_kg,waist_cm,chest_cm,shoulders_cm,biceps_left_cm,biceps_right_cm,thigh_cm,calf_cm,body_fat_percent,comment,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $query->execute([$userId, $date->format('Y-m-d'), ...$values, mb_substr((string) ($data['comment'] ?? ''), 0, 2000) ?: null]);
    }

    public function swimming(int $userId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM swimming_sessions WHERE user_id=? AND deleted_at IS NULL ORDER BY occurred_at DESC,id DESC LIMIT 100');
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function swimmingSession(int $id, int $userId): ?array
    {
        $query = $this->pdo()->prepare('SELECT sw.*,s.label schedule_label,s.weekday schedule_weekday FROM swimming_sessions sw LEFT JOIN schedules s ON s.id=sw.schedule_id AND s.user_id=sw.user_id WHERE sw.id=? AND sw.user_id=? AND sw.deleted_at IS NULL');
        $query->execute([$id, $userId]);
        $session = $query->fetch();
        if (!$session) return null;
        $intervals = $this->pdo()->prepare('SELECT id,sequence_no,repeat_count,distance_m,style,intensity,rest_seconds,note FROM swimming_intervals WHERE swimming_session_id=? ORDER BY sequence_no');
        $intervals->execute([$id]);
        $session['intervals'] = $intervals->fetchAll();
        return $session;
    }

    public function addSwimming(int $userId, array $data): void
    {
        $this->createSwimming($userId, $data);
    }

    public function createSwimming(int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($userId, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'swimming.create');
            if ($receipt !== null) return $receipt;
            $timezone = $this->userTimezone($pdo, $userId);
            $swim = Swimming::validate($data, $timezone);
            $source = 'manual';
            if ($swim['schedule_id'] !== null) {
                $schedule = $this->ownedSwimmingSchedule($pdo, $userId, $swim['schedule_id']);
                if ((int) $schedule['weekday'] !== $swim['weekday']) {
                    throw new InvalidArgumentException('Дата не совпадает с днём выбранного расписания.');
                }
                $source = 'schedule';
            }
            $publicId = 'swim-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $insert = $pdo->prepare('INSERT INTO swimming_sessions (public_id,user_id,schedule_id,source,swim_date,occurred_at,duration_minutes,pool_length_m,total_distance_m,primary_style,intensity,arms_fatigue,back_fatigue,legs_fatigue,wellbeing,intervals_json,comment,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $insert->execute([$publicId,$userId,$swim['schedule_id'],$source,$swim['swim_date'],$swim['occurred_at'],$swim['duration_minutes'],$swim['pool_length_m'],$swim['total_distance_m'],$swim['primary_style'],$swim['intensity'],$swim['arms_fatigue'],$swim['back_fatigue'],$swim['legs_fatigue'],$swim['wellbeing'],json_encode($swim['intervals'],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$swim['comment']]);
            $id = (int) $pdo->lastInsertId();
            $this->insertSwimmingIntervals($pdo, $id, $swim['intervals']);
            $after = ['public_id' => $publicId, ...$swim, 'source' => $source];
            $this->audit($pdo, $userId, 'swimming_session', (string) $id, 'create', null, $after);
            $result = ['id' => $id, 'public_id' => $publicId, 'version' => 1, 'redirect' => '/swimming/' . $id];
            $this->completeAction($pdo, $userId, $data, 'swimming.create', $result);
            return $result;
        });
    }

    public function updateSwimming(int $id, int $userId, array $data): array
    {
        return $this->transaction(function (PDO $pdo) use ($id, $userId, $data): array {
            $receipt = $this->beginAction($pdo, $userId, $data, 'swimming.update');
            if ($receipt !== null) return $receipt;
            $query = $pdo->prepare('SELECT * FROM swimming_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $query->execute([$id, $userId]);
            $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('Запись плавания не найдена.');
            $expectedVersion = $data['version'] ?? null;
            if (is_string($expectedVersion) && ctype_digit($expectedVersion)) $expectedVersion = (int) $expectedVersion;
            if (!is_int($expectedVersion) || $expectedVersion !== (int) $before['version']) {
                throw new VersionConflictException('Запись плавания уже изменена в другой вкладке. Обновите страницу.');
            }
            $timezone = $this->userTimezone($pdo, $userId);
            $swim = Swimming::validate($data, $timezone);
            $source = 'manual';
            if ($swim['schedule_id'] !== null) {
                $schedule = $this->ownedSwimmingSchedule($pdo, $userId, $swim['schedule_id']);
                if ((int) $schedule['weekday'] !== $swim['weekday']) throw new InvalidArgumentException('Дата не совпадает с днём выбранного расписания.');
                $source = 'schedule';
            }
            $update = $pdo->prepare('UPDATE swimming_sessions SET schedule_id=?,source=?,swim_date=?,occurred_at=?,duration_minutes=?,pool_length_m=?,total_distance_m=?,primary_style=?,intensity=?,arms_fatigue=?,back_fatigue=?,legs_fatigue=?,wellbeing=?,intervals_json=?,comment=?,version=version+1,edited_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?');
            $update->execute([$swim['schedule_id'],$source,$swim['swim_date'],$swim['occurred_at'],$swim['duration_minutes'],$swim['pool_length_m'],$swim['total_distance_m'],$swim['primary_style'],$swim['intensity'],$swim['arms_fatigue'],$swim['back_fatigue'],$swim['legs_fatigue'],$swim['wellbeing'],json_encode($swim['intervals'],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$swim['comment'],$id,$userId]);
            $pdo->prepare('DELETE FROM swimming_intervals WHERE swimming_session_id=?')->execute([$id]);
            $this->insertSwimmingIntervals($pdo, $id, $swim['intervals']);
            $this->audit($pdo, $userId, 'swimming_session', (string) $id, 'update', $before, [...$swim, 'source' => $source, 'version' => $expectedVersion + 1]);
            $result = ['id' => $id, 'public_id' => $before['public_id'], 'version' => $expectedVersion + 1, 'redirect' => '/swimming/' . $id];
            $this->completeAction($pdo, $userId, $data, 'swimming.update', $result);
            return $result;
        });
    }

    public function schedule(int $userId): array
    {
        $this->ensureDefaultSchedule($this->pdo(), $userId);
        $query = $this->pdo()->prepare('SELECT id,weekday,workout_type,label,active,version FROM schedules WHERE user_id=? ORDER BY weekday');
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function saveSchedule(int $userId, array $data): void
    {
        $days = is_array($data['days'] ?? null) ? $data['days'] : [];
        $allowed = ['strength','swimming','cardio','mobility','other'];
        $normalized = [];
        foreach ($days as $weekday => $row) {
            $weekday = (int) $weekday;
            if ($weekday < 1 || $weekday > 7 || !is_array($row)) continue;
            $active = !empty($row['active']);
            $type = (string) ($row['workout_type'] ?? 'strength');
            $label = trim((string) ($row['label'] ?? ''));
            if ($active && (!in_array($type, $allowed, true) || $label === '' || mb_strlen($label) > 120)) {
                throw new InvalidArgumentException('Для активного дня укажите тип и название до 120 символов.');
            }
            $normalized[$weekday] = ['weekday' => $weekday, 'workout_type' => $type, 'label' => $label ?: 'Тренировка', 'active' => $active ? 1 : 0];
        }
        $this->transaction(function (PDO $pdo) use ($userId, $normalized): void {
            $existingQuery = $pdo->prepare('SELECT id,weekday,workout_type,label,active,version FROM schedules WHERE user_id=? ORDER BY weekday' . $this->lock());
            $existingQuery->execute([$userId]);
            $before = $existingQuery->fetchAll();
            $byDay = [];
            foreach ($before as $row) $byDay[(int) $row['weekday']] = $row;
            foreach (range(1, 7) as $weekday) {
                $row = $normalized[$weekday] ?? ['weekday' => $weekday, 'workout_type' => 'strength', 'label' => 'Тренировка', 'active' => 0];
                if (isset($byDay[$weekday])) {
                    $pdo->prepare('UPDATE schedules SET workout_type=?,label=?,active=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$row['workout_type'],$row['label'],$row['active'],$byDay[$weekday]['id'],$userId]);
                } else {
                    $pdo->prepare('INSERT INTO schedules (user_id,weekday,workout_type,label,active,version,created_at,updated_at) VALUES (?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$userId,$weekday,$row['workout_type'],$row['label'],$row['active']]);
                }
            }
            $afterQuery = $pdo->prepare('SELECT id,weekday,workout_type,label,active,version FROM schedules WHERE user_id=? ORDER BY weekday');
            $afterQuery->execute([$userId]);
            $this->audit($pdo, $userId, 'schedule', (string) $userId, 'replace_week', $before, $afterQuery->fetchAll());
        });
    }

    public function trainingSequence(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT * FROM (SELECT 'strength' source_kind,s.id,s.public_id,s.started_at occurred_at,s.workout_type,p.name,NULL distance_m FROM workout_sessions s JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id WHERE s.user_id=? AND s.status='completed' AND s.deleted_at IS NULL UNION ALL SELECT 'swimming',sw.id,sw.public_id,sw.occurred_at,'swimming','Плавание',sw.total_distance_m FROM swimming_sessions sw WHERE sw.user_id=? AND sw.deleted_at IS NULL) sequence ORDER BY occurred_at DESC,id DESC LIMIT {$limit}";
        $query = $this->pdo()->prepare($sql);
        $query->execute([$userId, $userId]);
        return $query->fetchAll();
    }

    private function ownedSwimmingSchedule(PDO $pdo, int $userId, int $scheduleId): array
    {
        $query = $pdo->prepare("SELECT id,weekday,label FROM schedules WHERE id=? AND user_id=? AND workout_type='swimming' AND active=1");
        $query->execute([$scheduleId, $userId]);
        $row = $query->fetch();
        if (!$row) throw new InvalidArgumentException('Выбранное расписание бассейна недоступно.');
        return $row;
    }

    private function ensureDefaultSchedule(PDO $pdo, int $userId): void
    {
        $query = $pdo->prepare('SELECT COUNT(*) FROM schedules WHERE user_id=?');
        $query->execute([$userId]);
        if ((int) $query->fetchColumn() > 0) return;
        $insert = $pdo->prepare('INSERT INTO schedules (user_id,weekday,workout_type,label,active,version,created_at,updated_at) VALUES (?,?,?,?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        foreach ([[1,'strength','Зал'],[3,'strength','Зал'],[4,'swimming','Бассейн']] as $row) $insert->execute([$userId,...$row]);
    }

    private function insertSwimmingIntervals(PDO $pdo, int $sessionId, array $intervals): void
    {
        $insert = $pdo->prepare('INSERT INTO swimming_intervals (swimming_session_id,sequence_no,repeat_count,distance_m,style,intensity,rest_seconds,note,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        foreach ($intervals as $interval) {
            $insert->execute([$sessionId,$interval['sequence_no'],$interval['repeat_count'],$interval['distance_m'],$interval['style'],$interval['intensity'],$interval['rest_seconds'],$interval['note']]);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $query->execute([$table]);
            return (bool) $query->fetchColumn();
        }
        $query = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $query->execute([$table]);
        return (bool) $query->fetchColumn();
    }

    public function updateTheme(int $userId, string $theme): void
    {
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            throw new InvalidArgumentException('Выберите светлую, тёмную или системную тему.');
        }
        $query = $this->pdo()->prepare('UPDATE users SET theme=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL');
        $query->execute([$theme, $userId]);
        if ($query->rowCount() !== 1) throw new InvalidArgumentException('Пользователь не найден.');
    }

    public function cancelSession(int $sessionId, int $userId, int $version, bool $confirmed): void
    {
        if (!$confirmed) throw new InvalidArgumentException('Подтвердите отмену незавершённой тренировки.');
        $this->transaction(function (PDO $pdo) use ($sessionId, $userId, $version): void {
            $query = $pdo->prepare("SELECT * FROM workout_sessions WHERE id=? AND user_id=? AND status='in_progress' AND deleted_at IS NULL" . $this->lock());
            $query->execute([$sessionId, $userId]);
            $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('Незавершённая тренировка не найдена.');
            if ($version !== (int) $before['version']) throw new VersionConflictException('Тренировка уже изменена в другой вкладке.');
            $pdo->prepare("UPDATE workout_sessions SET status='cancelled',finished_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?")->execute([$sessionId, $userId]);
            $pdo->prepare("UPDATE workout_plans SET status='planned',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?")->execute([$before['workout_plan_id'], $userId]);
            $this->audit($pdo, $userId, 'workout_session', (string) $sessionId, 'cancel', $before, ['status' => 'cancelled']);
        });
    }

    public function softDeleteSet(int $setId, int $userId, int $version, int $sessionVersion, bool $confirmed): void
    {
        if (!$confirmed) throw new InvalidArgumentException('Подтвердите удаление подхода.');
        [$sessionId, $sessionStatus] = $this->transaction(function (PDO $pdo) use ($setId, $userId, $version, $sessionVersion): array {
            $query = $pdo->prepare('SELECT * FROM exercise_sets WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $query->execute([$setId, $userId]);
            $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('Подход не найден.');
            if ($version !== (int) $before['version']) throw new VersionConflictException('Подход уже изменён.');
            $session = $pdo->prepare('SELECT version,status FROM workout_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $session->execute([$before['workout_session_id'], $userId]);
            $sessionRow = $session->fetch();
            if (!$sessionRow || $sessionVersion !== (int) $sessionRow['version']) throw new VersionConflictException('Тренировка уже изменена.');
            $pdo->prepare('UPDATE exercise_sets SET deleted_at=UTC_TIMESTAMP(),version=version+1,edited_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$setId, $userId]);
            $pdo->prepare("UPDATE workout_sessions SET version=version+1,updated_at=UTC_TIMESTAMP(),edited_after_completion=CASE WHEN status='completed' THEN 1 ELSE edited_after_completion END,edited_at=CASE WHEN status='completed' THEN UTC_TIMESTAMP() ELSE edited_at END WHERE id=? AND user_id=?")->execute([$before['workout_session_id'], $userId]);
            $this->audit($pdo, $userId, 'exercise_set', (string) $setId, 'soft_delete', $before, ['deleted' => true]);
            return [(int) $before['workout_session_id'], (string) $sessionRow['status']];
        });
        if ($sessionStatus === 'completed') $this->rebuildDerivedData($sessionId, $userId);
    }

    public function softDeleteMeasurement(int $id, int $userId, bool $confirmed): void
    {
        if (!$confirmed) throw new InvalidArgumentException('Подтвердите удаление измерения.');
        $this->transaction(function (PDO $pdo) use ($id, $userId): void {
            $query = $pdo->prepare('SELECT * FROM body_measurements WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $query->execute([$id, $userId]); $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('Измерение не найдено.');
            $pdo->prepare('UPDATE body_measurements SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$id, $userId]);
            $this->audit($pdo, $userId, 'body_measurement', (string) $id, 'soft_delete', $before, ['deleted' => true]);
        });
    }

    public function softDeleteSwimming(int $id, int $userId, int $version, bool $confirmed): void
    {
        if (!$confirmed) throw new InvalidArgumentException('Подтвердите удаление записи плавания.');
        $this->transaction(function (PDO $pdo) use ($id, $userId, $version): void {
            $query = $pdo->prepare('SELECT * FROM swimming_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
            $query->execute([$id, $userId]); $before = $query->fetch();
            if (!$before) throw new InvalidArgumentException('Запись плавания не найдена.');
            if ($version !== (int) $before['version']) throw new VersionConflictException('Запись плавания уже изменена.');
            $pdo->prepare('UPDATE swimming_sessions SET deleted_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$id, $userId]);
            $this->audit($pdo, $userId, 'swimming_session', (string) $id, 'soft_delete', $before, ['deleted' => true]);
        });
    }

    public function weeklyAnalytics(int $userId, ?string $timezone = null, int $weeks = 12): array
    {
        $pdo = $this->pdo();
        $timezone ??= $this->userTimezone($pdo, $userId);
        $weeks = max(4, min(52, $weeks));
        $window = Analytics::weekWindow($timezone, $weeks);
        $query = $pdo->prepare("SELECT ws.id,ws.started_at,ws.finished_at,COUNT(es.id) working_sets,COALESCE(SUM(es.performed_weight_kg*es.reps),0) tonnage,AVG(es.rir) average_rir,COUNT(es.rir) rir_count FROM workout_sessions ws LEFT JOIN exercise_sets es ON es.workout_session_id=ws.id AND es.user_id=ws.user_id AND es.set_type='working' AND es.deleted_at IS NULL WHERE ws.user_id=? AND ws.status='completed' AND ws.started_at>=? AND ws.started_at<? AND ws.deleted_at IS NULL GROUP BY ws.id,ws.started_at,ws.finished_at ORDER BY ws.started_at");
        $query->execute([$userId, $window['start_utc'], $window['end_utc']]);
        $weekly = Analytics::weekly($query->fetchAll(), $timezone, $weeks);
        $current = $weekly[array_key_last($weekly)] ?? ['workouts' => 0, 'working_sets' => 0, 'tonnage' => 0, 'average_rir' => null, 'duration_minutes' => 0];

        $musclesQuery = $pdo->prepare("SELECT e.category,e.muscle_groups,COUNT(es.id) working_sets FROM exercise_sets es JOIN workout_sessions ws ON ws.id=es.workout_session_id AND ws.user_id=es.user_id JOIN session_exercises se ON se.id=es.session_exercise_id AND se.workout_session_id=ws.id JOIN exercises e ON e.exercise_id=se.actual_exercise_id WHERE es.user_id=? AND ws.user_id=? AND ws.status='completed' AND ws.started_at>=? AND ws.started_at<? AND ws.deleted_at IS NULL AND es.set_type='working' AND es.deleted_at IS NULL GROUP BY e.exercise_id,e.category,e.muscle_groups");
        $musclesQuery->execute([$userId, $userId, $window['current_start_utc'], $window['end_utc']]);
        $muscles = [];
        foreach ($musclesQuery->fetchAll() as $row) {
            $groups = json_decode((string) ($row['muscle_groups'] ?? ''), true);
            if (!is_array($groups) || $groups === []) $groups = [(string) ($row['category'] ?: 'not_set')];
            foreach (array_unique($groups) as $group) $muscles[(string) $group] = ($muscles[(string) $group] ?? 0) + (int) $row['working_sets'];
        }
        arsort($muscles);

        $recordsQuery = $pdo->prepare('SELECT pr.record_type,pr.value_decimal,pr.exercise_id,pr.metadata_json,pr.achieved_at,e.name exercise_name,ws.id session_id FROM personal_records pr JOIN workout_sessions ws ON ws.id=pr.workout_session_id AND ws.user_id=pr.user_id LEFT JOIN exercises e ON e.exercise_id=pr.exercise_id WHERE pr.user_id=? ORDER BY pr.achieved_at DESC,pr.id DESC LIMIT 20');
        $recordsQuery->execute([$userId]);
        $records = $recordsQuery->fetchAll();
        return ['current' => $current, 'weeks' => $weekly, 'sets_by_muscle' => $muscles, 'records' => $records, 'timezone' => $timezone];
    }

    private function insertRecord(PDO $pdo, int $userId, int $sessionId, ?string $exerciseId, string $type, float|int $value, array $metadata): void
    {
        $query = $pdo->prepare('INSERT INTO personal_records (user_id,workout_session_id,exercise_id,record_type,value_decimal,metadata_json,achieved_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())');
        $query->execute([$userId, $sessionId, $exerciseId, $type, round((float) $value, 2), json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
    }

    private function userTimezone(PDO $pdo, int $userId): string
    {
        $query = $pdo->prepare('SELECT timezone FROM users WHERE id=? AND deleted_at IS NULL');
        $query->execute([$userId]);
        $timezone = (string) ($query->fetchColumn() ?: 'Europe/Moscow');
        try { new \DateTimeZone($timezone); } catch (\Throwable) { return 'Europe/Moscow'; }
        return $timezone;
    }

    private function requiredScore(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 5) {
            throw new InvalidArgumentException('Оцените ' . $label . ' по шкале от 1 до 5.');
        }
        return $value;
    }

    private function beginAction(PDO $pdo, int $userId, array $data, string $actionType): ?array
    {
        $clientId = $data['client_action_id'] ?? null;
        if ($clientId === null) {
            return null;
        }
        if (!is_string($clientId) || !preg_match('/^[a-zA-Z0-9._:-]{8,80}$/', $clientId)) {
            throw new InvalidArgumentException('Некорректный client_action_id.');
        }
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id=?' . $this->lock());
        $userLock->execute([$userId]);
        if (!$userLock->fetchColumn()) {
            throw new InvalidArgumentException('Пользователь не найден.');
        }
        $query = $pdo->prepare('SELECT action_type,response_json FROM offline_action_receipts WHERE user_id=? AND client_action_id=?');
        $query->execute([$userId, $clientId]);
        $receipt = $query->fetch();
        if (!$receipt) {
            return null;
        }
        if (!hash_equals((string) $receipt['action_type'], $actionType)) {
            throw new InvalidArgumentException('client_action_id уже использован для другого действия.');
        }
        $response = json_decode((string) $receipt['response_json'], true);
        if (!is_array($response)) {
            throw new RuntimeException('Повреждён результат идемпотентного действия.');
        }
        return $response;
    }

    private function completeAction(PDO $pdo, int $userId, array $data, string $actionType, array $response): void
    {
        $clientId = $data['client_action_id'] ?? null;
        if ($clientId === null) {
            return;
        }
        $insert = $pdo->prepare('INSERT INTO offline_action_receipts (user_id,client_action_id,action_type,response_json,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
        $insert->execute([$userId, $clientId, $actionType, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
    }

    private function text(mixed $value, int $limit, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' должен быть текстом.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $limit) {
            throw new InvalidArgumentException($label . ' слишком длинный.');
        }
        return $value === '' ? null : $value;
    }

    private function lockedExercise(PDO $pdo, int $sessionId, int $userId, array $data): array
    {
        $sessionQuery = $pdo->prepare('SELECT version,status FROM workout_sessions WHERE id=? AND user_id=? AND deleted_at IS NULL' . $this->lock());
        $sessionQuery->execute([$sessionId, $userId]);
        $session = $sessionQuery->fetch();
        if (!$session || $session['status'] !== 'in_progress') {
            throw new InvalidArgumentException('Активная тренировка не найдена.');
        }
        if (!is_int($data['session_version'] ?? null) || $data['session_version'] !== (int) $session['version']) {
            throw new VersionConflictException('Данные изменены в другой вкладке. Обновите тренировку.');
        }
        $exerciseId = $data['session_exercise_id'] ?? null;
        if (!is_int($exerciseId) || $exerciseId < 1) {
            throw new InvalidArgumentException('Некорректный идентификатор упражнения.');
        }
        $query = $pdo->prepare('SELECT * FROM session_exercises WHERE id=? AND workout_session_id=?' . $this->lock());
        $query->execute([$exerciseId, $sessionId]);
        $exercise = $query->fetch();
        if (!$exercise) {
            throw new InvalidArgumentException('Упражнение не найдено.');
        }
        if (!is_int($data['exercise_version'] ?? null) || $data['exercise_version'] !== (int) $exercise['version']) {
            throw new VersionConflictException('Упражнение изменено в другой вкладке. Обновите тренировку.');
        }
        return [$session, $exercise];
    }

    private function summarize(array $session): array
    {
        $sets = [];
        $completed = 0;
        $skipped = 0;
        foreach ($session['exercises'] as $exercise) {
            $sets = [...$sets, ...$exercise['sets']];
            $completed += $exercise['status'] === 'completed' ? 1 : 0;
            $skipped += $exercise['status'] === 'skipped' ? 1 : 0;
        }
        return [
            'completed_exercises' => $completed,
            'skipped_exercises' => $skipped,
            'total_exercises' => count($session['exercises']),
            'working_sets' => count(array_filter($sets, static fn (array $set): bool => $set['set_type'] === 'working')),
            'tonnage_kg' => TrainingMetrics::tonnage($sets),
            'average_rir' => TrainingMetrics::averageRir($sets),
            'duration_minutes' => $session['finished_at'] ? max(1, (int) round((strtotime($session['finished_at']) - strtotime($session['started_at'])) / 60)) : max(0, (int) floor((time() - strtotime($session['started_at'] . ' UTC')) / 60)),
        ];
    }

    private function audit(PDO $pdo, int $userId, string $entityType, string $entityId, string $action, ?array $before, ?array $after): void
    {
        $query = $pdo->prepare('INSERT INTO audit_logs (user_id,entity_type,entity_id,action,before_json,after_json,ip_address,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $query->execute([$userId,$entityType,$entityId,$action,$before ? json_encode($before,JSON_UNESCAPED_UNICODE) : null,$after ? json_encode($after,JSON_UNESCAPED_UNICODE) : null,substr($_SERVER['REMOTE_ADDR'] ?? '',0,45)]);
    }
}
