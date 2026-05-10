<?php

declare(strict_types=1);

use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\SalesAnalyticsService;
use BIomie\SyncPipelineRepository;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireAnyCapability(['jobs.view', 'sales.view', 'finance.view', 'fiscal.view'], true);

$range    = Http::resolveDateRange();
$cacheKey = SalesAnalyticsService::cacheKey($range['start'], $range['end']);

// ── Sync status por domínio ──────────────────────────────────────────────────
$syncStatus = [
    'canonical_commercial' => ['last_sync_at' => null, 'available' => false],
    'canonical_financial'  => ['last_sync_at' => null, 'available' => false],
    'snapshot_commercial'  => ['generated_at' => null, 'age_seconds' => null],
    'pipeline_cursors'     => [],
];

// ── Contrato de fonte de verdade por domínio (Fase 0) ───────────────────────
$dataContract = [
    'comercial'  => [
        'source_of_truth'   => 'unknown',
        'coverage_start'    => null,
        'coverage_end'      => null,
        'last_success_at'   => null,
        'last_reconcile_at' => null,
    ],
    'fiscal' => [
        'source_of_truth'   => 'unknown',
        'coverage_start'    => null,
        'coverage_end'      => null,
        'last_success_at'   => null,
        'last_reconcile_at' => null,
    ],
    'financeiro' => [
        'source_of_truth'   => 'unknown',
        'coverage_start'    => null,
        'coverage_end'      => null,
        'last_success_at'   => null,
        'last_reconcile_at' => null,
    ],
];

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (Database::isConfigured($config, $connection)) {
        $pdo       = Database::connect($config, $connection);
        $reconnect = static fn () => Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger, $reconnect);

        if ($canonical->isAvailable()) {
            // Último sync canônico comercial (global — sem filtro de período)
            $stmtCommercial = $pdo->query(
                'SELECT MAX(last_sync) AS last_sync FROM (
                    SELECT MAX(sincronizado_em) AS last_sync FROM biomie_notas_fiscais_canonicas
                    UNION ALL
                    SELECT MAX(sincronizado_em) AS last_sync FROM biomie_pedidos_canonicos
                ) t'
            );
            $rowCommercial = $stmtCommercial ? $stmtCommercial->fetch(\PDO::FETCH_ASSOC) : [];
            $syncStatus['canonical_commercial'] = [
                'last_sync_at' => $rowCommercial['last_sync'] ?? null,
                'available'    => true,
            ];

            // Cobertura de datas reais — comercial
            try {
                $rowPed = $pdo->query(
                    'SELECT MIN(data_emissao) AS cov_start, MAX(data_emissao) AS cov_end FROM biomie_pedidos_canonicos'
                )->fetch(\PDO::FETCH_ASSOC);
                $canonicalEnabled = $config->isCanonicalEnabled('overview');
                $dataContract['comercial']['source_of_truth'] = $canonicalEnabled ? 'canonical' : 'legacy';
                $dataContract['comercial']['coverage_start']  = $rowPed['cov_start'] ?? null;
                $dataContract['comercial']['coverage_end']    = $rowPed['cov_end']   ?? null;
            } catch (\Throwable) {}

            // Cobertura de datas reais — fiscal
            try {
                $rowNf = $pdo->query(
                    'SELECT MIN(data_competencia) AS cov_start, MAX(data_competencia) AS cov_end FROM biomie_notas_fiscais_canonicas'
                )->fetch(\PDO::FETCH_ASSOC);
                $dataContract['fiscal']['source_of_truth'] = $config->isCanonicalEnabled('overview') ? 'canonical' : 'legacy';
                $dataContract['fiscal']['coverage_start']  = $rowNf['cov_start'] ?? null;
                $dataContract['fiscal']['coverage_end']    = $rowNf['cov_end']   ?? null;
            } catch (\Throwable) {}

            // Último sync canônico financeiro
            try {
                $stmtFin = $pdo->query(
                    'SELECT MAX(sincronizado_em) AS last_sync,
                            MIN(data_vencimento)  AS cov_start,
                            MAX(data_vencimento)  AS cov_end
                     FROM biomie_titulos_financeiros_canonicos'
                );
                $rowFin = $stmtFin ? $stmtFin->fetch(\PDO::FETCH_ASSOC) : [];
                $syncStatus['canonical_financial'] = [
                    'last_sync_at' => $rowFin['last_sync'] ?? null,
                    'available'    => true,
                ];
                if (!empty($rowFin['last_sync'])) {
                    $dataContract['financeiro']['source_of_truth'] = 'canonical';
                    $dataContract['financeiro']['coverage_start']  = $rowFin['cov_start'] ?? null;
                    $dataContract['financeiro']['coverage_end']    = $rowFin['cov_end']   ?? null;
                }
            } catch (\Throwable) {
                // tabela pode não existir ainda
            }
        }

        // Cursores do pipeline (último sucesso por domínio/endpoint)
        $pipeline = SyncPipelineRepository::boot($config, $logger);
        if ($pipeline !== null) {
            $summary = $pipeline->summarizeOperationalState(5);
            $cursors = [];
            foreach (($summary['cursors'] ?? []) as $cursor) {
                $cursors[] = [
                    'domain'          => $cursor['domain_name'],
                    'endpoint_group'  => $cursor['endpoint_group'],
                    'last_success_at' => $cursor['last_success_at'] ?? null,
                    'last_event_at'   => $cursor['last_event_at']   ?? null,
                    'status'          => $cursor['status']           ?? null,
                ];

                // Propaga last_success_at para o data_contract por domínio
                $d = strtolower(trim((string) ($cursor['domain_name'] ?? '')));
                $sa = $cursor['last_success_at'] ?? null;
                if ($sa !== null) {
                    if ($d === 'commercial' || $d === 'comercial') {
                        if ($dataContract['comercial']['last_success_at'] === null
                            || strcmp($sa, $dataContract['comercial']['last_success_at']) > 0) {
                            $dataContract['comercial']['last_success_at'] = $sa;
                        }
                    } elseif ($d === 'fiscal') {
                        if ($dataContract['fiscal']['last_success_at'] === null
                            || strcmp($sa, $dataContract['fiscal']['last_success_at']) > 0) {
                            $dataContract['fiscal']['last_success_at'] = $sa;
                        }
                    } elseif ($d === 'finance' || $d === 'financeiro') {
                        if ($dataContract['financeiro']['last_success_at'] === null
                            || strcmp($sa, $dataContract['financeiro']['last_success_at']) > 0) {
                            $dataContract['financeiro']['last_success_at'] = $sa;
                        }
                    }
                }
            }
            $syncStatus['pipeline_cursors'] = $cursors;

            // last_reconcile_at por domínio
            foreach ($pipeline->listRecentRuns(60) as $run) {
                if (strtolower(trim((string) ($run['run_type'] ?? ''))) !== 'reconcile') {
                    continue;
                }
                $rs = strtolower(trim((string) ($run['status'] ?? '')));
                if (!in_array($rs, ['completed', 'completed_with_warnings'], true)) {
                    continue;
                }
                $rd = strtolower(trim((string) ($run['domain_name'] ?? '')));
                $ra = $run['finished_at'] ?? $run['period_end'] ?? null;
                if ($ra === null) {
                    continue;
                }
                if ($rd === 'commercial' || $rd === 'comercial') {
                    if ($dataContract['comercial']['last_reconcile_at'] === null
                        || strcmp($ra, $dataContract['comercial']['last_reconcile_at']) > 0) {
                        $dataContract['comercial']['last_reconcile_at'] = $ra;
                    }
                } elseif ($rd === 'fiscal') {
                    if ($dataContract['fiscal']['last_reconcile_at'] === null
                        || strcmp($ra, $dataContract['fiscal']['last_reconcile_at']) > 0) {
                        $dataContract['fiscal']['last_reconcile_at'] = $ra;
                    }
                } elseif ($rd === 'finance' || $rd === 'financeiro') {
                    if ($dataContract['financeiro']['last_reconcile_at'] === null
                        || strcmp($ra, $dataContract['financeiro']['last_reconcile_at']) > 0) {
                        $dataContract['financeiro']['last_reconcile_at'] = $ra;
                    }
                }
            }
        }
    }
} catch (\Throwable $e) {
    $logger->warning('status.php: erro ao consultar sync_status', ['message' => $e->getMessage()]);
}

