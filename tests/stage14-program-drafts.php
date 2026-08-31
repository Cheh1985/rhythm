<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/VersionConflictException.php';
require dirname(__DIR__) . '/app/Service/TrainingPlanContractValidator.php';
require dirname(__DIR__) . '/app/Service/ProgramDraftValidator.php';
require dirname(__DIR__) . '/app/Service/ProgramVersionService.php';

use App\Core\VersionConflictException;
use App\Service\ProgramDraftValidator;
use App\Service\ProgramVersionService;

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    $passed++;
};
$throws = static function (callable $callback, string $class, string $messagePart, string $message) use ($check): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        $check($exception instanceof $class && str_contains($exception->getMessage(), $messagePart), $message . ' (' . $exception->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (исключение не выброшено)');
};

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(<<<'SQL'
CREATE TABLE exercises (
 exercise_id TEXT PRIMARY KEY,owner_user_id INTEGER NULL,name TEXT NOT NULL,status TEXT NOT NULL,
 deleted_at TEXT NULL
);
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
 id INTEGER PRIMARY KEY AUTOINCREMENT,program_version_id INTEGER NOT NULL,workout_template_id INTEGER NOT NULL,
 weekday INTEGER NOT NULL CHECK(weekday BETWEEN 1 AND 7),created_at TEXT NOT NULL,UNIQUE(program_version_id,weekday),
 FOREIGN KEY(program_version_id) REFERENCES program_versions(id),
 FOREIGN KEY(workout_template_id,program_version_id) REFERENCES workout_templates(id,program_version_id)
);
SQL);
$insertExercise = $pdo->prepare("INSERT INTO exercises (exercise_id,owner_user_id,name,status) VALUES (?,?,?,'active')");
foreach ([
    ['bench_press_001', null, 'Жим лёжа'],
    ['squat_001', null, 'Присед'],
    ['row_001', 1, 'Тяга пользователя 1'],
    ['private_001', 2, 'Чужое упражнение'],
] as $exercise) {
    $insertExercise->execute($exercise);
}

$exercise = static fn (string $id, string $name, int $order): array => [
    'exercise_id' => $id,
    'name' => $name,
    'order' => $order,
    'sets' => 3,
    'rep_range' => ['min' => 6, 'max' => 10],
    'target_rir' => ['min' => 2, 'max' => 3],
    'rest_seconds' => 120,
];
$template = static fn (string $id, string $name, array $exercises): array => [
    'template_id' => $id,
    'name' => $name,
    'type' => 'strength',
    'exercises' => $exercises,
];

