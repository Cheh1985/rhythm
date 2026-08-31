<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

$databasePath = tempnam(sys_get_temp_dir(), 'rhythm-stage12-db-');
$cookiePath = tempnam(sys_get_temp_dir(), 'rhythm-stage12-cookie-');
if ($databasePath === false || $cookiePath === false) {
    throw new RuntimeException('Не удалось создать временные файлы HTTP-теста.');
}

$pdo = new PDO('sqlite:' . $databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE users (
 id INTEGER PRIMARY KEY,login TEXT NOT NULL,email TEXT NOT NULL,password_hash TEXT NOT NULL,role TEXT NOT NULL,
 timezone TEXT NOT NULL,theme TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE login_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,attempt_key TEXT NOT NULL,ip_address TEXT NOT NULL,successful INTEGER NOT NULL,attempted_at TEXT NOT NULL
);
CREATE TABLE exercises (
 exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NOT NULL,category TEXT NULL,muscle_groups TEXT NULL,
 exercise_type TEXT NOT NULL,equipment TEXT NULL,progression_increment REAL NOT NULL,progression_mode TEXT NOT NULL,
 status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE training_programs (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,external_program_id TEXT NOT NULL,name TEXT NOT NULL,description TEXT NULL,
 status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,archived_at TEXT NULL,deleted_at TEXT NULL,active_version_id INTEGER NULL
);
CREATE TABLE program_versions (
 id INTEGER PRIMARY KEY,program_id INTEGER NOT NULL,version_number INTEGER NOT NULL,source TEXT NOT NULL,change_reason TEXT NULL,
 trainer_comment TEXT NULL,snapshot_json TEXT NOT NULL,snapshot_hash TEXT NOT NULL,parent_version_id INTEGER NULL,created_at TEXT NOT NULL,
 lifecycle_status TEXT NOT NULL,lock_version INTEGER NOT NULL,aggregate_hash TEXT NOT NULL,updated_at TEXT NOT NULL,activated_at TEXT NULL,archived_at TEXT NULL
);
CREATE TABLE workout_templates (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,program_version_id INTEGER NULL,code TEXT NOT NULL,name TEXT NOT NULL,
 workout_type TEXT NOT NULL,content_json TEXT NOT NULL,content_hash TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE workout_plans (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,external_plan_id TEXT NOT NULL,program_version_id INTEGER NULL,workout_template_id INTEGER NULL,
 name TEXT NOT NULL,workout_type TEXT NOT NULL,scheduled_date TEXT NOT NULL,goal TEXT NULL,estimated_duration_min INTEGER NULL,
 trainer_notes TEXT NULL,pre_workout_json TEXT NULL,source_json TEXT NOT NULL,schema_version TEXT NOT NULL,status TEXT NOT NULL,
 version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE workout_exercises (
 id INTEGER PRIMARY KEY,workout_plan_id INTEGER NOT NULL,exercise_id TEXT NOT NULL,sequence_no INTEGER NOT NULL,planned_sets INTEGER NOT NULL,
 rep_min INTEGER NOT NULL,rep_max INTEGER NOT NULL,target_rir_min REAL NULL,target_rir_max REAL NULL,rest_seconds INTEGER NOT NULL,
 planned_weight_kg REAL NULL,warmup_sets INTEGER NOT NULL,method_type TEXT NOT NULL,group_id TEXT NULL,instructions TEXT NULL,created_at TEXT NOT NULL
);
CREATE TABLE workout_sessions (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_plan_id INTEGER NOT NULL,workout_type TEXT NOT NULL,
 status TEXT NOT NULL,started_at TEXT NOT NULL,finished_at TEXT NULL,session_rpe INTEGER NULL,wellbeing INTEGER NULL,user_comment TEXT NULL,
 version INTEGER NOT NULL,edited_after_completion INTEGER NOT NULL,edited_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE session_exercises (
 id INTEGER PRIMARY KEY,workout_session_id INTEGER NOT NULL,workout_exercise_id INTEGER NOT NULL,original_exercise_id TEXT NOT NULL,
 actual_exercise_id TEXT NOT NULL,status TEXT NOT NULL,skip_reason TEXT NULL,substitution_reason TEXT NULL,substituted_at TEXT NULL,
 exercise_rating TEXT NULL,comment TEXT NULL,completed_at TEXT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
);
CREATE TABLE exercise_sets (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_session_id INTEGER NOT NULL,session_exercise_id INTEGER NOT NULL,
 set_number INTEGER NOT NULL,set_type TEXT NOT NULL,method_type TEXT NOT NULL,group_id TEXT NULL,sequence_no INTEGER NOT NULL,
 performed_weight_kg REAL NULL,reps INTEGER NULL,rir REAL NULL,duration_seconds INTEGER NULL,distance_m INTEGER NULL,
 completed_at TEXT NOT NULL,client_action_id TEXT NULL,version INTEGER NOT NULL,edited_at TEXT NULL,deleted_at TEXT NULL
);
CREATE TABLE schedules (
 id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,weekday INTEGER NOT NULL,workout_type TEXT NOT NULL,label TEXT NOT NULL,
 active INTEGER NOT NULL,version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
);
CREATE TABLE swimming_sessions (
 id INTEGER PRIMARY KEY,public_id TEXT NOT NULL,user_id INTEGER NOT NULL,workout_session_id INTEGER NULL,schedule_id INTEGER NULL,
 source TEXT NOT NULL,swim_date TEXT NOT NULL,occurred_at TEXT NOT NULL,duration_minutes INTEGER NOT NULL,pool_length_m INTEGER NOT NULL,
 total_distance_m INTEGER NOT NULL,primary_style TEXT NOT NULL,intensity INTEGER NOT NULL,arms_fatigue INTEGER NOT NULL,
 back_fatigue INTEGER NOT NULL,legs_fatigue INTEGER NOT NULL,wellbeing INTEGER NOT NULL,intervals_json TEXT NULL,comment TEXT NULL,
 version INTEGER NOT NULL,edited_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE assistant_tool_calls (
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,request_id TEXT NOT NULL,tool_name TEXT NOT NULL,
 outcome TEXT NOT NULL,entity_type TEXT NULL,entity_id TEXT NULL,error_code TEXT NULL,duration_ms INTEGER NULL,
 metadata_json TEXT NULL,created_at TEXT NOT NULL
);
SQL);

$passwordHash = password_hash('stage12-password', PASSWORD_DEFAULT);
$insertUser = $pdo->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?,NULL)');
$insertUser->execute([1, 'athlete', 'private@example.test', $passwordHash, 'user', 'Europe/Moscow', 'system', '2025-01-01 00:00:00', '2026-08-01 00:00:00']);
$insertUser->execute([2, 'other', 'other@example.test', $passwordHash, 'user', 'UTC', 'system', '2025-02-01 00:00:00', '2026-08-01 00:00:00']);
$pdo->exec(<<<'SQL'
INSERT INTO exercises VALUES
 ('bench',NULL,'Жим лёжа','chest','["chest","triceps"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('row',NULL,'Тяга штанги','back','["back","biceps"]','strength','barbell',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('fly',NULL,'Сведение рук','chest','["chest"]','strength','cable',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 ('secret',2,'Чужое упражнение','back','["back"]','strength','machine',2.5,'absolute','active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO training_programs VALUES
 (1,1,'base','Базовый цикл','Безопасное описание','active','2026-07-01 00:00:00','2026-08-01 00:00:00',NULL,NULL,2),
 (2,2,'private-program','Чужая программа','private','active','2026-07-01 00:00:00','2026-08-01 00:00:00',NULL,NULL,3);
INSERT INTO program_versions VALUES
 (1,1,1,'manual','Старт','Комментарий','{}','hash-1',NULL,'2026-07-01 00:00:00','published',1,'hash-1','2026-07-01 00:00:00',NULL,NULL),
 (2,1,2,'manual','Прогрессия','Проверить технику','{}','hash-2',1,'2026-08-01 00:00:00','published',1,'hash-2','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL),
 (3,2,1,'manual','Private','Private','{}','hash-3',NULL,'2026-07-01 00:00:00','published',1,'hash-3','2026-07-01 00:00:00','2026-07-01 00:00:00',NULL);
INSERT INTO workout_templates VALUES
 (1,1,2,'strength-a','Силовая A','strength','{}','template-hash','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL),
 (2,2,3,'private','Чужой шаблон','strength','{}','private-hash','2026-08-01 00:00:00','2026-08-01 00:00:00',NULL);
INSERT INTO workout_plans VALUES
 (1,1,'plan-completed',2,1,'Силовая A','strength','2026-08-24','Объём',60,'Техника',NULL,'{}','1.0','completed',3,'2026-08-01 00:00:00','2026-08-24 10:00:00',NULL),
 (2,1,'plan-today',2,1,'Сегодня','strength','2026-08-26','Лёгкая',40,NULL,NULL,'{}','1.0','planned',1,'2026-08-20 00:00:00','2026-08-20 00:00:00',NULL),
 (3,2,'plan-private',3,2,'Чужая тренировка','strength','2026-08-24','Private',50,'Private',NULL,'{}','1.0','completed',2,'2026-08-01 00:00:00','2026-08-24 10:00:00',NULL);
INSERT INTO workout_exercises VALUES
 (1,1,'bench',1,2,8,10,1,3,120,60,1,'normal',NULL,'Контроль','2026-08-01 00:00:00'),
 (2,1,'row',2,1,8,12,1,3,90,50,0,'normal',NULL,NULL,'2026-08-01 00:00:00'),
 (3,2,'bench',1,2,8,10,2,3,120,55,1,'normal',NULL,NULL,'2026-08-20 00:00:00'),
 (4,3,'secret',1,1,5,8,1,2,120,100,0,'normal',NULL,NULL,'2026-08-01 00:00:00');
INSERT INTO workout_sessions VALUES
 (1,'session-public',1,1,'strength','completed','2026-08-23 21:30:00','2026-08-23 22:30:00',8,4,NULL,5,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,'session-private',2,3,'strength','completed','2026-08-24 09:00:00','2026-08-24 10:00:00',9,3,'private',2,0,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
INSERT INTO session_exercises VALUES
 (1,1,1,'bench','row','completed',NULL,'Скамья занята','2026-08-23 21:35:00','normal',NULL,'2026-08-23 22:00:00',3,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,1,2,'row','row','skipped','time',NULL,NULL,NULL,NULL,'2026-08-23 22:00:00',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (3,2,4,'secret','secret','completed',NULL,NULL,NULL,'normal','private','2026-08-24 10:00:00',2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO exercise_sets VALUES
 (1,'set-one',1,1,1,1,'working','normal',NULL,1,60,10,2,NULL,NULL,'2026-08-23 21:45:00',NULL,1,NULL,NULL),
 (2,'set-two',1,1,1,2,'working','normal',NULL,1,50,8,NULL,NULL,NULL,'2026-08-23 21:50:00',NULL,1,NULL,NULL),
 (3,'set-private',2,2,3,1,'working','normal',NULL,1,100,5,1,NULL,NULL,'2026-08-24 09:30:00',NULL,1,NULL,NULL);
INSERT INTO schedules VALUES
 (1,1,3,'strength','Зал',1,2,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,2,3,'strength','Private',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO swimming_sessions VALUES
 (1,'swim-public',1,NULL,NULL,'manual','2026-08-25','2026-08-25 07:00:00',45,25,1500,'Кроль',7,3,2,4,4,NULL,NULL,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (2,'swim-private',2,NULL,NULL,'manual','2026-08-25','2026-08-25 08:00:00',60,25,2000,'Брасс',8,4,3,5,3,NULL,'private',1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
SQL);
unset($pdo);

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketErrorNumber, $socketError);
if ($socket === false) {
    throw new RuntimeException('Не удалось выбрать порт: ' . $socketError);
}
$address = (string) stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr($address, ':'), 1);
$baseUrl = 'http://127.0.0.1:' . $port;

$environment = array_merge(getenv(), [
    'APP_ENV' => 'test',
    'APP_DEBUG' => 'false',
    'APP_URL' => $baseUrl,
    'DB_DSN' => 'sqlite:' . $databasePath,
    'DB_USER' => '',
    'DB_PASSWORD' => '',
    'SESSION_NAME' => 'rhythm_stage12',
    'SESSION_SECURE' => 'false',
    'WEBMCP_ENABLED' => 'true',
    'WEBMCP_READ_ENABLED' => 'true',
    'WEBMCP_READ_RATE_LIMIT' => '4',
    'WEBMCP_READ_RATE_WINDOW_SECONDS' => '60',
]);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, 'router.php'], $descriptors, $pipes, $root, $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Не удалось запустить тестовый HTTP server.');
}
fclose($pipes[0]);

/** @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
$request = static function (string $method, string $path, ?string $body = null, array $headers = []) use ($baseUrl, $cookiePath): array {
    $responseHeaders = [];
    $curl = curl_init($baseUrl . $path);
    $allHeaders = ['Accept: application/json', ...$headers];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_COOKIEJAR => $cookiePath,
        CURLOPT_COOKIEFILE => $cookiePath,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'json' => json_decode($responseBody, true),
    ];
};

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $probe = $request('GET', '/api/assistant/profile', null, ['X-Request-ID: stage12-probe-0001']);
            if ($probe['status'] === 401) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    $check($ready, 'HTTP server запущен и endpoint отвечает');
    if (!$ready) {
        throw new RuntimeException('Тестовый HTTP server не запустился.');
    }

    $unauthorized = $request('GET', '/api/assistant/plans', null, ['X-Request-ID: stage12-unauth-0001']);
    $check($unauthorized['status'] === 401 && ($unauthorized['json']['error']['code'] ?? null) === 'authentication_required', 'без сессии возвращается структурированный 401');
    $check(($unauthorized['json']['error']['request_id'] ?? null) === 'stage12-unauth-0001', '401 содержит correlation ID');
    $unauthorizedPage = $request('GET', '/assistant');
    $check($unauthorizedPage['status'] === 303 && ($unauthorizedPage['headers']['location'] ?? null) === $baseUrl . '/login', '/assistant без сессии перенаправляет на login');
    $check(!str_contains($unauthorizedPage['body'], 'webmcp-tool-catalog'), 'unauthenticated response не раскрывает tool catalog');

    $loginPage = $request('GET', '/login');
    preg_match('/name="_csrf" value="([^"]+)"/', $loginPage['body'], $csrfMatch);
    $check(isset($csrfMatch[1]), 'login form выдаёт CSRF в session cookie flow');
    $loginBody = http_build_query(['_csrf' => $csrfMatch[1] ?? '', 'login' => 'athlete', 'password' => 'stage12-password']);
    $login = $request('POST', '/login', $loginBody, ['Content-Type: application/x-www-form-urlencoded']);
    $check($login['status'] === 303 && ($login['headers']['location'] ?? null) === $baseUrl . '/', 'HTTP login создаёт аутентифицированную сессию');

    $assistantPage = $request('GET', '/assistant');
    $check($assistantPage['status'] === 200 && str_contains($assistantPage['body'], 'id="webmcp-tool-catalog"'), '/assistant доступен только после входа и содержит server catalog');
    $check(str_contains($assistantPage['body'], '/assets/webmcp.js') && str_contains($assistantPage['body'], 'training.get_profile'), '/assistant условно подключает adapter и read tools');
    $check(str_contains(strtolower($assistantPage['headers']['cache-control'] ?? ''), 'no-store'), '/assistant возвращает no-store');
    $check(str_contains($assistantPage['headers']['permissions-policy'] ?? '', 'tools=(self)') && ($assistantPage['headers']['origin-agent-cluster'] ?? null) === '?1', '/assistant получает WebMCP permissions и origin-isolation headers');
    $ordinaryPage = $request('GET', '/');
    $check(!str_contains($ordinaryPage['body'], '/assets/webmcp.js') && !str_contains($ordinaryPage['body'], 'webmcp-tool-catalog'), 'обычные authenticated pages не публикуют tools');

    $profile = $request('GET', '/api/assistant/profile', null, ['X-Request-ID: stage12-profile-0001']);
    $check($profile['status'] === 200 && ($profile['json']['data']['timezone'] ?? null) === 'Europe/Moscow', 'profile context доступен владельцу');
    $check(str_contains(strtolower($profile['headers']['cache-control'] ?? ''), 'no-store'), 'успешный ответ содержит no-store');
    $check(($profile['headers']['x-request-id'] ?? null) === 'stage12-profile-0001' && ($profile['json']['meta']['request_id'] ?? null) === 'stage12-profile-0001', 'success envelope и header используют один correlation ID');

    $versions = $request('GET', '/api/assistant/plans/base/versions');
    $check($versions['status'] === 200 && ($versions['json']['data']['count'] ?? null) === 2, 'list plan versions возвращает обе безопасные версии');
    $specificVersion = $request('GET', '/api/assistant/plans/base/versions/1');
    $check($specificVersion['status'] === 200 && ($specificVersion['json']['data']['version'] ?? null) === 1, 'specific plan version выбирается строго по номеру');
    $invalidRoute = $request('GET', '/api/assistant/plans/base/versions/1abc');
    $check($invalidRoute['status'] === 422 && ($invalidRoute['json']['error']['code'] ?? null) === 'validation_error', 'route value 1abc отклоняется с 422');
    $foreignPlan = $request('GET', '/api/assistant/plans/private-program');
    $check($foreignPlan['status'] === 404 && ($foreignPlan['json']['error']['code'] ?? null) === 'not_found', 'foreign program ID скрыт как 404');
    $foreignWorkout = $request('GET', '/api/assistant/workouts/plan-private');
    $check($foreignWorkout['status'] === 404, 'foreign workout ID скрыт как 404');

    $page1 = $request('GET', '/api/assistant/workouts?from=2026-08-24&to=2026-08-26&limit=1');
    $cursor = $page1['json']['data']['next_cursor'] ?? null;
    $page2 = $request('GET', '/api/assistant/workouts?from=2026-08-24&to=2026-08-26&limit=1&cursor=' . rawurlencode((string) $cursor));
    $firstId = $page1['json']['data']['items'][0]['workout_id'] ?? null;
    $secondId = $page2['json']['data']['items'][0]['workout_id'] ?? null;
    $check($page1['status'] === 200 && is_string($cursor) && $cursor !== '' && $page2['status'] === 200 && $firstId !== $secondId, 'workout pagination проходит через HTTP cursor contract');

    $invalidEnum = $request('GET', '/api/assistant/workouts?type=secret');
    $check($invalidEnum['status'] === 422, 'invalid enum возвращает 422');
    $invalidDate = $request('GET', '/api/assistant/progress?from=2026-02-30&to=2026-03-01');
    $check($invalidDate['status'] === 422, 'invalid date возвращает 422');
    $injectedUser = $request('GET', '/api/assistant/progress?user_id=2');
    $check($injectedUser['status'] === 422, 'user_id отсутствует во входном контракте');
    $oversizedQuery = $request('GET', '/api/assistant/exercises/search?query=' . str_repeat('a', 4200));
    $check($oversizedQuery['status'] === 422, 'oversized query отклоняется до service layer');

    $search1 = $request('GET', '/api/assistant/exercises/search?query=' . rawurlencode('и') . '&limit=1');
    $searchCursor = $search1['json']['data']['next_cursor'] ?? null;
    $search2 = $request('GET', '/api/assistant/exercises/search?query=' . rawurlencode('и') . '&limit=1&cursor=' . rawurlencode((string) $searchCursor));
    $check($search1['status'] === 200 && is_string($searchCursor) && $search2['status'] === 200, 'exercise search поддерживает HTTP pagination');
    $foreignExercise = $request('GET', '/api/assistant/exercises/secret/history?from=2026-08-24&to=2026-08-24');
    $check($foreignExercise['status'] === 404, 'foreign exercise history скрыта как 404');

    $bodyRejected = $request('GET', '/api/assistant/profile', str_repeat('x', 8192), ['Content-Type: application/json']);
    $check($bodyRejected['status'] === 422, 'GET endpoint отклоняет oversized/non-empty body');
    $request('GET', '/api/assistant/profile');
    $request('GET', '/api/assistant/profile');
    $rateLimited = $request('GET', '/api/assistant/profile');
    $check($rateLimited['status'] === 429 && ($rateLimited['json']['error']['code'] ?? null) === 'rate_limit_exceeded', 'per-user/tool rate limit возвращает 429');
    $check(($rateLimited['headers']['retry-after'] ?? null) === '60', '429 сообщает Retry-After');

    $payloads = json_encode([$profile['json'], $versions['json'], $page1['json'], $search1['json']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['private@example.test', 'password_hash', 'snapshot_json', 'source_json', 'internal_plan_id', 'session-private', 'plan-private'] as $forbidden) {
        $check(!str_contains($payloads, $forbidden), 'HTTP DTO не раскрывают ' . $forbidden);
    }
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
}

$auditPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$auditRows = $auditPdo->query('SELECT tool_name,outcome,error_code,metadata_json FROM assistant_tool_calls ORDER BY id')->fetchAll();
$auditJson = json_encode($auditRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(count($auditRows) >= 10, 'tool-call audit записан для success/error/denied');
$check(str_contains($auditJson, 'rate_limit_exceeded') && str_contains($auditJson, 'validation_error'), 'audit фиксирует коды rate/validation без payload');
$check(!str_contains($auditJson, 'stage12-password') && !str_contains($auditJson, 'private@example.test') && !str_contains($auditJson, 'user_id'), 'audit не хранит credentials или raw query');
unset($auditPdo);

@unlink($databasePath);
@unlink($cookiePath);

if ($failures !== []) {
    fwrite(STDERR, "Stage 12 site tools API checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 12 site tools API checks passed ({$checks}).\n");
