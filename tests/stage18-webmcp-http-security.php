<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$databasePath = tempnam(sys_get_temp_dir(), 'rhythm-stage18-http-');
$cookiePath = tempnam(sys_get_temp_dir(), 'rhythm-stage18-cookie-');
if ($databasePath === false || $cookiePath === false) throw new RuntimeException('Не удалось создать временные файлы.');

$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(<<<'SQL'
CREATE TABLE users (
 id INTEGER PRIMARY KEY,login TEXT NOT NULL,email TEXT NOT NULL,password_hash TEXT NOT NULL,role TEXT NOT NULL,
 timezone TEXT NOT NULL,theme TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL
);
CREATE TABLE assistant_tool_calls (
 id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,request_id TEXT NOT NULL,tool_name TEXT NOT NULL,
 outcome TEXT NOT NULL,entity_type TEXT NULL,entity_id TEXT NULL,error_code TEXT NULL,duration_ms INTEGER NULL,
 metadata_json TEXT NULL,created_at TEXT NOT NULL
);
INSERT INTO users VALUES (1,'stage18','stage18@example.test','not-used','user','Europe/Moscow','system',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL);
SQL);
unset($pdo);

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketErrorNumber, $socketError);
if ($socket === false) throw new RuntimeException('Не удалось выбрать порт: ' . $socketError);
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
    'SESSION_NAME' => 'rhythm_stage18_http',
    'SESSION_SECURE' => 'false',
    'WEBMCP_ENABLED' => 'true',
    'WEBMCP_READ_ENABLED' => 'true',
    'WEBMCP_DRAFT_WRITE_ENABLED' => 'true',
    'WEBMCP_WRITE_RATE_LIMIT' => '2',
    'WEBMCP_WRITE_RATE_WINDOW_SECONDS' => '60',
    'WEBMCP_READ_RATE_LIMIT' => '10',
    'WEBMCP_READ_RATE_WINDOW_SECONDS' => '60',
]);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, 'tests/fixtures/webmcp/guard-endpoint.php'], $descriptors, $pipes, $root, $environment);
if (!is_resource($process)) throw new RuntimeException('Не удалось запустить тестовый HTTP server.');
fclose($pipes[0]);

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};

/** @return array{status:int,headers:array<string,string>,json:mixed,body:string} */
$request = static function (string $method, string $path, ?string $body = null, array $headers = []) use ($baseUrl, $cookiePath): array {
    $responseHeaders = [];
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
        CURLOPT_COOKIEJAR => $cookiePath,
        CURLOPT_COOKIEFILE => $cookiePath,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'headers' => $responseHeaders, 'json' => json_decode($responseBody, true), 'body' => $responseBody];
};

