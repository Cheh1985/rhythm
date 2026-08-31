<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use PDO;

final class AssistantAuditPruner
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function countBefore(DateTimeImmutable $threshold): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM assistant_tool_calls WHERE created_at < ?');
        $statement->execute([$threshold->format('Y-m-d H:i:s')]);
        return (int) $statement->fetchColumn();
    }

    public function pruneBefore(DateTimeImmutable $threshold): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM assistant_tool_calls WHERE created_at < ?');
        $statement->execute([$threshold->format('Y-m-d H:i:s')]);
        return $statement->rowCount();
    }

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }
}
