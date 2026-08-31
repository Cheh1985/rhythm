<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('APP_DEBUG=true');

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql is required. Example: php -d extension=pdo_mysql tests/mysql-webmcp-stage10.php\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Service\BackupService;

$dsn = getenv('WEBMCP_TEST_MYSQL_DSN') ?: '';
$user = getenv('WEBMCP_TEST_MYSQL_USER') ?: '';
$password = getenv('WEBMCP_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '' || !str_starts_with($dsn, 'mysql:') || str_contains(strtolower($dsn), 'dbname=')) {
    fwrite(STDERR, "Set WEBMCP_TEST_MYSQL_DSN without dbname, for example mysql:host=127.0.0.1;port=3306;charset=utf8mb4.\n");
    exit(2);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
];
$admin = new PDO($dsn, $user, $password, $options);
$suffix = strtolower(bin2hex(random_bytes(6)));
$freshDatabase = 'rhythm_stage10_fresh_' . $suffix;
$migrationDatabase = 'rhythm_stage10_migration_' . $suffix;
$root = dirname(__DIR__);
$checks = 0;
$failure = null;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($label);
};
$database = static fn (string $name): PDO => new PDO($dsn . ';dbname=' . $name, $user, $password, $options);
$execFile = static function (PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read SQL file: ' . $path);
    try {
        $pdo->exec($sql);
    } catch (Throwable $exception) {
        $status = $pdo->query('SHOW ENGINE INNODB STATUS')->fetch(PDO::FETCH_ASSOC);
        $innodb = is_array($status) ? (string) ($status['Status'] ?? '') : '';
        $detail = '';
        if (preg_match('/LATEST FOREIGN KEY ERROR\s+-+\s+(.*?)(?:\n-+\n|$)/s', $innodb, $match) === 1) {
            $detail = ' | ' . trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');
        }
        throw new RuntimeException(basename($path) . ': ' . $exception->getMessage() . $detail, 0, $exception);
    }
};
$canonical = static function (array $data): string {
    $sort = static function (mixed $value) use (&$sort): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $sort($item);
        return $value;
    };
    return json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
};