$writeHeaders = static fn (string $action, array $extra = []): array => [
    'Origin: ' . $baseUrl,
    'Sec-Fetch-Site: same-origin',
    'X-CSRF-Token: stage18-http-csrf',
    'Idempotency-Key: ' . $action,
    'Content-Type: application/json',
    ...$extra,
];

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            if ($request('GET', '/session')['status'] === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
            usleep(100000);
        }
    }
    $check($ready, 'test HTTP boundary запущен и session создана');
    if (!$ready) throw new RuntimeException('HTTP boundary не запустился.');

    $valid = $request('POST', '/write/valid.test', '{"value":"ok"}', $writeHeaders('stage18-valid-action'));
    $check($valid['status'] === 200 && ($valid['json']['data']['user'] ?? null) === 1, 'валидный same-origin CSRF write проходит boundary');

    $csrf = $request('POST', '/write/csrf.test', '{}', $writeHeaders('stage18-csrf-action', ['X-CSRF-Token: wrong'])) ;
    $check($csrf['status'] === 419 && ($csrf['json']['error']['code'] ?? null) === 'csrf_failed', 'неверный CSRF возвращает 419');

    $origin = $request('POST', '/write/origin.test', '{}', $writeHeaders('stage18-origin-action', ['Origin: https://evil.example']));
    $check($origin['status'] === 403 && ($origin['json']['error']['code'] ?? null) === 'cross_origin_denied', 'чужой Origin возвращает 403');

    $fetchMetadata = $request('POST', '/write/fetch.test', '{}', $writeHeaders('stage18-fetch-action', ['Sec-Fetch-Site: cross-site']));
    $check($fetchMetadata['status'] === 403 && ($fetchMetadata['json']['error']['code'] ?? null) === 'cross_origin_denied', 'cross-site Fetch Metadata возвращает 403');

    $safari = $request('POST', '/write/safari.test', '{"value":"ok"}', $writeHeaders('stage18-safari-action', ['Sec-Fetch-Site:']));
    $check($safari['status'] === 200, 'отсутствующий Fetch Metadata не ломает Safari write с Origin/CSRF (HTTP ' . $safari['status'] . ')');

    $oversized = $request('POST', '/write/size.test', '{"value":"' . str_repeat('x', 1024 * 1024) . '"}', $writeHeaders('stage18-size-action'));
    $check($oversized['status'] === 422 && ($oversized['json']['error']['code'] ?? null) === 'validation_error', 'write body больше 1 MiB отклоняется с 422');

    $wrongType = $request('POST', '/write/type.test', '{}', $writeHeaders('stage18-type-action', ['Content-Type: text/plain']));
    $check($wrongType['status'] === 415 && ($wrongType['json']['error']['code'] ?? null) === 'unsupported_media_type', 'write требует application/json');

    $rateOne = $request('POST', '/write/rate.test', '{"value":1}', $writeHeaders('stage18-rate-action-1'));
    $rateTwo = $request('POST', '/write/rate.test', '{"value":2}', $writeHeaders('stage18-rate-action-2'));
    $rateThree = $request('POST', '/write/rate.test', '{"value":3}', $writeHeaders('stage18-rate-action-3'));
    $check($rateOne['status'] === 200 && $rateTwo['status'] === 200 && $rateThree['status'] === 429, 'write rate limit срабатывает после двух calls (' . $rateOne['status'] . '/' . $rateTwo['status'] . '/' . $rateThree['status'] . ')');
    $check(($rateThree['headers']['retry-after'] ?? null) === '60', 'write rate limit возвращает Retry-After');

    $readFetch = $request('GET', '/read/read.fetch', null, ['Sec-Fetch-Site: same-site']);
    $check($readFetch['status'] === 403 && ($readFetch['json']['error']['code'] ?? null) === 'cross_origin_denied', 'read boundary также отклоняет declared non-same-origin');

    $missingLength = $request('GET', '/read/length.missing');
    $check($missingLength['status'] === 200, 'read принимает отсутствующий Content-Length');

    $emptyLength = $request('GET', '/read/length.empty');
    $check($emptyLength['status'] === 200, 'read принимает FastCGI CONTENT_LENGTH с пустой строкой');

    $zeroLength = $request('GET', '/read/length.zero');
    $check($zeroLength['status'] === 200, 'read принимает нулевой Content-Length');

    $positiveLength = $request('GET', '/read/length.positive');
    $check($positiveLength['status'] === 422 && ($positiveLength['json']['error']['code'] ?? null) === 'validation_error', 'read отклоняет положительный Content-Length');

    $invalidLength = $request('GET', '/read/length.invalid');
    $check($invalidLength['status'] === 422 && ($invalidLength['json']['error']['code'] ?? null) === 'validation_error', 'read отклоняет нечисловой Content-Length');

    $negativeLength = $request('GET', '/read/length.negative');
    $check($negativeLength['status'] === 422 && ($negativeLength['json']['error']['code'] ?? null) === 'validation_error', 'read отклоняет отрицательный Content-Length');

    $transferEncoding = $request('GET', '/read/transfer.encoding');
    $check($transferEncoding['status'] === 422 && ($transferEncoding['json']['error']['code'] ?? null) === 'validation_error', 'read отклоняет Transfer-Encoding');
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($process);
}

$audit = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$auditRows = $audit->query('SELECT tool_name,outcome,error_code,metadata_json FROM assistant_tool_calls ORDER BY id')->fetchAll();
$auditJson = json_encode($auditRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(str_contains($auditJson, 'csrf_failed') && str_contains($auditJson, 'cross_origin_denied') && str_contains($auditJson, 'rate_limit_exceeded'), 'security denials попадают в compact assistant audit');
$check(!str_contains($auditJson, 'stage18-http-csrf') && !str_contains($auditJson, str_repeat('x', 100)), 'audit не хранит CSRF или body');
unset($audit);

@unlink($databasePath);
@unlink($cookiePath);

if ($failures !== []) {
    fwrite(STDERR, "Stage 18 WebMCP HTTP security checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 18 WebMCP HTTP security checks passed ({$checks}).\n");
