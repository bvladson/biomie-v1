<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\NeighborhoodNormalizer;

final class GeoNameNormalizer
{
    public static function normalizeNeighborhood(?string $value, ?string $city = null): string
    {
        if ($city !== null && !NeighborhoodNormalizer::isFortaleza($city)) {
            return NeighborhoodNormalizer::explain($value)['collapsed_spaces'];
        }
        return NeighborhoodNormalizer::normalizeKey($value);
    }

    public static function normalizeCity(?string $value): string
    {
        return NeighborhoodNormalizer::normalizeCity($value);
    }

    public static function normalizeKey(?string $value): string
    {
        return NeighborhoodNormalizer::normalizeKey($value);
    }
}
