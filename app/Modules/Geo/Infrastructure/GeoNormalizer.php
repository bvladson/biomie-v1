<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\GeoNameNormalizer;

final class GeoNormalizer
{
    public static function normalizeNeighborhood(string $bairro): string
    {
        return GeoNameNormalizer::normalizeNeighborhood($bairro);
    }

    public static function normalizeCity(string $cidade): string
    {
        return GeoNameNormalizer::normalizeCity($cidade);
    }

    public static function normalizeKey(string $value): string
    {
        return GeoNameNormalizer::normalizeKey($value);
    }
}
