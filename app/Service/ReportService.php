<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TrainingMetrics;
use App\Repository\TrainingRepository;
use PDO;
use RuntimeException;

final class ReportService
{
    private readonly PDO $pdo;
    private readonly TrainingRepository $training;

    public function __construct(?PDO $pdo = null, ?TrainingRepository $training = null)
    {
        $this->pdo = $pdo ?? \db()->pdo();
        $this->training = $training ?? new TrainingRepository($pdo);
    }

    public function build(int $sessionId, int $userId): array
    {
        $session = $this->training->session($sessionId, $userId);
        if (!$session || $session['status'] !== 'completed') {
            throw new RuntimeException('Завершённая тренировка не найдена.');
        }

        $suggestions = $this->pdo->prepare('SELECT id,exercise_id,current_weight_kg,suggested_next_weight_kg,accepted_next_weight_kg,reason,status,created_at,resolved_at FROM progression_suggestions WHERE workout_session_id=? AND user_id=? ORDER BY id');
        $suggestions->execute([$sessionId, $userId]);
        $byExercise = [];
        foreach ($suggestions->fetchAll() as $suggestion) {
            $byExercise[$suggestion['exercise_id']] = $suggestion;
        }

        // training-report v1.0 keeps its original e1RM-only PR contract; the richer
        // stage 6 record catalogue is exposed by history and analytics screens.
        $recordQuery = $this->pdo->prepare("SELECT id,exercise_id,record_type,value_decimal,metadata_json,achieved_at FROM personal_records WHERE workout_session_id=? AND user_id=? AND record_type='best_e1rm' ORDER BY id");
        $recordQuery->execute([$sessionId, $userId]);
        $records = array_map(fn (array $record): array => [
            'id' => (int) $record['id'],
            'exercise_id' => $record['exercise_id'],
            'type' => $record['record_type'],
            'value' => (float) $record['value_decimal'],
            'metadata' => $this->jsonObject($record['metadata_json']),
            'achieved_at_utc' => $this->utc($record['achieved_at']),
        ], $recordQuery->fetchAll());
        $recordsByExercise = [];
        foreach ($records as $record) {
            $recordsByExercise[$record['exercise_id']][] = $record;
        }

        $discomfort = $this->pdo->prepare('SELECT session_exercise_id,body_area,intensity,comment,logged_at FROM discomfort_logs WHERE workout_session_id=? AND user_id=? ORDER BY id');
        $discomfort->execute([$sessionId, $userId]);
        $discomfortByExercise = [];
        foreach ($discomfort->fetchAll() as $item) {
            $discomfortByExercise[$item['session_exercise_id']][] = [
                'body_area' => $item['body_area'], 'intensity' => (int) $item['intensity'], 'comment' => $item['comment'],
                'logged_at_utc' => $this->utc($item['logged_at']),
            ];
        }

        $exercises = [];
        foreach ($session['exercises'] as $exercise) {
            $sets = array_map(fn (array $set): array => [
                'set_id' => $set['public_id'], 'set_number' => (int) $set['set_number'], 'type' => $set['set_type'], 'method' => $set['method_type'],
                'weight_kg' => $set['weight_kg'] !== null ? (float) $set['weight_kg'] : null,
                'reps' => $set['reps'] !== null ? (int) $set['reps'] : null, 'rir' => $set['rir'] !== null ? (float) $set['rir'] : null,
                'performed_at_utc' => $this->utc($set['completed_at']), 'edited_at_utc' => $this->utc($set['edited_at'] ?? null),
            ], $exercise['sets']);
            $suggestion = $byExercise[$exercise['actual_exercise_id']] ?? null;
            $factMetrics = [
                'working_sets' => count(array_filter($exercise['sets'], static fn (array $set): bool => $set['set_type'] === 'working')),
                'tonnage_kg' => TrainingMetrics::tonnage($exercise['sets']),
                'average_rir' => TrainingMetrics::averageRir($exercise['sets']),
                'best_e1rm' => TrainingMetrics::bestEpley($exercise['sets']),
            ];
            $substituted = $exercise['original_exercise_id'] !== $exercise['actual_exercise_id'];
            $exercises[] = [
                'exercise_id' => $exercise['actual_exercise_id'], 'name' => $exercise['exercise_name'],
                'planned' => [
                    'exercise_id' => $exercise['original_exercise_id'], 'name' => $exercise['original_exercise_name'],
                    'sets' => (int) $exercise['planned_sets'],
                    'rep_range' => ['min' => (int) $exercise['rep_min'], 'max' => (int) $exercise['rep_max']],
                    'target_rir' => ['min' => $exercise['target_rir_min'] !== null ? (float) $exercise['target_rir_min'] : null, 'max' => $exercise['target_rir_max'] !== null ? (float) $exercise['target_rir_max'] : null],
                    'rest_seconds' => (int) $exercise['rest_seconds'], 'weight_kg' => $exercise['planned_weight_kg'] !== null ? (float) $exercise['planned_weight_kg'] : null,
                    'method' => $exercise['method_type'], 'instructions' => $exercise['instructions'],
                ],
                'fact' => [
                    'exercise_id' => $exercise['actual_exercise_id'], 'name' => $exercise['exercise_name'], 'status' => $exercise['status'],
                    'skip' => $exercise['status'] === 'skipped' ? ['reason' => $exercise['skip_reason']] : null,
                    'substitution' => $substituted ? [
                        'original_exercise_id' => $exercise['original_exercise_id'], 'actual_exercise_id' => $exercise['actual_exercise_id'],
                        'reason' => $exercise['substitution_reason'], 'substituted_at_utc' => $this->utc($exercise['substituted_at']),
                    ] : null,
                    'sets' => $sets, ...$factMetrics, 'exercise_rating' => $exercise['exercise_rating'], 'comment' => $exercise['comment'],
                    'discomfort' => $discomfortByExercise[$exercise['id']] ?? [], 'personal_records' => $recordsByExercise[$exercise['actual_exercise_id']] ?? [],
                ],
                'suggestion' => $suggestion ? [
                    'id' => (int) $suggestion['id'], 'current_weight_kg' => (float) $suggestion['current_weight_kg'],
                    'suggested_weight_kg' => (float) $suggestion['suggested_next_weight_kg'],
                    'accepted_weight_kg' => $suggestion['accepted_next_weight_kg'] !== null ? (float) $suggestion['accepted_next_weight_kg'] : null,
                    'status' => $suggestion['status'], 'reason' => $suggestion['reason'], 'created_at_utc' => $this->utc($suggestion['created_at']),
                    'resolved_at_utc' => $this->utc($suggestion['resolved_at']), 'program_changed' => false,
                ] : null,
            ];
        }

        $previous = $this->previousSession($session, $userId);
        $comparison = $previous ? [
            'session_id' => $previous['public_id'], 'date' => $previous['scheduled_date'],
            'duration_minutes_delta' => $session['summary']['duration_minutes'] - $previous['summary']['duration_minutes'],
            'working_sets_delta' => $session['summary']['working_sets'] - $previous['summary']['working_sets'],
            'tonnage_kg_delta' => round($session['summary']['tonnage_kg'] - $previous['summary']['tonnage_kg'], 2),
            'average_rir_delta' => $this->nullableDelta($session['summary']['average_rir'], $previous['summary']['average_rir']),
        ] : null;

        return [
            'schema' => 'training-report', 'schema_version' => '1.0', 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'plan' => ['plan_id' => $session['external_plan_id'], 'name' => $session['name'], 'date' => $session['scheduled_date'], 'type' => $session['workout_type'], 'goal' => $session['goal'], 'trainer_notes' => $session['trainer_notes']],
            'session' => [
                'session_id' => $session['public_id'], 'status' => $session['status'], 'started_at_utc' => $this->utc($session['started_at']),
                'finished_at_utc' => $this->utc($session['finished_at']), 'duration_minutes' => $session['summary']['duration_minutes'],
                'session_rpe' => $session['session_rpe'] !== null ? (int) $session['session_rpe'] : null,
                'wellbeing' => $session['wellbeing'] !== null ? (int) $session['wellbeing'] : null, 'comment' => $session['user_comment'],
                'edited_after_completion' => (bool) $session['edited_after_completion'], 'edited_at_utc' => $this->utc($session['edited_at']),
            ],
            'readiness' => [
                'body_weight_kg' => $session['body_weight_kg'] !== null ? (float) $session['body_weight_kg'] : null,
                'sleep' => $session['sleep_score'] !== null ? (int) $session['sleep_score'] : null,
                'energy' => $session['energy_score'] !== null ? (int) $session['energy_score'] : null,
                'readiness' => $session['readiness_score'] !== null ? (int) $session['readiness_score'] : null, 'comment' => $session['readiness_comment'],
            ],
            'exercises' => $exercises,
            'summary' => [
                'completed_exercises' => $session['summary']['completed_exercises'], 'skipped_exercises' => $session['summary']['skipped_exercises'],
                'total_exercises' => $session['summary']['total_exercises'], 'working_sets' => $session['summary']['working_sets'],
                'tonnage_kg' => $session['summary']['tonnage_kg'], 'average_rir' => $session['summary']['average_rir'],
                'personal_records' => $records, 'comparison_with_previous' => $comparison,
            ],
            'edits' => $this->auditTrail($session, $userId),
        ];
    }