$service = new ProgramVersionService($pdo);
$created = $service->createProgramDraft(1, [
    'program_id' => 'base-program',
    'name' => 'Базовая программа',
    'description' => 'Исходная версия',
    'templates' => [$template('full-body-a', 'Full Body A', [
        $exercise('bench_press_001', 'Жим лёжа', 1),
        $exercise('squat_001', 'Присед', 2),
    ])],
    'schedule_slots' => [['weekday' => 1, 'template_id' => 'full-body-a']],
], 'Первая версия программы', 'webmcp');
$check($created['aggregate']['program']['version'] === 1 && $created['aggregate']['source'] === 'webmcp', 'new draft получает server-assigned version 1 и поддерживает source=webmcp');
$check($created['aggregate']['program']['parent_version'] === null && $created['aggregate']['program']['parent_aggregate_hash'] === null, 'new program сохраняет явный null parent provenance');
$check((int) $pdo->query('SELECT COUNT(*) FROM workout_templates')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM program_schedule_slots')->fetchColumn() === 1, 'полный draft aggregate материализован в template/slot rows');

$throws(
    static fn () => $service->createProgramDraft(1, ['program_id' => 'bad-program', 'name' => 'Bad'], '', 'manual'),
    InvalidArgumentException::class,
    'обязателен',
    'change reason обязателен',
);
$throws(
    static fn () => $service->createProgramDraft(1, [
        'program_id' => 'private-program',
        'name' => 'Private',
        'templates' => [[
            'template_id' => 'private-template', 'name' => 'Private', 'type' => 'strength',
            'exercises' => [[
                'exercise_id' => 'private_001', 'name' => 'Private', 'order' => 1, 'sets' => 1,
                'rep_range' => ['min' => 1, 'max' => 1], 'target_rir' => ['min' => 1, 'max' => 1], 'rest_seconds' => 1,
            ]],
        ]],
    ], 'Tenant test'),
    InvalidArgumentException::class,
    'Недоступные',
    'чужой exercise_id не проходит tenant isolation',
);
$check((int) $pdo->query("SELECT COUNT(*) FROM training_programs WHERE external_program_id='private-program'")->fetchColumn() === 0, 'ошибка exercise reference откатывает всю create transaction');

// Make v1 immutable and active, then clone both current and explicitly selected old version.
$programId = (int) $pdo->query("SELECT id FROM training_programs WHERE external_program_id='base-program'")->fetchColumn();
$pdo->exec("UPDATE program_versions SET lifecycle_status='published' WHERE id={$created['draft_id']}");
$pdo->exec("UPDATE training_programs SET status='active',active_version_id={$created['draft_id']} WHERE id={$programId}");
$throws(
    static fn () => $service->updateDraft(1, $created['draft_id'], 1, 'set_program_metadata', ['name' => 'Нельзя']),
    RuntimeException::class,
    'неизменяемы',
    'published/active content immutable',
);

$currentClone = $service->cloneProgramDraft(1, 'base-program', null, 'Рабочая копия active version');
$check($currentClone['aggregate']['program']['version'] === 2 && $currentClone['aggregate']['program']['parent_version'] === 1, 'clone current получает server-assigned version и parent provenance');
$check($currentClone['aggregate']['program']['parent_aggregate_hash'] === $created['aggregate_hash'], 'clone current фиксирует hash родителя');
$oldClone = $service->cloneProgramDraft(1, 'base-program', 1, 'Альтернативная ветка от старой версии');
$check($oldClone['aggregate']['program']['version'] === 3 && $oldClone['aggregate']['program']['parent_version'] === 1, 'clone explicitly selected old version поддержан');

// Every typed operation increments lock_version and validates the full aggregate.
$draft = $currentClone;
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'set_program_metadata', [
    'name' => 'Базовая программа — draft',
    'description' => 'Изменяется только snapshot draft',
    'change_reason' => 'Обновлена структура недели',
]);
$mobility = $template('mobility-a', 'Mobility A', [$exercise('row_001', 'Тяга пользователя 1', 1)]);
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'upsert_template', $mobility);
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'upsert_exercise', [
    'template_id' => 'full-body-a',
    'exercise' => $exercise('row_001', 'Тяга пользователя 1', 3),
]);
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'remove_exercise', [
    'template_id' => 'full-body-a', 'exercise_id' => 'row_001',
]);
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'set_schedule_slot', [
    'weekday' => 2, 'template_id' => 'mobility-a',
]);

$beforeInvalidRemove = $draft;
$throws(
    static fn () => $service->updateDraft(1, $beforeInvalidRemove['draft_id'], $beforeInvalidRemove['lock_version'], 'remove_template', ['template_id' => 'mobility-a']),
    InvalidArgumentException::class,
    'отсутствующий template_id',
    'нельзя удалить template, пока schedule ссылается на него',
);
$afterInvalidRemove = $service->getDraft(1, $draft['draft_id']);
$check($afterInvalidRemove['lock_version'] === $beforeInvalidRemove['lock_version'] && $afterInvalidRemove['aggregate_hash'] === $beforeInvalidRemove['aggregate_hash'], 'invalid template operation откатывается целиком');

$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'remove_schedule_slot', ['weekday' => 2]);
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'remove_template', ['template_id' => 'mobility-a']);
$check($draft['lock_version'] === 8 && count($draft['aggregate']['templates']) === 1, 'все семь typed operations применены с optimistic lock increments');

$stale = $draft;
$draft = $service->updateDraft(1, $draft['draft_id'], $draft['lock_version'], 'set_program_metadata', ['description' => 'Новая запись']);
$throws(
    static fn () => $service->updateDraft(1, $stale['draft_id'], $stale['lock_version'], 'set_program_metadata', ['description' => 'Stale write']),
    VersionConflictException::class,
    'конкурентно',
    'stale lock_version даёт conflict',
);

