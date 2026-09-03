<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('DB_DSN=sqlite::memory:');
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Locale;
use App\Core\SystemContent;
use App\Repository\TrainingQueryRepository;
use App\Repository\TrainingRepository;
use App\WebMcp\ToolCatalog;

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$_SESSION = [];
Locale::set('en');
$check(locale() === 'en' && t('Сегодня') === 'Today', 'locale и PHP-каталог переключаются на EN');
$check(local_date('2026-09-03') === '3 Sep 2026', 'EN-дата использует недвусмысленный international format');
$check(unit('kg') === 'kg' && system_label('workout_type', 'swimming') === 'Swimming', 'единицы и системные enum локализуются');
$localizedTools = SystemContent::localize(ToolCatalog::enabled(true, true, true, true));
$check(count($localizedTools) === 17 && is_array($localizedTools[12]['inputSchema']['properties']['metadata'] ?? null), 'полный WebMCP-каталог безопасно проходит EN-локализацию');

Locale::beginRender();
$userText = e('Ритм — пользовательская заметка');
$translated = Locale::translateMarkup('<p>Ритм</p><div>' . $userText . '</div>');
$check(str_contains($translated, '<p>Rhythm</p>') && str_contains($translated, 'Ритм — пользовательская заметка'), 'шаблон переводится без изменения пользовательского текста');

$_SERVER['REQUEST_URI'] = '/help';
ob_start();
render('help', [], 'Как пользоваться');
$help = (string) ob_get_clean();
$check(str_contains($help, '<html lang="en"') && str_contains($help, 'How to use Rhythm'), 'справка полностью рендерится в EN');
$check(!preg_match('/[А-Яа-яЁё]/u', strip_tags($help)), 'в статическом EN-интерфейсе справки нет кириллицы');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$pdo->exec(<<<'SQL'
CREATE TABLE users(id INTEGER PRIMARY KEY,locale TEXT NOT NULL,updated_at TEXT NOT NULL,deleted_at TEXT NULL);
CREATE TABLE exercises(exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NOT NULL,category TEXT NULL,muscle_groups TEXT NULL,exercise_type TEXT NOT NULL,equipment TEXT NULL,progression_increment REAL NOT NULL DEFAULT 2.5,progression_mode TEXT NOT NULL DEFAULT 'absolute',status TEXT NOT NULL,deleted_at TEXT NULL);
CREATE TABLE exercise_translations(exercise_id TEXT NOT NULL,locale TEXT NOT NULL,name TEXT NOT NULL,PRIMARY KEY(exercise_id,locale));
INSERT INTO users VALUES(1,'ru','2026-09-03',NULL);
INSERT INTO exercises VALUES('bench_press_001',NULL,'Жим лёжа','chest','["chest"]','strength','barbell',2.5,'absolute','active',NULL);
INSERT INTO exercises VALUES('custom',1,'Мой жим','chest','["chest"]','strength','barbell',2.5,'absolute','active',NULL);
INSERT INTO exercise_translations VALUES('bench_press_001','en','Bench Press');
SQL);
$rows = (new TrainingQueryRepository($pdo))->exerciseSearchRows(1, 'Bench', null, 10);
$check(count($rows) === 1 && $rows[0]['name'] === 'Bench Press', 'системное упражнение находится по EN-названию');
$custom = (new TrainingQueryRepository($pdo))->exerciseSearchRows(1, 'Мой', null, 10);
$check(count($custom) === 1 && $custom[0]['name'] === 'Мой жим', 'пользовательское название не переводится');
$directory = (new TrainingRepository($pdo))->exercises(1);
$check($directory[0]['name'] === 'Bench Press' && $directory[1]['name'] === 'Мой жим', 'EN-справочник переводит только системное упражнение');
(new TrainingRepository($pdo))->updateLocale(1, 'en');
$check($pdo->query('SELECT locale FROM users WHERE id=1')->fetchColumn() === 'en', 'locale сохраняется в профиле');

$root = dirname(__DIR__);
$schema = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/database/migrations/013_localization.sql');
$routes = (string) file_get_contents($root . '/public/index.php');
$check(str_contains($schema, "locale ENUM('ru','en')") && str_contains($schema, 'CREATE TABLE exercise_translations'), 'основная схема содержит locale и переводы упражнений');
$check(str_contains($migration, 'ALTER TABLE users') && str_contains($migration, 'Bench Press'), 'миграция обновляет существующую установку');
$check(str_contains($routes, "'/language'") && str_contains($routes, "'saveLanguage'"), 'публичный language route подключён');

$manifest = json_decode((string) file_get_contents($root . '/public/manifest.en.json'), true, 512, JSON_THROW_ON_ERROR);
$serviceWorker = (string) file_get_contents($root . '/public/service-worker.js');
$check(($manifest['lang'] ?? null) === 'en' && ($manifest['short_name'] ?? null) === 'Rhythm', 'EN manifest содержит локализованный бренд');
$check(is_file($root . '/public/offline.en.html') && is_file($root . '/public/icons/icon-en-512.png'), 'EN offline fallback и иконки существуют');
$check(str_contains($serviceWorker, 'SET_LOCALE') && str_contains($serviceWorker, 'offline.en.html') && str_contains($serviceWorker, "rhythm-shell-v9.0"), 'Service Worker хранит locale и кеширует EN shell');
$check(str_contains((string) file_get_contents($root . '/public/assets/i18n.js'), 'window.RhythmI18n'), 'JS-каталог подключён');

if ($failures !== []) {
    fwrite(STDERR, "Stage 19 localization checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 19 localization checks passed ({$checks}).\n");