// Snapshot comercial — usa o cache existente
$cacheMeta = $cache->getMeta($cacheKey);
if ($cacheMeta['exists'] ?? false) {
    $syncStatus['snapshot_commercial'] = [
        'generated_at' => $cacheMeta['modified_at'] ?? null,
        'age_seconds'  => $cacheMeta['age_seconds']  ?? null,
    ];
}

Http::json([
    'ok'                     => true,
    'app_name'               => $config->get('app.name'),
    'timezone'               => $config->get('app.timezone'),
    'credentials_configured' => $config->hasOmieCredentials(),
    'mode'                   => $config->hasOmieCredentials() ? 'live-ready' : 'demo',
    'cache'                  => $cacheMeta,
    'sync_status'            => $syncStatus,
    'data_contract'          => $dataContract,
    'endpoints'              => [
        'orders'             => $config->get('omie.endpoints.orders'),
        'invoices'           => $config->get('omie.endpoints.invoices'),
        'vendors'            => $config->get('omie.endpoints.vendors'),
        'clients'            => $config->get('omie.endpoints.clients'),
        'orders_fat'         => $config->get('omie.endpoints.orders_fat'),
        'accounts_receivable'=> $config->get('omie.endpoints.accounts_receivable'),
    ],
    'notes' => [
        'Preencha config/env.php com a APP KEY e APP SECRET do Omie para trocar do modo demo para o modo online.',
        'Use o pacote completo ou o patch full desta versao: a correcao inclui HTML + JavaScript + APIs PHP + camada app; aplicar apenas as paginas quebra a consistencia das demais telas.',
        'Apos salvar as credenciais, use o botao "Sincronizar canonico" na tela de integracoes.',
        'A versao atual consulta Pedidos de Venda, Vendedores e Clientes; tambem deixa configurados Pedidos de Venda - Faturamento e Contas a Receber para ampliar a reconciliacao do cockpit.',
    ],
]);
