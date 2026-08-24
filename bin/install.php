<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Расширение pdo_mysql не установлено. Включите его в PHP и повторите команду.\n");
    exit(1);
}

/** @return list<string> */
function sql_statements(string $file): array
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать ' . $file);
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $statements), static function (string $statement): bool {
        if ($statement === '') {
            return false;
        }
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $statement);
        return trim((string) $withoutComments) !== '';
    }));
}

try {
    $pdo = db()->pdo();
    foreach (['database/schema.sql', 'database/seed.sql'] as $relativeFile) {
        foreach (sql_statements(APP_ROOT . '/' . $relativeFile) as $statement) {
            $pdo->exec($statement);
        }
        fwrite(STDOUT, "Применён {$relativeFile}\n");
    }
    fwrite(STDOUT, "База данных Ритма установлена.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Установка остановлена: {$exception->getMessage()}\n");
    exit(1);
}
