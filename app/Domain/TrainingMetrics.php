<?php

declare(strict_types=1);

namespace App\Domain;

final class TrainingMetrics
{
    public static function tonnage(array $sets): float
    {
        return round(array_reduce($sets, static function (float $sum, array $set): float {
            if (($set['set_type'] ?? $set['type'] ?? 'working') !== 'working' || !empty($set['deleted_at'])) {
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
            if (($set['set_type'] ?? $set['type'] ?? 'working') === 'working' && isset($set['rir']) && $set['rir'] !== null && empty($set['deleted_at'])) {
                $values[] = (float) $set['rir'];
            }
        }
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    public static function bestEpley(array $sets): ?array
    {
        $best = null;
        foreach ($sets as $set) {
            if (($set['set_type'] ?? $set['type'] ?? 'working') !== 'working' || !empty($set['deleted_at'])) {
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

    public static function workingSets(array $sets): int
    {
        return count(array_filter($sets, static fn (array $set): bool =>
            ($set['set_type'] ?? $set['type'] ?? 'working') === 'working' && empty($set['deleted_at'])
        ));
    }

    public static function durationMinutes(mixed $startedAt, mixed $finishedAt): ?int
    {
        if (!is_string($startedAt) || $startedAt === '' || !is_string($finishedAt) || $finishedAt === '') {
            return null;
        }
        $started = strtotime($startedAt . (str_contains($startedAt, 'T') || str_contains($startedAt, '+') || str_ends_with($startedAt, 'Z') ? '' : ' UTC'));
        $finished = strtotime($finishedAt . (str_contains($finishedAt, 'T') || str_contains($finishedAt, '+') || str_ends_with($finishedAt, 'Z') ? '' : ' UTC'));
        if ($started === false || $finished === false || $finished < $started) {
            return null;
        }
        return (int) round(($finished - $started) / 60);
    }

    public static function targetCompliance(array $sets, ?int $repMin, ?int $repMax): array
    {
        if ($repMin === null || $repMax === null || $repMin < 0 || $repMax < $repMin) {
            return ['observed_sets' => 0, 'compliant_sets' => 0, 'rate' => null];
        }
        $observed = 0;
        $compliant = 0;
        foreach ($sets as $set) {
            if (($set['set_type'] ?? $set['type'] ?? 'working') !== 'working' || !empty($set['deleted_at']) || !isset($set['reps']) || $set['reps'] === null) {
                continue;
            }
            $observed++;
            $reps = (int) $set['reps'];
            if ($reps >= $repMin && $reps <= $repMax) {
                $compliant++;
            }
        }
        return [
            'observed_sets' => $observed,
            'compliant_sets' => $compliant,
            'rate' => $observed > 0 ? round($compliant / $observed, 3) : null,
        ];
    }

    public static function summarizeSets(array $sets, ?int $repMin = null, ?int $repMax = null): array
    {
        $working = self::workingSets($sets);
        $rirObservations = count(array_filter($sets, static fn (array $set): bool =>
            ($set['set_type'] ?? $set['type'] ?? 'working') === 'working'
            && ($set['rir'] ?? null) !== null
            && empty($set['deleted_at'])
        ));
        return [
            'working_sets' => $working,
            'tonnage_kg' => self::tonnage($sets),
            'average_rir' => self::averageRir($sets),
            'rir_observations' => $rirObservations,
            'best_e1rm' => self::bestEpley($sets),
            'target_rep_range' => self::targetCompliance($sets, $repMin, $repMax),
        ];
    }

    public static function exerciseStatusCounts(array $exercises): array
    {
        $counts = ['completed_exercises' => 0, 'skipped_exercises' => 0, 'pending_exercises' => 0, 'substitutions' => 0];
        foreach ($exercises as $exercise) {
            $status = (string) ($exercise['status'] ?? 'pending');
            if ($status === 'completed') {
                $counts['completed_exercises']++;
            } elseif ($status === 'skipped') {
                $counts['skipped_exercises']++;
            } else {
                $counts['pending_exercises']++;
            }
            if (!empty($exercise['substituted']) || (isset($exercise['original_exercise_id'], $exercise['actual_exercise_id']) && $exercise['original_exercise_id'] !== $exercise['actual_exercise_id'])) {
                $counts['substitutions']++;
            }
        }
        return $counts;
    }

    public static function repsByWeight(array $sets): array
    {
        $result = [];
        foreach ($sets as $set) {
            if (($set['set_type'] ?? $set['type'] ?? 'working') !== 'working' || ($set['weight_kg'] ?? null) === null || ($set['reps'] ?? null) === null) {
                continue;
            }
            $key = rtrim(rtrim(number_format((float) $set['weight_kg'], 2, '.', ''), '0'), '.');
            $result[$key] = max($result[$key] ?? 0, (int) $set['reps']);
        }
        return $result;
    }
}
