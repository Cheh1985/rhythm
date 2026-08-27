<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use PDO;

final class ProgramVersionReconciliationService
{
    public function __construct(private readonly ?PDO $connection = null) {}

    /** @return list<array<string, mixed>> */
    public function inspect(?int $userId = null): array
    {
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('user_id должен быть положительным числом.');
        }

        $sql = <<<'SQL'
SELECT p.id,p.user_id,p.external_program_id,p.name,p.active_version_id,
       COUNT(pv.id) AS version_count,
       MAX(CASE WHEN pv.id=p.active_version_id THEN pv.version_number END) AS active_version_number,
       SUM(CASE WHEN pv.id=p.active_version_id THEN 1 ELSE 0 END) AS pointer_matches
FROM training_programs p
LEFT JOIN program_versions pv ON pv.program_id=p.id
WHERE p.deleted_at IS NULL
SQL;
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND p.user_id=?';
            $params[] = $userId;
        }
        $sql .= ' GROUP BY p.id,p.user_id,p.external_program_id,p.name,p.active_version_id ORDER BY p.user_id,p.id';
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);

        return array_map(static function (array $row): array {
            $versionCount = (int) $row['version_count'];
            $pointerMatches = (int) $row['pointer_matches'];
            if ($row['active_version_id'] !== null) {
                $state = $pointerMatches === 1 ? 'resolved' : 'invalid_pointer';
            } elseif ($versionCount === 0) {
                $state = 'no_versions';
            } elseif ($versionCount === 1) {
                $state = 'reconcilable';
            } else {
                $state = 'ambiguous';
            }
            return [
                'program_id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'external_program_id' => (string) $row['external_program_id'],
                'name' => (string) $row['name'],
                'version_count' => $versionCount,
                'active_version_id' => $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
                'active_version_number' => $row['active_version_number'] === null ? null : (int) $row['active_version_number'],
                'state' => $state,
            ];
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{updated:int,programs:list<array<string,mixed>>} */
    public function reconcileUnambiguous(?int $userId = null): array
    {
        $pdo = $this->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $updated = 0;
            foreach ($this->inspect($userId) as $program) {
                if ($program['state'] !== 'reconcilable') {
                    continue;
                }
                $version = $pdo->prepare('SELECT id FROM program_versions WHERE program_id=? ORDER BY id LIMIT 1');
                $version->execute([$program['program_id']]);
                $versionId = $version->fetchColumn();
                if ($versionId === false) {
                    continue;
                }
                $update = $pdo->prepare(<<<'SQL'
UPDATE training_programs
SET active_version_id=?,updated_at=UTC_TIMESTAMP()
WHERE id=? AND user_id=? AND active_version_id IS NULL
  AND (SELECT COUNT(*) FROM program_versions pv WHERE pv.program_id=training_programs.id)=1
SQL);
                $update->execute([(int) $versionId, $program['program_id'], $program['user_id']]);
                if ($update->rowCount() !== 1) {
                    continue;
                }
                $markActive = $pdo->prepare('UPDATE program_versions SET activated_at=COALESCE(activated_at,created_at) WHERE id=? AND program_id=?');
                $markActive->execute([(int) $versionId, $program['program_id']]);
                $updated++;
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['updated' => $updated, 'programs' => $this->inspect($userId)];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function pdo(): PDO
    {
        return $this->connection ?? \db()->pdo();
    }
}
