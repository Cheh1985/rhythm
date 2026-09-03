<?php

declare(strict_types=1);

putenv('APP_URL=');
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Router;

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$check(e('<script>') === '&lt;script&gt;', 'HTML escaping');
$check(url('/login') === '/login', 'relative URL');
$hash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
$check(password_verify('correct horse battery staple', $hash), 'password hashing');

$_SESSION = [];
$token = App\Core\Csrf::token();
$check(strlen($token) === 64 && App\Core\Csrf::validate($token), 'CSRF token');
$check(!App\Core\Csrf::validate(str_repeat('0', 64)), 'CSRF rejection');

$captured = null;
$router = new Router();
$router->add('GET', '/plans/{id}', static function (string $id) use (&$captured): void {
    $captured = $id;
});
$router->dispatch('GET', '/plans/42');
$check($captured === '42', 'router parameter');

$css = dirname(__DIR__) . '/public/assets/app.css';
$check(is_file($css) && filesize($css) > 1000, 'mobile CSS asset');
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$check(substr_count($schema, 'CREATE TABLE ') >= 15, 'database schema');
$check(str_contains($schema, 'FOREIGN KEY') && str_contains($schema, 'idx_login_attempts_ip_time'), 'database constraints and indexes');
$check(str_contains($schema, 'external_program_id') && str_contains($schema, 'snapshot_hash') && str_contains($schema, 'content_hash'), 'stage 2 immutable schema');
$check(str_contains($schema, 'CREATE TABLE workout_sessions') && str_contains($schema, 'CREATE TABLE exercise_sets') && str_contains($schema, "status ENUM('pending','active','completed','skipped','waiting')"), 'stage 3 workout schema');
$contract = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/training-plan-v1.0.schema.json'), true);
$check(is_array($contract) && ($contract['properties']['schema']['const'] ?? null) === 'training-plan', 'training-plan JSON Schema');
$routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$check(str_contains($routes, '/plans/import/preview') && str_contains($routes, '/programs') && str_contains($routes, '/exercises'), 'stage 2 routes');
$landing = (string) file_get_contents(dirname(__DIR__) . '/views/landing.php');
$check(str_contains($routes, "[\$web, 'home']") && str_contains($landing, "url('/login')") && str_contains($landing, 'WebMCP'), 'public landing route, login link and exchange modes');
$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$check(str_contains($routes, "'/help'") && is_file(dirname(__DIR__) . '/views/help.php') && str_contains($layout, "url('/help')"), 'user guide route, view and top navigation');
$check(str_contains($routes, '/api/sessions/{id}/sets') && str_contains($routes, '/replace-exercise') && str_contains($routes, '/discomfort'), 'stage 3 API routes');
$workoutJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/workout.js');
$check(str_contains($workoutJs, 'session_version') && str_contains($workoutJs, 'endAt') && str_contains($workoutJs, 'client_action_id'), 'stage 3 autosave and timestamp timer');
$check(is_file(dirname(__DIR__) . '/database/migrations/003_stage_3_workout_flow.sql'), 'stage 3 migration');
$check(is_file(dirname(__DIR__) . '/tests/stage3.php'), 'stage 3 integration checks');
$check(str_contains($schema, 'uq_suggestions_session_exercise') && str_contains($schema, 'uq_records_session_exercise_type'), 'stage 5 derived data uniqueness');
$check(str_contains($routes, '/export/session/{id}.{format}') && str_contains($routes, '/sessions/{id}/edit'), 'stage 5 report and edit routes');
$check(is_file(dirname(__DIR__) . '/database/migrations/005_stage_5_reports_progression.sql'), 'stage 5 migration');
$check(is_file(dirname(__DIR__) . '/tests/stage5.php'), 'stage 5 integration checks');
$check(str_contains($routes, '/history') && str_contains($routes, '/analytics') && str_contains($routes, '/measurements'), 'stage 6 analytics routes');
$check(is_file(dirname(__DIR__) . '/database/migrations/006_stage_6_analytics.sql') && is_file(dirname(__DIR__) . '/tests/stage6.php'), 'stage 6 migration and checks');
$check(str_contains($schema, 'CREATE TABLE swimming_intervals') && str_contains($schema, 'uq_schedules_user_day'), 'stage 7 swimming and schedule schema');
$check(str_contains($routes, '/api/swimming') && str_contains($routes, '/export/swimming/{id}.{format}') && str_contains($routes, '/schedule'), 'stage 7 routes');
$check(is_file(dirname(__DIR__) . '/database/migrations/007_stage_7_swimming_schedule.sql') && is_file(dirname(__DIR__) . '/tests/stage7.php'), 'stage 7 migration and checks');
$reportContract = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/training-report-v1.0.schema.json'), true);
$check(is_array($reportContract) && ($reportContract['properties']['schema']['const'] ?? null) === 'training-report', 'training-report JSON Schema');

if ($failures !== []) {
    fwrite(STDERR, "Smoke checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'Smoke checks passed (' . (29 - count($failures)) . ").\n");
