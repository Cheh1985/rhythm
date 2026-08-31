<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

final class WriteIdempotencyService
{
    public function replay(PDO $pdo, int $userId, string $key, string $action, array $request): ?array
    {
        $this->validate($key, $action);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $user = $pdo->prepare('SELECT id FROM users WHERE id=?' . $lock);
        $user->execute([$userId]);
        if ($user->fetchColumn() === false) {
            throw new InvalidArgumentException('Пользователь не найден.');
        }

        $query = $pdo->prepare('SELECT action_type,request_hash,response_json FROM assistant_write_receipts WHERE user_id=? AND idempotency_key=?');
        $query->execute([$userId, $key]);
        $receipt = $query->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) {
            return null;
        }
        $hash = $this->requestHash($request);
        if (!hash_equals((string) $receipt['action_type'], $action) || !hash_equals((string) $receipt['request_hash'], $hash)) {
            throw new InvalidArgumentException('Idempotency-Key уже использован для другого запроса.');
        }
        try {
            $response = json_decode((string) $receipt['response_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Повреждён idempotency receipt.', 0, $exception);
        }
        if (!is_array($response) || array_is_list($response)) {
            throw new RuntimeException('Повреждён результат идемпотентного запроса.');
        }
        $response['idempotent'] = true;
        return $response;
    }

    public function store(PDO $pdo, int $userId, string $key, string $action, array $request, array $response): void
    {
        $this->validate($key, $action);
        $insert = $pdo->prepare('INSERT INTO assistant_write_receipts (user_id,idempotency_key,action_type,request_hash,response_json,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)');
        $insert->execute([
            $userId,
            $key,
            $action,
            $this->requestHash($request),
            json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    private function validate(string $key, string $action): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Некорректный Idempotency-Key.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{2,47}$/D', $action) !== 1) {
            throw new InvalidArgumentException('Некорректный тип идемпотентного действия.');
        }
    }

    private function requestHash(array $request): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($sort, $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $child) {
                $value[$key] = $sort($child);
            }
            return $value;
        };
        return hash('sha256', json_encode($sort($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