$beforeInvalid = $draft;
$badExercise = $exercise('row_001', 'Bad range', 3);
$badExercise['rep_range'] = ['min' => 20, 'max' => 10];
$throws(
    static fn () => $service->updateDraft(1, $beforeInvalid['draft_id'], $beforeInvalid['lock_version'], 'upsert_exercise', [
        'template_id' => 'full-body-a', 'exercise' => $badExercise,
    ]),
    InvalidArgumentException::class,
    'числом от 20',
    'invalid rep range отклоняется полной aggregate validation',
);
$throws(
    static fn () => $service->updateDraft(1, $beforeInvalid['draft_id'], $beforeInvalid['lock_version'], 'set_schedule_slot', [
        'weekday' => 7, 'template_id' => 'missing-template',
    ]),
    InvalidArgumentException::class,
    'отсутствующий template_id',
    'invalid schedule reference отклоняется',
);
$afterInvalid = $service->getDraft(1, $draft['draft_id']);
$check($afterInvalid['lock_version'] === $beforeInvalid['lock_version'] && $afterInvalid['aggregate_hash'] === $beforeInvalid['aggregate_hash'], 'invalid range/schedule не оставляют partial writes');

// Force a storage failure after the version row has been optimistically
// updated; the transaction must restore version, templates and slots together.
$pdo->exec("CREATE TRIGGER fail_draft_slot BEFORE INSERT ON program_schedule_slots WHEN NEW.weekday=6 BEGIN SELECT RAISE(ABORT,'forced slot failure'); END");
$beforeStorageFailure = $service->getDraft(1, $draft['draft_id']);
$throws(
    static fn () => $service->updateDraft(1, $beforeStorageFailure['draft_id'], $beforeStorageFailure['lock_version'], 'set_schedule_slot', [
        'weekday' => 6, 'template_id' => 'full-body-a',
    ]),
    PDOException::class,
    'forced slot failure',
    'SQL failure после optimistic update откатывает transaction',
);
$pdo->exec('DROP TRIGGER fail_draft_slot');
$afterStorageFailure = $service->getDraft(1, $draft['draft_id']);
$check($afterStorageFailure['lock_version'] === $beforeStorageFailure['lock_version'] && $afterStorageFailure['aggregate_hash'] === $beforeStorageFailure['aggregate_hash'], 'storage rollback восстанавливает исходный version aggregate');

$throws(
    static fn () => $service->getDraft(2, $draft['draft_id']),
    InvalidArgumentException::class,
    'не найден',
    'tenant не может прочитать чужой draft',
);
$throws(
    static fn () => $service->updateDraft(2, $draft['draft_id'], $draft['lock_version'], 'set_program_metadata', ['name' => 'Чужая правка']),
    InvalidArgumentException::class,
    'не найден',
    'tenant не может изменить чужой draft',
);

$pdo->exec("UPDATE program_versions SET lifecycle_status='archived' WHERE id={$oldClone['draft_id']}");
$throws(
    static fn () => $service->updateDraft(1, $oldClone['draft_id'], 1, 'set_program_metadata', ['name' => 'Нельзя']),
    RuntimeException::class,
    'неизменяемы',
    'archived content immutable',
);

$validator = new ProgramDraftValidator();
$hashAggregate = $draft['aggregate'];
$hashAggregate['templates'] = array_reverse($hashAggregate['templates']);
foreach ($hashAggregate['templates'] as &$hashTemplate) {
    $hashTemplate['exercises'] = array_reverse($hashTemplate['exercises']);
}
unset($hashTemplate);
$check($validator->canonicalHash($hashAggregate) === $draft['aggregate_hash'], 'canonical aggregate hash стабилен при ином порядке semantic arrays');

$schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/training-program-draft-v1.0.schema.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($schema['properties']['schema']['const'] ?? null) === 'training-program-draft' && ($schema['properties']['templates']['items']['$ref'] ?? null) !== null, 'отдельная machine-readable draft schema существует');
$legacySchema = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/training-plan-v1.0.schema.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($legacySchema['properties']['schema']['const'] ?? null) === 'training-plan', 'legacy training-plan v1.0 root не переопределён');

fwrite(STDOUT, "Stage 14 program draft checks passed ({$passed}).\n");
