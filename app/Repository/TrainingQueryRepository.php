<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Read-only, tenant-scoped persistence adapter for assistant and UI projections.
 *
 * Rows returned here are deliberately selected (never SELECT *) and are mapped to
 * public DTOs by TrainingQueryService. Numeric database ids may be present only
 * as private join keys used by that mapper.
 */
final class TrainingQueryRepository
{
    public function __construct(private readonly ?PDO $connection = null) {}

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }

    public function profileRow(int $userId): ?array
    {
        $query = $this->pdo()->prepare('SELECT id,timezone,theme,created_at FROM users WHERE id=? AND deleted_at IS NULL');
        $query->execute([$userId]);
        return $query->fetch() ?: null;
    }

    public function programRows(int $userId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT p.external_program_id,p.name,p.description,p.status,p.created_at,p.updated_at,
       pv.version_number,pv.source,pv.change_reason,pv.trainer_comment,pv.created_at version_created_at,
       parent.version_number parent_version,
       CASE
           WHEN p.active_version_id IS NOT NULL AND pv.id IS NULL THEN 'invalid_pointer'
           WHEN p.active_version_id IS NOT NULL THEN 'resolved'
           WHEN (SELECT COUNT(*) FROM program_versions vc WHERE vc.program_id=p.id)=0 THEN 'no_versions'
           WHEN (SELECT COUNT(*) FROM program_versions vc WHERE vc.program_id=p.id)=1 THEN 'reconcilable'
           ELSE 'ambiguous'
       END active_version_state,
       (SELECT COUNT(*) FROM workout_templates wt WHERE wt.program_version_id=pv.id AND wt.user_id=p.user_id AND wt.deleted_at IS NULL) template_count,
       (SELECT COUNT(*) FROM workout_plans wp WHERE wp.program_version_id=pv.id AND wp.user_id=p.user_id AND wp.deleted_at IS NULL) workout_count
FROM training_programs p
LEFT JOIN program_versions pv ON pv.id=p.active_version_id AND pv.program_id=p.id
LEFT JOIN program_versions parent ON parent.id=pv.parent_version_id AND parent.program_id=p.id
WHERE p.user_id=? AND p.deleted_at IS NULL
ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END,p.updated_at DESC,p.external_program_id
SQL);
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function programVersionRow(int $userId, string $programId, ?int $version): ?array
    {
        $sql = 'SELECT p.external_program_id,p.name,p.description,p.status,pv.id internal_version_id,pv.version_number,pv.source,pv.change_reason,pv.trainer_comment,pv.created_at,pv.lifecycle_status,pv.lock_version,pv.aggregate_hash,pv.activated_at,pv.archived_at,parent.version_number parent_version FROM training_programs p JOIN program_versions pv ON pv.program_id=p.id LEFT JOIN program_versions parent ON parent.id=pv.parent_version_id AND parent.program_id=p.id WHERE p.user_id=? AND p.external_program_id=? AND p.deleted_at IS NULL';
        $params = [$userId, $programId];
        if ($version !== null) {
            $sql .= ' AND pv.version_number=?';
            $params[] = $version;
        } else {
            $sql .= ' AND pv.id=p.active_version_id';
        }
        $sql .= ' ORDER BY pv.version_number DESC,pv.id DESC LIMIT 1';
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);
        return $query->fetch() ?: null;
    }

    public function programVersionRows(int $userId, string $programId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT p.external_program_id,p.name,p.status,pv.id internal_version_id,pv.version_number,pv.source,pv.change_reason,pv.trainer_comment,pv.created_at,
       pv.lifecycle_status,pv.lock_version,pv.aggregate_hash,pv.activated_at,pv.archived_at,
       parent.version_number parent_version,
       (SELECT COUNT(*) FROM workout_templates wt WHERE wt.program_version_id=pv.id AND wt.user_id=p.user_id AND wt.deleted_at IS NULL) template_count,
       (SELECT COUNT(*) FROM workout_plans wp WHERE wp.program_version_id=pv.id AND wp.user_id=p.user_id AND wp.deleted_at IS NULL) workout_count
FROM training_programs p
JOIN program_versions pv ON pv.program_id=p.id
LEFT JOIN program_versions parent ON parent.id=pv.parent_version_id AND parent.program_id=p.id
WHERE p.user_id=? AND p.external_program_id=? AND p.deleted_at IS NULL
ORDER BY pv.version_number DESC,pv.id DESC
SQL);
        $query->execute([$userId, $programId]);
        return $query->fetchAll();
    }

    public function templateRows(int $userId, int $internalVersionId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT wt.code,wt.name,wt.workout_type,wt.content_json,
       (SELECT COUNT(*) FROM workout_plans wp WHERE wp.workout_template_id=wt.id AND wp.user_id=wt.user_id AND wp.deleted_at IS NULL) workout_count
FROM workout_templates wt
JOIN program_versions pv ON pv.id=wt.program_version_id
JOIN training_programs p ON p.id=pv.program_id AND p.user_id=wt.user_id
WHERE wt.user_id=? AND wt.program_version_id=? AND p.user_id=? AND wt.deleted_at IS NULL AND p.deleted_at IS NULL
ORDER BY wt.code
SQL);
        $query->execute([$userId, $internalVersionId, $userId]);
        return $query->fetchAll();
    }

    public function templateRow(int $userId, int $internalVersionId, string $templateId): ?array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT wt.code,wt.name,wt.workout_type,wt.content_json,
       (SELECT COUNT(*) FROM workout_plans wp WHERE wp.workout_template_id=wt.id AND wp.user_id=wt.user_id AND wp.deleted_at IS NULL) workout_count