    public function markdown(array $report): string
    {
        if (\locale() === 'en') return $this->markdownEnglish(\App\Core\SystemContent::localize($report));
        $plan = $report['plan']; $session = $report['session']; $ready = $report['readiness']; $summary = $report['summary'];
        $lines = [
            '# Отчёт о тренировке: ' . $plan['name'], '', '- Дата: ' . $plan['date'], '- Длительность: ' . $session['duration_minutes'] . ' мин.',
            '- План: `' . $plan['plan_id'] . '`', '- Общая тяжесть: ' . ($session['session_rpe'] ?? 'не указана') . '/10',
            '- Самочувствие: ' . ($session['wellbeing'] ?? 'не указано') . '/5', '', '## Состояние перед тренировкой', '',
            '- Вес: ' . ($ready['body_weight_kg'] !== null ? $ready['body_weight_kg'] . ' кг' : 'не указан'),
            '- Сон: ' . ($ready['sleep'] !== null ? $ready['sleep'] . '/5' : 'не указан'),
            '- Энергия: ' . ($ready['energy'] !== null ? $ready['energy'] . '/5' : 'не указана'),
            '- Готовность: ' . ($ready['readiness'] !== null ? $ready['readiness'] . '/5' : 'не указана'),
        ];
        if ($ready['comment']) $lines[] = '- Комментарий: ' . $ready['comment'];
        $lines[] = '';
        foreach ($report['exercises'] as $exercise) {
            $planned = $exercise['planned']; $fact = $exercise['fact'];
            $lines[] = '## ' . $exercise['name']; $lines[] = '';
            $lines[] = '**План:** ' . $planned['sets'] . ' × ' . $planned['rep_range']['min'] . '–' . $planned['rep_range']['max'] . '; RIR ' . ($planned['target_rir']['min'] ?? '—') . '–' . ($planned['target_rir']['max'] ?? '—') . '; отдых ' . $planned['rest_seconds'] . ' сек.';
            if ($fact['substitution']) $lines[] = '**Замена:** `' . $fact['substitution']['original_exercise_id'] . '` → `' . $fact['substitution']['actual_exercise_id'] . '`. Причина: ' . ($fact['substitution']['reason'] ?: 'не указана') . '.';
            if ($fact['skip']) {
                $lines[] = '**Факт:** упражнение пропущено. Причина: `' . $fact['skip']['reason'] . '`.';
            } else {
                $lines[] = '**Факт:**';
                foreach ($fact['sets'] as $set) {
                    $label = $set['type'] === 'warmup' ? 'Разминка ' . $set['set_number'] : 'Рабочий ' . $set['set_number'];
                    $lines[] = '- ' . $label . ': ' . $set['weight_kg'] . ' кг × ' . $set['reps'] . '; RIR ' . $set['rir'];
                }
                $lines[] = '- Объём упражнения: ' . $fact['tonnage_kg'] . ' кг; средний RIR: ' . ($fact['average_rir'] ?? 'нет данных') . '.';
                if ($fact['best_e1rm']) $lines[] = '- Лучший e1RM по Epley: ' . $fact['best_e1rm']['e1rm_kg'] . ' кг (оценка, не фактический максимум).';
            }
            if ($fact['personal_records']) $lines[] = '- Новый личный ориентир e1RM: ' . $fact['personal_records'][0]['value'] . ' кг.';
            foreach ($fact['discomfort'] as $item) $lines[] = '- Дискомфорт: ' . $item['body_area'] . ', ' . $item['intensity'] . '/10' . ($item['comment'] ? ' — ' . $item['comment'] : '') . '.';
            if ($fact['comment']) $lines[] = '- Комментарий: ' . $fact['comment'];
            $suggestion = $exercise['suggestion'];
            $lines[] = $suggestion ? '**Предложение:** ' . $suggestion['current_weight_kg'] . ' → ' . $suggestion['suggested_weight_kg'] . ' кг; статус `' . $suggestion['status'] . '`. Программа не изменена автоматически.' : '**Предложение:** повышения веса нет.';
            $lines[] = '';
        }
        array_push($lines, '## Итоги', '', '- Выполнено упражнений: ' . $summary['completed_exercises'] . '; пропущено: ' . $summary['skipped_exercises'] . '.',
            '- Рабочих подходов: ' . $summary['working_sets'] . '.', '- Тренировочный объём: ' . $summary['tonnage_kg'] . ' кг.', '- Средний RIR: ' . ($summary['average_rir'] ?? 'нет данных') . '.');
        $comparison = $summary['comparison_with_previous'];
        $lines[] = $comparison ? '- К прошлой такой тренировке: объём ' . $this->signed($comparison['tonnage_kg_delta']) . ' кг, рабочих подходов ' . $this->signed($comparison['working_sets_delta']) . ', длительность ' . $this->signed($comparison['duration_minutes_delta']) . ' мин.' : '- Сравнение: предыдущая такая тренировка не найдена.';
        array_push($lines, '', '## Комментарий пользователя', '', $session['comment'] ?: 'Нет комментария.', '');
        if ($session['edited_after_completion']) { $lines[] = '> Данные редактировались после завершения; изменения сохранены в audit trail JSON-отчёта.'; $lines[] = ''; }
        return implode("\n", $lines);
    }

