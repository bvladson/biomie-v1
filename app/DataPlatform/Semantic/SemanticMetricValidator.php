<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Validates the full MetricRegistry and returns a SemanticAuditResult.
 *
 * Checks performed:
 * - Duplicate KPI IDs (caught by registry itself, so treated as impossible here)
 * - Invalid domain / status / source_of_truth (constructor enforces these)
 * - Empty or unknown RBAC permission
 * - Active KPI missing source_table or official_date
 * - Active KPI with discouraged source_of_truth
 * - KPI freshness_domain unknown (not mapped in FreshnessService::DOMAIN_STALE_MINUTES)
 * - Entity / dimension / measure referenced by a KPI but not registered (warns)
 * - Draft KPIs with no notes (warns)
 */
final class SemanticMetricValidator
{
    /** Must mirror FreshnessService::DOMAIN_STALE_MINUTES keys. */
    private const KNOWN_FRESHNESS_DOMAINS = [
        // storage tiers
        'gold', 'canonical', 'operational', 'legacy', 'external',
        // business domain aliases
        'sales', 'fiscal', 'finance', 'customers', 'geo', 'cnae', 'omie', 'operations', 'shadow',
    ];

    public function validate(MetricRegistry $registry): SemanticAuditResult
    {
        $result = new SemanticAuditResult();
        $permMap = new MetricPermissionMap();

        $kpis = $registry->all();
        $result->metricsTotal = count($kpis);

        $registeredEntityIds   = array_keys($registry->allEntities());
        $registeredDimIds      = array_keys($registry->allDimensions());
        $registeredMeasureIds  = array_keys($registry->allMeasures());

        foreach ($kpis as $def) {
            $this->validateKpi($def, $permMap, $registeredEntityIds, $registeredDimIds, $registeredMeasureIds, $result);

            match ($def->status) {
                'active'     => $result->active++,
                'draft'      => $result->draft++,
                'deprecated' => $result->deprecated++,
                default      => null,
            };
        }

        return $result;
    }

    // -------------------------------------------------------------------------

    private function validateKpi(
        KpiDefinition     $def,
        MetricPermissionMap $permMap,
        array             $entityIds,
        array             $dimIds,
        array             $measureIds,
        SemanticAuditResult $result,
    ): void {
        $id = $def->id;

        // RBAC permission
        if ($def->permission === '') {
            $result->addError("[{$id}] permission is empty.");
        } elseif (!$permMap->isKnownPermission($def->permission)) {
            $result->addError("[{$id}] permission '{$def->permission}' not in RBAC matrix.");
        }

        // Active-only constraints
        if ($def->isActive()) {
            if ($def->sourceTable === '') {
                $result->addError("[{$id}] active KPI has no source_table.");
            }
            if ($def->officialDate === '') {
                $result->addError("[{$id}] active KPI has no official_date.");
            }
            if (DataSourceTruth::isDiscouraged($def->sourceOfTruth)) {
                $result->addError("[{$id}] active KPI uses discouraged source_of_truth '{$def->sourceOfTruth}'.");
            }
        }

        // Freshness domain
        if (!in_array($def->freshnessDomain, self::KNOWN_FRESHNESS_DOMAINS, true)) {
            $result->addError("[{$id}] unknown freshness_domain '{$def->freshnessDomain}'. Known: " . implode(', ', self::KNOWN_FRESHNESS_DOMAINS) . '.');
        }

        // Source of truth validity (constructor enforces this but double-check)
        if (!DataSourceTruth::isValid($def->sourceOfTruth)) {
            $result->addError("[{$id}] invalid source_of_truth '{$def->sourceOfTruth}'.");
        }

        // Cross-reference warnings: entities, dimensions, measures
        foreach ($def->entities as $eid) {
            if (!in_array($eid, $entityIds, true)) {
                $result->addWarning("[{$id}] references entity '{$eid}' which is not registered.");
            }
        }
        foreach ($def->dimensions as $did) {
            if (!in_array($did, $dimIds, true)) {
                $result->addWarning("[{$id}] references dimension '{$did}' which is not registered.");
            }
        }
        foreach ($def->measures as $mid) {
            if (!in_array($mid, $measureIds, true)) {
                $result->addWarning("[{$id}] references measure '{$mid}' which is not registered.");
            }
        }

        // Draft without notes
        if ($def->isDraft() && trim($def->notes) === '') {
            $result->addWarning("[{$id}] draft KPI has no notes explaining what is unresolved.");
        }
    }
}
