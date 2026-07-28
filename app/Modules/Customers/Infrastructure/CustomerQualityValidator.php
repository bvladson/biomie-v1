<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

final class CustomerQualityValidator
{
    public function normalizeMessage(string $msg): string
    {
        return strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $msg) ?: $msg);
    }

    public function isNotFoundError(string $normalized): bool
    {
        return str_contains($normalized, 'nao encontrado')
            || str_contains($normalized, 'nao localizado')
            || str_contains($normalized, 'nao existe')
            || str_contains($normalized, 'nao existem registros')
            || str_contains($normalized, 'not found');
    }

    public function isRateLimitError(string $normalized): bool
    {
        return str_contains($normalized, 'redundant')
            || str_contains($normalized, 'consumo')
            || str_contains($normalized, 'bloqueada')
            || str_contains($normalized, 'too many')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'tente novamente em');
    }
}
