<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Service/ProgramVersionReconciliationService.php';
require dirname(__DIR__) . '/app/Service/BackupService.php';
require dirname(__DIR__) . '/app/Repository/TrainingRepository.php';
require dirname(__DIR__) . '/app/Core/VersionConflictException.php';
require dirname(__DIR__) . '/app/Domain/Analytics.php';
require dirname(__DIR__) . '/app/Domain/TrainingMetrics.php';

use App\Service\BackupService;
use App\Service\ProgramVersionReconciliationService;

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) throw new RuntimeException("FAILED: {$message}");
    $passed++;
};
$throws = static function (callable $callback, string $messagePart, string $message) use ($check): void {
    try { $callback(); } catch (Throwable $exception) {
        $check(str_contains($exception->getMessage(), $messagePart), $message);
        return;
    }
    throw new RuntimeException("FAILED: {$message}");
};
$pdo = static function (): PDO {
    $connection = new PDO('sqlite::memory:');
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->exec('PRAGMA foreign_keys=ON');
    $connection->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-08-27 10:00:00', 0);
    return $connection;
};
$createLifecycleSchema = static function (PDO $connection): void {
    $connection->exec(<<<'SQL'
CREATE TABLE training_programs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,external_program_id TEXT NOT NULL,name TEXT NOT NULL,
 description TEXT NULL,status TEXT NOT NULL DEFAULT 'active',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
 archived_at TEXT NULL,deleted_at TEXT NULL,active_version_id INTEGER NULL,UNIQUE(user_id,external_program_id),
 FOREIGN KEY(active_version_id,id) REFERENCES program_versions(id,program_id)
);
CREATE TABLE program_versions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,program_id INTEGER NOT NULL,version_number INTEGER NOT NULL,source TEXT NOT NULL DEFAULT 'manual',
 change_reason TEXT NULL,trainer_comment TEXT NULL,snapshot_json TEXT NOT NULL,snapshot_hash TEXT NOT NULL,parent_version_id INTEGER NULL,
 created_at TEXT NOT NULL,lifecycle_status TEXT NOT NULL DEFAULT 'published',lock_version INTEGER NOT NULL DEFAULT 1,
 aggregate_hash TEXT NOT NULL,updated_at TEXT NOT NULL,activated_at TEXT NULL,archived_at TEXT NULL,
 UNIQUE(program_id,version_number),UNIQUE(id,program_id),
 FOREIGN KEY(program_id) REFERENCES training_programs(id),FOREIGN KEY(parent_version_id,program_id) REFERENCES program_versions(id,program_id)
);
CREATE TABLE workout_templates (
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,program_version_id INTEGER NULL,code TEXT NOT NULL,name TEXT NOT NULL,
 workout_type TEXT NOT NULL DEFAULT 'strength',content_json TEXT NOT NULL,content_hash TEXT NOT NULL,created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,deleted_at TEXT NULL,UNIQUE(program_version_id,code),UNIQUE(id,program_version_id),
 FOREIGN KEY(program_version_id) REFERENCES program_versions(id)
);
CREATE TABLE program_schedule_slots (
 id INTEGER PRIMARY KEY AUTOINCREMENT,program_version_id INTEGER NOT NULL,workout_template_id INTEGER NOT NULL,weekday INTEGER NOT NULL CHECK(weekday BETWEEN 1 AND 7),created_at TEXT NOT NULL,
 UNIQUE(program_version_id,weekday),FOREIGN KEY(program_version_id) REFERENCES program_versions(id),
 FOREIGN KEY(workout_template_id,program_version_id) REFERENCES workout_templates(id,program_version_id)
);
SQL);
};
$createBackupSupport = static function (PDO $connection): void {
    $connection->exec(<<<'SQL'
CREATE TABLE exercises(exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NULL);
CREATE TABLE workout_plans(id INTEGER PRIMARY KEY,user_id INTEGER,external_plan_id TEXT,workout_plan_id INTEGER,program_version_id INTEGER,workout_template_id INTEGER,name TEXT,status TEXT,deleted_at TEXT);
CREATE TABLE workout_exercises(id INTEGER PRIMARY KEY,workout_plan_id INTEGER,exercise_id TEXT,sequence_no INTEGER);
CREATE TABLE workout_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,public_id TEXT,workout_plan_id INTEGER,status TEXT,deleted_at TEXT,started_at TEXT,workout_type TEXT);
CREATE TABLE readiness_logs(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER);
CREATE TABLE session_exercises(id INTEGER PRIMARY KEY,workout_session_id INTEGER,workout_exercise_id INTEGER,original_exercise_id TEXT,actual_exercise_id TEXT);
CREATE TABLE exercise_sets(id INTEGER PRIMARY KEY,user_id INTEGER,public_id TEXT,workout_session_id INTEGER,session_exercise_id INTEGER);
CREATE TABLE discomfort_logs(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER,body_area TEXT,logged_at TEXT);
CREATE TABLE progression_suggestions(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER,exercise_id TEXT);
CREATE TABLE personal_records(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER,exercise_id TEXT,record_type TEXT);
CREATE TABLE body_measurements(id INTEGER PRIMARY KEY,user_id INTEGER,measured_on TEXT,created_at TEXT);
CREATE TABLE schedules(id INTEGER PRIMARY KEY,user_id INTEGER,weekday INTEGER);
CREATE TABLE swimming_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,public_id TEXT,workout_session_id INTEGER,schedule_id INTEGER,deleted_at TEXT,occurred_at TEXT,total_distance_m INTEGER);
CREATE TABLE swimming_intervals(id INTEGER PRIMARY KEY,swimming_session_id INTEGER,sequence_no INTEGER);
CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,action TEXT NOT NULL,before_json TEXT NULL,after_json TEXT NULL,ip_address TEXT NULL,created_at TEXT NOT NULL);
CREATE TABLE backup_restores(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,backup_id TEXT NOT NULL,checksum_sha256 TEXT NOT NULL,summary_json TEXT NOT NULL,restored_at TEXT NOT NULL,UNIQUE(user_id,checksum_sha256));
CREATE TABLE assistant_tool_calls(id INTEGER PRIMARY KEY,user_id INTEGER,request_id TEXT);
SQL);
};
$hash = str_repeat('a', 64);

