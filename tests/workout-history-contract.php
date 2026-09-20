<?php

declare(strict_types=1);

require __DIR__ . '/stage3.php';
restore_exception_handler();

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$pdo->exec("INSERT INTO exercises VALUES
    ('history-contract',NULL,'История контракта',2.5,'absolute','active',NULL),
    ('history-empty',NULL,'Без истории',2.5,'absolute','active',NULL)");

$planInsert = $pdo->prepare(<<<'SQL'
INSERT INTO workout_plans
    (id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at,deleted_at)
VALUES (?,?,?,?, 'strength',?,?,1,UTC_TIMESTAMP(),NULL)
SQL);
$exerciseInsert = $pdo->prepare(<<<'SQL'
INSERT INTO workout_exercises
    (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,planned_weight_value,planned_weight_unit,warmup_sets,method_type,instructions)
VALUES (?,?,?,1,2,6,10,?,?,90,40,40,'kg',0,'normal',NULL)
SQL);
$sessionInsert = $pdo->prepare(<<<'SQL'
INSERT INTO workout_sessions
    (id,public_id,user_id,workout_plan_id,workout_type,status,started_at,finished_at,active_duration_seconds,active_segment_started_at,version,created_at,updated_at,deleted_at)
VALUES (?,?,?,?,'strength',?,?,?,?,NULL,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)
SQL);
$sessionExerciseInsert = $pdo->prepare(<<<'SQL'
INSERT INTO session_exercises
    (id,workout_session_id,workout_exercise_id,original_exercise_id,actual_exercise_id,weight_unit,status,version,created_at,updated_at)
VALUES (?,?,?,'history-contract','history-contract','kg','completed',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())
SQL);
$setInsert = $pdo->prepare(<<<'SQL'
INSERT INTO exercise_sets
    (public_id,user_id,workout_session_id,session_exercise_id,set_number,set_type,method_type,sequence_no,performed_weight_kg,weight_value,weight_unit,reps,rir,completed_at,version,deleted_at)
VALUES (?,?,?,?,?,?,'normal',1,?,?,?,?,?,?,1,?)
SQL);

for ($day = 1; $day <= 8; $day++) {
    $planId = 100 + $day;
    $date = sprintf('2026-09-%02d', $day);
    $targetMin = $day === 4 ? null : ($day === 8 ? 1.5 : 1.0);
    $targetMax = $day === 4 ? null : ($day === 8 ? 2.5 : 3.0);
    $planInsert->execute([$planId, 1, 'history-' . $day, 'История ' . $day, $date, 'completed']);
    $exerciseInsert->execute([$planId, $planId, 'history-contract', $targetMin, $targetMax]);
    $sessionInsert->execute([$planId, 'history-session-' . $day, 1, $planId, 'completed', $date . ' 10:00:00', $date . ' 11:00:00', 3600, null]);
    $sessionExerciseInsert->execute([$planId, $planId, $planId]);

    if ($day === 8) {
        $setInsert->execute(['history-8-working-1', 1, $planId, $planId, 1, 'working', 45.359237, 100, 'lb', 8, 1.5, $date . ' 10:10:00', null]);
        $setInsert->execute(['history-8-working-2', 1, $planId, $planId, 2, 'working', 50, 50, 'kg', 7, 2.5, $date . ' 10:20:00', null]);
        $setInsert->execute(['history-8-warmup', 1, $planId, $planId, 1, 'warmup', 20, 20, 'kg', 12, 4, $date . ' 10:05:00', null]);
        $setInsert->execute(['history-8-deleted', 1, $planId, $planId, 3, 'working', 200, 200, 'kg', 1, 0, $date . ' 10:30:00', $date . ' 12:00:00']);
    } else {
        $setInsert->execute(['history-' . $day . '-working', 1, $planId, $planId, 1, 'working', 30 + $day, 30 + $day, 'kg', 10, 2, $date . ' 10:10:00', null]);
    }
}

// A newer soft-deleted workout and a newer workout owned by another user must not leak in.
$planInsert->execute([109, 1, 'history-deleted', 'Удалённая история', '2026-09-09', 'completed']);
$exerciseInsert->execute([109, 109, 'history-contract', 0, 0]);
$sessionInsert->execute([109, 'history-session-deleted', 1, 109, 'completed', '2026-09-09 10:00:00', '2026-09-09 11:00:00', 3600, '2026-09-09 12:00:00']);
$sessionExerciseInsert->execute([109, 109, 109]);
$setInsert->execute(['history-deleted-working', 1, 109, 109, 1, 'working', 90, 90, 'kg', 1, 0, '2026-09-09 10:10:00', null]);

$planInsert->execute([120, 2, 'history-foreign', 'Чужая история', '2026-09-10', 'completed']);
$exerciseInsert->execute([120, 120, 'history-contract', 0, 0]);
$sessionInsert->execute([120, 'history-session-foreign', 2, 120, 'completed', '2026-09-10 10:00:00', '2026-09-10 11:00:00', 3600, null]);
$sessionExerciseInsert->execute([120, 120, 120]);
$setInsert->execute(['history-foreign-working', 2, 120, 120, 1, 'working', 100, 100, 'kg', 1, 0, '2026-09-10 10:10:00', null]);

// Current workout contains one exercise with history and one without it.
$planInsert->execute([130, 1, 'history-current', 'Текущая тренировка', '2026-09-11', 'in_progress']);
$exerciseInsert->execute([130, 130, 'history-contract', 9, 9]);
$pdo->exec("INSERT INTO workout_exercises (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,planned_weight_value,planned_weight_unit,warmup_sets,method_type,instructions) VALUES (131,130,'history-empty',2,1,8,10,1,2,60,20,20,'kg',0,'normal',NULL)");
$sessionInsert->execute([130, 'history-session-current', 1, 130, 'in_progress', '2026-09-11 10:00:00', null, 0, null]);
$pdo->exec("INSERT INTO session_exercises (id,workout_session_id,workout_exercise_id,original_exercise_id,actual_exercise_id,weight_unit,status,version,created_at,updated_at) VALUES (130,130,130,'history-contract','history-contract','kg','pending',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),(131,130,131,'history-empty','history-empty','kg','pending',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

$current = $repository->session(130, 1);
$historyExercise = $current['exercises'][0];
$emptyExercise = $current['exercises'][1];
$history = $historyExercise['history_sessions'];

$check(!array_key_exists('previous_sets', $historyExercise), 'плоский previous_sets удалён из серверного контракта');
$repositorySource = (string) file_get_contents(dirname(__DIR__) . '/app/Repository/TrainingRepository.php');
$check(str_contains($repositorySource, 'es.workout_session_id=se.workout_session_id') && !str_contains($repositorySource, 'es.session_exercise_id=se.id AND es.workout_session_id=ws.id'), 'коррелированный EXISTS не ссылается на внешний alias из ON и совместим с MySQL');
$check(count($history) === 6, 'история ограничена шестью тренировками');
$check(array_column($history, 'scheduled_date') === ['2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07', '2026-09-08'], 'тренировки сгруппированы и отсортированы от старой к новой');
$check($emptyExercise['history_sessions'] === [], 'отсутствие истории представлено пустым массивом');

$unknownTarget = array_values(array_filter($history, static fn (array $item): bool => $item['scheduled_date'] === '2026-09-04'))[0];
$latest = $history[5];
$check($unknownTarget['target_rir_min'] === null && $unknownTarget['target_rir_max'] === null, 'неизвестная историческая цель RIR остаётся null');
$check($latest['target_rir_min'] === 1.5 && $latest['target_rir_max'] === 2.5, 'дробный исторический диапазон RIR возвращается из своего плана');
$check(count($latest['sets']) === 2 && array_column($latest['sets'], 'set_number') === [1, 2], 'в группе остаются все рабочие подходы в стабильном порядке');
$check($latest['sets'][0]['weight_value'] === 100.0 && $latest['sets'][0]['weight_unit'] === 'lb' && $latest['sets'][0]['weight_kg'] === 45.359237, 'исходный вес в lb и нормализованный вес передаются вместе');
$check($latest['sets'][1]['weight_value'] === 50.0 && $latest['sets'][1]['weight_unit'] === 'kg' && $latest['sets'][1]['rir'] === 2.5, 'смешанные веса и дробный RIR не теряются');
$check(!in_array(109, array_column($history, 'session_id'), true) && !in_array(120, array_column($history, 'session_id'), true), 'soft-deleted и чужие тренировки исключены');
$check(!in_array(200.0, array_column($latest['sets'], 'weight_value'), true) && !in_array(20.0, array_column($latest['sets'], 'weight_value'), true), 'разминка и soft-deleted подход исключены');

$session = $current;
$error = null;
$success = null;
ob_start();
require dirname(__DIR__) . '/views/sessions/workout.php';
$html = (string) ob_get_clean();
$check((bool) preg_match('/class="weight-input"[^>]+value="50"/', $html), 'форма предзаполняет вес последним подходом последней исторической тренировки');
$check((bool) preg_match('/class="reps-input"[^>]+value="7"/', $html), 'форма предзаполняет повторы последним историческим подходом');
$check(str_contains($html, 'data-history-chart') && str_contains($html, '"session_id":108') && !str_contains($html, 'В прошлый раз'), 'структурированная история передана локальному графику');
$check(str_contains($html, '/assets/exercises/generic.svg') && str_contains($html, 'aria-expanded="false"'), 'неизвестное упражнение получает fallback-пиктограмму и свёрнутую карточку');

// Even when the inspected workout is completed, it must never appear in its own history.
$setInsert->execute(['history-current-working', 1, 130, 130, 1, 'working', 999, 999, 'kg', 1, 0, '2026-09-11 10:10:00', null]);
$pdo->exec("UPDATE workout_sessions SET status='completed',finished_at='2026-09-11 11:00:00' WHERE id=130");
$completedCurrent = $repository->session(130, 1);
$completedHistory = $completedCurrent['exercises'][0]['history_sessions'];
$check(!in_array(130, array_column($completedHistory, 'session_id'), true), 'текущая сессия исключена из собственной истории');
$check(!in_array(999.0, array_column($completedHistory[5]['sets'], 'weight_value'), true), 'подход текущей сессии не попадает в историю');

if ($failures !== []) {
    fwrite(STDERR, "Workout history contract checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Workout history contract checks passed ({$checks}).\n");