FROM workout_templates wt
JOIN program_versions pv ON pv.id=wt.program_version_id
JOIN training_programs p ON p.id=pv.program_id AND p.user_id=wt.user_id
WHERE wt.user_id=? AND wt.program_version_id=? AND wt.code=? AND p.user_id=?
  AND wt.deleted_at IS NULL AND p.deleted_at IS NULL
LIMIT 1
SQL);
        $query->execute([$userId, $internalVersionId, $templateId, $userId]);
        return $query->fetch() ?: null;
    }

    public function scheduleSlotRows(int $userId, int $internalVersionId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT pss.weekday,wt.code template_code
FROM program_schedule_slots pss
JOIN program_versions pv ON pv.id=pss.program_version_id
JOIN training_programs p ON p.id=pv.program_id
JOIN workout_templates wt
  ON wt.id=pss.workout_template_id
 AND wt.program_version_id=pss.program_version_id
 AND wt.user_id=p.user_id
WHERE p.user_id=? AND pss.program_version_id=? AND p.deleted_at IS NULL AND wt.deleted_at IS NULL
ORDER BY pss.weekday
SQL);
        $query->execute([$userId, $internalVersionId]);
        return $query->fetchAll();
    }

    public function workoutRows(
        int $userId,
        string $from,
        string $to,
        ?string $type,
        ?string $status,
        ?array $cursor,
        int $limit
    ): array {
        $sql = <<<'SQL'
SELECT * FROM (
    SELECT 'strength' source_kind,p.external_plan_id item_key,p.scheduled_date local_date,p.name,p.workout_type,
           p.status planned_status,p.version plan_version,s.public_id session_key,s.status session_status,
           s.started_at,s.finished_at,s.session_rpe,s.wellbeing,
           COALESCE(sa.working_sets,0) working_sets,COALESCE(sa.tonnage_kg,0) tonnage_kg,sa.average_rir,
           COALESCE(sa.completed_exercises,0) completed_exercises,COALESCE(sa.skipped_exercises,0) skipped_exercises,
           COALESCE(sa.pending_exercises,0) pending_exercises,COALESCE(sa.substitutions,0) substitutions,
           NULL swim_duration_minutes,NULL total_distance_m,NULL primary_style,NULL intensity
    FROM workout_plans p
    LEFT JOIN workout_sessions s ON s.id=(
        SELECT MAX(s2.id) FROM workout_sessions s2
        WHERE s2.workout_plan_id=p.id AND s2.user_id=p.user_id AND s2.deleted_at IS NULL
    ) AND s.user_id=p.user_id
    LEFT JOIN (
        SELECT ws.id,
               SUM(CASE WHEN es.set_type='working' AND es.deleted_at IS NULL THEN 1 ELSE 0 END) working_sets,
               SUM(CASE WHEN es.set_type='working' AND es.deleted_at IS NULL THEN COALESCE(es.performed_weight_kg,0)*COALESCE(es.reps,0) ELSE 0 END) tonnage_kg,
               AVG(CASE WHEN es.set_type='working' AND es.deleted_at IS NULL THEN es.rir ELSE NULL END) average_rir,
               COUNT(DISTINCT CASE WHEN se.status='completed' THEN se.id END) completed_exercises,
               COUNT(DISTINCT CASE WHEN se.status='skipped' THEN se.id END) skipped_exercises,
               COUNT(DISTINCT CASE WHEN se.status IN ('pending','active','waiting') THEN se.id END) pending_exercises,
               COUNT(DISTINCT CASE WHEN se.original_exercise_id<>se.actual_exercise_id THEN se.id END) substitutions
        FROM workout_sessions ws
        LEFT JOIN session_exercises se ON se.workout_session_id=ws.id
        LEFT JOIN exercise_sets es ON es.workout_session_id=ws.id AND es.session_exercise_id=se.id AND es.user_id=ws.user_id
        WHERE ws.user_id=? AND ws.deleted_at IS NULL
        GROUP BY ws.id
    ) sa ON sa.id=s.id
    WHERE p.user_id=? AND p.deleted_at IS NULL
    UNION ALL
    SELECT 'swimming',sw.public_id,sw.swim_date,'Плавание','swimming','completed',sw.version,NULL,'completed',
           sw.occurred_at,sw.occurred_at,NULL,sw.wellbeing,0,0,NULL,0,0,0,0,
           sw.duration_minutes,sw.total_distance_m,sw.primary_style,sw.intensity
    FROM swimming_sessions sw
    WHERE sw.user_id=? AND sw.deleted_at IS NULL
) timeline
WHERE local_date>=? AND local_date<=?
SQL;
        $params = [$userId, $userId, $userId, $from, $to];
        if ($type !== null) {
            $sql .= ' AND workout_type=?';
            $params[] = $type;
        }
        if ($status !== null) {
            $sql .= " AND (CASE WHEN source_kind='strength' THEN COALESCE(session_status,planned_status) ELSE 'completed' END)=?";
            $params[] = $status;
        }
        if ($cursor !== null) {
            $sql .= ' AND (local_date<? OR (local_date=? AND source_kind<?) OR (local_date=? AND source_kind=? AND item_key<?))';
            array_push($params, $cursor['date'], $cursor['date'], $cursor['kind'], $cursor['date'], $cursor['kind'], $cursor['key']);
        }
        $sql .= ' ORDER BY local_date DESC,source_kind DESC,item_key DESC LIMIT ' . ($limit + 1);
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    public function planRow(int $userId, string $planId): ?array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT p.id internal_plan_id,p.external_plan_id,p.name,p.workout_type,p.scheduled_date,p.goal,p.estimated_duration_min,
       p.trainer_notes,p.status,p.version,tp.external_program_id,tp.name program_name,pv.version_number,wt.code template_code
FROM workout_plans p
LEFT JOIN program_versions pv ON pv.id=p.program_version_id
LEFT JOIN training_programs tp ON tp.id=pv.program_id AND tp.user_id=p.user_id
LEFT JOIN workout_templates wt ON wt.id=p.workout_template_id AND wt.user_id=p.user_id
WHERE p.user_id=? AND p.external_plan_id=? AND p.deleted_at IS NULL
SQL);
        $query->execute([$userId, $planId]);
        return $query->fetch() ?: null;
    }

    public function planExerciseRows(int $userId, int $internalPlanId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT we.exercise_id,COALESCE(we.original_exercise_id,we.exercise_id) original_exercise_id,
       e.name,original.name original_name,e.exercise_type,e.category,e.muscle_groups,e.equipment,we.sequence_no,we.planned_sets,
       we.rep_min,we.rep_max,we.target_rir_min,we.target_rir_max,we.rest_seconds,we.planned_weight_kg,we.warmup_sets,
       we.method_type,we.instructions,we.substitution_reason,we.substituted_at,we.version
FROM workout_exercises we
JOIN workout_plans p ON p.id=we.workout_plan_id
JOIN exercises e ON e.exercise_id=we.exercise_id AND (e.owner_user_id IS NULL OR e.owner_user_id=p.user_id)
JOIN exercises original ON original.exercise_id=COALESCE(we.original_exercise_id,we.exercise_id) AND (original.owner_user_id IS NULL OR original.owner_user_id=p.user_id)
WHERE p.id=? AND p.user_id=? AND p.deleted_at IS NULL AND e.deleted_at IS NULL
ORDER BY we.sequence_no
SQL);
        $query->execute([$internalPlanId, $userId]);
        return $query->fetchAll();
    }

    public function sessionRow(int $userId, string $sessionId): ?array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT s.id internal_session_id,s.public_id,s.status,s.workout_type,s.started_at,s.finished_at,s.session_rpe,s.wellbeing,
       s.version,s.edited_after_completion,s.edited_at,p.external_plan_id,p.name,p.scheduled_date,p.goal,p.estimated_duration_min
