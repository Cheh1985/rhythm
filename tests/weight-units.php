<?php

declare(strict_types=1);

require __DIR__ . '/stage5.php';
restore_exception_handler(); // Let CLI failures return a nonzero exit status.

use App\Domain\Weight;
use App\Domain\TrainingMetrics;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $fn) use ($assert): void {
    try { $fn(); } catch (InvalidArgumentException|App\Core\VersionConflictException) { return; }
    $assert(false, 'Expected validation or conflict error');
};
$assert(Weight::toKg(100, 'lb') === 45.359237, 'Exact pound conversion');
$assert(Weight::fromKg(45.359237, 'lb') === 100.0, 'Conversion round trip');
$assert(Weight::toKg(null, 'lb') === null, 'Unknown weight stays null');
foreach ([['weight_value'=>10,'weight_unit'=>'lbs'], ['weight_kg'=>10,'weight_value'=>10,'weight_unit'=>'kg'], ['weight_unit'=>'lb'], ['weight_value'=>1.001,'weight_unit'=>'kg'], ['weight_kg'=>INF], ['weight_kg'=>-1]] as $bad) $reject(fn () => Weight::input($bad));
$assert(Weight::input(['weight_kg'=>12.5])['weight_unit'] === 'kg', 'Legacy request means kg');

$pdo->exec("INSERT INTO workout_plans(id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at) VALUES (50,1,'units-test','Units','strength','2026-09-17','planned',1,UTC_TIMESTAMP()),(51,1,'units-next','Units next','strength','2026-09-18','planned',1,UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO workout_exercises(id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,planned_weight_value,planned_weight_unit,warmup_sets,method_type) VALUES (50,50,'bench',1,2,8,10,1,3,90,45.359237,100,'lb',0,'normal'),(51,51,'bench',1,2,8,10,1,3,90,50,50,'kg',0,'normal')");
$sid = $repository->startSession(50, 1, ['sleep'=>4,'energy'=>4,'readiness'=>4]);
$exercise = $repository->session($sid, 1)['exercises'][0];
$assert($exercise['weight_unit'] === 'lb', 'Initial unit comes from plan');
$unitWrite = ['session_version'=>1,'session_exercise_id'=>(int)$exercise['id'],'exercise_version'=>1,'actual_exercise_id'=>'bench','weight_unit'=>'kg','client_action_id'=>'unit-test-0001'];
$unitResult = $repository->changeWeightUnit($sid,1,$unitWrite);
$assert($repository->changeWeightUnit($sid,1,$unitWrite) === $unitResult, 'Unit action is idempotent');
$reject(fn () => $repository->changeWeightUnit($sid,2,$unitWrite));
$reject(fn () => $repository->changeWeightUnit($sid,1,[...$unitWrite,'client_action_id'=>'unit-test-0002']));
$assert($repository->preferredWeightUnit(1,'bench') === 'kg' && $repository->preferredWeightUnit(2,'bench') === 'kg', 'Preference is tenant scoped');
$repository->changeWeightUnit($sid,1,[...$unitWrite,'session_version'=>2,'exercise_version'=>2,'weight_unit'=>'lb','client_action_id'=>'unit-test-0003']);
$assert($repository->preferredWeightUnit(1,'bench') === 'lb' && $repository->preferredWeightUnit(2,'bench') === 'kg', 'One user cannot alter another preference');
$set = $repository->addSet($sid,1,['session_version'=>3,'session_exercise_id'=>(int)$exercise['id'],'set_number'=>1,'set_type'=>'working','weight_value'=>100,'weight_unit'=>'lb','reps'=>10,'rir'=>1.5,'client_action_id'=>'unit-set-0001']);
$assert($set['weight_value'] === 100.0 && $set['weight_kg'] === 45.359237 && $set['weight_unit'] === 'lb', 'Source and kg returned together');
$set2 = $repository->addSet($sid,1,['session_version'=>4,'session_exercise_id'=>(int)$exercise['id'],'set_number'=>2,'set_type'=>'working','weight_kg'=>50,'reps'=>10,'rir'=>2]);
$assert($repository->session($sid,1)['summary']['tonnage_kg'] === 953.59, 'Mixed-unit tonnage');
$partial = $repository->updateSet((int)$set['id'],1,['session_version'=>5,'version'=>1,'reps'=>11]);
$assert($partial['weight_value'] === 100.0 && $partial['weight_unit'] === 'lb', 'Partial edit preserves original pair');
$repository->finish($sid,1,['session_version'=>6,'session_rpe'=>7,'wellbeing'=>4]);
$report = $reporter->build($sid,1);
$assert($report['schema_version'] === '1.1' && $report['exercises'][0]['planned']['weight_unit'] === 'lb', 'Report includes planned source unit');
$assert(str_contains($reporter->markdown($report), '100 lb'), 'Markdown preserves pounds');
$before = $repository->session($sid,1);
$corrected = $repository->updateSet((int)$set2['id'],1,['session_version'=>(int)$before['version'],'version'=>1,'weight_value'=>50,'weight_unit'=>'lb']);
$assert($corrected['weight_kg'] === 22.6796185, 'Manual historical correction keeps the entered number');
$assert($repository->session($sid,1)['summary']['tonnage_kg'] === 725.75, 'Historical correction rebuilds metrics');
$assert(TrainingMetrics::epley(Weight::toKg(100, 'lb'), 10) === 60.48, 'Pound e1RM uses kg');
$suggestion = $pdo->query("SELECT * FROM progression_suggestions WHERE workout_session_id={$sid} AND status='pending'")->fetch();
$accepted = $repository->resolveProgression((int)$suggestion['id'],1,['status'=>'accepted','accepted_weight_value'=>Weight::fromKg((float)$suggestion['suggested_next_weight_kg'],'lb'),'weight_unit'=>'lb']);
$assert($accepted['accepted_next_weight_kg'] === 47.859237, 'Accepting displayed pounds preserves exact suggestion kg');
$assert($repository->preferredWeightUnit(1,'bench') === 'lb', 'Historical editor does not alter preference');
$next = $repository->startSession(51,1,['sleep'=>4,'energy'=>4,'readiness'=>4]);
$nextExercise = $repository->session($next,1)['exercises'][0];
$assert($nextExercise['weight_unit'] === 'lb', 'Preference wins over next kg plan');
$replacement = $repository->replaceExercise($next,1,['session_version'=>1,'session_exercise_id'=>(int)$nextExercise['id'],'exercise_version'=>1,'actual_exercise_id'=>'row','reason'=>'Different equipment']);
$assert($replacement['weight_unit'] === 'kg', 'Replacement uses its own preference');
$assert($pdo->query("SELECT weight_unit FROM exercise_sets WHERE id=".(int)$set['id'])->fetchColumn() === 'lb', 'Saved sets retain units');
$before = $repository->session($sid,1);
$repository->updateSet((int)$set['id'],1,['session_version'=>(int)$before['version'],'version'=>2,'weight_value'=>99,'weight_unit'=>'lb']);
$assert((float)$pdo->query("SELECT accepted_next_weight_kg FROM progression_suggestions WHERE id=".(int)$suggestion['id'])->fetchColumn() === 47.859237, 'Historical rebuild preserves accepted progression');
fwrite(STDOUT, "Weight unit domain, persistence, history and report checks passed.\n");
