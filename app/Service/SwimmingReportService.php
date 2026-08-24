<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TrainingRepository;
use InvalidArgumentException;

final class SwimmingReportService
{
    public function __construct(private readonly TrainingRepository $training = new TrainingRepository()) {}

    public function build(int $id, int $userId): array
    {
        $session = $this->training->swimmingSession($id, $userId);
        if (!$session) throw new InvalidArgumentException('Запись плавания не найдена.');
        $intervals = array_map(static fn (array $row): array => [
            'sequence' => (int) $row['sequence_no'],
            'repeat_count' => (int) $row['repeat_count'],
            'distance_m' => (int) $row['distance_m'],
            'total_distance_m' => (int) $row['repeat_count'] * (int) $row['distance_m'],
            'style' => (string) $row['style'],
            'intensity' => $row['intensity'] === null ? null : (int) $row['intensity'],
            'rest_seconds' => $row['rest_seconds'] === null ? null : (int) $row['rest_seconds'],
            'note' => $row['note'],
        ], $session['intervals']);
        $sequence = array_map(static fn (array $row): array => [
            'type' => (string) $row['workout_type'],
            'source' => (string) $row['source_kind'],
            'id' => (string) $row['public_id'],
            'occurred_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $row['occurred_at'] . ' UTC')),
            'label' => (string) $row['name'],
            'distance_m' => $row['distance_m'] === null ? null : (int) $row['distance_m'],
        ], $this->training->trainingSequence($userId, 20));
        return [
            'schema' => 'swimming-report',
            'schema_version' => '1.0',
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'session' => [
                'session_id' => (string) $session['public_id'],
                'date' => (string) $session['swim_date'],
                'occurred_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $session['occurred_at'] . ' UTC')),
                'duration_minutes' => (int) $session['duration_minutes'],
                'pool_length_m' => (int) $session['pool_length_m'],
                'total_distance_m' => (int) $session['total_distance_m'],
                'primary_style' => (string) $session['primary_style'],
                'intensity' => (int) $session['intensity'],
                'fatigue' => ['arms' => (int) $session['arms_fatigue'], 'back' => (int) $session['back_fatigue'], 'legs' => (int) $session['legs_fatigue']],
                'wellbeing' => (int) $session['wellbeing'],
                'comment' => $session['comment'],
                'source' => (string) $session['source'],
                'schedule' => $session['schedule_id'] === null ? null : ['id' => (int) $session['schedule_id'], 'weekday' => (int) $session['schedule_weekday'], 'label' => (string) $session['schedule_label']],
                'version' => (int) $session['version'],
                'edited_at_utc' => $session['edited_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $session['edited_at'] . ' UTC')) : null,
            ],
            'intervals' => $intervals,
            'training_sequence' => $sequence,
            'interpretation' => null,
        ];
    }

    public function markdown(array $report): string
    {
        $session = $report['session'];
        $lines = [
            '# Плавание ' . $session['date'], '',
            '- Дистанция: ' . $session['total_distance_m'] . ' м',
            '- Длительность: ' . $session['duration_minutes'] . ' мин',
            '- Бассейн: ' . $session['pool_length_m'] . ' м',
            '- Основной стиль: ' . $session['primary_style'],
            '- Интенсивность: ' . $session['intensity'] . '/10',
            '- Усталость (руки / спина / ноги): ' . $session['fatigue']['arms'] . ' / ' . $session['fatigue']['back'] . ' / ' . $session['fatigue']['legs'],
            '- Самочувствие: ' . $session['wellbeing'] . '/5', '', '## Блоки', '',
        ];
        foreach ($report['intervals'] as $interval) {
            $repeat = $interval['repeat_count'] > 1 ? $interval['repeat_count'] . '×' : '';
            $line = $interval['sequence'] . '. ' . $repeat . $interval['distance_m'] . ' м · ' . $interval['style'];
            if ($interval['intensity'] !== null) $line .= ' · интенсивность ' . $interval['intensity'] . '/10';
            if ($interval['rest_seconds'] !== null) $line .= ' · отдых ' . $interval['rest_seconds'] . ' с';
            if ($interval['note']) $line .= ' — ' . str_replace(["\r", "\n"], ' ', (string) $interval['note']);
            $lines[] = $line;
        }
        if ($session['comment']) array_push($lines, '', '## Комментарий', '', (string) $session['comment']);
        array_push($lines, '', '## Последовательность тренировок', '', 'Данные перечислены без физиологических выводов.');
        foreach ($report['training_sequence'] as $item) $lines[] = '- ' . $item['occurred_at_utc'] . ' · ' . $item['type'] . ' · ' . $item['label'];
        return implode("\n", $lines) . "\n";
    }
}
