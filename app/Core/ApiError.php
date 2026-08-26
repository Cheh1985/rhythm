<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ApiError extends RuntimeException
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        private readonly string $errorCode,
        string $publicMessage,
        private readonly int $status = 400,
        private readonly array $details = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Некорректный код API-ошибки.');
        }
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('Некорректный HTTP-статус API-ошибки.');
        }
        parent::__construct($publicMessage);
    }

    public static function internal(): self
    {
        return new self('internal_error', 'Внутренняя ошибка сервера.', 500);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array{error: array<string, mixed>} */
    public function envelope(): array
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'request_id' => RequestContext::requestId(),
        ];
        if ($this->details !== []) {
            $error['details'] = $this->details;
        }
        return ['error' => $error];
    }
}
