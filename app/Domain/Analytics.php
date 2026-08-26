<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class Analytics
{
    public static function weekWindow(string $timezone, int $weeks = 12, ?DateTimeImmutable $now = null): array
    {
        $zone = self::timezone($timezone);
        $localNow = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
        $currentStart = $localNow->setTime(0, 0)->modify('monday this week');
        $start = $currentStart->modify('-' . max(0, $weeks - 1) . ' weeks');
        $end = $currentStart->modify('+1 week');

        return [
            'start_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'current_start_utc' => $currentStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'start_local' => $start,
            'end_local' => $end,
            'current_start_local' => $currentStart,
        ];
    }

    public static function localDateBounds(?string $from, ?string $to, string $timezone): array
    {
        $zone = self::timezone($timezone);
        $parse = static function (?string $value, DateTimeZone $zone): ?DateTimeImmutable {
            if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };
        $fromDate = $parse($from, $zone);
        $toDate = $parse($to, $zone);

        return [
            'from_utc' => $fromDate?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'to_utc' => $toDate?->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'from' => $fromDate?->format('Y-m-d'),
            'to' => $toDate?->format('Y-m-d'),
        ];
    }

    public static function weekly(array $sessions, string $timezone, int $weeks = 12, ?DateTimeImmutable $now = null): array
    {
        $window = self::weekWindow($timezone, $weeks, $now);
        $zone = self::timezone($timezone);
        $buckets = [];
        for ($cursor = $window['start_local']; $cursor < $window['end_local']; $cursor = $cursor->modify('+1 week')) {
            $key = $cursor->format('Y-m-d');
            $buckets[$key] = [
                'week_start' => $key,
                'label' => $cursor->format('d.m'),
                'workouts' => 0,
                'working_sets' => 0,
                'tonnage' => 0.0,
                'average_rir' => null,
                'duration_minutes' => 0,
                '_rir_sum' => 0.0,
                '_rir_count' => 0,
            ];
        }

        foreach ($sessions as $session) {
            $started = self::utc((string) ($session['started_at'] ?? ''));
            if (!$started) {
                continue;
            }
            $local = $started->setTimezone($zone);
            $key = $local->setTime(0, 0)->modify('monday this week')->format('Y-m-d');
            if (!isset($buckets[$key])) {
                continue;
            }
            $sets = (int) ($session['working_sets'] ?? 0);
            $buckets[$key]['workouts']++;
            $buckets[$key]['working_sets'] += $sets;
            $buckets[$key]['tonnage'] += (float) ($session['tonnage'] ?? 0);
            $finished = self::utc((string) ($session['finished_at'] ?? ''));
            if ($finished) {
                $buckets[$key]['duration_minutes'] += max(0, (int) round(($finished->getTimestamp() - $started->getTimestamp()) / 60));
            }
            if (($session['average_rir'] ?? null) !== null && $sets > 0) {
                $count = (int) ($session['rir_count'] ?? $sets);
                $buckets[$key]['_rir_sum'] += (float) $session['average_rir'] * $count;
                $buckets[$key]['_rir_count'] += $count;
            }
        }

        foreach ($buckets as &$bucket) {
            $bucket['tonnage'] = round($bucket['tonnage'], 2);
            $bucket['average_rir'] = $bucket['_rir_count'] > 0 ? round($bucket['_rir_sum'] / $bucket['_rir_count'], 1) : null;
            unset($bucket['_rir_sum'], $bucket['_rir_count']);
        }
        unset($bucket);
        return array_values($buckets);
    }

    public static function softSignals(array $sessions): array
    {
        $signals = [];
        $recent = array_slice($sessions, 0, 3);
        $e1rm = array_values(array_filter(array_map(static fn (array $row): mixed => $row['best_e1rm'] ?? null, $recent), static fn (mixed $value): bool => $value !== null));
        if (count($e1rm) === 3) {
            $max = max($e1rm);
            $spread = $max > 0 ? ($max - min($e1rm)) / $max : 0;
            if ($max > 0 && $spread <= 0.02) {
                $signals[] = [
                    'kind' => 'plateau',
                    'title' => 'Кандидат на плато',
                    'text' => 'Три последних e1RM находятся в коридоре ' . round($spread * 100, 1) . '%. Это наблюдение для анализа, а не вывод о причине.',
                ];
            }
        }
        if (($sessions[0]['average_rir'] ?? null) !== null && (float) $sessions[0]['average_rir'] >= 4) {
            $signals[] = [
                'kind' => 'high_rir',
                'title' => 'Высокий RIR',
                'text' => 'В последней записи средний RIR ' . round((float) $sessions[0]['average_rir'], 1) . '. Возможно, нагрузка ощущалась легче; контекст остаётся за пользователем.',
            ];
        }
        if (count($sessions) >= 2) {
            $current = $sessions[0]['reps_by_weight'] ?? [];
            $previous = $sessions[1]['reps_by_weight'] ?? [];
            $largestDrop = 0.0;
            $weight = null;
            foreach ($current as $key => $reps) {
                if (!isset($previous[$key]) || (int) $previous[$key] < 4) {
                    continue;
                }
                $drop = ((int) $previous[$key] - (int) $reps) / (int) $previous[$key];
                if ($drop >= 0.25 && $drop > $largestDrop) {
                    $largestDrop = $drop;
                    $weight = $key;
                }
            }
            if ($weight !== null) {
                $signals[] = [
                    'kind' => 'reps_drop',
                    'title' => 'Резкое падение повторов',
                    'text' => 'При ' . $weight . ' кг максимум повторов снизился на ' . round($largestDrop * 100) . '% к предыдущей записи. Сигнал не объясняет причину.',
                ];
            }
        }
        return $signals;
    }

    public static function weeklyTrend(array $weeks): array
    {
        $nonEmpty = array_values(array_filter($weeks, static fn (array $week): bool => (int) ($week['workouts'] ?? 0) > 0));
        if (count($nonEmpty) < 2) {
            return ['direction' => 'insufficient_data', 'comparison' => null];
        }
        $current = $nonEmpty[array_key_last($nonEmpty)];
        $previous = $nonEmpty[array_key_last($nonEmpty) - 1];
        $delta = static fn (string $key): float => round((float) ($current[$key] ?? 0) - (float) ($previous[$key] ?? 0), 2);
        $tonnageDelta = $delta('tonnage');
        return [
            'direction' => $tonnageDelta > 0 ? 'up' : ($tonnageDelta < 0 ? 'down' : 'flat'),
            'comparison' => [
                'current_week_start' => $current['week_start'] ?? null,
                'previous_week_start' => $previous['week_start'] ?? null,
                'workouts_delta' => (int) $delta('workouts'),
                'working_sets_delta' => (int) $delta('working_sets'),
                'tonnage_kg_delta' => $tonnageDelta,
                'duration_minutes_delta' => (int) $delta('duration_minutes'),
                'average_rir_delta' => isset($current['average_rir'], $previous['average_rir'])
                    ? round((float) $current['average_rir'] - (float) $previous['average_rir'], 1)
                    : null,
            ],
        ];
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            return new DateTimeZone('Europe/Moscow');
        }
    }

    private static function utc(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
