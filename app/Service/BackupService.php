<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class BackupService
{
    private const TABLES_V10 = ['custom_exercises','training_programs','program_versions','workout_templates','workout_plans','workout_exercises','workout_sessions','readiness_logs','session_exercises','exercise_sets','discomfort_logs','progression_suggestions','personal_records','body_measurements','schedules','swimming_sessions','swimming_intervals','training_sequence','audit_logs'];
    private const TABLES_V11 = ['custom_exercises','training_programs','program_versions','workout_templates','program_schedule_slots','workout_plans','workout_exercises','workout_sessions','readiness_logs','session_exercises','exercise_sets','discomfort_logs','progression_suggestions','personal_records','body_measurements','schedules','swimming_sessions','swimming_intervals','training_sequence','audit_logs'];
    private const REQUIRED = [
        'custom_exercises'=>['exercise_id','name'],'training_programs'=>['id','external_program_id'],'program_versions'=>['id','program_id','version_number'],
        'workout_templates'=>['id','code'],'program_schedule_slots'=>['id','program_version_id','workout_template_id','weekday'],'workout_plans'=>['id','external_plan_id'],'workout_exercises'=>['id','workout_plan_id','exercise_id','sequence_no'],
        'workout_sessions'=>['id','public_id','workout_plan_id'],'readiness_logs'=>['workout_session_id'],'session_exercises'=>['id','workout_session_id','workout_exercise_id','original_exercise_id','actual_exercise_id'],
        'exercise_sets'=>['id','public_id','workout_session_id','session_exercise_id'],'discomfort_logs'=>['id','workout_session_id','body_area','logged_at'],
        'progression_suggestions'=>['id','workout_session_id','exercise_id'],'personal_records'=>['id','workout_session_id','record_type'],
        'body_measurements'=>['id','measured_on','created_at'],'schedules'=>['id','weekday'],'swimming_sessions'=>['id','public_id'],
        'swimming_intervals'=>['id','swimming_session_id','sequence_no'],'training_sequence'=>['source_kind','public_id','occurred_at','workout_type','name'],'audit_logs'=>['id','entity_type','entity_id','action','created_at'],
    ];

    public function __construct(private readonly ?PDO $connection = null) {}

    public function export(int $userId): array
    {
        if ($userId < 1) throw new InvalidArgumentException('Некорректный пользователь резервной копии.');
        $data = [];
        foreach (self::TABLES_V11 as $table) $data[$table] = $this->exportRows($table, $userId);
        return [
            'schema'=>'training-diary-backup','schema_version'=>'1.1','backup_id'=>'backup-'.bin2hex(random_bytes(16)),
            'exported_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'checksum_sha256'=>hash('sha256',$this->canonical($data)),'data'=>$data,
        ];
    }

    public function validate(string $json): array
    {
        try { $backup=json_decode($json,true,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('Некорректная резервная копия: JSON не читается.'); }
        $root=['schema','schema_version','backup_id','exported_at_utc','checksum_sha256','data'];
        if (!is_array($backup) || array_is_list($backup) || array_diff(array_keys($backup),$root) || array_diff($root,array_keys($backup))) throw new InvalidArgumentException('Резервная копия содержит неизвестные или отсутствующие корневые поля.');
        if ($backup['schema']!=='training-diary-backup' || !in_array($backup['schema_version'],['1.0','1.1'],true)) throw new InvalidArgumentException('Формат резервной копии не поддерживается.');
        if (!is_string($backup['backup_id']) || !preg_match('/^backup-[a-f0-9]{32}$/',$backup['backup_id'])) throw new InvalidArgumentException('Некорректный backup_id.');
        if (!is_string($backup['exported_at_utc']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',$backup['exported_at_utc'])) throw new InvalidArgumentException('Некорректное время экспорта.');
        $data=$backup['data']??null;
        $tables=$this->tablesForVersion((string)$backup['schema_version']);
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data),$tables) || array_diff($tables,array_keys($data))) throw new InvalidArgumentException('Набор секций backup v'.$backup['schema_version'].' неполон или содержит неизвестную секцию.');
        $total=0;
        foreach ($tables as $table) {
            if (!is_array($data[$table]) || !array_is_list($data[$table])) throw new InvalidArgumentException("Секция {$table} должна быть массивом.");
            $total+=count($data[$table]);
            if ($total>200000) throw new InvalidArgumentException('Резервная копия содержит слишком много записей.');
            foreach ($data[$table] as $row) {
                if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException("Секция {$table} содержит некорректную запись.");
                foreach (self::REQUIRED[$table] as $field) if (!array_key_exists($field,$row) || is_array($row[$field]) || is_object($row[$field])) throw new InvalidArgumentException("В секции {$table} отсутствует обязательное поле {$field}.");
                foreach ($row as $value) if (is_array($value)||is_object($value)) throw new InvalidArgumentException("Секция {$table} содержит вложенное значение.");
            }
        }
        $checksum=$backup['checksum_sha256']??'';
        if (!is_string($checksum)||!preg_match('/^[a-f0-9]{64}$/',$checksum)||!hash_equals($checksum,hash('sha256',$this->canonical($data)))) throw new InvalidArgumentException('Контрольная сумма резервной копии не совпадает: файл повреждён или изменён.');
        return $backup;
    }

    public function preview(array $backup,int $userId): array
    {
        $counts=[];$total=0;
        foreach($this->tablesForVersion((string)($backup['schema_version']??'')) as $table){$counts[$table]=count($backup['data'][$table]??[]);$total+=$counts[$table];}
        return ['backup_id'=>$backup['backup_id'],'exported_at_utc'=>$backup['exported_at_utc'],'checksum_sha256'=>$backup['checksum_sha256'],'counts'=>$counts,'total_rows'=>$total,'already_restored'=>$this->restoreExists($userId,(string)$backup['checksum_sha256']),'mode'=>'merge'];
    }

    public function restore(array $backup,int $userId): array
    {
        if($userId<1||($backup['schema']??null)!=='training-diary-backup') throw new InvalidArgumentException('Некорректные данные восстановления.');
        return $this->transaction(function(PDO $pdo)use($backup,$userId):array{
            $checksum=(string)$backup['checksum_sha256'];
            $q=$pdo->prepare('SELECT summary_json FROM backup_restores WHERE user_id=? AND checksum_sha256=?');$q->execute([$userId,$checksum]);
            if($saved=$q->fetchColumn()){ $result=json_decode((string)$saved,true); return is_array($result)?[...$result,'idempotent'=>true]:['idempotent'=>true]; }
            $version=(string)($backup['schema_version']??'');
            $tables=$this->tablesForVersion($version);
            $data=$backup['data'];
            $data['program_schedule_slots']??=[];
            $maps=[];$counts=array_fill_keys($tables,['inserted'=>0,'skipped'=>0]);$counts['training_sequence']['skipped']=count($data['training_sequence']);
            $mark=static function(string $table,bool $inserted)use(&$counts):void{$counts[$table][$inserted?'inserted':'skipped']++;};

            foreach($data['custom_exercises'] as $row){
                $q=$pdo->prepare('SELECT owner_user_id FROM exercises WHERE exercise_id=?');$q->execute([$row['exercise_id']]);$owner=$q->fetchColumn();
                if($owner!==false){if($owner!==null&&(int)$owner!==$userId)throw new InvalidArgumentException('Backup содержит конфликтующий exercise_id.');$mark('custom_exercises',false);continue;}
                $this->insert($pdo,'exercises',$row,['owner_user_id'=>$userId]);$mark('custom_exercises',true);
            }

            $newPrograms=[];$requestedActive=[];
            foreach($data['training_programs'] as $row){
                $id=$this->id($pdo,'SELECT id FROM training_programs WHERE user_id=? AND external_program_id=?',[$userId,$row['external_program_id']]);
                $new=$id===null;
                $id??=$this->insert($pdo,'training_programs',$row,['user_id'=>$userId,'active_version_id'=>null]);
                $maps['training_programs'][(string)$row['id']]=$id;
                $newPrograms[(string)$row['id']]=$new;
                $requestedActive[(string)$row['id']]=$row['active_version_id']??null;
                $mark('training_programs',$new);
            }
            $sourceVersions=[];foreach($data['program_versions'] as $sourceVersion)$sourceVersions[(string)$sourceVersion['id']]=$sourceVersion;
            $pending=$data['program_versions'];
            while($pending){$progress=false;foreach($pending as $key=>$row){
                $program=$maps['training_programs'][(string)$row['program_id']]??null;
                $parentSource=$row['parent_version_id'];
                $parent=$parentSource===null?null:($maps['program_versions'][(string)$parentSource]??null);
                if($program===null||($parentSource!==null&&$parent===null))continue;
                if($parentSource!==null&&(string)($sourceVersions[(string)$parentSource]['program_id']??'')!==(string)$row['program_id'])throw new InvalidArgumentException('Backup содержит родительскую версию из другой программы.');
                $id=$this->id($pdo,'SELECT id FROM program_versions WHERE program_id=? AND version_number=?',[$program,$row['version_number']]);
                $new=$id===null;
                $id??=$this->insert($pdo,'program_versions',$row,[
                    'program_id'=>$program,'parent_version_id'=>$parent,
                    'lifecycle_status'=>$row['lifecycle_status']??'published','lock_version'=>$row['lock_version']??1,
                    'aggregate_hash'=>$row['aggregate_hash']??$row['snapshot_hash'],'updated_at'=>$row['updated_at']??$row['created_at'],
                    'activated_at'=>$row['activated_at']??null,'archived_at'=>$row['archived_at']??null,
                ]);
                $maps['program_versions'][(string)$row['id']]=$id;$mark('program_versions',$new);unset($pending[$key]);$progress=true;
            }if(!$progress)throw new InvalidArgumentException('Backup содержит разорванную цепочку версий.');}
            $sourceTemplates=[];foreach($data['workout_templates'] as $sourceTemplate)$sourceTemplates[(string)$sourceTemplate['id']]=$sourceTemplate;
            foreach($data['workout_templates'] as $row){$version=$row['program_version_id']===null?null:($maps['program_versions'][(string)$row['program_version_id']]??null);if($row['program_version_id']!==null&&$version===null)throw new InvalidArgumentException('Не найдена версия шаблона.');$id=$version===null?$this->id($pdo,'SELECT id FROM workout_templates WHERE user_id=? AND program_version_id IS NULL AND code=? AND content_hash=?',[$userId,$row['code'],$row['content_hash']]):$this->id($pdo,'SELECT id FROM workout_templates WHERE user_id=? AND program_version_id=? AND code=?',[$userId,$version,$row['code']]);$new=$id===null;$id??=$this->insert($pdo,'workout_templates',$row,['user_id'=>$userId,'program_version_id'=>$version]);$maps['workout_templates'][(string)$row['id']]=$id;$mark('workout_templates',$new);}
            foreach($data['program_schedule_slots'] as $row){
                $versionId=$maps['program_versions'][(string)$row['program_version_id']]??null;
                $templateId=$maps['workout_templates'][(string)$row['workout_template_id']]??null;
                $sourceTemplate=$sourceTemplates[(string)$row['workout_template_id']]??null;
                if($versionId===null||$templateId===null||$sourceTemplate===null||(string)$sourceTemplate['program_version_id']!==(string)$row['program_version_id'])throw new InvalidArgumentException('Backup содержит slot с шаблоном из другой версии или tenant.');
                if((int)$row['weekday']<1||(int)$row['weekday']>7)throw new InvalidArgumentException('Backup содержит некорректный weekday program slot.');
                $q=$pdo->prepare('SELECT id,workout_template_id FROM program_schedule_slots WHERE program_version_id=? AND weekday=?');$q->execute([$versionId,$row['weekday']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
                if($existing&&((int)$existing['workout_template_id']!==$templateId))throw new InvalidArgumentException('Backup конфликтует с существующим slot на этот weekday.');
                $new=$existing===false;$id=$new?$this->insert($pdo,'program_schedule_slots',$row,['program_version_id'=>$versionId,'workout_template_id'=>$templateId]):(int)$existing['id'];
                $maps['program_schedule_slots'][(string)$row['id']]=$id;$mark('program_schedule_slots',$new);
            }
            foreach($newPrograms as $sourceProgramId=>$isNew){
                if(!$isNew)continue;
                $targetProgramId=$maps['training_programs'][$sourceProgramId];
                $sourceActive=$requestedActive[$sourceProgramId];
                $targetActive=null;
                if($sourceActive!==null){
                    if((string)($sourceVersions[(string)$sourceActive]['program_id']??'')!==(string)$sourceProgramId)throw new InvalidArgumentException('Backup содержит active_version_id из другой программы.');
                    $targetActive=$maps['program_versions'][(string)$sourceActive]??null;
                    if($targetActive===null)throw new InvalidArgumentException('Backup содержит неизвестный active_version_id.');
                }elseif($version==='1.0'){
                    $q=$pdo->prepare('SELECT MIN(id),COUNT(*) FROM program_versions WHERE program_id=?');$q->execute([$targetProgramId]);$single=$q->fetch(PDO::FETCH_NUM);
                    if((int)$single[1]===1)$targetActive=(int)$single[0];
                }
                if($targetActive!==null){
                    $q=$pdo->prepare('UPDATE training_programs SET active_version_id=? WHERE id=? AND user_id=?');$q->execute([$targetActive,$targetProgramId,$userId]);
                    if($version==='1.0'){$q=$pdo->prepare('UPDATE program_versions SET activated_at=COALESCE(activated_at,created_at) WHERE id=? AND program_id=?');$q->execute([$targetActive,$targetProgramId]);}
                }
            }
            foreach($data['workout_plans'] as $row){$version=$row['program_version_id']===null?null:($maps['program_versions'][(string)$row['program_version_id']]??null);$template=$row['workout_template_id']===null?null:($maps['workout_templates'][(string)$row['workout_template_id']]??null);$id=$this->id($pdo,'SELECT id FROM workout_plans WHERE user_id=? AND external_plan_id=?',[$userId,$row['external_plan_id']]);$new=$id===null;$id??=$this->insert($pdo,'workout_plans',$row,['user_id'=>$userId,'program_version_id'=>$version,'workout_template_id'=>$template]);$maps['workout_plans'][(string)$row['id']]=$id;$mark('workout_plans',$new);}
            foreach($data['workout_exercises'] as $row){$plan=$maps['workout_plans'][(string)$row['workout_plan_id']]??null;$original=(string)($row['original_exercise_id']??$row['exercise_id']);if($plan===null||!$this->exercise($pdo,(string)$row['exercise_id'],$userId)||!$this->exercise($pdo,$original,$userId))throw new InvalidArgumentException('Backup ссылается на недоступное упражнение или план.');$id=$this->id($pdo,'SELECT id FROM workout_exercises WHERE workout_plan_id=? AND sequence_no=?',[$plan,$row['sequence_no']]);$new=$id===null;$id??=$this->insert($pdo,'workout_exercises',$row,['workout_plan_id'=>$plan,'original_exercise_id'=>$original]);$maps['workout_exercises'][(string)$row['id']]=$id;$mark('workout_exercises',$new);}
            foreach($data['workout_sessions'] as $row){$plan=$maps['workout_plans'][(string)$row['workout_plan_id']]??null;if($plan===null)throw new InvalidArgumentException('Backup содержит сессию без плана.');$id=$this->id($pdo,'SELECT id FROM workout_sessions WHERE user_id=? AND public_id=?',[$userId,$row['public_id']]);$new=$id===null;$id??=$this->insert($pdo,'workout_sessions',$row,['user_id'=>$userId,'workout_plan_id'=>$plan]);$maps['workout_sessions'][(string)$row['id']]=$id;$mark('workout_sessions',$new);}
            foreach($data['readiness_logs'] as $row){$session=$maps['workout_sessions'][(string)$row['workout_session_id']]??null;if($session===null)throw new InvalidArgumentException('Backup содержит readiness без сессии.');$id=$this->id($pdo,'SELECT id FROM readiness_logs WHERE user_id=? AND workout_session_id=?',[$userId,$session]);$new=$id===null;if($new)$this->insert($pdo,'readiness_logs',$row,['user_id'=>$userId,'workout_session_id'=>$session]);$mark('readiness_logs',$new);}
            foreach($data['session_exercises'] as $row){$session=$maps['workout_sessions'][(string)$row['workout_session_id']]??null;$planned=$maps['workout_exercises'][(string)$row['workout_exercise_id']]??null;if($session===null||$planned===null||!$this->exercise($pdo,(string)$row['original_exercise_id'],$userId)||!$this->exercise($pdo,(string)$row['actual_exercise_id'],$userId))throw new InvalidArgumentException('Backup содержит упражнение с чужой связью.');$id=$this->id($pdo,'SELECT id FROM session_exercises WHERE workout_session_id=? AND workout_exercise_id=?',[$session,$planned]);$new=$id===null;$id??=$this->insert($pdo,'session_exercises',$row,['workout_session_id'=>$session,'workout_exercise_id'=>$planned]);$maps['session_exercises'][(string)$row['id']]=$id;$mark('session_exercises',$new);}
            foreach($data['exercise_sets'] as $row){$session=$maps['workout_sessions'][(string)$row['workout_session_id']]??null;$exercise=$maps['session_exercises'][(string)$row['session_exercise_id']]??null;if($session===null||$exercise===null)throw new InvalidArgumentException('Backup содержит подход без упражнения.');$id=$this->id($pdo,'SELECT id FROM exercise_sets WHERE user_id=? AND public_id=?',[$userId,$row['public_id']]);$new=$id===null;$id??=$this->insert($pdo,'exercise_sets',$row,['user_id'=>$userId,'workout_session_id'=>$session,'session_exercise_id'=>$exercise,'client_action_id'=>null]);$maps['exercise_sets'][(string)$row['id']]=$id;$mark('exercise_sets',$new);}
            foreach($data['discomfort_logs'] as $row){$session=$maps['workout_sessions'][(string)$row['workout_session_id']]??null;$exercise=$row['session_exercise_id']===null?null:($maps['session_exercises'][(string)$row['session_exercise_id']]??null);if($session===null)throw new InvalidArgumentException('Backup содержит дискомфорт без сессии.');$id=$this->id($pdo,'SELECT id FROM discomfort_logs WHERE user_id=? AND workout_session_id=? AND logged_at=? AND body_area=?',[$userId,$session,$row['logged_at'],$row['body_area']]);$new=$id===null;$id??=$this->insert($pdo,'discomfort_logs',$row,['user_id'=>$userId,'workout_session_id'=>$session,'session_exercise_id'=>$exercise]);$maps['discomfort_logs'][(string)$row['id']]=$id;$mark('discomfort_logs',$new);}
            foreach(['progression_suggestions','personal_records'] as $table)foreach($data[$table] as $row){$session=$maps['workout_sessions'][(string)$row['workout_session_id']]??null;if($session===null)throw new InvalidArgumentException("Backup содержит {$table} без сессии.");if($table==='progression_suggestions'){$sql='SELECT id FROM progression_suggestions WHERE user_id=? AND workout_session_id=? AND exercise_id=?';$args=[$userId,$session,$row['exercise_id']];}else{$sql='SELECT id FROM personal_records WHERE user_id=? AND workout_session_id=? AND ((exercise_id IS NULL AND ? IS NULL) OR exercise_id=?) AND record_type=?';$args=[$userId,$session,$row['exercise_id'],$row['exercise_id'],$row['record_type']];}$id=$this->id($pdo,$sql,$args);$new=$id===null;$id??=$this->insert($pdo,$table,$row,['user_id'=>$userId,'workout_session_id'=>$session]);$maps[$table][(string)$row['id']]=$id;$mark($table,$new);}
            foreach($data['body_measurements'] as $row){$id=$this->id($pdo,'SELECT id FROM body_measurements WHERE user_id=? AND measured_on=? AND created_at=?',[$userId,$row['measured_on'],$row['created_at']]);$new=$id===null;$id??=$this->insert($pdo,'body_measurements',$row,['user_id'=>$userId]);$maps['body_measurements'][(string)$row['id']]=$id;$mark('body_measurements',$new);}
            foreach($data['schedules'] as $row){$id=$this->id($pdo,'SELECT id FROM schedules WHERE user_id=? AND weekday=?',[$userId,$row['weekday']]);$new=$id===null;$id??=$this->insert($pdo,'schedules',$row,['user_id'=>$userId]);$maps['schedules'][(string)$row['id']]=$id;$mark('schedules',$new);}
            foreach($data['swimming_sessions'] as $row){$workout=$row['workout_session_id']===null?null:($maps['workout_sessions'][(string)$row['workout_session_id']]??null);$schedule=$row['schedule_id']===null?null:($maps['schedules'][(string)$row['schedule_id']]??null);$id=$this->id($pdo,'SELECT id FROM swimming_sessions WHERE user_id=? AND public_id=?',[$userId,$row['public_id']]);$new=$id===null;$id??=$this->insert($pdo,'swimming_sessions',$row,['user_id'=>$userId,'workout_session_id'=>$workout,'schedule_id'=>$schedule]);$maps['swimming_sessions'][(string)$row['id']]=$id;$mark('swimming_sessions',$new);}
            foreach($data['swimming_intervals'] as $row){$session=$maps['swimming_sessions'][(string)$row['swimming_session_id']]??null;if($session===null)throw new InvalidArgumentException('Backup содержит блок без плавания.');$id=$this->id($pdo,'SELECT id FROM swimming_intervals WHERE swimming_session_id=? AND sequence_no=?',[$session,$row['sequence_no']]);$new=$id===null;$id??=$this->insert($pdo,'swimming_intervals',$row,['swimming_session_id'=>$session]);$maps['swimming_intervals'][(string)$row['id']]=$id;$mark('swimming_intervals',$new);}
            foreach($data['audit_logs'] as $row){$entity=$this->auditEntity((string)$row['entity_type'],(string)$row['entity_id'],$maps);$id=$this->id($pdo,'SELECT id FROM audit_logs WHERE user_id=? AND entity_type=? AND entity_id=? AND action=? AND created_at=?',[$userId,$row['entity_type'],$entity,$row['action'],$row['created_at']]);$new=$id===null;if($new)$this->insert($pdo,'audit_logs',$row,['user_id'=>$userId,'entity_id'=>$entity,'ip_address'=>null]);$mark('audit_logs',$new);}

            $summary=['backup_id'=>$backup['backup_id'],'checksum_sha256'=>$checksum,'mode'=>'merge','tables'=>$counts,'idempotent'=>false];$encoded=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $q=$pdo->prepare('INSERT INTO backup_restores (user_id,backup_id,checksum_sha256,summary_json,restored_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');$q->execute([$userId,$backup['backup_id'],$checksum,$encoded]);
            $q=$pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,after_json,ip_address,created_at) VALUES (?,'backup_restore',?,'restore_merge',?,?,UTC_TIMESTAMP())");$q->execute([$userId,$backup['backup_id'],$encoded,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null]);
            return $summary;
        });
    }

    private function exportRows(string $table,int $userId):array
    {
        $pdo=$this->pdo();
        if($table==='training_sequence')return(new \App\Repository\TrainingRepository($pdo))->trainingSequence($userId,100);
        if($table==='custom_exercises')$sql='SELECT * FROM exercises WHERE owner_user_id=? ORDER BY exercise_id';
        elseif(in_array($table,['training_programs','workout_templates','workout_plans','workout_sessions','readiness_logs','exercise_sets','discomfort_logs','progression_suggestions','personal_records','body_measurements','schedules','swimming_sessions','audit_logs'],true))$sql="SELECT * FROM {$table} WHERE user_id=? ORDER BY id";
        else $sql=['program_versions'=>'SELECT pv.* FROM program_versions pv JOIN training_programs p ON p.id=pv.program_id WHERE p.user_id=? ORDER BY pv.id','program_schedule_slots'=>'SELECT pss.* FROM program_schedule_slots pss JOIN program_versions pv ON pv.id=pss.program_version_id JOIN training_programs p ON p.id=pv.program_id WHERE p.user_id=? ORDER BY pss.id','workout_exercises'=>'SELECT we.* FROM workout_exercises we JOIN workout_plans p ON p.id=we.workout_plan_id WHERE p.user_id=? ORDER BY we.id','session_exercises'=>'SELECT se.* FROM session_exercises se JOIN workout_sessions ws ON ws.id=se.workout_session_id WHERE ws.user_id=? ORDER BY se.id','swimming_intervals'=>'SELECT si.* FROM swimming_intervals si JOIN swimming_sessions sw ON sw.id=si.swimming_session_id WHERE sw.user_id=? ORDER BY si.id'][$table]??throw new RuntimeException('Неизвестная backup-секция.');
        $q=$pdo->prepare($sql);$q->execute([$userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function restoreExists(int $userId,string $checksum):bool{try{$q=$this->pdo()->prepare('SELECT 1 FROM backup_restores WHERE user_id=? AND checksum_sha256=?');$q->execute([$userId,$checksum]);return(bool)$q->fetchColumn();}catch(\Throwable){return false;}}
    private function insert(PDO $pdo,string $table,array $row,array $overrides=[]):int{$available=array_flip($this->columns($pdo,$table));$payload=array_intersect_key([...$row,...$overrides],$available);unset($payload['id']);if(!$payload)throw new RuntimeException("Нет полей для {$table}.");$names=array_keys($payload);$quoted=implode(',',array_map(static fn($v)=>"`{$v}`",$names));$pdo->prepare("INSERT INTO `{$table}` ({$quoted}) VALUES (".implode(',',array_fill(0,count($names),'?')).')')->execute(array_values($payload));return(int)$pdo->lastInsertId();}
    private function columns(PDO $pdo,string $table):array{static $cache=[];$key=spl_object_id($pdo).$table;if(isset($cache[$key]))return$cache[$key];if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return$cache[$key]=array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC),'name');$q=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');$q->execute([$table]);return$cache[$key]=$q->fetchAll(PDO::FETCH_COLUMN);}
    private function id(PDO $pdo,string $sql,array $args):?int{$q=$pdo->prepare($sql);$q->execute($args);$id=$q->fetchColumn();return$id===false?null:(int)$id;}
    private function exercise(PDO $pdo,string $id,int $userId):bool{$q=$pdo->prepare('SELECT 1 FROM exercises WHERE exercise_id=? AND (owner_user_id IS NULL OR owner_user_id=?)');$q->execute([$id,$userId]);return(bool)$q->fetchColumn();}
    private function auditEntity(string $type,string $id,array $maps):string{$table=['workout_plan'=>'workout_plans','workout_session'=>'workout_sessions','session_exercise'=>'session_exercises','exercise_set'=>'exercise_sets','swimming_session'=>'swimming_sessions','body_measurement'=>'body_measurements'][$type]??null;return$table&&isset($maps[$table][$id])?(string)$maps[$table][$id]:$id;}
    private function tablesForVersion(string $version):array{return match($version){'1.0'=>self::TABLES_V10,'1.1'=>self::TABLES_V11,default=>throw new InvalidArgumentException('Формат резервной копии не поддерживается.')};}
    private function canonical(array $data):string{$sort=function(mixed $v)use(&$sort):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$sort($x);return$v;};return json_encode($sort($data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    private function pdo():PDO{return$this->connection??\db()->pdo();}
    private function transaction(callable $callback):mixed{if($this->connection===null)return\db()->transaction($callback);$this->connection->beginTransaction();try{$result=$callback($this->connection);$this->connection->commit();return$result;}catch(\Throwable $e){if($this->connection->inTransaction())$this->connection->rollBack();throw$e;}}
}
