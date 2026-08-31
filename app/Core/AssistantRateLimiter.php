<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/** Database-clock rate limiting shared by semantic assistant endpoints. */
final class AssistantRateLimiter
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function enforce(
        int $userId,
        string $toolName,
        string $limitEnv,
        string $windowEnv,
        int $defaultLimit,
        int $defaultWindowSeconds,
    ): void {
        $limit = $this->boundedEnv($limitEnv, $defaultLimit, 1, 1000);
        $window = $this->boundedEnv($windowEnv, $defaultWindowSeconds, 1, 3600);
        $pdo = $this->connection ?? \db()->pdo();
        $databaseNow = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        if (!is_string($databaseNow) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $databaseNow) !== 1) {
            throw new RuntimeException('Не удалось определить время базы данных для rate limit.');
        }

        $threshold = (new \DateTimeImmutable($databaseNow))
            ->modify('-' . $window . ' seconds')
            ->format('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM assistant_tool_calls WHERE user_id=? AND tool_name=? AND created_at>=?'
        );
        $statement->execute([$userId, $toolName, $threshold]);
        if ((int) $statement->fetchColumn() >= $limit) {
            header('Retry-After: ' . $window);
            throw new ApiError('rate_limit_exceeded', 'Слишком много запросов. Повторите позже.', 429, [
                'retry_after_seconds' => $window,
            ]);
        }
    }

    private function boundedEnv(string $name, int $default, int $min, int $max): int
    {
        $value = \env($name);
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        return is_int($parsed) ? $parsed : $default;
    }
}
