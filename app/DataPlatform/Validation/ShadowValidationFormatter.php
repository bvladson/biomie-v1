<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

final class ShadowValidationFormatter
{
    public function payloadHash(array $payload): string
    {
        return hash('sha256', $this->encodeJson($this->normalizeForHash($payload)));
    }

    public function encodeJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }

    public function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }

    public function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, max(0, $maxLength - 3)) . '...';
    }

    public function maxSeverity(string $left, string $right): string
    {
        $priority = [
            'none' => 0,
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
        ];

        return ($priority[$right] ?? 0) > ($priority[$left] ?? 0) ? $right : $left;
    }
}
