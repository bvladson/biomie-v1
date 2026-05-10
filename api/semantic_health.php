<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Semantic\FreshnessService;
use BIomie\DataPlatform\Semantic\MetricRegistry;
use BIomie\DataPlatform\Semantic\SemanticMetricValidator;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['logs.view', 'jobs.view', 'admin.view'], true);

/**
 * Estado semântico do sistema BIomie.
 *
 * Retorna:
 *   - Resultado do semantic_audit (OK/FAIL, erros, avisos)
 *   - Contagem de KPIs active/draft/deprecated
 *   - Freshness por domínio de negócio
 *   - Estado do job gold_mart_refresh
 *   - Shadow divergências e cobertura canonical (quando disponíveis)
 *
 * Permissão: logs.view | jobs.view | admin.view
 * Método: GET
 */

try {
    $registry  = MetricRegistry::build();
    $validator = new SemanticMetricValidator();
    $audit     = $validator->validate($registry);

    // Freshness — requer banco; degradável sem ele
    $freshnessResults  = [];
    $freshnessError    = null;
    $jobStatus         = null;

    $freshnessService = null;
    try {
        [$pdo] = Database::getGeoConnection($config);
        $freshnessService = new FreshnessService($pdo);

        // Freshness por domínio de negócio (usa os que têm KPIs active)
        $domainsWithKpis = [];
        foreach ($registry->active() as $def) {
            $domainsWithKpis[$def->freshnessDomain] = true;
        }

        foreach (array_keys($domainsWithKpis) as $domain) {
            $freshnessResults[$domain] = $freshnessService->forDomain($domain);
        }

        // Freshness para KPIs operacionais específicos
        foreach (['shadow_divergencias', 'coverage_canonical', 'omie_sync_freshness'] as $kpiId) {
            if ($registry->has($kpiId)) {
                $freshnessResults['kpi:' . $kpiId] = $freshnessService->forMetric($kpiId);
            }
        }

        // Status do job gold_mart_refresh
        $goldFreshness = $freshnessService->forDomain('gold');
        $jobStatus = [
            'job'              => 'gold_mart_refresh',
            'status'           => $goldFreshness['status'],
            'last_success_at'  => $goldFreshness['last_success_at'],
            'last_reconcile_at'=> $goldFreshness['last_reconcile_at'],
            'stale_after_min'  => $goldFreshness['stale_after_minutes'],
        ];
    } catch (Throwable $e) {
        $freshnessError = $e->getMessage();
        $logger->warning('semantic_health: banco indisponível para freshness.', [
            'request_id' => RequestContext::id(),
            'error'      => $e->getMessage(),
        ]);
    }

    Http::json([
        'ok'         => true,
        'semantic'   => [
            'audit_ok'   => $audit->ok,
            'errors'     => $audit->errors,
            'warnings'   => $audit->warnings,
            'kpis'       => [
                'total'      => $audit->metricsTotal,
                'active'     => $audit->active,
                'draft'      => $audit->draft,
                'deprecated' => $audit->deprecated,
            ],
        ],
        'freshness'  => [
            'available'   => $freshnessService !== null,
            'error'       => $freshnessError,
            'by_domain'   => $freshnessResults,
            'gold_job'    => $jobStatus,
        ],
        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao carregar estado semântico.', [
        'request_id' => RequestContext::id(),
        'exception'  => $exception::class,
        'message'    => $exception->getMessage(),
    ]);

    Http::json([
        'ok'         => false,
        'message'    => 'Não foi possível carregar o estado semântico.',
        'request_id' => RequestContext::id(),
    ], 500);
}
