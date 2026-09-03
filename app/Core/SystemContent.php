<?php

declare(strict_types=1);

namespace App\Core;

final class SystemContent
{
    /** @var array<string,string>|null */
    private static ?array $exerciseNames = null;

    public static function localize(mixed $value): mixed
    {
        if (Locale::current() !== 'en' || !is_array($value)) return $value;
        $names = self::exerciseNames();
        return self::walk($value, $names);
    }

    /** @param array<string,string> $names */
    private static function walk(array $row, array $names): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) $row[$key] = self::walk($value, $names);
        }

        $pairs = [
            ['exercise_id', 'name'],
            ['exercise_id', 'exercise_name'],
            ['actual_exercise_id', 'name'],
            ['actual_exercise_id', 'exercise_name'],
            ['actual_exercise_id', 'actual_name'],
            ['original_exercise_id', 'original_name'],
            ['original_exercise_id', 'original_exercise_name'],
        ];
        foreach ($pairs as [$idKey, $nameKey]) {
            $id = isset($row[$idKey]) ? (string) $row[$idKey] : '';
            if ($id !== '' && isset($names[$id]) && array_key_exists($nameKey, $row)) $row[$nameKey] = $names[$id];
        }

        if (isset($row['weekday'], $row['workout_type'], $row['label']) && is_string($row['label'])) {
            $row['label'] = match ($row['label']) {
                'Зал' => 'Gym',
                'Бассейн' => 'Pool',
                'Тренировка' => 'Workout',
                default => $row['label'],
            };
        }
        return $row;
    }

    /** @return array<string,string> */
    private static function exerciseNames(): array
    {
        if (self::$exerciseNames !== null) return self::$exerciseNames;
        self::$exerciseNames = [];
        try {
            $query = \db()->pdo()->prepare('SELECT exercise_id,name FROM exercise_translations WHERE locale=?');
            $query->execute(['en']);
            foreach ($query->fetchAll() as $row) self::$exerciseNames[(string) $row['exercise_id']] = (string) $row['name'];
        } catch (\Throwable) {
            // The interface remains usable while a deployment is applying migrations.
        }
        return self::$exerciseNames;
    }
}
