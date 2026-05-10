<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final class DataSourceTruth
{
    public const BRONZE       = 'bronze';
    public const SILVER       = 'silver';
    public const CANONICAL    = 'canonical';
    public const GOLD         = 'gold';
    public const LEGACY       = 'legacy';
    public const SHADOW       = 'shadow';
    public const EXTERNAL_OMIE = 'external_omie';
    public const OPERATIONAL  = 'operational';

    public const ALL = [
        self::BRONZE,
        self::SILVER,
        self::CANONICAL,
        self::GOLD,
        self::LEGACY,
        self::SHADOW,
        self::EXTERNAL_OMIE,
        self::OPERATIONAL,
    ];

    /**
     * Sources approved for dashboard KPIs (prefer gold or canonical).
     * Legacy is accepted when a documented fallback exists.
     */
    public const DASHBOARD_APPROVED = [self::GOLD, self::CANONICAL, self::LEGACY, self::OPERATIONAL];

    /**
     * Sources NOT suitable as primary source for a final KPI.
     */
    public const DISCOURAGED = [self::BRONZE, self::EXTERNAL_OMIE, self::SHADOW];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    public static function isDashboardApproved(string $source): bool
    {
        return in_array($source, self::DASHBOARD_APPROVED, true);
    }

    public static function isDiscouraged(string $source): bool
    {
        return in_array($source, self::DISCOURAGED, true);
    }

    /** Returns a human-readable guideline for the given source. */
    public static function guideline(string $source): string
    {
        return match ($source) {
            self::GOLD        => 'Preferred for dashboard KPIs — fully materialized and idempotent.',
            self::CANONICAL   => 'Acceptable — canonical layer synced from Omie. May lag gold.',
            self::SILVER      => 'Intermediate — enriched but not aggregated. Use for lookup, not KPI sum.',
            self::BRONZE      => 'Raw ingestion — do not use for final KPI. Use only in pipelines.',
            self::LEGACY      => 'Legacy — accepted only with documented fallback and migration plan.',
            self::SHADOW      => 'Validation only — never the primary source of a dashboard KPI.',
            self::EXTERNAL_OMIE => 'External API — never read directly by dashboards.',
            self::OPERATIONAL => 'Operational metadata (jobs, health checks). Not for business KPIs.',
            default           => 'Unknown source.',
        };
    }
}
