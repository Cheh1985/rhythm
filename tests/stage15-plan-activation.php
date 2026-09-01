<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\SameOrigin;
use App\Core\Csrf;
use App\Core\VersionConflictException;
use App\Service\ActivationConfirmationStore;
use App\Service\AssistantAuditService;
use App\Service\ProgramActivationService;
use App\Service\ProgramDraftApplicationService;
use App\Service\ProgramVersionService;

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $passed++;
};
$throws = static function (callable $callback, string $class, string $part, string $message) use ($check): void {
    try { $callback(); }
    catch (Throwable $exception) {
        $check($exception instanceof $class && str_contains($exception->getMessage(), $part), $message . ' (' . $exception->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (исключение не выброшено)');
};

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,login TEXT);
CREATE TABLE exercises(exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT,status TEXT,deleted_at TEXT NULL);
CREATE TABLE training_programs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,external_program_id TEXT NOT NULL,name TEXT NOT NULL,description TEXT NULL,
 status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,archived_at TEXT NULL,deleted_at TEXT NULL,active_version_id INTEGER NULL,
 UNIQUE(user_id,external_program_id),FOREIGN KEY(active_version_id,id) REFERENCES program_versions(id,program_id)
);
CREATE TABLE program_versions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,program_id INTEGER NOT NULL,version_number INTEGER NOT NULL,source TEXT NOT NULL,change_reason TEXT NULL,
 trainer_comment TEXT NULL,snapshot_json TEXT NOT NULL,snapshot_hash TEXT NOT NULL,parent_version_id INTEGER NULL,created_at TEXT NOT NULL,
 lifecycle_status TEXT NOT NULL,lock_version INTEGER NOT NULL,aggregate_hash TEXT NOT NULL,updated_at TEXT NOT NULL,activated_at TEXT NULL,archived_at TEXT NULL,
 UNIQUE(program_id,version_number),UNIQUE(id,program_id),FOREIGN KEY(program_id) REFERENCES training_programs(id),
 FOREIGN KEY(parent_version_id,program_id) REFERENCES program_versions(id,program_id)
);
CREATE TABLE workout_templates(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,program_version_id INTEGER NULL,code TEXT NOT NULL,name TEXT NOT NULL,
 workout_type TEXT NOT NULL,content_json TEXT NOT NULL,content_hash TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL,
 UNIQUE(program_version_id,code),UNIQUE(id,program_version_id),FOREIGN KEY(program_version_id) REFERENCES program_versions(id)
);
CREATE TABLE program_schedule_slots(
 id INTEGER PRIMARY KEY AUTOINCREMENT,program_version_id INTEGER NOT NULL,workout_template_id INTEGER NOT NULL,weekday INTEGER NOT NULL,created_at TEXT NOT NULL,
 UNIQUE(program_version_id,weekday),FOREIGN KEY(program_version_id) REFERENCES program_versions(id),
 FOREIGN KEY(workout_template_id,program_version_id) REFERENCES workout_templates(id,program_version_id)
);
CREATE TABLE workout_plans(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,external_plan_id TEXT NOT NULL,program_version_id INTEGER NULL,workout_template_id INTEGER NULL,
 name TEXT NOT NULL,workout_type TEXT NOT NULL,scheduled_date TEXT NOT NULL,goal TEXT NULL,estimated_duration_min INTEGER NULL,trainer_notes TEXT NULL,
 pre_workout_json TEXT NULL,source_json TEXT NOT NULL,schema_version TEXT NOT NULL,status TEXT NOT NULL,version INTEGER NOT NULL,
 created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL,UNIQUE(user_id,external_plan_id)
);
CREATE TABLE workout_exercises(
 id INTEGER PRIMARY KEY AUTOINCREMENT,workout_plan_id INTEGER NOT NULL,exercise_id TEXT NOT NULL,sequence_no INTEGER NOT NULL,planned_sets INTEGER NOT NULL,
 rep_min INTEGER NOT NULL,rep_max INTEGER NOT NULL,target_rir_min REAL NULL,target_rir_max REAL NULL,rest_seconds INTEGER NOT NULL,
 planned_weight_kg REAL NULL,warmup_sets INTEGER NOT NULL,method_type TEXT NOT NULL,group_id TEXT NULL,instructions TEXT NULL,created_at TEXT NOT NULL,
 UNIQUE(workout_plan_id,sequence_no),FOREIGN KEY(workout_plan_id) REFERENCES workout_plans(id)
);
CREATE TABLE workout_sessions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_plan_id INTEGER NOT NULL,workout_type TEXT NOT NULL,
 status TEXT NOT NULL,started_at TEXT NOT NULL,finished_at TEXT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE audit_logs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,action TEXT NOT NULL,source TEXT NULL,
 request_id TEXT NULL,before_json TEXT NULL,after_json TEXT NULL,ip_address TEXT NULL,created_at TEXT NOT NULL
);
CREATE TABLE assistant_tool_calls(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,request_id TEXT NOT NULL,tool_name TEXT NOT NULL,outcome TEXT NOT NULL,
 entity_type TEXT NULL,entity_id TEXT NULL,error_code TEXT NULL,duration_ms INTEGER NULL,metadata_json TEXT NULL,created_at TEXT NOT NULL
);
CREATE TABLE assistant_write_receipts(
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,idempotency_key TEXT NOT NULL,action_type TEXT NOT NULL,request_hash TEXT NOT NULL,
 response_json TEXT NOT NULL,created_at TEXT NOT NULL,UNIQUE(user_id,idempotency_key)
);
SQL);
$pdo->exec("INSERT INTO users(id,login) VALUES (1,'one'),(2,'two')");
$pdo->exec("INSERT INTO exercises(exercise_id,owner_user_id,name,status) VALUES ('bench_press_001',NULL,'Bench','active'),('row_001',1,'Row','active')");

