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

    /**
     * Official Omie consumption order for BIomie commercial data.
     */
    public const OMIE_ARCHITECTURE_FLOW = [
        'omie_api',
        'raw_staging',
        'canonical',
        'commercial_marts',
        'internal_apis',
        'pages_and_drawers',
    ];

    /**
     * Contract tables for the current Omie commercial surfaces.
     */
    public const OFFICIAL_OMIE_TABLES = [
        'realized_sales' => [
            'tier' => self::CANONICAL,
            'table' => 'biomie_notas_fiscais_canonicas',
            'date_column' => 'data_competencia',
            'required_filter' => "status_fiscal = 'autorizada'",
            'vendor_key' => 'codigo_vendedor_omie',
        ],
        'items_products' => [
            'tier' => self::CANONICAL,
            'table' => 'canonical_invoice_items_or_productDocumentBreakdownCommercial',
            'date_column' => 'data_competencia',
            'required_filter' => "parent NF status_fiscal = 'autorizada'",
        ],
        'targets' => [
            'tier' => self::GOLD,
            'table' => 'biomie_sales_targets_monthly',
            'date_column' => 'year_month',
            'vendor_key' => 'codigo_vendedor_omie',
        ],
        'vendors' => [
            'tier' => self::CANONICAL,
            'primary_key' => 'codigo_vendedor_omie',
            'fallback_key' => 'vendor_name_for_display_only',
        ],
        'map' => [
            'tier' => self::GOLD,
            'table' => 'mart_compradores_por_geo',
            'dimension_table' => 'biomie_bairros_fortaleza_dim',
            'required_diagnostic' => 'coverage',
        ],
    ];

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

    /** Returns the official Omie data flow from ingestion to UI. */
    public static function omieArchitectureFlow(): array
    {
        return self::OMIE_ARCHITECTURE_FLOW;
    }

    /** Returns the official Omie source contract by business domain. */
    public static function officialOmieTables(): array
    {
        return self::OFFICIAL_OMIE_TABLES;
    }

    /** Returns a human-readable guideline for the given source. */
    public static function guideline(string $source): string
    {
        return match ($source) {
            self::GOLD        => 'Preferred for dashboard KPIs when the mart reconciles to the canonical contract.',
            self::CANONICAL   => 'Official transaction layer synced from Omie. Use for auditability and drilldown.',
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
