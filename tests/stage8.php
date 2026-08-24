<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Service/BackupService.php';
require dirname(__DIR__) . '/app/Core/VersionConflictException.php';
require dirname(__DIR__) . '/app/Repository/TrainingRepository.php';

use App\Service\BackupService;
use App\Repository\TrainingRepository;

$passed=0;
$check=function(bool $condition,string $message)use(&$passed):void{if(!$condition)throw new RuntimeException("FAILED: {$message}");$passed++;};
$throws=function(callable $fn,string $part,string $message)use($check):void{try{$fn();}catch(Throwable $e){$check(str_contains($e->getMessage(),$part),$message);return;}throw new RuntimeException("FAILED: {$message}");};
$normalize=function(mixed $value)use(&$normalize):mixed{if(!is_array($value))return$value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as $key=>$child)$value[$key]=$normalize($child);return$value;};
$tables=['custom_exercises','training_programs','program_versions','workout_templates','workout_plans','workout_exercises','workout_sessions','readiness_logs','session_exercises','exercise_sets','discomfort_logs','progression_suggestions','personal_records','body_measurements','schedules','swimming_sessions','swimming_intervals','training_sequence','audit_logs'];
$make=function(array $data)use($normalize):array{return['schema'=>'training-diary-backup','schema_version'=>'1.0','backup_id'=>'backup-'.str_repeat('a',32),'exported_at_utc'=>'2026-08-24T10:00:00Z','checksum_sha256'=>hash('sha256',json_encode($normalize($data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)),'data'=>$data];};
$data=array_fill_keys($tables,[]);$backup=$make($data);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-08-24 10:00:00',0);
$pdo->exec('CREATE TABLE exercises(exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE backup_restores(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,backup_id TEXT NOT NULL,checksum_sha256 TEXT NOT NULL,summary_json TEXT NOT NULL,restored_at TEXT NOT NULL,UNIQUE(user_id,checksum_sha256))');
$pdo->exec('CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,action TEXT NOT NULL,before_json TEXT NULL,after_json TEXT NULL,ip_address TEXT NULL,created_at TEXT NOT NULL)');
$service=new BackupService($pdo);$json=json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$validated=$service->validate($json);$check($validated['schema_version']==='1.0','strict backup v1.0 проходит validation');
$preview=$service->preview($validated,1);$check($preview['total_rows']===0&&!$preview['already_restored'],'preview считает секции и статус idempotency');
$result=$service->restore($validated,1);$check(!$result['idempotent']&&(int)$pdo->query('SELECT COUNT(*) FROM backup_restores')->fetchColumn()===1,'первый restore фиксируется');
$same=$service->restore($validated,1);$check($same['idempotent']&&(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===1,'повтор restore идемпотентен и не дублирует audit');
$other=$service->restore($validated,2);$check(!$other['idempotent']&&(int)$pdo->query('SELECT COUNT(*) FROM backup_restores')->fetchColumn()===2,'checksum изолирован по user_id');
$damaged=$backup;$damaged['exported_at_utc']='2026-08-24T10:00:01Z';$damaged['data']['custom_exercises'][]=['exercise_id'=>'mine','name'=>'Mine'];
$throws(fn()=>$service->validate(json_encode($damaged,JSON_THROW_ON_ERROR)),'Контрольная сумма','изменённые данные отклоняются checksum');
$extra=$backup;$extra['unexpected']=true;$throws(fn()=>$service->validate(json_encode($extra,JSON_THROW_ON_ERROR)),'корневые поля','неизвестное корневое поле отклоняется');
$missing=$backup;unset($missing['data']['audit_logs']);$missing['checksum_sha256']=hash('sha256',json_encode($normalize($missing['data']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));$throws(fn()=>$service->validate(json_encode($missing,JSON_THROW_ON_ERROR)),'Набор секций','неполный набор секций отклоняется');

$rollbackData=$data;$rollbackData['custom_exercises']=[['exercise_id'=>'rollback-ex','name'=>'Rollback']];$rollbackData['training_programs']=[['id'=>99,'external_program_id'=>'broken','name'=>'Broken']];$rollback=$service->validate(json_encode($make($rollbackData),JSON_THROW_ON_ERROR));
$throws(fn()=>$service->restore($rollback,3),'training_programs','ошибка внутри restore пробрасывается');
$check((int)$pdo->query("SELECT COUNT(*) FROM exercises WHERE exercise_id='rollback-ex'")->fetchColumn()===0,'ошибка откатывает ранее вставленное упражнение');

$flow=new PDO('sqlite::memory:');$flow->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$flow->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$flow->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-08-24 11:00:00',0);
$flow->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,theme TEXT,updated_at TEXT,deleted_at TEXT NULL);CREATE TABLE workout_plans(id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,version INTEGER,updated_at TEXT);CREATE TABLE workout_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,workout_plan_id INTEGER,status TEXT,version INTEGER,finished_at TEXT NULL,updated_at TEXT,deleted_at TEXT NULL,edited_after_completion INTEGER DEFAULT 0,edited_at TEXT NULL);CREATE TABLE exercise_sets(id INTEGER PRIMARY KEY,user_id INTEGER,workout_session_id INTEGER,version INTEGER,deleted_at TEXT NULL,edited_at TEXT NULL);CREATE TABLE body_measurements(id INTEGER PRIMARY KEY,user_id INTEGER,deleted_at TEXT NULL,updated_at TEXT);CREATE TABLE swimming_sessions(id INTEGER PRIMARY KEY,user_id INTEGER,version INTEGER,deleted_at TEXT NULL,updated_at TEXT);CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,entity_type TEXT,entity_id TEXT,action TEXT,before_json TEXT,after_json TEXT,ip_address TEXT,created_at TEXT)");
$flow->exec("INSERT INTO users VALUES(1,'system','2026-08-24',NULL),(2,'system','2026-08-24',NULL);INSERT INTO workout_plans VALUES(1,1,'in_progress',1,'2026-08-24');INSERT INTO workout_sessions VALUES(1,1,1,'in_progress',1,NULL,'2026-08-24',NULL,0,NULL),(2,1,1,'in_progress',1,NULL,'2026-08-24',NULL,0,NULL),(3,2,1,'in_progress',1,NULL,'2026-08-24',NULL,0,NULL);INSERT INTO exercise_sets VALUES(1,1,2,1,NULL,NULL),(2,2,3,1,NULL,NULL);INSERT INTO body_measurements VALUES(1,1,NULL,'2026-08-24');INSERT INTO swimming_sessions VALUES(1,1,1,NULL,'2026-08-24')");
$repository=new TrainingRepository($flow);$repository->updateTheme(1,'dark');$check($flow->query('SELECT theme FROM users WHERE id=1')->fetchColumn()==='dark','theme сохраняется только в допустимом enum');
$repository->cancelSession(1,1,1,true);$check($flow->query('SELECT status FROM workout_sessions WHERE id=1')->fetchColumn()==='cancelled'&&$flow->query('SELECT status FROM workout_plans WHERE id=1')->fetchColumn()==='planned','отмена сохраняет историю и возвращает план');
$throws(fn()=>$repository->cancelSession(3,1,1,true),'не найдена','чужую сессию нельзя отменить');
$repository->softDeleteSet(1,1,1,1,true);$check($flow->query('SELECT deleted_at FROM exercise_sets WHERE id=1')->fetchColumn()!==null,'подход удаляется мягко с optimistic lock');
$throws(fn()=>$repository->softDeleteSet(2,1,1,1,true),'не найден','чужой подход нельзя удалить');
$repository->softDeleteMeasurement(1,1,true);$repository->softDeleteSwimming(1,1,1,true);$check($flow->query('SELECT deleted_at FROM body_measurements WHERE id=1')->fetchColumn()!==null&&$flow->query('SELECT deleted_at FROM swimming_sessions WHERE id=1')->fetchColumn()!==null,'измерение и плавание удаляются мягко');
$check((int)$flow->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('cancel','soft_delete')")->fetchColumn()===4,'отмена и soft delete полностью аудитируются');

$root=dirname(__DIR__);$routes=file_get_contents($root.'/public/index.php');$schema=file_get_contents($root.'/database/schema.sql');$source=file_get_contents($root.'/app/Service/BackupService.php');$css=file_get_contents($root.'/public/assets/app.css');$bootstrap=file_get_contents($root.'/bootstrap.php');
$check(str_contains($routes,"'/restore/preview'")&&str_contains($routes,"'/restore/confirm'")&&str_contains($routes,"'/settings/theme'"),'маршруты preview/confirm/theme подключены');
$check(str_contains($schema,'uq_backup_restore_checksum')&&str_contains($schema,'idx_plans_user_status_date'),'schema содержит idempotency и production индекс');
$check(str_contains($source,"'user_id' => \$userId")||str_contains($source,"'user_id'=>\$userId"),'restore принудительно назначает текущего tenant');
$check(str_contains($source,"'client_action_id'=>null")&&str_contains($source,'workout_session_id'),'restore remap не переносит offline idempotency ключи');
$check(str_contains($css,'prefers-color-scheme: dark')&&str_contains($css,'prefers-reduced-motion: reduce')&&str_contains($css,':focus-visible'),'темы, focus и reduced motion оформлены');
$check(str_contains($bootstrap,'Strict-Transport-Security')&&str_contains($bootstrap,"object-src 'none'")&&str_contains($bootstrap,'Cross-Origin-Opener-Policy'),'production security headers подключены');
$check(is_file($root.'/bin/cleanup.php')&&is_file($root.'/database/migrations/008_stage_8_backup_restore.sql'),'cleanup и миграция этапа 8 присутствуют');

fwrite(STDOUT,"Stage 8 checks passed ({$passed}).\n");
