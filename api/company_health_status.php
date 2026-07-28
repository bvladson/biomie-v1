<?php

/**
 * api/company_health_status.php — Freshness e completude por empresa.
 *
 * Retorna para cada empresa ativa:
 *   - completeness_score (0-100) + tier (full/operational/minimal/incomplete)
 *   - completeness_dims: 9 sinais booleanos ponderados
 *   - sync.domains: freshness comercial e financeira separadas (lag em horas)
 *   - freshness.canonical: contagens NF/pedidos/clientes/financeiro + last_sync
 *   - freshness.coverage_months: últimos 6 meses com contagem de NFs (empty/sparse/ok)
 *   - marts: contagem de linhas em 9 marts Gold por empresa
 *   - credentials: status de integração Omie
 *
 * Permissão: jobs.view ou admin.view
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Monitoring\CompanyCompletenessService;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['jobs.view', 'admin.view'], true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    // ── Access control ────────────────────────────────────────────────────────
    $isAdmin       = Auth::isAdmin() || Auth::hasCapability('admin.view');
    $accessibleIds = TenantContext::accessibleCompanyIds();
    $companyFilter = (int) Http::query('company_id', '0');

    $allowedIds = $isAdmin ? null : $accessibleIds;
    if ($companyFilter > 0) {
        if (!$isAdmin && !in_array($companyFilter, $accessibleIds, true)) {
            Http::json(['ok' => false, 'message' => 'Sem acesso a esta empresa.', 'request_id' => RequestContext::id()], 403);
        }
        $allowedIds = [$companyFilter];
    }

    if ($allowedIds !== null && $allowedIds === []) {
        Http::json(['ok' => false, 'message' => 'Sem empresas acessíveis.', 'request_id' => RequestContext::id()], 403);
    }

    // ── Load companies + Omie integration state ───────────────────────────────
    $companySql = 'SELECT c.id, c.slug, c.nome_fantasia, c.ativo,
                          COALESCE(i.active, 0)                       AS integration_active,
                          CASE WHEN COALESCE(i.app_key,"") <> ""
                                AND COALESCE(i.app_secret_encrypted,"") <> ""
                               THEN 1 ELSE 0 END                      AS integration_configured,
                          i.last_success_at,
                          i.last_error_at,
                          i.last_error_message
                   FROM biomie_companies c
                   LEFT JOIN biomie_company_integrations i
                          ON i.company_id = c.id AND i.provider = "omie"
                   WHERE c.ativo = 1';

    if ($allowedIds !== null) {
        $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
        $stmtC = $pdo->prepare($companySql . " AND c.id IN ({$placeholders}) ORDER BY c.id");
        $stmtC->execute(array_values($allowedIds));
    } else {
        $stmtC = $pdo->query("{$companySql} ORDER BY c.id");
    }
    $companies = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    if ($companies === []) {
        Http::json(['ok' => false, 'message' => 'Nenhuma empresa ativa encontrada.', 'request_id' => RequestContext::id()], 404);
    }

    $companyIds = array_map('intval', array_column($companies, 'id'));
    $idList     = implode(',', $companyIds);

    // ── Sync runs aggregate (stuck/running detection) ─────────────────────────
    $syncByCompany = [];
    try {
        $syncRows = $pdo->query(
            "SELECT company_id,
                    MAX(CASE WHEN status IN ('success','completed','completed_with_warnings')
                             THEN finished_at END)                                         AS last_success_at,
                    MAX(CASE WHEN status IN ('failed','failed_retryable','failed_terminal')
                             THEN finished_at END)                                         AS last_failure_at,
                    SUM(CASE WHEN status = 'running'
                              AND started_at < DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                             THEN 1 ELSE 0 END)                                            AS stuck_runs,
                    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END)                   AS running_runs
             FROM biomie_sync_runs
             WHERE company_id IN ({$idList})
             GROUP BY company_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($syncRows as $row) {
            $syncByCompany[(int) $row['company_id']] = $row;
        }
    } catch (\Throwable) {
        // biomie_sync_runs may not exist on fresh installs — handled gracefully below
    }

    // ── Completeness service (score + dims + domain freshness + canonical + coverage + marts) ──
    // Pass already-loaded integration state to avoid a duplicate DB query inside the service
    $preloadedCreds = [];
    foreach ($companies as $company) {
        $preloadedCreds[(int) $company['id']] = [
            'configured' => (bool) $company['integration_configured'] && (bool) $company['integration_active'],
        ];
    }
    $completeness = (new CompanyCompletenessService($pdo))->analyse($companyIds, $preloadedCreds);

    // ── Global alerts ─────────────────────────────────────────────────────────
    $globalAlerts = [];
    foreach ([
        'biomie_clientes_dim'     => 'clientes_dim_company0',
        'biomie_clientes_geo_dim' => 'clientes_geo_dim_company0',
        'mart_forecasts'          => 'forecasts_company0',
        'mart_oportunidades'      => 'oportunidades_company0',
    ] as $table => $key) {
        try {
            $cnt = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$table}` WHERE company_id IS NULL OR company_id = 0"
            )->fetchColumn();
            if ($cnt > 0) {
                $globalAlerts[$key] = $cnt;
            }
        } catch (\Throwable) {
        }
    }

    try {
        $orphaned = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM biomie_notas_fiscais_itens_canonicos i
             LEFT JOIN biomie_notas_fiscais_canonicas n
                    ON n.company_id = i.company_id AND n.nota_key = i.nota_key
             WHERE i.company_id IN ({$idList}) AND n.id IS NULL"
        )->fetchColumn();
        if ($orphaned > 0) {
            $globalAlerts['nf_items_without_matching_header'] = $orphaned;
        }
    } catch (\Throwable) {
    }

    // ── Assemble per-company result ───────────────────────────────────────────
    $result = [];
    foreach ($companies as $company) {
        $cid  = (int) $company['id'];
        $sync = $syncByCompany[$cid] ?? [];
        $comp = $completeness[$cid]  ?? [
            'score' => 0, 'tier' => 'incomplete', 'dims' => [],
            'sync_domains' => [], 'canonical' => [],
            'coverage_months' => [], 'marts' => [],
        ];

        $stuckRuns   = (int) ($sync['stuck_runs']   ?? 0);
        $runningRuns = (int) ($sync['running_runs'] ?? 0);
        $lastSuccess = ($sync['last_success_at'] ?? '') ?: null;
        $lastFailure = ($sync['last_failure_at'] ?? '') ?: null;

        // Freshness age in days — derived from the most recent NF emission date (business date)
        // NOT from updated_at (sync timestamp), which would measure pipeline lag, not data age
        $nfLastDate    = $comp['canonical']['nf']['last_nf_date'] ?? null;
        $freshnessAgeD = null;
        if ($nfLastDate !== null) {
            $ts = strtotime($nfLastDate);
            if ($ts !== false) {
                $freshnessAgeD = max(0, (int) round((time() - $ts) / 86400));
            }
        }

        $syncStatus = match (true) {
            $stuckRuns > 0                                      => 'stuck',
            $runningRuns > 0                                    => 'running',
            $lastSuccess === null                               => 'never',
            $freshnessAgeD !== null && $freshnessAgeD > 3      => 'stale',
            default                                             => 'ok',
        };

        $credStatus = match (true) {
            !(bool) $company['integration_configured']       => 'not_configured',
            !(bool) $company['integration_active']           => 'inactive',
            !empty($company['last_error_at'])
                && (empty($company['last_success_at'])
                    || strtotime((string) ($company['last_error_at']   ?? '0')) >
                       strtotime((string) ($company['last_success_at'] ?? '0')))
                => 'error',
            default => 'ok',
        };

        $score = $comp['score'];
        $tier  = $comp['tier'];

        $overallStatus = match (true) {
            $syncStatus === 'stuck'                                    => 'critical',
            $credStatus === 'not_configured' && $tier === 'incomplete' => 'critical',
            $syncStatus === 'never'          && $tier === 'incomplete' => 'critical',
            $credStatus === 'not_configured'                           => 'warning',
            $credStatus === 'error'                                    => 'warning',
            $syncStatus === 'stale'                                    => 'warning',
            $credStatus === 'inactive'                                 => 'warning',
            $tier === 'minimal' || $tier === 'incomplete'              => 'warning',
            default                                                    => 'ok',
        };

        $result[] = [
            'company_id'         => $cid,
            'slug'               => (string) $company['slug'],
            'name'               => (string) $company['nome_fantasia'],
            'overall_status'     => $overallStatus,
            'completeness_score' => $score,
            'completeness_tier'  => $tier,
            'completeness_dims'  => $comp['dims'],
            'sync' => [
                'status'          => $syncStatus,
                'running_runs'    => $runningRuns,
                'stuck_runs'      => $stuckRuns,
                'last_success_at' => $lastSuccess,
                'last_failure_at' => $lastFailure,
                'domains'         => $comp['sync_domains'],
            ],
            'freshness' => [
                'age_days'        => $freshnessAgeD,
                'canonical'       => $comp['canonical'],
                'coverage_months' => $comp['coverage_months'],
            ],
            'marts'       => $comp['marts'],
            'credentials' => [
                'status'                 => $credStatus,
                'integration_active'     => (bool) $company['integration_active'],
                'integration_configured' => (bool) $company['integration_configured'],
                'last_success_at'        => ($company['last_success_at'] ?? '') ?: null,
                'last_error_at'          => ($company['last_error_at']   ?? '') ?: null,
                'has_recent_error'       => !empty($company['last_error_at'])
                    && (empty($company['last_success_at'])
                        || strtotime((string) ($company['last_error_at']   ?? '0')) >
                           strtotime((string) ($company['last_success_at'] ?? '0'))),
            ],
        ];
    }

    // ── System-level summary ──────────────────────────────────────────────────
    $statuses      = array_column($result, 'overall_status');
    $systemStatus  = in_array('critical', $statuses, true) ? 'critical'
        : (in_array('warning', $statuses, true) ? 'warning' : 'ok');
    $criticalCount = count(array_filter($statuses, static fn (string $s): bool => $s === 'critical'));
    $warningCount  = count(array_filter($statuses, static fn (string $s): bool => $s === 'warning'));
    $scores        = array_column($result, 'completeness_score');
    $avgScore      = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0.0;
    $tierCounts    = array_count_values(array_column($result, 'completeness_tier'));

    Http::json([
        'ok'            => true,
        'generated_at'  => date(DATE_ATOM),
        'system_status' => $systemStatus,
        'summary'       => [
            'total'              => count($result),
            'critical'           => $criticalCount,
            'warning'            => $warningCount,
            'ok'                 => count($result) - $criticalCount - $warningCount,
            'completeness_avg'   => $avgScore,
            'completeness_tiers' => $tierCounts,
        ],
        'companies'    => $result,
        'global_alerts'=> $globalAlerts,
        'meta'         => [
            'request_id'    => RequestContext::id(),
            'is_admin_view' => $isAdmin,
        ],
    ]);
} catch (\Throwable $e) {
    $logger->error('company_health_status falhou.', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'message' => 'Falha ao carregar saúde das empresas.', 'request_id' => RequestContext::id()], 500);
}
