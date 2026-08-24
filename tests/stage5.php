<?php

declare(strict_types=1);

require __DIR__ . '/stage4.php';

use App\Domain\TrainingMetrics;
use App\Service\ProgressionService;
use App\Service\ReportService;

$failures = [];
$checks = 0;

$metricSets = [
    ['set_type' => 'warmup', 'weight_kg' => 20, 'reps' => 10, 'rir' => 5],
    ['set_type' => 'working', 'weight_kg' => 50, 'reps' => 10, 'rir' => 2],
    ['set_type' => 'working', 'weight_kg' => 50, 'reps' => 8, 'rir' => 1],
    ['set_type' => 'working', 'weight_kg' => 100, 'reps' => 100, 'rir' => 0, 'deleted_at' => '2026-01-01'],
];
$check(TrainingMetrics::tonnage($metricSets) === 900.0, 'tonnage считает только неудалённые working sets');
$check(TrainingMetrics::averageRir($metricSets) === 1.5, 'average RIR считает только неудалённые working sets');
$check(TrainingMetrics::epley(60, 10) === 80.0, 'e1RM Epley рассчитывается точно');
$check(TrainingMetrics::epley(0, 10) === null, 'e1RM не строится для нулевого веса');

$progression = new ProgressionService();
$exercise = ['planned_sets' => 2, 'rep_max' => 10, 'target_rir_min' => 1, 'target_rir_max' => 3, 'progression_increment' => 2.5, 'progression_mode' => 'absolute'];
$eligibleSets = [
    ['set_type' => 'working', 'weight_kg' => 60, 'reps' => 10, 'rir' => 1],
    ['set_type' => 'working', 'weight_kg' => 60, 'reps' => 11, 'rir' => 3],
];
$absolute = $progression->suggest($exercise, $eligibleSets);
$check($absolute !== null && $absolute['current_weight_kg'] === 60.0 && $absolute['suggested_weight_kg'] === 62.5, 'absolute double progression проходит на обеих границах RIR');
$percent = $progression->suggest([...$exercise, 'progression_mode' => 'percent', 'progression_increment' => 5], $eligibleSets);
$check($percent !== null && $percent['suggested_weight_kg'] === 63.0, 'percent progression округляется до 0.5 кг');
$check($progression->suggest($exercise, [$eligibleSets[0]]) === null, 'прогрессии нет при неполном числе подходов');
$check($progression->suggest($exercise, [...$eligibleSets, $eligibleSets[1]]) !== null, 'дополнительный успешный working set не блокирует прогрессию');
$check($progression->suggest($exercise, [...$eligibleSets, [...$eligibleSets[1], 'reps' => 9]]) === null, 'дополнительный неуспешный working set блокирует прогрессию');
$check($progression->suggest($exercise, [$eligibleSets[0], [...$eligibleSets[1], 'reps' => 9]]) === null, 'прогрессии нет ниже верхней границы повторов');
$check($progression->suggest($exercise, [[...$eligibleSets[0], 'rir' => 0.5], $eligibleSets[1]]) === null, 'прогрессии нет ниже допустимого RIR');
$check($progression->suggest($exercise, [$eligibleSets[0], [...$eligibleSets[1], 'rir' => 3.5]]) === null, 'прогрессии нет выше допустимого RIR');

$reporter = new ReportService($pdo, $repository);
$report = $reporter->build($offlineSession, 1);
$check($report['schema'] === 'training-report' && $report['schema_version'] === '1.0', 'report имеет маркеры training-report v1.0');
$check(isset($report['exercises'][0]['planned'], $report['exercises'][0]['fact']) && array_key_exists('suggestion', $report['exercises'][0]), 'report разделяет planned, fact и suggestion');
$check($report['exercises'][0]['fact']['substitution']['original_exercise_id'] === 'bench' && $report['exercises'][0]['fact']['substitution']['actual_exercise_id'] === 'row', 'report хранит замену отдельно от плана');
$check(count($report['exercises'][0]['fact']['discomfort']) === 1 && $report['exercises'][0]['fact']['discomfort'][0]['intensity'] === 2, 'report содержит структурированный дискомфорт');
$check($report['exercises'][0]['suggestion']['current_weight_kg'] === 50.0 && $report['exercises'][0]['suggestion']['suggested_weight_kg'] === 52.5, 'report содержит current и suggested progression');
$check(str_ends_with($report['generated_at_utc'], 'Z') && str_ends_with($report['session']['finished_at_utc'], 'Z'), 'timestamps отчёта явно представлены в UTC');
$json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$roundTrip = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$check($roundTrip['session']['session_id'] === $report['session']['session_id'], 'скачиваемый JSON повторно парсится без потери контракта');
$markdown = $reporter->markdown($report);
$check(str_contains($markdown, '**План:**') && str_contains($markdown, '**Факт:**') && str_contains($markdown, '**Предложение:**'), 'Markdown явно разделяет план, факт и предложение');
$check(str_contains($markdown, 'Epley') && str_contains($markdown, 'Программа не изменена автоматически'), 'Markdown объясняет e1RM и отсутствие автоприменения');
$throws(fn () => $reporter->build($offlineSession, 2), 'не найдена', 'tenant isolation запрещает отчёт чужой сессии');

