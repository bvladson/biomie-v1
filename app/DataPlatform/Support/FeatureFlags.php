<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\Auth;
use BIomie\Config;
use BIomie\Http;

final class FeatureFlags
{
    public static function geoReadModelV2(Config $config): bool
    {
        return self::enabled($config, 'geo_readmodel_v2');
    }

    public static function geoSinglePayload(Config $config): bool
    {
        return self::enabled($config, 'geo_single_payload');
    }

    public static function adminRequestOverride(string $queryKey): bool
    {
        try {
            return Auth::canAccessAdminArea() && Http::boolQuery($queryKey, false);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function enabled(Config $config, string $featureKey, bool $default = false): bool
    {
        $value = $config->get('features.' . $featureKey, $default);
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'sim', 'on'], true);
    }
}