// Reconciliation is conservative and tenant-scoped.
$lifecycle = $pdo();
$createLifecycleSchema($lifecycle);
$lifecycle->exec("INSERT INTO training_programs(id,user_id,external_program_id,name,status,created_at,updated_at) VALUES (1,1,'single','Single','active','2026-01-01','2026-01-01'),(2,1,'ambiguous','Ambiguous','active','2026-01-01','2026-01-01'),(3,2,'other','Other','active','2026-01-01','2026-01-01')");
$insertVersion = $lifecycle->prepare("INSERT INTO program_versions(id,program_id,version_number,snapshot_json,snapshot_hash,created_at,aggregate_hash,updated_at) VALUES (?,?,?,'{}',?,'2026-01-01',?,'2026-01-01')");
foreach ([[11,1,1],[21,2,1],[22,2,2],[31,3,1]] as $row) $insertVersion->execute([$row[0],$row[1],$row[2],$hash,$hash]);
$reconciliation = new ProgramVersionReconciliationService($lifecycle);
$before = $reconciliation->inspect(1);
$check($before[0]['state'] === 'reconcilable' && $before[1]['state'] === 'ambiguous', 'dry-run различает single и ambiguous программы');
$result = $reconciliation->reconcileUnambiguous(1);
$check($result['updated'] === 1 && (int)$lifecycle->query('SELECT active_version_id FROM training_programs WHERE id=1')->fetchColumn() === 11, 'apply связывает только однозначную программу');
$check($lifecycle->query('SELECT active_version_id FROM training_programs WHERE id=2')->fetchColumn() === null, 'несколько версий не выбираются автоматически');
$check($lifecycle->query('SELECT active_version_id FROM training_programs WHERE id=3')->fetchColumn() === null, 'tenant filter не изменяет другого пользователя');
$throws(static fn () => $lifecycle->exec('UPDATE training_programs SET active_version_id=21 WHERE id=1'), 'FOREIGN KEY', 'active pointer не принимает версию другой программы');

// Slots are version-owned and unique per weekday.
$lifecycle->exec("INSERT INTO workout_templates(id,user_id,program_version_id,code,name,content_json,content_hash,created_at,updated_at) VALUES (101,1,11,'a','A','{}','{$hash}','2026-01-01','2026-01-01'),(102,1,21,'b','B','{}','{$hash}','2026-01-01','2026-01-01')");
$lifecycle->exec("INSERT INTO program_schedule_slots(program_version_id,workout_template_id,weekday,created_at) VALUES (11,101,1,'2026-01-01')");
$throws(static fn () => $lifecycle->exec("INSERT INTO program_schedule_slots(program_version_id,workout_template_id,weekday,created_at) VALUES (11,101,1,'2026-01-01')"), 'UNIQUE', 'weekday уникален внутри версии');
$throws(static fn () => $lifecycle->exec("INSERT INTO program_schedule_slots(program_version_id,workout_template_id,weekday,created_at) VALUES (11,102,2,'2026-01-01')"), 'FOREIGN KEY', 'slot не принимает шаблон другой версии');