$finishedBeforeEdit = $repository->session($offlineSession, 1);
$setBeforeEdit = $finishedBeforeEdit['exercises'][0]['sets'][0];
$edited = $repository->updateSet((int) $setBeforeEdit['id'], 1, [
    'version' => (int) $setBeforeEdit['version'], 'session_version' => (int) $finishedBeforeEdit['version'],
    'weight_kg' => 55.0, 'reps' => 10, 'rir' => 2.0,
]);
$afterSetEdit = $repository->session($offlineSession, 1);
$check((bool) $afterSetEdit['edited_after_completion'] && $afterSetEdit['edited_at'] !== null, 'правка подхода помечает завершённую тренировку');
$check((float) $afterSetEdit['summary']['tonnage_kg'] === 550.0 && (float) $afterSetEdit['summary']['average_rir'] === 2.0, 'после правки итоговые метрики пересчитываются');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='exercise_set' AND action='update_after_completion'")->fetchColumn() === 1, 'правка завершённого подхода попадает в audit trail');
$check((int) $pdo->query("SELECT COUNT(*) FROM progression_suggestions WHERE workout_session_id={$offlineSession} AND status='pending'")->fetchColumn() === 1, 'после правки progression пересчитывается без дублей');

$editedSession = $repository->updateCompletedSession($offlineSession, 1, [
    'session_version' => (int) $afterSetEdit['version'], 'session_rpe' => 8, 'wellbeing' => 5, 'comment' => 'Исправленный итог',
]);
$check((int) $editedSession['session_rpe'] === 8 && $editedSession['user_comment'] === 'Исправленный итог', 'итоговая оценка редактируется после завершения');
$throws(fn () => $repository->updateCompletedSession($offlineSession, 2, ['session_version' => 1, 'session_rpe' => 1, 'wellbeing' => 1]), 'не найдена', 'tenant isolation запрещает редактирование чужого итога');

$suggestionId = (int) $pdo->query("SELECT id FROM progression_suggestions WHERE workout_session_id={$offlineSession}")->fetchColumn();
$resolved = $repository->resolveProgression($suggestionId, 1, ['status' => 'accepted', 'accepted_weight_kg' => 58.0]);
$check($resolved['accepted_next_weight_kg'] === 58.0 && $resolved['program_changed'] === false, 'accepted progression хранится отдельно и не меняет программу');
$check((float) $pdo->query('SELECT planned_weight_kg FROM workout_exercises WHERE id=3')->fetchColumn() === 50.0, 'принятие suggestion не перезаписывает план');
$throws(fn () => $repository->resolveProgression($suggestionId, 2, ['status' => 'rejected']), 'не найдено', 'tenant isolation запрещает решение по чужой progression');
$editedReport = $reporter->build($offlineSession, 1);
$check($editedReport['session']['edited_after_completion'] === true && $editedReport['exercises'][0]['suggestion']['accepted_weight_kg'] === 58.0, 'report отражает edit flag и accepted progression');
$check(count(array_filter($editedReport['edits'], static fn (array $item): bool => $item['action'] === 'update_after_completion')) >= 2, 'JSON report содержит audit trail правок');

$pdo->exec("UPDATE workout_sessions SET started_at='2026-08-23 09:00:00',finished_at='2026-08-23 10:00:00' WHERE id={$offlineSession}");
$pdo->exec("INSERT INTO workout_plans (id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at) VALUES (4,1,'plan-offline-next','Offline flow','strength','2026-08-26','planned',1,UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO workout_exercises (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,warmup_sets,method_type,instructions) VALUES (4,4,'row',1,1,8,10,1,3,90,60,0,'normal',NULL)");
$nextSession = $repository->startSession(4, 1, ['sleep' => 4, 'energy' => 4, 'readiness' => 4]);
$nextData = $repository->session($nextSession, 1);
$nextExercise = (int) $nextData['exercises'][0]['id'];
$repository->addSet($nextSession, 1, ['session_version' => 1, 'session_exercise_id' => $nextExercise, 'set_number' => 1, 'set_type' => 'working', 'weight_kg' => 60.0, 'reps' => 10, 'rir' => 2.0]);
$repository->setExerciseStatus($nextSession, 1, ['session_version' => 2, 'session_exercise_id' => $nextExercise, 'exercise_version' => 2, 'status' => 'completed', 'exercise_rating' => 'normal']);
$repository->finish($nextSession, 1, ['session_version' => 3, 'session_rpe' => 8, 'wellbeing' => 4]);
$comparisonReport = $reporter->build($nextSession, 1);
$check($comparisonReport['summary']['comparison_with_previous']['tonnage_kg_delta'] === 50.0, 'report сравнивает итог с прошлой такой тренировкой');
$check(count($comparisonReport['summary']['personal_records']) === 0, 'PR показывается ненавязчиво и только при реальном улучшении e1RM');

$routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$check(str_contains($routes, '/export/session/{id}.{format}') && str_contains($routes, '/sessions/{id}/edit'), 'маршруты экспорта и правки подключены');
$check(is_file(dirname(__DIR__) . '/database/migrations/005_stage_5_reports_progression.sql'), 'миграция этапа 5 существует');

if ($failures !== []) {
    fwrite(STDERR, "Stage 5 checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 5 checks passed ({$checks}).\n");
