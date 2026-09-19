<?php
declare(strict_types=1);
require __DIR__ . '/stage5.php';
restore_exception_handler(); // Let CLI failures return a nonzero exit status.

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$pdo->exec("INSERT INTO exercises (exercise_id,name) VALUES ('unit-history','Weight history')");
foreach ([60,61,62,63] as $planId) {
    $pdo->exec("INSERT INTO workout_plans (id,user_id,external_plan_id,name,workout_type,scheduled_date,status,version,updated_at) VALUES ({$planId},1,'weight-history-{$planId}','Weight history','strength','2026-09-17','planned',1,UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO workout_exercises (id,workout_plan_id,exercise_id,sequence_no,planned_sets,rep_min,rep_max,target_rir_min,target_rir_max,rest_seconds,planned_weight_kg,planned_weight_value,planned_weight_unit) VALUES ({$planId},{$planId},'unit-history',1,1,8,10,1,3,90,50,50,'kg')");
}
$perform = static function (int $planId, array $weights) use ($repository): array {
    $id = $repository->startSession($planId,1,['sleep'=>4,'energy'=>4,'readiness'=>4]);
    $exercise = $repository->session($id,1)['exercises'][0];
    $sets = [];
    foreach ($weights as $index => [$value,$unit,$reps]) {
        $sets[] = $repository->addSet($id,1,['session_version'=>$index+1,'session_exercise_id'=>(int)$exercise['id'],'set_number'=>$index+1,'set_type'=>'working','weight_value'=>$value,'weight_unit'=>$unit,'reps'=>$reps,'rir'=>2]);
    }
    $repository->finish($id,1,['session_version'=>count($sets)+1,'session_rpe'=>7,'wellbeing'=>4]);
    return [$id,$sets];
};
[$early,$earlySets] = $perform(60,[[100,'kg',10]]);
[$later] = $perform(61,[[60,'kg',10],[45.36,'kg',11]]);
$pdo->exec("UPDATE workout_sessions SET finished_at='2026-09-17 10:00:00' WHERE id={$early}");
$pdo->exec("UPDATE workout_sessions SET finished_at='2026-09-18 10:00:00' WHERE id={$later}");
$countRecord = static function (int $id, string $type) use ($pdo): int {
    $q = $pdo->prepare('SELECT COUNT(*) FROM personal_records WHERE workout_session_id=? AND exercise_id=? AND record_type=?');
    $q->execute([$id,'unit-history',$type]);
    return (int)$q->fetchColumn();
};
$assert($countRecord($later,'max_weight') === 0, 'Later session initially below earlier max');
$suggestion = $pdo->query("SELECT id FROM progression_suggestions WHERE workout_session_id={$early}")->fetchColumn();
$repository->resolveProgression((int)$suggestion,1,['status'=>'accepted']);
$before = $repository->session($early,1);
$repository->updateSet((int)$earlySets[0]['id'],1,['session_version'=>(int)$before['version'],'version'=>1,'weight_value'=>100,'weight_unit'=>'lb']);
$assert($countRecord($later,'max_weight') === 1 && $countRecord($later,'best_e1rm') === 1, 'Correction rebuilds later kg records');
$assert($pdo->query("SELECT achieved_at FROM personal_records WHERE workout_session_id={$later} AND record_type='max_weight'")->fetchColumn() === '2026-09-18 10:00:00', 'Rebuilt records keep the achievement date');
$assert($countRecord($later,'max_reps_at_weight') === 1, 'PHP/SQL group 100 lb and 45.36 kg together for reps');
$report = $reporter->build($later,1);
$assert($report['summary']['comparison_with_previous']['tonnage_kg_delta'] === 645.37, 'Later comparison uses corrected normalized volume');
$assert((float)$pdo->query("SELECT accepted_next_weight_kg FROM progression_suggestions WHERE id={$suggestion}")->fetchColumn() === 102.5, 'Correction does not rewrite accepted progression');
$audit = $pdo->query("SELECT after_json FROM audit_logs WHERE entity_id=".(int)$earlySets[0]['id']." AND entity_type='exercise_set' AND action='update_after_completion' ORDER BY id DESC LIMIT 1")->fetchColumn();
$assert(json_decode($audit,true)['weight_unit'] === 'lb', 'Correction is audited with original unit');

// A second exercise isolates a fractional volume that rounds upward.
$pdo->exec("INSERT INTO exercises (exercise_id,name) VALUES ('unit-rounding','Weight rounding')");
$pdo->exec("UPDATE workout_exercises SET exercise_id='unit-rounding' WHERE id IN (62,63)");
$perform(62,[[51.25,'lb',11]]);
[$repeat] = $perform(63,[[51.25,'lb',11]]);
$q = $pdo->prepare("SELECT COUNT(*) FROM personal_records WHERE workout_session_id=? AND exercise_id='unit-rounding' AND record_type='exercise_tonnage'");
$q->execute([$repeat]);
$assert((int)$q->fetchColumn() === 0, 'Equal fractional volume does not create a rounding-only record');
fwrite(STDOUT, "Weight historical correction, later comparisons and record rounding checks passed.\n");