FROM workout_sessions s
JOIN workout_plans p ON p.id=s.workout_plan_id AND p.user_id=s.user_id
WHERE s.user_id=? AND s.public_id=? AND s.deleted_at IS NULL AND p.deleted_at IS NULL
SQL);
        $query->execute([$userId, $sessionId]);
        return $query->fetch() ?: null;
    }

    public function sessionExerciseRows(int $userId, int $internalSessionId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT se.id internal_session_exercise_id,se.original_exercise_id,se.actual_exercise_id,se.status,se.skip_reason,
       se.substitution_reason,se.substituted_at,se.exercise_rating,se.completed_at,se.version,we.sequence_no,we.planned_sets,
       we.rep_min,we.rep_max,we.target_rir_min,we.target_rir_max,we.rest_seconds,we.planned_weight_kg,we.method_type,
       original.name original_name,actual.name actual_name,actual.exercise_type,actual.category,actual.muscle_groups
FROM session_exercises se
JOIN workout_sessions s ON s.id=se.workout_session_id
JOIN workout_exercises we ON we.id=se.workout_exercise_id AND we.workout_plan_id=s.workout_plan_id
JOIN exercises original ON original.exercise_id=se.original_exercise_id AND (original.owner_user_id IS NULL OR original.owner_user_id=s.user_id)
JOIN exercises actual ON actual.exercise_id=se.actual_exercise_id AND (actual.owner_user_id IS NULL OR actual.owner_user_id=s.user_id)
WHERE se.workout_session_id=? AND s.user_id=? AND s.deleted_at IS NULL
ORDER BY we.sequence_no
SQL);
        $query->execute([$internalSessionId, $userId]);
        return $query->fetchAll();
    }

    public function setRows(int $userId, int $internalSessionId): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT es.session_exercise_id internal_session_exercise_id,es.public_id,es.set_number,es.set_type,es.method_type,
       es.performed_weight_kg,es.reps,es.rir,es.duration_seconds,es.distance_m,es.completed_at,es.version,es.edited_at