    private function markdownEnglish(array $report): string
    {
        $plan = $report['plan']; $session = $report['session']; $ready = $report['readiness']; $summary = $report['summary'];
        $missing = 'not provided';
        $lines = [
            '# Workout report: ' . $plan['name'], '', '- Date: ' . $plan['date'], '- Duration: ' . $session['duration_minutes'] . ' min',
            '- Plan: `' . $plan['plan_id'] . '`', '- Session effort: ' . ($session['session_rpe'] ?? $missing) . '/10',
            '- Wellbeing: ' . ($session['wellbeing'] ?? $missing) . '/5', '', '## Pre-workout readiness', '',
            '- Weight: ' . ($ready['body_weight_kg'] !== null ? $ready['body_weight_kg'] . ' kg' : $missing),
            '- Sleep: ' . ($ready['sleep'] !== null ? $ready['sleep'] . '/5' : $missing),
            '- Energy: ' . ($ready['energy'] !== null ? $ready['energy'] . '/5' : $missing),
            '- Readiness: ' . ($ready['readiness'] !== null ? $ready['readiness'] . '/5' : $missing),
        ];
        if ($ready['comment']) $lines[] = '- Comment: ' . $ready['comment'];
        $lines[] = '';
        foreach ($report['exercises'] as $exercise) {
            $planned = $exercise['planned']; $fact = $exercise['fact'];
            array_push($lines, '## ' . $exercise['name'], '', '**Plan:** ' . $planned['sets'] . ' × ' . $planned['rep_range']['min'] . '–' . $planned['rep_range']['max'] . '; RIR ' . ($planned['target_rir']['min'] ?? '—') . '–' . ($planned['target_rir']['max'] ?? '—') . '; rest ' . $planned['rest_seconds'] . ' sec.');
            if ($fact['substitution']) $lines[] = '**Substitution:** `' . $fact['substitution']['original_exercise_id'] . '` → `' . $fact['substitution']['actual_exercise_id'] . '`. Reason: ' . ($fact['substitution']['reason'] ?: $missing) . '.';
            if ($fact['skip']) {
                $lines[] = '**Result:** exercise skipped. Reason: `' . $fact['skip']['reason'] . '`.';
            } else {
                $lines[] = '**Result:**';
                foreach ($fact['sets'] as $set) $lines[] = '- ' . ($set['type'] === 'warmup' ? 'Warm-up ' : 'Working ') . $set['set_number'] . ': ' . $set['weight_kg'] . ' kg × ' . $set['reps'] . '; RIR ' . $set['rir'];
                $lines[] = '- Exercise volume: ' . $fact['tonnage_kg'] . ' kg; average RIR: ' . ($fact['average_rir'] ?? 'no data') . '.';
                if ($fact['best_e1rm']) $lines[] = '- Best Epley e1RM: ' . $fact['best_e1rm']['e1rm_kg'] . ' kg (estimate, not an actual maximum).';
            }
            if ($fact['personal_records']) $lines[] = '- New personal e1RM benchmark: ' . $fact['personal_records'][0]['value'] . ' kg.';
            foreach ($fact['discomfort'] as $item) $lines[] = '- Discomfort: ' . $item['body_area'] . ', ' . $item['intensity'] . '/10' . ($item['comment'] ? ' — ' . $item['comment'] : '') . '.';
            if ($fact['comment']) $lines[] = '- Comment: ' . $fact['comment'];
            $suggestion = $exercise['suggestion'];
            $lines[] = $suggestion ? '**Suggestion:** ' . $suggestion['current_weight_kg'] . ' → ' . $suggestion['suggested_weight_kg'] . ' kg; status `' . $suggestion['status'] . '`. The program was not changed automatically.' : '**Suggestion:** no weight increase.';
            $lines[] = '';
        }
        array_push($lines, '## Summary', '', '- Exercises completed: ' . $summary['completed_exercises'] . '; skipped: ' . $summary['skipped_exercises'] . '.', '- Working sets: ' . $summary['working_sets'] . '.', '- Training volume: ' . $summary['tonnage_kg'] . ' kg.', '- Average RIR: ' . ($summary['average_rir'] ?? 'no data') . '.');
        $comparison = $summary['comparison_with_previous'];
        $lines[] = $comparison ? '- Compared with the previous matching workout: volume ' . $this->signed($comparison['tonnage_kg_delta']) . ' kg, working sets ' . $this->signed($comparison['working_sets_delta']) . ', duration ' . $this->signed($comparison['duration_minutes_delta']) . ' min.' : '- Comparison: no previous matching workout was found.';
        array_push($lines, '', '## User comment', '', $session['comment'] ?: 'No comment.', '');
        if ($session['edited_after_completion']) array_push($lines, '> Data was edited after completion; changes are preserved in the JSON report audit trail.', '');
        return implode("\n", $lines);
    }

