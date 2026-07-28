<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Lightweight facade for builder-internal KPI context lookups.
 *
 * Builders call this to verify their data source matches the registry contract
 * and to get the official permission/freshness_domain for a KPI.
 * Zero JSON contract impact — for logging and validation only.
 */
final class KpiContextService
{
    private MetricRegistry $registry;

    public function __construct()
    {
        $this->registry = MetricRegistry::build();
    }

    /**
     * Returns KPI definitions for a business domain, keyed by KPI ID.
     *
     * @return array<string,KpiDefinition>
     */
    public function forDomain(string $domain): array
    {
        $result = [];
        foreach ($this->registry->byDomain($domain) as $def) {
            $result[$def->id] = $def;
        }
        return $result;
    }

    /**
     * Returns the primary KPI for a domain (first active one, or null).
     */
    public function primaryKpiForDomain(string $domain): ?KpiDefinition
    {
        foreach ($this->registry->byDomain($domain) as $def) {
            if ($def->isActive()) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Checks whether the given source_of_truth matches the registry contract
     * for any active KPI in this domain. Returns a validation message or null.
     */
    public function validateSource(string $domain, string $actualSource): ?string
    {
        foreach ($this->registry->byDomain($domain) as $def) {
            if ($def->isActive() && $def->sourceOfTruth !== $actualSource) {
                return "KPI {$def->id} expects source_of_truth='{$def->sourceOfTruth}', got '{$actualSource}'.";
            }
        }
        return null;
    }

    /**
     * Returns the RBAC permission required to view any active KPI in this domain.
     * Returns '' if no active KPI found.
     */
    public function permissionForDomain(string $domain): string
    {
        $kpi = $this->primaryKpiForDomain($domain);
        return $kpi !== null ? $kpi->permission : '';
    }

    /**
     * Returns summary metadata for logging purposes.
     *
     * @return array{active:int, draft:int, domains:list<string>}
     */
    public function summary(): array
    {
        return [
            'active'  => count($this->registry->active()),
            'draft'   => count($this->registry->draft()),
            'domains' => array_unique(array_map(fn ($d) => $d->domain, $this->registry->all())),
        ];
    }
}
