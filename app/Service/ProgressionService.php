<?php

declare(strict_types=1);

namespace App\Service;

final class ProgressionService
{
    public function suggest(array $exercise, array $sets): ?array
    {
        $working = array_values(array_filter($sets, static fn (array $set): bool => ($set['set_type'] ?? 'working') === 'working' && empty($set['deleted_at'])));
        $plannedSets = (int) ($exercise['planned_sets'] ?? 0);
        $repMax = (int) ($exercise['rep_max'] ?? 0);
        $rirMin = (float) ($exercise['target_rir_min'] ?? 1);
        $rirMax = (float) ($exercise['target_rir_max'] ?? 3);
        if ($plannedSets < 1 || count($working) < $plannedSets || $repMax < 1 || $rirMin > $rirMax) {
            return null;
        }
        foreach ($working as $set) {
            if ((int) ($set['reps'] ?? 0) < $repMax || !isset($set['rir']) || (float) $set['rir'] < $rirMin || (float) $set['rir'] > $rirMax) {
                return null;
            }
        }
        $current = max(array_map(static fn (array $set): float => (float) ($set['weight_kg'] ?? 0), $working));
        if ($current <= 0) {
            return null;
        }
        $increment = (float) ($exercise['progression_increment'] ?? 2.5);
        if ($increment <= 0) {
            return null;
        }
        if (($exercise['progression_mode'] ?? 'absolute') === 'percent') {
            $suggested = round(($current * (1 + $increment / 100)) * 2) / 2;
        } else {
            $suggested = $current + $increment;
        }
        return [
            'current_weight_kg' => $current,
            'suggested_weight_kg' => round($suggested, 2),
            'reason' => "Верхняя граница {$repMax} повторений достигнута во всех рабочих подходах при допустимом RIR.",
        ];
    }
}
