<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

final class Weight
{
    public const LB_KG = 0.45359237;

    public static function unit(mixed $unit): string
    {
        if (!in_array($unit, ['kg', 'lb'], true)) {
            throw new InvalidArgumentException('Единица веса должна быть kg или lb.');
        }
        return $unit;
    }

    public static function toKg(?float $value, string $unit): ?float
    {
        self::unit($unit);
        return $value === null ? null : round($value * ($unit === 'lb' ? self::LB_KG : 1), 8);
    }

    public static function fromKg(?float $value, string $unit): ?float
    {
        self::unit($unit);
        return $value === null ? null : round($value / ($unit === 'lb' ? self::LB_KG : 1), 2);
    }

    /** Parse a write, preserving the original pair on partial updates. */
    public static function input(array $data, ?array $before = null): array
    {
        $pair = array_key_exists('weight_value', $data) || array_key_exists('weight_unit', $data);
        if ($pair && (array_key_exists('weight_kg', $data) || !array_key_exists('weight_value', $data) || !array_key_exists('weight_unit', $data))) {
            throw new InvalidArgumentException('Передайте weight_value и weight_unit вместе, без weight_kg.');
        }
        if (!$pair && !array_key_exists('weight_kg', $data) && $before !== null) {
            return self::fields($before);
        }
        $value = $pair ? $data['weight_value'] : ($data['weight_kg'] ?? null);
        $unit = self::unit($pair ? $data['weight_unit'] : 'kg');
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 2000 || abs($value - round($value, 2)) > 0.00000001) {
            throw new InvalidArgumentException('Вес должен быть числом от 0 до 2000 с точностью до двух знаков.');
        }
        return ['weight_value' => (float) $value, 'weight_unit' => $unit, 'weight_kg' => self::toKg((float) $value, $unit)];
    }

    public static function fields(array $row): array
    {
        $kg = $row['weight_kg'] ?? $row['performed_weight_kg'] ?? null;
        $value = $row['weight_value'] ?? $kg;
        return ['weight_value' => $value === null ? null : (float) $value, 'weight_unit' => $row['weight_unit'] ?? 'kg', 'weight_kg' => $kg === null ? null : (float) $kg];
    }

    public static function text(array $row): string
    {
        $weight = self::fields($row);
        return ($weight['weight_value'] === null ? '—' : self::number($weight['weight_value'])) . ' ' . \unit($weight['weight_unit']);
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