$exercise = static fn (string $id, string $name, int $order): array => [
    'exercise_id'=>$id,'name'=>$name,'order'=>$order,'sets'=>3,'rep_range'=>['min'=>6,'max'=>10],
    'target_rir'=>['min'=>2,'max'=>3],'rest_seconds'=>120,
];
$template = static fn (string $id, string $name): array => [
    'template_id'=>$id,'name'=>$name,'type'=>'strength','goal'=>'Работа','exercises'=>[$exercise('bench_press_001','Bench',1)],
];
$versions = new ProgramVersionService($pdo);
$v1 = $versions->createProgramDraft(1, [
    'program_id'=>'base-program','name'=>'Base','templates'=>[$template('strength-a','Strength A')],
    'schedule_slots'=>[['weekday'=>2,'template_id'=>'strength-a'],['weekday'=>4,'template_id'=>'strength-a']],
], 'Initial');
$baseProgramId = (int) $pdo->query("SELECT id FROM training_programs WHERE external_program_id='base-program'")->fetchColumn();
$pdo->exec("UPDATE program_versions SET lifecycle_status='published',activated_at=CURRENT_TIMESTAMP WHERE id={$v1['draft_id']}");
$pdo->exec("UPDATE training_programs SET status='active',active_version_id={$v1['draft_id']} WHERE id={$baseProgramId}");
$v2 = $versions->cloneProgramDraft(1, 'base-program', null, 'New weekly structure', 'webmcp');

$other = $versions->createProgramDraft(1, [
    'program_id'=>'other-program','name'=>'Other','templates'=>[$template('other-a','Other A')],
    'schedule_slots'=>[['weekday'=>1,'template_id'=>'other-a']],
], 'Other initial');
$otherProgramId = (int) $pdo->query("SELECT id FROM training_programs WHERE external_program_id='other-program'")->fetchColumn();
$pdo->exec("UPDATE program_versions SET lifecycle_status='published' WHERE id={$other['draft_id']}");
$pdo->exec("UPDATE training_programs SET status='active',active_version_id={$other['draft_id']} WHERE id={$otherProgramId}");

$foreign = $versions->createProgramDraft(2, [
    'program_id'=>'foreign-program','name'=>'Foreign','templates'=>[$template('foreign-a','Foreign A')],
    'schedule_slots'=>[['weekday'=>2,'template_id'=>'foreign-a']],
], 'Foreign initial');

