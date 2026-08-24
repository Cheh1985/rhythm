<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = \env('DB_DSN');
        if (!$dsn) {
            $host = \env('DB_HOST', '127.0.0.1');
            $port = \env('DB_PORT', '3306');
            $name = \env('DB_NAME', 'training_diary');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        try {
            $this->pdo = new PDO($dsn, \env('DB_USER', ''), \env('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && APP_ENVIRONMENT !== 'production' && method_exists($this->pdo, 'sqliteCreateFunction')) {
                $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Не удалось подключиться к базе данных.', 0, $exception);
        }

        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
