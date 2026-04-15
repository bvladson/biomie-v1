<?php

declare(strict_types=1);

namespace BIomie\Canonical\Support;

final class CanonicalJsonCodec
{
    public function encode(mixed $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Falha ao serializar JSON.');
        }

        return $json;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function decodeObject(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }
}
