<?php
declare(strict_types=1);
require __DIR__ . '/stage13-program-lifecycle.php';
restore_exception_handler(); // Let CLI failures return a nonzero exit status.

$addWeights = static function (PDO $db): void {
    $db->exec("ALTER TABLE workout_exercises ADD planned_weight_kg REAL NULL; ALTER TABLE workout_exercises ADD planned_weight_value REAL NULL; ALTER TABLE workout_exercises ADD planned_weight_unit TEXT DEFAULT 'kg'; ALTER TABLE session_exercises ADD weight_unit TEXT DEFAULT 'kg'; ALTER TABLE exercise_sets ADD performed_weight_kg REAL NULL; ALTER TABLE exercise_sets ADD weight_value REAL NULL; ALTER TABLE exercise_sets ADD weight_unit TEXT DEFAULT 'kg'");
};
$addWeights($source);
$source->exec("UPDATE workout_exercises SET planned_weight_value=100,planned_weight_unit='lb',planned_weight_kg=45.359237; INSERT INTO exercise_weight_preferences VALUES (1,'custom-actual','lb',CURRENT_TIMESTAMP)");
$source->exec("INSERT INTO workout_sessions(id,user_id,public_id,workout_plan_id,status,started_at,active_duration_seconds,active_segment_started_at) VALUES(80,1,'units-backup-session',50,'in_progress','2026-09-19 10:00:00',300,'2026-09-19 10:05:00'); INSERT INTO session_exercises(id,workout_session_id,workout_exercise_id,original_exercise_id,actual_exercise_id,weight_unit) VALUES(90,80,60,'custom-original','custom-actual','lb'); INSERT INTO exercise_sets(id,user_id,public_id,workout_session_id,session_exercise_id,performed_weight_kg,weight_value,weight_unit) VALUES(100,1,'units-backup-set',80,90,45.359237,100,'lb')");
$backupService = new App\Service\BackupService($source);
$backup = $backupService->export(1);
$valid = $backupService->validate(json_encode($backup, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
$destination = $pdo(); $createLifecycleSchema($destination); $createBackupSupport($destination); $addWeights($destination);
$restorer = new App\Service\BackupService($destination);
$restorer->restore($valid, 1);
$set = $destination->query('SELECT * FROM exercise_sets')->fetch();
if ($set['weight_unit'] !== 'lb' || (float)$set['weight_value'] !== 100.0 || (float)$set['performed_weight_kg'] !== 45.359237) throw new RuntimeException('Backup lost original set weight');
if ($destination->query('SELECT weight_unit FROM exercise_weight_preferences')->fetchColumn() !== 'lb') throw new RuntimeException('Preference was not restored');
if ((int)$destination->query('SELECT active_duration_seconds FROM workout_sessions')->fetchColumn() !== 300) throw new RuntimeException('Active duration was not restored');
if (!$restorer->restore($valid,1)['idempotent']) throw new RuntimeException('Repeated restore was not idempotent');
foreach (['1.0','1.1','1.2'] as $version) {
    $old = $backup; $old['schema_version'] = $version;
    foreach ($old['data']['workout_sessions'] as &$row) { unset($row['active_duration_seconds'],$row['active_segment_started_at']); } unset($row);
    if ($version !== '1.2') unset($old['data']['exercise_weight_preferences']);
    if ($version === '1.0') unset($old['data']['program_schedule_slots']);
    if ($version !== '1.2') {
        foreach ($old['data']['exercise_sets'] as &$row) { unset($row['weight_value'],$row['weight_unit']); $row['performed_weight_kg']=50; } unset($row);
        foreach ($old['data']['workout_exercises'] as &$row) { unset($row['planned_weight_value'],$row['planned_weight_unit']); $row['planned_weight_kg']=50; } unset($row);
        foreach ($old['data']['session_exercises'] as &$row) unset($row['weight_unit']); unset($row);
    }
    $canonical = new ReflectionMethod(App\Service\BackupService::class,'canonical');
    $old['checksum_sha256'] = hash('sha256',$canonical->invoke($backupService,$old['data']));
    $legacyDb=$pdo();$createLifecycleSchema($legacyDb);$createBackupSupport($legacyDb);$addWeights($legacyDb);
    $legacyRestore=new App\Service\BackupService($legacyDb);
    $legacyRestore->restore($legacyRestore->validate(json_encode($old,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),1);
    $legacySet=$legacyDb->query('SELECT * FROM exercise_sets')->fetch();
    if ($version !== '1.2' && ($legacySet['weight_unit'] !== 'kg' || (float)$legacySet['weight_value'] !== 50.0)) throw new RuntimeException('Legacy backup did not default to kg');
    $legacySession=$legacyDb->query('SELECT * FROM workout_sessions')->fetch();
    if ($legacySession['active_segment_started_at'] !== '2026-09-19 10:00:00') throw new RuntimeException('Legacy backup did not reconstruct active timing');
}
fwrite(STDOUT,"Backup v1.3 active timing, weight units and legacy restore checks passed.\n");
