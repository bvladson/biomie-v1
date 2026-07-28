<?php

declare(strict_types=1);

namespace BIomie\Http;

use DateTimeImmutable;
use InvalidArgumentException;

final class RequestInput
{
    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values) {}

    public static function fromJsonBody(string $body): self
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Corpo JSON inválido.');
        }
        return new self($decoded);
    }

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($key . ' deve ser texto.');
        }
        return trim($value);
    }

    public function requiredString(string $key): string
    {
        $value = $this->string($key);
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($key . ' é obrigatório.');
        }
        return $value;
    }

    public function positiveInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException($key . ' deve ser um inteiro positivo.');
        }
        return (int) $value;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException($key . ' deve ser uma lista.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException($key . ' deve conter somente textos.');
            }
            if (($item = trim($item)) !== '') {
                $result[] = $item;
            }
        }
        return $result;
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException($key . ' deve ser uma lista de inteiros.');
        }
        $result = [];
        foreach ($value as $item) {
            if (filter_var($item, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException($key . ' deve conter somente inteiros.');
            }
            $result[] = (int) $item;
        }
        return $result;
    }

    public function boolean(string $key): bool
    {
        $value = $this->values[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'off', 'no', null, ''], true)) {
            return false;
        }
        throw new InvalidArgumentException($key . ' deve ser booleano.');
    }

    public function date(string $key): ?DateTimeImmutable
    {
        $value = $this->string($key);
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($key . ' deve usar o formato YYYY-MM-DD.');
        }
        return $date;
    }
}