$insertPlan = $pdo->prepare("INSERT INTO workout_plans(user_id,external_plan_id,name,workout_type,scheduled_date,source_json,schema_version,status,version,created_at,updated_at) VALUES (1,?,?, 'strength',?,'{}','1.0',?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$insertPlan->execute(['mutable-plan','Mutable','2026-09-02','planned']);
$mutableId = (int) $pdo->lastInsertId();
$insertLinkedPlan = $pdo->prepare("INSERT INTO workout_plans(user_id,external_plan_id,program_version_id,name,workout_type,scheduled_date,source_json,schema_version,status,version,created_at,updated_at) VALUES (1,?,?,'Linked mutable','strength',?,'{}','1.0','planned',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$insertLinkedPlan->execute(['linked-mutable-plan', $v1['draft_id'], '2026-09-05']);
$linkedMutableId = (int) $pdo->lastInsertId();
$insertPlan->execute(['protected-plan','Protected','2026-09-03','in_progress']);
$protectedId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO workout_sessions(public_id,user_id,workout_plan_id,workout_type,status,started_at,version,created_at,updated_at) VALUES ('session-protected',1,{$protectedId},'strength','in_progress','2026-09-03 10:00:00',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$insertPlan->execute(['completed-plan','Completed','2026-09-04','completed']);
$completedId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO workout_sessions(public_id,user_id,workout_plan_id,workout_type,status,started_at,finished_at,version,created_at,updated_at) VALUES ('session-completed',1,{$completedId},'strength','completed','2026-09-04 10:00:00','2026-09-04 11:00:00',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");

$activation = new ProgramActivationService($pdo);
$keep = $activation->preview(1, $v2['draft_id'], $v2['lock_version'], $v2['aggregate_hash'], '2026-09-01', 1, 'keep');
$check((int) $pdo->query("SELECT active_version_id FROM training_programs WHERE id={$baseProgramId}")->fetchColumn() === $v1['draft_id'], 'prepare preview ничего не активирует');
$check(count($keep['future_plans']['kept']) === 2 && count($keep['future_plans']['superseded']) === 0, 'keep сохраняет связанные и несвязанные future plans');
$check(count($keep['future_plans']['protected']) === 2 && count($keep['future_plans']['created']) === 1 && count($keep['future_plans']['blocked_materialization']) === 1, 'keep показывает protected, created и blocked impact');
$supersede = $activation->preview(1, $v2['draft_id'], $v2['lock_version'], $v2['aggregate_hash'], '2026-09-01', 1, 'supersede');
$check(count($supersede['future_plans']['superseded']) === 1 && $supersede['future_plans']['superseded'][0]['workout_id'] === 'linked-mutable-plan', 'supersede показывает только mutable plan прежней версии той же программы');
$check(count($supersede['future_plans']['kept']) === 1 && $supersede['future_plans']['kept'][0]['workout_id'] === 'mutable-plan', 'supersede сохраняет ручной или несвязанный future plan');
$check($supersede['programs']['will_pause_count'] === 1, 'preview показывает все другие active programs');
$throws(fn () => $activation->preview(2, $v2['draft_id'], 1, $v2['aggregate_hash'], '2026-09-01', 1, 'keep'), InvalidArgumentException::class, 'не найден', 'ownership изолирует draft');
$throws(fn () => $activation->preview(1, $v2['draft_id'], 1, str_repeat('0', 64), '2026-09-01', 1, 'keep'), VersionConflictException::class, 'aggregate_hash', 'stale hash отклонён');
$throws(fn () => $activation->preview(1, $v2['draft_id'], 1, $v2['aggregate_hash'], '2026-09-01', 13, 'keep'), InvalidArgumentException::class, 'от 1 до 12', 'horizon ограничен 1–12 неделями');

$_SESSION = [];
$now = 1000;
$store = new ActivationConfirmationStore(static function () use (&$now): int { return $now; });
$binding = ['user_id'=>1,'draft_id'=>$v2['draft_id'],'lock_version'=>$v2['lock_version'],'aggregate_hash'=>$v2['aggregate_hash'],'effective_from'=>'2026-09-01','horizon_weeks'=>1,'future_plan_policy'=>'supersede'];
$cancelPrepared = $store->prepare(1, $binding, $supersede, 30);
$check($store->peek(1)['confirmation_token'] === $cancelPrepared['confirmation_token'], 'confirmation хранится в текущей session и доступно UI');
$store->cancel(1, $cancelPrepared['confirmation_token']);
$check($store->peek(1) === null && (int) $pdo->query("SELECT active_version_id FROM training_programs WHERE id={$baseProgramId}")->fetchColumn() === $v1['draft_id'], 'cancel потребляет token и не меняет DB');
$throws(fn () => $store->consume(1, $cancelPrepared['confirmation_token']), InvalidArgumentException::class, 'уже использовано', 'cancelled confirmation нельзя replay');
$expired = $store->prepare(1, $binding, $supersede, 2); $now += 3;
$throws(fn () => $store->consume(1, $expired['confirmation_token']), InvalidArgumentException::class, 'истёк', 'expired confirmation отклонено');

$now = 2000;
$prepared = $store->prepare(1, $binding, $supersede, 30);
$confirmed = $store->consume(1, $prepared['confirmation_token']);
$result = $activation->activate($confirmed);
$check($result['lifecycle_status'] === 'published' && count($result['created_workouts']) === 1 && count($result['superseded_workout_ids']) === 1, 'confirm публикует draft и применяет показанный impact');
$base = $pdo->query("SELECT status,active_version_id FROM training_programs WHERE id={$baseProgramId}")->fetch();
$check($base['status'] === 'active' && (int) $base['active_version_id'] === $v2['draft_id'], 'new version становится active pointer');
$check($pdo->query("SELECT status FROM training_programs WHERE id={$otherProgramId}")->fetchColumn() === 'paused', 'предыдущая active программа переведена в paused');
$check($pdo->query("SELECT lifecycle_status FROM program_versions WHERE id={$v1['draft_id']}")->fetchColumn() === 'published', 'old version сохранена immutable published');
$linkedMutable = $pdo->query("SELECT status,deleted_at FROM workout_plans WHERE id={$linkedMutableId}")->fetch();
$unrelatedMutable = $pdo->query("SELECT status,deleted_at FROM workout_plans WHERE id={$mutableId}")->fetch();
$check($linkedMutable['status'] === 'cancelled' && $linkedMutable['deleted_at'] !== null, 'supersede мягко отменяет future planned прежней версии программы');
$check($unrelatedMutable['status'] === 'planned' && $unrelatedMutable['deleted_at'] === null, 'supersede не меняет ручные и несвязанные future plans');
$check($pdo->query("SELECT status FROM workout_plans WHERE id={$protectedId}")->fetchColumn() === 'in_progress' && $pdo->query("SELECT status FROM workout_plans WHERE id={$completedId}")->fetchColumn() === 'completed', 'completed/in-progress/history не изменены');
$check((int) $pdo->query("SELECT COUNT(*) FROM workout_exercises we JOIN workout_plans wp ON wp.id=we.workout_plan_id WHERE wp.program_version_id={$v2['draft_id']}")->fetchColumn() === 1, 'future workout materialized из version schedule/template');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='activate' AND source='manual_confirmation'")->fetchColumn() === 1, 'activation пишет domain audit');
$throws(fn () => $store->consume(1, $prepared['confirmation_token']), InvalidArgumentException::class, 'уже использовано', 'confirmation token нельзя повторно использовать после success');

// A prepared preview becomes stale after any draft mutation.
$v3 = $versions->cloneProgramDraft(1, 'base-program', null, 'Stale candidate', 'webmcp');
$stalePreview = $activation->preview(1, $v3['draft_id'], 1, $v3['aggregate_hash'], '2026-09-15', 1, 'keep');
$staleBinding = ['user_id'=>1,'draft_id'=>$v3['draft_id'],'lock_version'=>1,'aggregate_hash'=>$v3['aggregate_hash'],'effective_from'=>'2026-09-15','horizon_weeks'=>1,'future_plan_policy'=>'keep'];
$staleToken = $store->prepare(1, $staleBinding, $stalePreview, 30);
$versions->updateDraft(1, $v3['draft_id'], 1, 'set_program_metadata', ['description'=>'changed']);
$throws(fn () => $activation->activate($store->consume(1, $staleToken['confirmation_token'])), VersionConflictException::class, 'lock_version', 'stale lock после prepare отклонён');
$check($pdo->query("SELECT lifecycle_status FROM program_versions WHERE id={$v3['draft_id']}")->fetchColumn() === 'draft', 'stale activation оставляет draft');

// Application write idempotency is bound to key + action + canonical request.
$draftApp = new ProgramDraftApplicationService($pdo);
$metadata = ['program_id'=>'idempotent-program','name'=>'Idempotent','templates'=>[$template('idem-a','Idem A')],'schedule_slots'=>[['weekday'=>1,'template_id'=>'idem-a']]];
$idem1 = $draftApp->create(1, $metadata, 'Idempotency test', 'idem-create-0001');
$idem2 = $draftApp->create(1, $metadata, 'Idempotency test', 'idem-create-0001');
$check(!$idem1['idempotent'] && $idem2['idempotent'] && $idem1['draft_id'] === $idem2['draft_id'], 'create draft idempotency возвращает исходный receipt');
$check((int) $pdo->query("SELECT COUNT(*) FROM training_programs WHERE external_program_id='idempotent-program'")->fetchColumn() === 1, 'idempotency не дублирует domain rows');
$throws(fn () => $draftApp->create(1, [...$metadata, 'name'=>'Different'], 'Idempotency test', 'idem-create-0001'), InvalidArgumentException::class, 'другого запроса', 'idempotency key нельзя переиспользовать с другим payload');
$updated1 = $draftApp->update(1, $idem1['draft_id'], 1, 'set_program_metadata', ['description'=>'new'], 'idem-update-0001');
$updated2 = $draftApp->update(1, $idem1['draft_id'], 1, 'set_program_metadata', ['description'=>'new'], 'idem-update-0001');
$check(!$updated1['idempotent'] && $updated2['idempotent'] && $updated2['lock_version'] === 2, 'update draft idempotency выполняет mutation один раз');
$clone1 = $draftApp->clone(1, 'base-program', null, 'Clone active semantic draft', 'idem-clone-0001');
$clone2 = $draftApp->clone(1, 'base-program', null, 'Clone active semantic draft', 'idem-clone-0001');
$check(!$clone1['idempotent'] && $clone2['idempotent'] && $clone1['draft_id'] === $clone2['draft_id'] && $clone1['aggregate']['program']['parent_version'] === 2, 'create draft clone mode идемпотентно клонирует active version');
$check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('draft_create','draft_update','draft_clone')")->fetchColumn() === 3, 'draft writes пишут domain audit без duplicate replay');

// Force failure after publishing and plan insert: the whole activation must roll back.
$v4 = $versions->cloneProgramDraft(1, 'base-program', null, 'Rollback candidate', 'webmcp');
$rollbackPreview = $activation->preview(1, $v4['draft_id'], 1, $v4['aggregate_hash'], '2026-10-06', 1, 'keep');
$rollbackBinding = ['user_id'=>1,'draft_id'=>$v4['draft_id'],'lock_version'=>1,'aggregate_hash'=>$v4['aggregate_hash'],'effective_from'=>'2026-10-06','horizon_weeks'=>1,'future_plan_policy'=>'keep'];
$rollbackToken = $store->prepare(1, $rollbackBinding, $rollbackPreview, 30);
$pdo->exec("CREATE TRIGGER fail_materialization BEFORE INSERT ON workout_exercises BEGIN SELECT RAISE(ABORT,'forced activation failure'); END");
$throws(fn () => $activation->activate($store->consume(1, $rollbackToken['confirmation_token'])), PDOException::class, 'forced activation failure', 'materialization failure пробрасывается');
$pdo->exec('DROP TRIGGER fail_materialization');
$check($pdo->query("SELECT lifecycle_status FROM program_versions WHERE id={$v4['draft_id']}")->fetchColumn() === 'draft' && (int) $pdo->query("SELECT active_version_id FROM training_programs WHERE id={$baseProgramId}")->fetchColumn() === $v2['draft_id'], 'transaction rollback восстанавливает lifecycle и active pointer');
$check((int) $pdo->query("SELECT COUNT(*) FROM workout_plans WHERE program_version_id={$v4['draft_id']}")->fetchColumn() === 0, 'transaction rollback удаляет partial future plans');

putenv('APP_URL=https://rhythm.example');
$check(SameOrigin::isValid('https://rhythm.example') && !SameOrigin::isValid('https://evil.example') && !SameOrigin::isValid(null), 'Origin check принимает только точный same-origin');
$_SESSION['_csrf'] = 'stage15-csrf-token';
$check(Csrf::validate('stage15-csrf-token') && !Csrf::validate('wrong-token') && !Csrf::validate(null), 'CSRF binding принимает только session token');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
(new AssistantAuditService($pdo))->record(1, 'program_activation.prepare', 'success', ['entity_type'=>'program_version','entity_id'=>(string)$v4['draft_id'],'confirmation_required'=>true,'status'=>202]);
$check((int) $pdo->query("SELECT COUNT(*) FROM assistant_tool_calls WHERE tool_name='program_activation.prepare'")->fetchColumn() === 1, 'tool-call audit сохраняется отдельно от domain audit');

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$guard = (string) file_get_contents($root . '/app/Core/SemanticWriteRequestGuard.php');
$adapter = (string) file_get_contents($root . '/public/assets/webmcp.js');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/database/migrations/011_program_activation.sql');
$check(str_contains($routes, '/api/assistant/program-drafts') && str_contains($routes, '/activation/prepare') && str_contains($routes, '/assistant/activation/confirm'), 'semantic draft/prepare и app confirm routes подключены');
$check(str_contains($guard, 'SameOrigin::requireValid') && str_contains($guard, 'HTTP_X_CSRF_TOKEN') && str_contains($guard, 'HTTP_IDEMPOTENCY_KEY'), 'write boundary требует Origin, CSRF и idempotency');
$check(!str_contains($adapter, 'program_drafts.create') && !str_contains($adapter, 'program_activation.prepare'), 'WebMCP write tools на этапе 7 не зарегистрированы');
$check(str_contains($schema, 'assistant_write_receipts') && str_contains($migration, 'uq_assistant_write_receipt'), 'schema и migration содержат payload-bound idempotency receipts');

fwrite(STDOUT, "Stage 15 plan activation checks passed ({$passed}).\n");