FROM exercise_sets es
JOIN workout_sessions s ON s.id=es.workout_session_id AND s.user_id=es.user_id
WHERE es.user_id=? AND es.workout_session_id=? AND s.user_id=? AND es.deleted_at IS NULL
ORDER BY es.session_exercise_id,es.completed_at,es.set_number,es.sequence_no
SQL);
        $query->execute([$userId, $internalSessionId, $userId]);
        return $query->fetchAll();
    }

    public function exerciseRow(int $userId, string $exerciseId): ?array
    {
        $query = $this->pdo()->prepare('SELECT exercise_id,name,category,muscle_groups,exercise_type,equipment FROM exercises WHERE exercise_id=? AND deleted_at IS NULL AND status=\'active\' AND (owner_user_id IS NULL OR owner_user_id=?)');
        $query->execute([$exerciseId, $userId]);
        return $query->fetch() ?: null;
    }

    public function exerciseHistoryRows(int $userId, string $exerciseId, string $fromUtc, string $toUtc, ?array $cursor, int $limit): array
    {
        $sql = <<<'SQL'
SELECT se.id internal_session_exercise_id,ws.public_id session_key,ws.started_at,ws.finished_at,ws.session_rpe,
       p.external_plan_id,p.name workout_name,se.status,se.original_exercise_id,se.actual_exercise_id,
       we.sequence_no,we.planned_sets,we.rep_min,we.rep_max,we.target_rir_min,we.target_rir_max
FROM session_exercises se
JOIN workout_sessions ws ON ws.id=se.workout_session_id
JOIN workout_plans p ON p.id=ws.workout_plan_id AND p.user_id=ws.user_id
JOIN workout_exercises we ON we.id=se.workout_exercise_id AND we.workout_plan_id=p.id
WHERE ws.user_id=? AND se.actual_exercise_id=? AND ws.status='completed' AND ws.deleted_at IS NULL
  AND ws.started_at>=? AND ws.started_at<?
SQL;
        $params = [$userId, $exerciseId, $fromUtc, $toUtc];
        if ($cursor !== null) {
            $sql .= ' AND (ws.started_at<? OR (ws.started_at=? AND ws.public_id<?) OR (ws.started_at=? AND ws.public_id=? AND we.sequence_no>?))';
            array_push($params, $cursor['at'], $cursor['at'], $cursor['key'], $cursor['at'], $cursor['key'], $cursor['sequence']);
        }
        $sql .= ' ORDER BY ws.started_at DESC,ws.public_id DESC,we.sequence_no ASC LIMIT ' . ($limit + 1);
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    public function setsForSessionExercises(int $userId, array $internalIds): array
    {
        if ($internalIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($internalIds), '?'));
        $query = $this->pdo()->prepare("SELECT es.session_exercise_id internal_session_exercise_id,es.public_id,es.set_number,es.set_type,es.method_type,es.performed_weight_kg,es.reps,es.rir,es.duration_seconds,es.distance_m,es.completed_at FROM exercise_sets es JOIN workout_sessions ws ON ws.id=es.workout_session_id AND ws.user_id=es.user_id WHERE es.user_id=? AND ws.user_id=? AND es.session_exercise_id IN ({$placeholders}) AND es.deleted_at IS NULL ORDER BY es.session_exercise_id,es.completed_at,es.set_number,es.sequence_no");
        $query->execute([$userId, $userId, ...$internalIds]);
        return $query->fetchAll();
    }

    public function completedSessionRows(int $userId, string $fromUtc, string $toUtc): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT ws.id internal_session_id,ws.public_id,ws.started_at,ws.finished_at,ws.session_rpe,ws.wellbeing,p.external_plan_id,p.name
