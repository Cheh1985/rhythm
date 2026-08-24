<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    public function dispatch(string $method, string $path): mixed
    {
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== strtoupper($method)) {
                continue;
            }
            $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
            $regex = '#^';
            foreach ($parts ?: [] as $part) {
                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $match)) {
                    $regex .= '(?P<' . $match[1] . '>[^/]+)';
                } else {
                    $regex .= preg_quote($part, '#');
                }
            }
            $regex .= '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $handler(...array_values($params));
            }
        }
        http_response_code(404);
        if (str_starts_with($path, '/api/')) {
            \json_response(['error' => 'Маршрут не найден.'], 404);
        }
        \render('not-found', [], 'Страница не найдена');
        return null;
    }
}
