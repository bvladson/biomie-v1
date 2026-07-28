<?php

declare(strict_types=1);

/**
 * Auditoria estatica, sem banco ou escrita, dos contratos de materializacao
 * comercial que evitam Gold parcial, meses truncados e cache stale.
 *
 * Uso: php tools/dev/commercial_gold_contract_audit.php [--pretty]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$pretty = in_array('--pretty', $_SERVER['argv'] ?? [], true);
$files = [
    'coordinator' => 'app/DataPlatform/Gold/GoldRefreshCoordinator.php',
    'service' => 'app/CommercialMaterializationService.php',
    'cnae' => 'app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php',
    'geo' => 'app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php',
    'fiscal_nfe' => 'app/DataPlatform/Gold/Builders/FiscalNfeMartBuilder.php',
    'summary' => 'app/DataPlatform/Gold/Builders/NfTituloBridgeSummaryBuilder.php',
    'invalidator' => 'app/Support/CommercialProjectionInvalidator.php',
    'cache' => 'app/Cache.php',
    'lock_set' => 'app/Support/CommercialDataLockSet.php',
    'admin' => 'app/AdminConsole/Commands/GoldMaterializeCommercialCommand.php',
    'materialize_cli' => 'bin/materialize_marts.php',
    'payload' => 'app/Competencia/CompetenciaPayloadBuilder.php',
    'snapshot_service' => 'app/CommercialCompetenciaSnapshotService.php',
    'geo_rebuild_service' => 'app/DataPlatform/Gold/GeoPeriodRebuildService.php',
    'sales_analytics' => 'app/SalesAnalyticsService.php',
    'overview_detail' => 'app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php',
    'month_comparison' => 'app/Modules/Sales/Application/Overview/OverviewMonthComparisonBuilder.php',
    'tenant_cli' => 'app/Support/TenantCliContext.php',
    'legacy_cnae_adapter' => 'app/Modules/Geo/Infrastructure/CnaeAggregateBuilder.php',
    'cnae_reader' => 'app/DataPlatform/Repository/CnaeReadRepository.php',
    'customer_reader' => 'app/DataPlatform/Repository/CustomerReadRepository.php',
    'top_clients_cnae_api' => 'api/top_clients_by_cnae.php',
    'sales_reader' => 'app/Modules/Sales/Infrastructure/SalesReadRepository.php',
    'fiscal_reader' => 'app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php',
    'fiscal_gold_base_migration' => 'database/migrations/Version20260719020000_FiscalGoldCommercialBase.php',
];

$sources = [];
$checks = [];
foreach ($files as $id => $relative) {
    $content = @file_get_contents($root . '/' . $relative);
    $sources[$id] = is_string($content) ? $content : '';
    cgcaCheck($checks, 'file_' . $id, $sources[$id] !== '', $relative . ' disponivel.');
}

$commercialRefresh = cgcaMethodBody($sources['coordinator'], 'refreshCommercialPeriod');
$fullRefresh = cgcaMethodBody($sources['coordinator'], 'refreshPeriod');
$serviceRun = cgcaMethodBody($sources['service'], 'run');
$cacheInvalidation = cgcaMethodBody($sources['cache'], 'invalidatePrefix');
$cacheGenerationWrite = cgcaMethodBody($sources['cache'], 'putWhileKeyLocked');
$cacheGetOrCompute = cgcaMethodBody($sources['cache'], 'getOrCompute');
$cacheResolvedDir = cgcaMethodBody($sources['cache'], 'resolvedDir');

cgcaCheck(
    $checks,
    'commercial_lock_before_pipeline_job',
    $commercialRefresh !== null
        && strpos($commercialRefresh, 'CommercialDataLockSet::acquire') !== false
        && strpos($commercialRefresh, "startJob('gold_mart_refresh'") !== false
        && strpos($commercialRefresh, 'CommercialDataLockSet::acquire')
            < strpos($commercialRefresh, "startJob('gold_mart_refresh'"),
    'O refresh comercial adquire os locks antes de abrir o job de pipeline.',
);
cgcaCheck(
    $checks,
    'both_refreshes_transactional',
    str_contains((string) $commercialRefresh, 'MartTransactionRunner')
        && str_contains((string) $fullRefresh, 'MartTransactionRunner'),
    'Refresh completo e comercial executam em transacao Gold.',
);
cgcaCheck(
    $checks,
    'commercial_refreshes_cnae_and_geo',
    cgcaContainsAll($commercialRefresh, [
        '$this->cnaeSales()->refresh',
        '$this->geoBuyer()->refreshReplace',
        "'vendas_cnae'",
        "'compradores_geo'",
    ]),
    'O escopo comercial reconstrói CNAE e geografia junto com os fatos centrais.',
);
cgcaCheck(
    $checks,
    'service_holds_shared_lock',
    $serviceRun !== null
        && strpos($serviceRun, 'CommercialDataLockSet::acquire') !== false
        && strpos($serviceRun, 'beginTransaction') !== false
        && strpos($serviceRun, 'CommercialDataLockSet::acquire') < strpos($serviceRun, 'beginTransaction')
        && str_contains($serviceRun, '$lockSet?->release()'),
    'Worker/admin mantêm o mesmo lock fiscal→Gold durante transacao e invalidacao.',
);
cgcaCheck(
    $checks,
    'lock_order_is_fiscal_then_gold',
    strpos($sources['lock_set'], "'biomie:fiscal_write:company:'") !== false
        && strpos($sources['lock_set'], "'biomie:materialize_marts:company:'") !== false
        && strpos($sources['lock_set'], "'biomie:fiscal_write:company:'")
            < strpos($sources['lock_set'], "'biomie:materialize_marts:company:'"),
    'Todos os entrypoints usam a ordem unica fiscal→Gold.',
);
cgcaCheck(
    $checks,
    'service_tracks_all_commercial_targets',
    cgcaContainsAll($sources['service'], [
        "'mart_vendas_por_cnae_cnpj'",
        "'mart_compradores_por_geo'",
        "'target_period_counts'",
        "'partial_gold'",
        'coreProjectionIssues',
    ]),
    'O resultado valida targets no periodo e recusa sucesso Gold parcial.',
);
cgcaCheck(
    $checks,
    'commercial_cli_uses_validated_service',
    cgcaContainsAll($sources['materialize_cli'], [
        "if (\$scope === 'commercial')",
        'new CommercialMaterializationService(',
        '$service->run(null, $startDate, $endDate)',
        "exit(!empty(\$report['ok']) ? 0 : 1)",
    ]),
    'Alias Gold comercial termina no serviço que valida partial_gold e invalida projeções.',
);
cgcaCheck(
    $checks,
    'admin_dry_run_tracks_cnae_and_geo',
    cgcaContainsAll(cgcaMethodBody($sources['admin'], 'runDryRun'), [
        "'mart_vendas_por_cnae_cnpj'",
        "'mart_compradores_por_geo'",
    ]),
    'O dry-run administrativo inspeciona os derivados CNAE e Geo.',
);
cgcaCheck(
    $checks,
    'cnae_replaces_whole_months',
    cgcaContainsAll(cgcaMethodBody($sources['cnae'], 'refresh'), [
        "date('Y-m-01', strtotime(\$start))",
        "date('Y-m-t', strtotime(\$end))",
        "'end' => \$monthEnd",
    ]),
    'CNAE apaga e reinsere a mesma janela mensal completa.',
);
cgcaCheck(
    $checks,
    'geo_replaces_whole_months',
    cgcaContainsAll(cgcaMethodBody($sources['geo'], 'refreshReplace'), [
        "date('Y-m-01', strtotime(\$start))",
        "date('Y-m-t', strtotime(\$end))",
        '$this->refresh($monthStart, $monthEnd)',
    ]),
    'Geo apaga e reinsere a mesma janela mensal completa.',
);
cgcaCheck(
    $checks,
    'fiscal_gold_preserves_commercial_base',
    cgcaContainsAll(cgcaMethodBody($sources['fiscal_nfe'], 'refresh'), [
        "columnExists('mart_fiscal_nfe', 'valor_base_comercial_nf')",
        'FiscalValueBasis::preComputedBaseSql',
        'valor_base_comercial_nf = VALUES(valor_base_comercial_nf)',
    ]) && cgcaContainsAll($sources['fiscal_gold_base_migration'], [
        'ADD COLUMN valor_base_comercial_nf DECIMAL(18,2)',
    ]),
    'Detalhes vindos do mart fiscal mantêm a mesma base comercial dos KPIs.',
);
cgcaCheck(
    $checks,
    'bridge_summary_removes_orphans',
    cgcaContainsAll(cgcaMethodBody($sources['summary'], 'refresh'), [
        'delete_orphan_bridge_summaries',
        'NOT EXISTS',
        'biomie_titulos_financeiros_canonicos',
    ]),
    'O resumo NF×titulo remove agregados sem vinculo e titulo-fonte validos.',
);
cgcaCheck(
    $checks,
    'cache_invalidation_serializes_with_compute',
    cgcaContainsAll($cacheInvalidation, [
        'acquireInvalidationGuard($dir, LOCK_EX)',
        'incrementGeneration($dir, (string) $safePrefix)',
        'unlink($file)',
    ]) && cgcaContainsAll($cacheGenerationWrite, [
        'acquireInvalidationGuard($dir, LOCK_SH)',
        '$observedGeneration !== $currentGeneration',
    ]) && $cacheGetOrCompute !== null
        && !str_contains($cacheGetOrCompute, 'acquireInvalidationGuard'),
    'A invalidacao usa geracao e locks de diretorio/chave para impedir stale-after-delete.',
);
cgcaCheck(
    $checks,
    'cache_fallback_preserves_tenant_visibility_scope',
    cgcaContainsAll($cacheResolvedDir, [
        'TenantContext::allowedCompanyIds()',
        'TenantContext::companyId()',
        'TenantContext::canal()',
        "'/tenant_'",
        '$visibility',
    ]) && !str_contains((string) $cacheResolvedDir, 'catch (\\Throwable) { return $this->cacheDir;'),
    'Falha ao criar storage dedicado continua isolada por empresa, canal e conjunto visivel.',
);
cgcaCheck(
    $checks,
    'snapshot_materialization_uses_shared_lock',
    cgcaContainsAll(cgcaMethodBody($sources['snapshot_service'], 'materializeCompetencia'), [
        'CommercialDataLockSet::acquire',
        'materializeCompetenciaWhileLocked',
        '$lockSet->release()',
    ]),
    'Snapshots historicos nao podem ultrapassar commit/invalidation de Canonical ou Gold.',
);
cgcaCheck(
    $checks,
    'geo_rebuild_uses_shared_lock',
    cgcaContainsAll(cgcaMethodBody($sources['geo_rebuild_service'], 'rebuild'), [
        'CommercialDataLockSet::acquire',
        '$lock->release()',
    ]) && !str_contains((string) cgcaMethodBody($sources['geo_rebuild_service'], 'rebuild'), 'geo_rebuild_period:company:'),
    'Rebuild Geo usa a mesma ordem fiscal->Gold dos demais escritores de marts.',
);
cgcaCheck(
    $checks,
    'legacy_cnae_entrypoints_delegate_to_gold_contract',
    cgcaContainsAll(cgcaMethodBody($sources['legacy_cnae_adapter'], 'buildVendasPorCnae'), [
        'CommercialDataLockSet::acquire',
        'MartTransactionRunner',
        'CnaeSalesMartBuilder',
        '$lockSet->release()',
    ]) && !str_contains($sources['legacy_cnae_adapter'], 'DELETE FROM mart_vendas_por_cnae_cnpj'),
    'Entradas CNAE legadas delegam ao builder Gold transacional e channel-aware.',
);
cgcaCheck(
    $checks,
    'sales_snapshot_cache_visibility_scope',
    cgcaContainsAll(cgcaMethodBody($sources['sales_analytics'], 'cacheKey'), [
        'OverviewRequest::tenantCacheScope()',
        'SNAPSHOT_CACHE_VERSION',
    ]),
    'Snapshot fiscal separa canal e conjunto de empresas visiveis no consolidado.',
);
cgcaCheck(
    $checks,
    'snapshot_cli_accepts_explicit_channel',
    cgcaContainsAll(cgcaMethodBody($sources['tenant_cli'], 'boot'), [
        "str_starts_with(\$arg, '--channel=')",
        "['varejo', 'licitacao']",
        'TenantContext::bootForCompany($pdo, $companyId, $canal)',
    ]),
    'Jobs tenant-aware podem materializar snapshots Varejo e Licitacao explicitamente.',
);
cgcaCheck(
    $checks,
    'overview_detail_channel_scope',
    cgcaContainsAll($sources['overview_detail'], [
        'canonicalCommercialFilter',
        'martChannelFilter',
        "operation_scope = 'commercial'",
        "business_channel = 'varejo'",
        'is_biomie_commercial_visible',
    ]),
    'Drawers usam o mesmo contrato de canal, operacao e visibilidade dos graficos.',
);
cgcaCheck(
    $checks,
    'all_commercial_cache_families_invalidated',
    cgcaContainsAll($sources['invalidator'], [
        "'snapshot_fiscal'",
        "'api_overview_payload'",
        "'home_bootstrap'",
        "'dashboard_boot'",
        "'board_consolidated'",
    ]),
    'Snapshots, bootstrap, overview e board consolidado são invalidados.',
);
cgcaCheck(
    $checks,
    'partial_product_range_uses_canonical',
    cgcaContainsAll($sources['payload'], [
        '$this->isWholeMonthRange($start, $end)',
        '$this->canonical->topProductsCommercial($start, $end, 12)',
    ]),
    'Intervalos parciais não leem totais mensais de produto fora da janela.',
);
cgcaCheck(
    $checks,
    'monthly_comparison_uses_shared_commercial_contract',
    cgcaContainsAll(cgcaMethodBody($sources['month_comparison'], 'queryMonthAggregates'), [
        '$readSupport->commercialScopeFilter()',
        '$readSupport->commercialBaseSql()',
        'DATE_ADD(:end, INTERVAL 1 DAY)',
    ]),
    'Comparativo mensal usa a mesma base fiscal, canal, visibilidade e intervalo dos KPIs.',
);
cgcaCheck(
    $checks,
    'overview_drawers_use_commercial_base_and_exact_partial_ranges',
    cgcaContainsAll($sources['overview_detail'], [
        "commercialBaseSql('n')",
        '!$this->isWholeMonthRange($start, $end)',
        '$this->canonical->topProductsCommercial',
        '$this->canonical->topClientsCommercial',
        "'period_scope' => 'exact_range'",
    ]),
    'Drawers reconciliam valores comerciais e não expandem intervalos parciais ao mês inteiro.',
);

cgcaCheck(
    $checks,
    'cnae_gold_reads_are_channel_aware',
    cgcaContainsAll(cgcaMethodBody($sources['cnae_reader'], 'baseWhere'), [
        'BiVisibilityScope::getCondition',
        "'mart_vendas_por_cnae_cnpj'",
        "'m'",
    ]) && cgcaContainsAll(cgcaMethodBody($sources['cnae_reader'], 'freshness'), [
        'BiVisibilityScope::getCondition',
        'AND {$channel}',
    ]),
    'Ranking, cobertura e freshness CNAE isolam Licitacao de Varejo/consolidado.',
);
cgcaCheck(
    $checks,
    'customer_gold_reads_are_channel_aware',
    cgcaContainsAll(cgcaMethodBody($sources['customer_reader'], 'martChannelFilter'), [
        'BiVisibilityScope::getCondition',
    ]) && cgcaContainsAll($sources['customer_reader'], [
        '$this->martChannelFilter(\'mart_cliente_periodo\'',
        '$this->martChannelFilter(\'mart_vendas_por_cnae_cnpj\'',
        '$this->martChannelFilter(\'mart_compradores_por_geo\'',
    ]) && !array_filter(
        ['search', 'opportunities', 'sampleCustomers', 'freshness', 'fallbackFromGeoMart', 'commercialSummary', 'commercialMonths', 'periodQuality'],
        static fn (string $method): bool => !str_contains(
            (string) cgcaMethodBody($sources['customer_reader'], $method),
            'martChannelFilter',
        ),
    ),
    'Busca, perfil e qualidade de clientes leem somente o canal Gold ativo.',
);
cgcaCheck(
    $checks,
    'top_clients_cnae_api_is_channel_aware',
    cgcaContainsAll($sources['top_clients_cnae_api'], [
        'BiVisibilityScope::getCondition',
        "'mart_vendas_por_cnae_cnpj'",
        "' AND ' . \$channelScope",
    ]),
    'Drilldown de top clientes por CNAE aplica o mesmo canal do ranking.',
);
cgcaCheck(
    $checks,
    'pending_order_reads_use_shared_commercial_scope',
    !array_filter(
        [
            'summarizeOrderPipelineCommercial',
            'aggregateOrderPipelineByVendorCommercial',
            'listPipelineOrdersForDisplayCommercial',
            'listPendingOrdersCommercial',
            'summarizePendingCommercial',
            'aggregatePendingByVendorCommercial',
            'dailyPendingStatusRowsCommercial',
        ],
        static fn (string $method): bool => !cgcaContainsAll(
            cgcaMethodBody($sources['sales_reader'], $method),
            ["commercialScopeFilter('p.')", 'DATE_ADD(:end, INTERVAL 1 DAY)'],
        ),
    ),
    'Pedidos pendentes, pipeline e agregados aplicam tenant, canal, visibilidade, operation_scope e intervalo semiaberto.',
);
cgcaCheck(
    $checks,
    'cancelled_summary_uses_shared_scope_and_base',
    cgcaContainsAll(cgcaMethodBody($sources['fiscal_reader'], 'summarizeCancelledCommercial'), [
        "commercialBaseSql('nf')",
        "commercialScopeFilter('nf.')",
        'DATE_ADD(:end, INTERVAL 1 DAY)',
    ]),
    'Resumo de canceladas usa a base fiscal comercial e o mesmo tenant, canal, visibilidade e operation_scope.',
);

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$result = [
    'ok' => $failed === [],
    'tool' => 'commercial_gold_contract_audit',
    'read_only' => true,
    'summary' => [
        'checks' => count($checks),
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
    ],
    'checks' => $checks,
];

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0),
) . PHP_EOL;
exit($failed === [] ? 0 : 1);

/** @param list<array{id:string,ok:bool,message:string}> $checks */
function cgcaCheck(array &$checks, string $id, bool $ok, string $message): void
{
    $checks[] = ['id' => $id, 'ok' => $ok, 'message' => $message];
}

/** @param list<string> $needles */
function cgcaContainsAll(?string $source, array $needles): bool
{
    if ($source === null) {
        return false;
    }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            return false;
        }
    }
    return true;
}

function cgcaMethodBody(string $source, string $method): ?string
{
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/s', $source, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = (int) $match[0][1];
    $open = strpos($source, '{', $start);
    if ($open === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}