try {
    $admin->exec("CREATE DATABASE `{$freshDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE DATABASE `{$migrationDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $fresh = $database($freshDatabase);
    $execFile($fresh, $root . '/database/schema.sql');
    $execFile($fresh, $root . '/database/seed.sql');
    $check((int) $fresh->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('assistant_tool_calls','assistant_write_receipts','program_schedule_slots')")->fetchColumn() === 3, 'fresh schema contains WebMCP tables');
    $check((int) $fresh->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='workout_exercises' AND column_name IN ('original_exercise_id','substitution_reason','substituted_at','version')")->fetchColumn() === 4, 'fresh schema contains instance substitution columns');

    $migration = $database($migrationDatabase);
    $migration->exec(<<<'SQL'
CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,entity_type VARCHAR(60) NOT NULL,
 entity_id VARCHAR(80) NOT NULL,action VARCHAR(60) NOT NULL,before_json JSON NULL,after_json JSON NULL,
 ip_address VARCHAR(45) NULL,created_at DATETIME NOT NULL,CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE exercises (exercise_id VARCHAR(80) PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE training_programs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,deleted_at DATETIME NULL) ENGINE=InnoDB;
CREATE TABLE program_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,program_id BIGINT UNSIGNED NOT NULL,snapshot_hash CHAR(64) NOT NULL,
 parent_version_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE workout_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,program_version_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB;
CREATE TABLE workout_exercises (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,exercise_id VARCHAR(80) NOT NULL,instructions TEXT NULL,
 CONSTRAINT fk_workout_exercises_exercise FOREIGN KEY(exercise_id) REFERENCES exercises(exercise_id)
) ENGINE=InnoDB;
SQL);
    foreach (['009_webmcp_foundation.sql','010_program_version_lifecycle.sql','011_program_activation.sql','012_workout_instance_substitutions.sql'] as $migrationFile) {
        $execFile($migration, $root . '/database/migrations/' . $migrationFile);
    }
    $check((int) $migration->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('assistant_tool_calls','assistant_write_receipts','program_schedule_slots')")->fetchColumn() === 3, '009-012 migrations create all WebMCP tables');
    $check((int) $migration->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_name IN ('fk_programs_active_version','fk_program_slots_template_version','fk_workout_exercises_original')")->fetchColumn() === 3, '009-012 migrations create cross-entity constraints');

    $insertUser = $fresh->prepare("INSERT INTO users (login,email,password_hash,role,timezone,theme,created_at,updated_at) VALUES (?,?,?,'user','Europe/Moscow','system',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach ([1,2,3] as $number) $insertUser->execute(["stage10-{$number}", "stage10-{$number}@example.test", password_hash('stage10-password', PASSWORD_DEFAULT)]);
    $userIds = $fresh->query("SELECT id FROM users WHERE login LIKE 'stage10-%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    [$sourceUser, $restoreV11User, $restoreV10User] = array_map('intval', $userIds);
    $fresh->prepare("INSERT INTO training_programs (user_id,external_program_id,name,status,created_at,updated_at) VALUES (?,'stage10-program','Stage 10 program','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$sourceUser]);
    $programId = (int) $fresh->lastInsertId();
    $hash = hash('sha256', '{}');
    $fresh->prepare("INSERT INTO program_versions (program_id,version_number,source,snapshot_json,snapshot_hash,created_at,lifecycle_status,lock_version,aggregate_hash,updated_at,activated_at) VALUES (?,1,'manual',JSON_OBJECT(),?,UTC_TIMESTAMP(),'published',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$programId, $hash, $hash]);
    $versionId = (int) $fresh->lastInsertId();
    $fresh->prepare("UPDATE training_programs SET active_version_id=? WHERE id=?")->execute([$versionId, $programId]);
    $fresh->prepare("INSERT INTO workout_templates (user_id,program_version_id,code,name,workout_type,content_json,content_hash,created_at,updated_at) VALUES (?,?,'stage10-a','Stage 10 A','strength',JSON_OBJECT(),?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$sourceUser, $versionId, $hash]);
    $templateId = (int) $fresh->lastInsertId();
    $fresh->prepare("INSERT INTO program_schedule_slots (program_version_id,workout_template_id,weekday,created_at) VALUES (?,?,1,UTC_TIMESTAMP())")->execute([$versionId, $templateId]);

    $backupService = new BackupService($fresh);
    $v11 = $backupService->export($sourceUser);
    $backupService->validate(json_encode($v11, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $resultV11 = $backupService->restore($v11, $restoreV11User);
    $check(($resultV11['idempotent'] ?? null) === false, 'backup v1.1 restores on MySQL');

    $v10 = $v11;
    $v10['schema_version'] = '1.0';
    unset($v10['data']['program_schedule_slots']);
    $v10['backup_id'] = 'backup-' . bin2hex(random_bytes(16));
    $v10['checksum_sha256'] = hash('sha256', $canonical($v10['data']));
    $backupService->validate(json_encode($v10, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $resultV10 = $backupService->restore($v10, $restoreV10User);
    $check(($resultV10['idempotent'] ?? null) === false, 'backup v1.0 restores on MySQL');
    $check((int) $fresh->query("SELECT COUNT(*) FROM program_schedule_slots pss JOIN program_versions pv ON pv.id=pss.program_version_id JOIN training_programs p ON p.id=pv.program_id WHERE p.user_id={$restoreV11User}")->fetchColumn() === 1, 'v1.1 preserves versioned schedule slot');
    $check((int) $fresh->query("SELECT COUNT(*) FROM training_programs WHERE user_id={$restoreV10User} AND active_version_id IS NOT NULL")->fetchColumn() === 1, 'v1.0 fallback reconciles single active version');

    fwrite(STDOUT, "MySQL WebMCP stage 10 checks passed ({$checks}).\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$freshDatabase}`");
    $admin->exec("DROP DATABASE IF EXISTS `{$migrationDatabase}`");
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, "MySQL WebMCP stage 10 checks failed: {$failure->getMessage()}\n");
    exit(1);
}
