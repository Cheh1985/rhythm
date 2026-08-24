<?php

declare(strict_types=1);

namespace App\Domain;

final class TrainingMetrics
{
    public static function tonnage(array $sets): float
    {
        return round(array_reduce($sets, static function (float $sum, array $set): float {
            if (($set['set_type'] ?? 'working') !== 'working' || !empty($set['deleted_at'])) {
                return $sum;
            }
            $weight = $set['weight_kg'] ?? $set['performed_weight_kg'] ?? 0;
            return $sum + (float) $weight * (int) ($set['reps'] ?? 0);
        }, 0.0), 2);
    }

    public static function epley(float $weight, int $reps): ?float
    {
        if ($weight <= 0 || $reps <= 0) {
            return null;
        }
        return round($weight * (1 + $reps / 30), 2);
    }

    public static function averageRir(array $sets): ?float
    {
        $values = [];
        foreach ($sets as $set) {
            if (($set['set_type'] ?? 'working') === 'working' && isset($set['rir']) && $set['rir'] !== null && empty($set['deleted_at'])) {
                $values[] = (float) $set['rir'];
            }
        }
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    public static function bestEpley(array $sets): ?array
    {
        $best = null;
        foreach ($sets as $set) {
            if (($set['set_type'] ?? 'working') !== 'working' || !empty($set['deleted_at'])) {
                continue;
            }
            $weight = (float) ($set['weight_kg'] ?? $set['performed_weight_kg'] ?? 0);
            $reps = (int) ($set['reps'] ?? 0);
            $value = self::epley($weight, $reps);
            if ($value !== null && ($best === null || $value > $best['e1rm_kg'])) {
                $best = ['e1rm_kg' => $value, 'weight_kg' => $weight, 'reps' => $reps];
            }
        }
        return $best;
    }
}
