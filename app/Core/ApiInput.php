<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use JsonException;

final class ApiInput
{
    public static function positiveId(mixed $value, string $field = 'id'): int
    {
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException($field . ' должен быть положительным целым идентификатором.');
    }

    /** @return array<string, mixed> */
    public static function jsonObject(string $body, int $maxBytes): array
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Лимит JSON body должен быть положительным.');
        }
        if ($body === '') {
            throw new InvalidArgumentException('JSON body пуст.');
        }
        if (strlen($body) > $maxBytes) {
            throw new InvalidArgumentException('JSON body превышает допустимый размер.');
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Некорректный JSON body.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('JSON body должен быть объектом.');
        }
        return $data;
    }

    public static function string(array $data, string $field, int $maxLength, bool $allowEmpty = false): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || (!$allowEmpty && trim($value) === '') || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' должен быть строкой до ' . $maxLength . ' символов.');
        }
        return $value;
    }

    public static function integer(array $data, string $field, int $min, int $max): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($field . " должен быть целым числом от {$min} до {$max}.");
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public static function object(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($field . ' должен быть объектом.');
        }
        return $value;
    }

    /** @return list<mixed> */
    public static function list(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' должен быть массивом.');
        }
        return $value;
    }
}
