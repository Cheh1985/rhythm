<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function e(mixed $value): string
{
    $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return \App\Core\Locale::protect($escaped);
}

function t(string $text, array $replace = [], ?string $locale = null): string
{
    return \App\Core\Locale::translate($text, $replace, $locale);
}

function te(string $text, array $replace = [], ?string $locale = null): string
{
    return e(t($text, $replace, $locale));
}

function locale(): string
{
    return \App\Core\Locale::current();
}

function url(string $path = ''): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    $suffix = '/' . ltrim($path, '/');
    return $base . ($suffix === '/' ? '/' : $suffix);
}

function redirect(string $path): never
{
    header('Location: ' . url('/' . ltrim($path, '/')), true, 303);
    exit;
}

function render(string $view, array $data = [], string $title = 'Дневник тренировок'): void
{
    \App\Core\Locale::bootstrap();
    $title = t($title);
    $data = \App\Core\SystemContent::localize($data);
    extract($data, EXTR_SKIP);
    $viewFile = APP_ROOT . '/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $view);
    }
    \App\Core\Locale::beginRender();
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    ob_start();
    require APP_ROOT . '/views/layout.php';
    echo \App\Core\Locale::translateMarkup((string) ob_get_clean());
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Некорректный JSON.');
    }
    return $data;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = \App\Core\SystemContent::localize($payload);
    $translateMessages = static function (array $value) use (&$translateMessages): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $translateMessages($item);
            elseif (is_string($item) && in_array($key, ['message', 'error'], true)) $value[$key] = system_message($item);
        }
        return $value;
    };
    $payload = $translateMessages($payload);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function local_datetime(?string $utc, string $timezone, string $format = 'd.m.Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        if ($format === 'd.m.Y') return \App\Core\Locale::formatUtc($utc, $timezone, false);
        if ($format === 'd.m.Y H:i') return \App\Core\Locale::formatUtc($utc, $timezone, true);
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function local_date(string $value, bool $short = false): string
{
    return \App\Core\Locale::formatDate($value, $short);
}

function unit(string $unit): string
{
    $labels = [
        'kg' => ['ru' => 'кг', 'en' => 'kg'],
        'cm' => ['ru' => 'см', 'en' => 'cm'],
        'm' => ['ru' => 'м', 'en' => 'm'],
        'min' => ['ru' => 'мин', 'en' => 'min'],
        'sec' => ['ru' => 'с', 'en' => 'sec'],
        'sets' => ['ru' => 'подх.', 'en' => 'sets'],
    ];
    return $labels[$unit][locale()] ?? $unit;
}

function system_label(string $group, string $value): string
{
    $labels = [
        'workout_type' => [
            'strength' => ['ru'=>'Силовая','en'=>'Strength'], 'swimming' => ['ru'=>'Плавание','en'=>'Swimming'],
            'cardio' => ['ru'=>'Кардио','en'=>'Cardio'], 'mobility' => ['ru'=>'Мобильность','en'=>'Mobility'], 'other' => ['ru'=>'Другое','en'=>'Other'],
        ],
        'status' => [
            'planned'=>['ru'=>'Запланирована','en'=>'Planned'], 'in_progress'=>['ru'=>'В процессе','en'=>'In progress'],
            'completed'=>['ru'=>'Завершена','en'=>'Completed'], 'cancelled'=>['ru'=>'Отменена','en'=>'Cancelled'],
            'pending'=>['ru'=>'Ожидает','en'=>'Pending'], 'active'=>['ru'=>'В работе','en'=>'In progress'],
            'waiting'=>['ru'=>'Ожидание','en'=>'Waiting'], 'skipped'=>['ru'=>'Пропущено','en'=>'Skipped'],
            'accepted'=>['ru'=>'Принято','en'=>'Accepted'], 'rejected'=>['ru'=>'Отклонено','en'=>'Rejected'],
            'inactive'=>['ru'=>'Неактивно','en'=>'Inactive'],
        ],
        'source' => ['schedule'=>['ru'=>'Из расписания','en'=>'From schedule'], 'manual'=>['ru'=>'Вручную','en'=>'Manual']],
    ];
    return $labels[$group][$value][locale()] ?? $value;
}

function system_message(string $message): string
{
    if (locale() === 'en' && preg_match('/^Верхняя граница (\d+) повторений достигнута во всех рабочих подходах при допустимом RIR\.$/u', $message, $match)) {
        return 'The upper target of ' . $match[1] . ' reps was reached in every working set within the allowed RIR range.';
    }
    return t($message);
}

function line_chart(array $points, string $title, string $unit = ''): string
{
    $points = array_values(array_filter($points, static fn (array $point): bool => isset($point['value']) && is_numeric($point['value'])));
    $title = t($title);
    $unit = match ($unit) {
        ' кг' => locale() === 'en' ? ' kg' : $unit,
        ' см' => locale() === 'en' ? ' cm' : $unit,
        default => $unit,
    };
    $heading = e($title);
    if ($points === []) {
        return '<article class="chart-card"><h3>' . $heading . '</h3><p class="muted">Пока недостаточно данных для графика.</p></article>';
    }
    $values = array_map(static fn (array $point): float => (float) $point['value'], $points);
    $min = min($values);
    $max = max($values);
    $span = max(0.0001, $max - $min);
    $count = count($points);
    $coordinates = [];
    $circles = [];
    foreach ($points as $index => $point) {
        $x = $count === 1 ? 50.0 : 5.0 + 90.0 * $index / ($count - 1);
        $y = 88.0 - 76.0 * (((float) $point['value'] - $min) / $span);
        if ($count === 1) $y = 50.0;
        $coordinates[] = round($x, 2) . ',' . round($y, 2);
        $circles[] = '<circle cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="1.7"><title>' . e(($point['label'] ?? '') . ': ' . $point['value'] . $unit) . '</title></circle>';
    }
    $rows = '';
    foreach ($points as $point) {
        $rows .= '<tr><td>' . e($point['label'] ?? '') . '</td><td>' . e($point['value']) . e($unit) . '</td></tr>';
    }
    $description = $title . ': от ' . round($min, 2) . $unit . ' до ' . round($max, 2) . $unit;
    return '<article class="chart-card"><div class="chart-head"><h3>' . $heading . '</h3><span>' . e(round($values[array_key_last($values)], 2) . $unit) . '</span></div>'
        . '<svg class="line-chart" viewBox="0 0 100 100" role="img" aria-label="' . e($description) . '" preserveAspectRatio="none"><line x1="5" y1="88" x2="95" y2="88"/><line x1="5" y1="12" x2="5" y2="88"/><polyline points="' . e(implode(' ', $coordinates)) . '"/>' . implode('', $circles) . '</svg>'
        . '<div class="chart-scale"><span>' . e($points[0]['label'] ?? '') . '</span><span>' . e($points[array_key_last($points)]['label'] ?? '') . '</span></div>'
        . '<details class="chart-data"><summary>Таблица данных</summary><div class="table-scroll"><table><thead><tr><th>Дата</th><th>Значение</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details></article>';
}