FROM workout_sessions ws
JOIN workout_plans p ON p.id=ws.workout_plan_id AND p.user_id=ws.user_id
WHERE ws.user_id=? AND ws.workout_type='strength' AND ws.status='completed' AND ws.started_at>=? AND ws.started_at<? AND ws.deleted_at IS NULL
ORDER BY ws.started_at
SQL);
        $query->execute([$userId, $fromUtc, $toUtc]);
        return $query->fetchAll();
    }

    public function completedSetRows(int $userId, string $fromUtc, string $toUtc): array
    {
        $query = $this->pdo()->prepare(<<<'SQL'
SELECT ws.id internal_session_id,ws.public_id,se.id internal_session_exercise_id,se.actual_exercise_id,e.name exercise_name,e.category,e.muscle_groups,
       es.set_type,es.performed_weight_kg,es.reps,es.rir,we.rep_min,we.rep_max,se.status,
       CASE WHEN se.original_exercise_id<>se.actual_exercise_id THEN 1 ELSE 0 END substituted
FROM workout_sessions ws
JOIN session_exercises se ON se.workout_session_id=ws.id
JOIN workout_exercises we ON we.id=se.workout_exercise_id AND we.workout_plan_id=ws.workout_plan_id
JOIN exercises e ON e.exercise_id=se.actual_exercise_id AND (e.owner_user_id IS NULL OR e.owner_user_id=ws.user_id)
LEFT JOIN exercise_sets es ON es.workout_session_id=ws.id AND es.session_exercise_id=se.id AND es.user_id=ws.user_id AND es.deleted_at IS NULL
WHERE ws.user_id=? AND ws.workout_type='strength' AND ws.status='completed' AND ws.started_at>=? AND ws.started_at<? AND ws.deleted_at IS NULL
ORDER BY ws.started_at,ws.id,we.sequence_no,es.set_number
SQL);
        $query->execute([$userId, $fromUtc, $toUtc]);
        return $query->fetchAll();
    }

    public function swimmingProgressRows(int $userId, string $from, string $to): array
    {
        $query = $this->pdo()->prepare('SELECT public_id,swim_date,occurred_at,duration_minutes,total_distance_m,primary_style,intensity,wellbeing FROM swimming_sessions WHERE user_id=? AND swim_date>=? AND swim_date<=? AND deleted_at IS NULL ORDER BY swim_date,occurred_at');
        $query->execute([$userId, $from, $to]);
        return $query->fetchAll();
    }

    public function planStatusRows(int $userId, string $from, string $to): array
    {
        $query = $this->pdo()->prepare('SELECT external_plan_id,scheduled_date,status,workout_type FROM workout_plans WHERE user_id=? AND scheduled_date>=? AND scheduled_date<=? AND deleted_at IS NULL ORDER BY scheduled_date');
        $query->execute([$userId, $from, $to]);
        return $query->fetchAll();
    }

    public function scheduledPlanRows(int $userId, string $date): array
    {
        $query = $this->pdo()->prepare('SELECT external_plan_id,name,workout_type,scheduled_date,goal,estimated_duration_min,status,version FROM workout_plans WHERE user_id=? AND scheduled_date=? AND deleted_at IS NULL ORDER BY external_plan_id');
        $query->execute([$userId, $date]);
        return $query->fetchAll();
    }

    public function scheduleRows(int $userId, int $weekday): array
    {
        $query = $this->pdo()->prepare('SELECT weekday,workout_type,label,active,version FROM schedules WHERE user_id=? AND weekday=? AND active=1 ORDER BY workout_type,label');
        $query->execute([$userId, $weekday]);
        return $query->fetchAll();
    }

    public function exerciseSearchRows(int $userId, string $queryText, ?array $cursor, int $limit): array
    {
        if (\locale() === 'en') {
            $sql = "SELECT e.exercise_id,COALESCE(et.name,e.name) name,e.category,e.muscle_groups,e.exercise_type,e.equipment FROM exercises e LEFT JOIN exercise_translations et ON et.exercise_id=e.exercise_id AND et.locale='en' WHERE e.deleted_at IS NULL AND e.status='active' AND (e.owner_user_id IS NULL OR e.owner_user_id=?) AND (e.name LIKE ? OR et.name LIKE ?)";
            $params = [$userId, '%' . $queryText . '%', '%' . $queryText . '%'];
            if ($cursor !== null) {
                $sql .= ' AND (COALESCE(et.name,e.name)>? OR (COALESCE(et.name,e.name)=? AND e.exercise_id>?))';
                array_push($params, $cursor['name'], $cursor['name'], $cursor['key']);
            }
            $sql .= ' ORDER BY COALESCE(et.name,e.name),e.exercise_id LIMIT ' . ($limit + 1);
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll();
        }
        $sql = "SELECT exercise_id,name,category,muscle_groups,exercise_type,equipment FROM exercises WHERE deleted_at IS NULL AND status='active' AND (owner_user_id IS NULL OR owner_user_id=?) AND name LIKE ?";
        $params = [$userId, '%' . $queryText . '%'];
        if ($cursor !== null) {
            $sql .= ' AND (name>? OR (name=? AND exercise_id>?))';
            array_push($params, $cursor['name'], $cursor['name'], $cursor['key']);
        }
        $sql .= ' ORDER BY name,exercise_id LIMIT ' . ($limit + 1);
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function exerciseAlternativeRows(int $userId, string $sourceExerciseId, string $exerciseType): array
    {
        $statement = $this->pdo()->prepare(<<<'SQL'
SELECT exercise_id,name,category,muscle_groups,exercise_type,equipment
FROM exercises
WHERE deleted_at IS NULL AND status='active'
  AND (owner_user_id IS NULL OR owner_user_id=?)
  AND exercise_type=? AND exercise_id<>?
ORDER BY name,exercise_id
SQL);
        $statement->execute([$userId, $exerciseType, $sourceExerciseId]);
        return $statement->fetchAll();
    }
}
