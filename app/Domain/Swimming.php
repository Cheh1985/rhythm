<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Swimming
{
    public static function validate(array $input, string $timezone): array
    {
        $dateText = (string) ($input['swim_date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, new DateTimeZone($timezone));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $dateText) {
            throw new InvalidArgumentException('Укажите корректную дату плавания.');
        }

        $duration = self::integer($input['duration_minutes'] ?? null, 1, 1440, 'Длительность');
        $poolLength = self::integer($input['pool_length_m'] ?? null, 10, 100, 'Длина бассейна');
        $totalDistance = self::integer($input['total_distance_m'] ?? null, $poolLength, 100000, 'Общая дистанция');
        if ($totalDistance % $poolLength !== 0) {
            throw new InvalidArgumentException('Общая дистанция должна состоять из целых длин бассейна.');
        }

        $style = self::text($input['primary_style'] ?? null, 60, 'Основной стиль', true);
        $intervals = self::intervalInput($input);
        if ($intervals === [] || count($intervals) > 50) {
            throw new InvalidArgumentException('Добавьте от 1 до 50 структурированных блоков плавания.');
        }
        $normalizedIntervals = [];
        $intervalDistance = 0;
        foreach ($intervals as $index => $interval) {
            if (!is_array($interval)) {
                throw new InvalidArgumentException('Проверьте структуру блоков плавания.');
            }
            $repeat = self::integer($interval['repeat_count'] ?? 1, 1, 100, 'Количество повторов блока');
            $distance = self::integer($interval['distance_m'] ?? null, $poolLength, 10000, 'Дистанция блока');
            if ($distance % $poolLength !== 0) {
                throw new InvalidArgumentException('Дистанция каждого блока должна состоять из целых длин бассейна.');
            }
            $intervalDistance += $repeat * $distance;
            if ($intervalDistance > 100000) {
                throw new InvalidArgumentException('Сумма дистанций блоков слишком велика.');
            }
            $intervalIntensity = self::optionalInteger($interval['intensity'] ?? null, 1, 10, 'Интенсивность блока');
            $rest = self::optionalInteger($interval['rest_seconds'] ?? null, 0, 3600, 'Отдых блока');
            $normalizedIntervals[] = [
                'sequence_no' => $index + 1,
                'repeat_count' => $repeat,
                'distance_m' => $distance,
                'total_distance_m' => $repeat * $distance,
                'style' => self::text($interval['style'] ?? null, 60, 'Стиль блока', true),
                'intensity' => $intervalIntensity,
                'rest_seconds' => $rest,
                'note' => self::text($interval['note'] ?? null, 500, 'Комментарий блока'),
            ];
        }
        if ($intervalDistance !== $totalDistance) {
            throw new InvalidArgumentException("Сумма блоков ({$intervalDistance} м) должна совпадать с общей дистанцией ({$totalDistance} м).");
        }

        $localNoon = $date->setTime(12, 0);
        return [
            'swim_date' => $dateText,
            'occurred_at' => $localNoon->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'weekday' => (int) $date->format('N'),
            'duration_minutes' => $duration,
            'pool_length_m' => $poolLength,
            'total_distance_m' => $totalDistance,
            'primary_style' => $style,
            'intensity' => self::integer($input['intensity'] ?? null, 1, 10, 'Интенсивность'),
            'arms_fatigue' => self::integer($input['arms_fatigue'] ?? null, 1, 5, 'Усталость рук'),
            'back_fatigue' => self::integer($input['back_fatigue'] ?? null, 1, 5, 'Усталость спины'),
            'legs_fatigue' => self::integer($input['legs_fatigue'] ?? null, 1, 5, 'Усталость ног'),
            'wellbeing' => self::integer($input['wellbeing'] ?? null, 1, 5, 'Самочувствие'),
            'comment' => self::text($input['comment'] ?? null, 2000, 'Комментарий'),
            'schedule_id' => self::optionalInteger($input['schedule_id'] ?? null, 1, PHP_INT_MAX, 'Расписание'),
            'intervals' => $normalizedIntervals,
        ];
    }

    private static function intervalInput(array $input): array
    {
        if (isset($input['intervals']) && is_array($input['intervals'])) {
            return array_values(array_filter($input['intervals'], static fn (mixed $row): bool => is_array($row) && array_filter($row, static fn (mixed $value): bool => $value !== '' && $value !== null) !== []));
        }
        $distances = is_array($input['interval_distance_m'] ?? null) ? $input['interval_distance_m'] : [];
        $rows = [];
        foreach ($distances as $index => $distance) {
            $row = [
                'repeat_count' => $input['interval_repeat_count'][$index] ?? 1,
                'distance_m' => $distance,
                'style' => $input['interval_style'][$index] ?? '',
                'intensity' => $input['interval_intensity'][$index] ?? null,
                'rest_seconds' => $input['interval_rest_seconds'][$index] ?? null,
                'note' => $input['interval_note'][$index] ?? null,
            ];
            if (array_filter($row, static fn (mixed $value): bool => $value !== '' && $value !== null) !== []) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$label}: укажите целое число от {$minimum} до {$maximum}.");
        }
        return $value;
    }

    private static function optionalInteger(mixed $value, int $minimum, int $maximum, string $label): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return self::integer($value, $minimum, $maximum, $label);
    }

    private static function text(mixed $value, int $maximum, string $label, bool $required = false): ?string
    {
        if (!is_string($value) && $value !== null) {
            throw new InvalidArgumentException("{$label}: недопустимый формат.");
        }
        $value = trim((string) $value);
        if (($required && $value === '') || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException("{$label}: заполните поле длиной до {$maximum} символов.");
        }
        return $value === '' ? null : $value;
    }
}