// Export v1.1 and restore preserve active pointer and slots with remapped ids.
$source = $pdo();$createLifecycleSchema($source);$createBackupSupport($source);
$source->exec("INSERT INTO training_programs(id,user_id,external_program_id,name,status,created_at,updated_at,active_version_id) VALUES (10,1,'roundtrip','Round trip','active','2026-01-01','2026-01-01',NULL)");
$source->exec("INSERT INTO program_versions(id,program_id,version_number,snapshot_json,snapshot_hash,created_at,aggregate_hash,updated_at,activated_at) VALUES (20,10,1,'{}','{$hash}','2026-01-01','{$hash}','2026-01-01','2026-01-01')");
$source->exec('UPDATE training_programs SET active_version_id=20 WHERE id=10');
$source->exec("INSERT INTO workout_templates(id,user_id,program_version_id,code,name,content_json,content_hash,created_at,updated_at) VALUES (30,1,20,'strength-a','A','{}','{$hash}','2026-01-01','2026-01-01')");
$source->exec("INSERT INTO program_schedule_slots(id,program_version_id,workout_template_id,weekday,created_at) VALUES (40,20,30,1,'2026-01-01')");
$export = (new BackupService($source))->export(1);
$check($export['schema_version'] === '1.1' && count($export['data']['program_schedule_slots']) === 1 && !array_key_exists('assistant_tool_calls',$export['data']), 'export v1.1 включает slots и исключает technical audit');
$validated = (new BackupService($source))->validate(json_encode($export, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$target = $pdo();$createLifecycleSchema($target);$createBackupSupport($target);
$restore = new BackupService($target);$restore->restore($validated,7);
$restored = $target->query('SELECT p.user_id,pv.version_number,pss.weekday,wt.code FROM training_programs p JOIN program_versions pv ON pv.id=p.active_version_id AND pv.program_id=p.id JOIN program_schedule_slots pss ON pss.program_version_id=pv.id JOIN workout_templates wt ON wt.id=pss.workout_template_id')->fetch();
$check((int)$restored['user_id'] === 7 && (int)$restored['version_number'] === 1 && (int)$restored['weekday'] === 1 && $restored['code'] === 'strength-a', 'v1.1 round-trip remap сохраняет pointer и slot');

// A legacy v1.0 file remains readable and receives published lifecycle defaults.
$tablesV10 = ['custom_exercises','training_programs','program_versions','workout_templates','workout_plans','workout_exercises','workout_sessions','readiness_logs','session_exercises','exercise_sets','discomfort_logs','progression_suggestions','personal_records','body_measurements','schedules','swimming_sessions','swimming_intervals','training_sequence','audit_logs'];
$legacyData = array_fill_keys($tablesV10, []);
$legacyData['training_programs'] = [['id'=>1,'user_id'=>999,'external_program_id'=>'legacy','name'=>'Legacy','status'=>'active','created_at'=>'2025-01-01','updated_at'=>'2025-01-01','archived_at'=>null,'deleted_at'=>null]];
$legacyData['program_versions'] = [['id'=>2,'program_id'=>1,'version_number'=>1,'source'=>'manual','change_reason'=>null,'trainer_comment'=>null,'snapshot_json'=>'{}','snapshot_hash'=>$hash,'parent_version_id'=>null,'created_at'=>'2025-01-01']];
$normalize = static function (mixed $value) use (&$normalize): mixed { if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$child)$value[$key]=$normalize($child);return $value; };
$legacy = ['schema'=>'training-diary-backup','schema_version'=>'1.0','backup_id'=>'backup-'.str_repeat('b',32),'exported_at_utc'=>'2026-08-27T10:00:00Z','checksum_sha256'=>hash('sha256',json_encode($normalize($legacyData),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)),'data'=>$legacyData];
$legacy = $restore->validate(json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$restore->restore($legacy,8);
$legacyRow = $target->query("SELECT p.user_id,p.active_version_id,pv.lifecycle_status,pv.aggregate_hash FROM training_programs p JOIN program_versions pv ON pv.program_id=p.id WHERE p.external_program_id='legacy'")->fetch();
$check((int)$legacyRow['user_id'] === 8 && $legacyRow['active_version_id'] !== null && $legacyRow['lifecycle_status'] === 'published' && $legacyRow['aggregate_hash'] === $hash, 'restore v1.0 backfill создаёт published single-version pointer');

$root = dirname(__DIR__);
$schema = (string)file_get_contents($root.'/database/schema.sql');
$migration = (string)file_get_contents($root.'/database/migrations/010_program_version_lifecycle.sql');
$queryRepository = (string)file_get_contents($root.'/app/Repository/TrainingQueryRepository.php');
foreach (['active_version_id','lifecycle_status','lock_version','aggregate_hash','program_schedule_slots','uq_program_slots_version_weekday','fk_programs_active_version'] as $fragment) {
    $check(str_contains($schema,$fragment) && str_contains($migration,$fragment), "schema и migration согласованы: {$fragment}");
}
$check(str_contains($queryRepository,'pv.id=p.active_version_id') && !str_contains($queryRepository,'pv2.version_number DESC'), 'effective active version читается через pointer, не MAX/sort');
$check(is_file($root.'/bin/reconcile-program-versions.php'), 'reconciliation command добавлена');

fwrite(STDOUT, "Stage 13 program lifecycle checks passed ({$passed}).\n");