    private function previousSession(array $session, int $userId): ?array
    {
        $query = $this->pdo->prepare("SELECT previous.id FROM workout_sessions previous JOIN workout_plans p ON p.id=previous.workout_plan_id WHERE previous.user_id=? AND previous.status='completed' AND previous.deleted_at IS NULL AND previous.id<>? AND (previous.finished_at<? OR (previous.finished_at=? AND previous.id<?)) AND ((? IS NOT NULL AND p.workout_template_id=?) OR (? IS NULL AND p.name=? AND p.workout_type=?)) ORDER BY previous.finished_at DESC,previous.id DESC LIMIT 1");
        $templateId = $session['workout_template_id'];
        $query->execute([$userId, $session['id'], $session['finished_at'], $session['finished_at'], $session['id'], $templateId, $templateId, $templateId, $session['name'], $session['workout_type']]);
        $id = $query->fetchColumn();
        return $id ? $this->training->session((int) $id, $userId) : null;
    }

    private function auditTrail(array $session, int $userId): array
    {
        $entities = ['workout_session' => [(string) $session['id']]];
        foreach ($session['exercises'] as $exercise) {
            $entities['session_exercise'][] = (string) $exercise['id'];
            foreach ($exercise['sets'] as $set) $entities['exercise_set'][] = (string) $set['id'];
        }
        $query = $this->pdo->prepare('SELECT entity_type,entity_id,action,before_json,after_json,created_at FROM audit_logs WHERE user_id=? ORDER BY id');
        $query->execute([$userId]); $result = [];
        foreach ($query->fetchAll() as $row) {
            if (!in_array((string) $row['entity_id'], $entities[$row['entity_type']] ?? [], true)) continue;
            $result[] = ['entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id'], 'action' => $row['action'],
                'before' => $this->jsonObject($row['before_json']), 'after' => $this->jsonObject($row['after_json']), 'at_utc' => $this->utc($row['created_at'])];
        }
        return $result;
    }

    private function jsonObject(mixed $json): mixed
    {
        return !is_string($json) || $json === '' ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function utc(?string $value): ?string
    {
        return $value === null || $value === '' ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC'));
    }

    private function nullableDelta(mixed $current, mixed $previous): ?float
    {
        return $current === null || $previous === null ? null : round((float) $current - (float) $previous, 1);
    }

    private function signed(float|int $value): string
    {
        return $value > 0 ? '+' . $value : (string) $value;
    }
}
