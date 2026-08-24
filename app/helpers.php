<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    extract($data, EXTR_SKIP);
    $viewFile = APP_ROOT . '/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $view);
    }
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require APP_ROOT . '/views/layout.php';
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
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function line_chart(array $points, string $title, string $unit = ''): string
{
    $points = array_values(array_filter($points, static fn (array $point): bool => isset($point['value']) && is_numeric($point['value'])));
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
