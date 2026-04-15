<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\SyncPipelineRepository;

require_once __DIR__ . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $diag = [
        'ok' => true,
        'app' => (string) $config->get('app.name', 'BIomie'),
        'timestamp' => date(DATE_ATOM),
        'php' => PHP_VERSION,
        'period' => $range,
    ];

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo = Database::connect($config, $connection);
            $canonical = new CanonicalRepository($pdo, $logger);

            $tablesPresent = $canonical->isAvailable();
            $vendorCount = 0;
            $orderCount = 0;
            $invoiceCount = 0;
            $invoiceItemCount = 0;
            $lastSyncPeriod = null;

            if ($tablesPresent) {
                try {
                    $vendorCount = (int) $pdo->query('SELECT COUNT(*) FROM biomie_vendedores_omie')->fetchColumn();
                    $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM biomie_pedidos_canonicos')->fetchColumn();
                    $invoiceCount = (int) $pdo->query('SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas')->fetchColumn();
                    $invoiceItemCount = (int) $pdo->query('SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos')->fetchColumn();
                    $lastSyncPeriod = $canonical->lastSyncAt($range['start'], $range['end']);
                } catch (Throwable) {
                }
            }

            $diag['canonical'] = [
                'tables_present' => $tablesPresent,
                'vendor_count' => $vendorCount,
                'pending_order_count' => $orderCount,
                'invoice_count' => $invoiceCount,
                'invoice_item_count' => $invoiceItemCount,
                'last_sync_period' => $lastSyncPeriod,
                'ready_for_period' => $tablesPresent && $lastSyncPeriod !== null,
                'fiscal_ready_flag' => (bool) $config->get('features.canonical_fiscal_ready', false),
                'reads_enabled' => [
                    'vendors' => $config->isCanonicalEnabled('vendors'),
                    'orders' => $config->isCanonicalEnabled('orders'),
                    'overview' => $config->isCanonicalEnabled('overview'),
                ],
            ];

            $diag['sources'] = [
                'vendors' => $config->isCanonicalEnabled('vendors') && $tablesPresent && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                'orders' => $config->isCanonicalEnabled('orders') && $tablesPresent && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                'overview_ranking' => $config->isCanonicalEnabled('overview') && $tablesPresent && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                'flight_plan_targets' => 'dynamic_fallback',
            ];

            $diag['db_connected'] = true;
        } catch (Throwable $exception) {
            $diag['ok'] = false;
            $diag['db_connected'] = false;
            $diag['db_error'] = $exception->getMessage();
            $diag['canonical'] = [
                'tables_present' => false,
                'vendor_count' => 0,
                'pending_order_count' => 0,
                'invoice_count' => 0,
                'invoice_item_count' => 0,
                'last_sync_period' => null,
                'ready_for_period' => false,
                'fiscal_ready_flag' => (bool) $config->get('features.canonical_fiscal_ready', false),
                'reads_enabled' => [
                    'vendors' => $config->isCanonicalEnabled('vendors'),
                    'orders' => $config->isCanonicalEnabled('orders'),
                    'overview' => $config->isCanonicalEnabled('overview'),
                ],
            ];
            $diag['sources'] = [
                'vendors' => 'legacy',
                'orders' => 'legacy',
                'overview_ranking' => 'legacy',
                'flight_plan_targets' => 'dynamic_fallback',
            ];
        }
    } else {
        $diag['ok'] = false;
        $diag['db_connected'] = false;
        $diag['db_error'] = 'Banco nao configurado.';
    }

    // ── Contrato de fonte de verdade por domínio (Fase 0) ───────────────────────
    $dataContract = [
        'comercial'  => [
            'source_of_truth'  => 'unknown',
            'coverage_start'   => null,
            'coverage_end'     => null,
            'last_success_at'  => null,
            'last_reconcile_at'=> null,
        ],
        'fiscal' => [
            'source_of_truth'  => 'unknown',
            'coverage_start'   => null,
            'coverage_end'     => null,
            'last_success_at'  => null,
            'last_reconcile_at'=> null,
        ],
        'financeiro' => [
            'source_of_truth'  => 'unknown',
            'coverage_start'   => null,
            'coverage_end'     => null,
            'last_success_at'  => null,
            'last_reconcile_at'=> null,
        ],
    ];

    try {
        if (isset($pdo) && isset($tablesPresent) && $tablesPresent) {
            // Cobertura de datas reais da camada canônica
            try {
                $rowCom = $pdo->query(
                    'SELECT MIN(data_emissao) AS cov_start, MAX(data_emissao) AS cov_end,
                            MAX(sincronizado_em) AS last_sync
                     FROM biomie_pedidos_canonicos'
                )->fetch(\PDO::FETCH_ASSOC);
                if (!empty($rowCom['last_sync'])) {
                    $canonicalEnabled = $config->isCanonicalEnabled('overview');
                    $dataContract['comercial']['source_of_truth'] = $canonicalEnabled ? 'canonical' : 'legacy';
                    $dataContract['comercial']['coverage_start']  = $rowCom['cov_start'] ?? null;
                    $dataContract['comercial']['coverage_end']    = $rowCom['cov_end']   ?? null;
                }
            } catch (\Throwable) {}

            try {
                $rowFis = $pdo->query(
                    'SELECT MIN(data_competencia) AS cov_start, MAX(data_competencia) AS cov_end,
                            MAX(sincronizado_em) AS last_sync
                     FROM biomie_notas_fiscais_canonicas'
                )->fetch(\PDO::FETCH_ASSOC);
                if (!empty($rowFis['last_sync'])) {
                    $dataContract['fiscal']['source_of_truth'] = $config->isCanonicalEnabled('overview') ? 'canonical' : 'legacy';
                    $dataContract['fiscal']['coverage_start']  = $rowFis['cov_start'] ?? null;
                    $dataContract['fiscal']['coverage_end']    = $rowFis['cov_end']   ?? null;
                }
            } catch (\Throwable) {}

            try {
                $rowFin = $pdo->query(
                    'SELECT MIN(data_vencimento) AS cov_start, MAX(data_vencimento) AS cov_end,
                            MAX(sincronizado_em) AS last_sync
                     FROM biomie_titulos_financeiros_canonicos'
                )->fetch(\PDO::FETCH_ASSOC);
                if (!empty($rowFin['last_sync'])) {
                    $dataContract['financeiro']['source_of_truth'] = 'canonical';
                    $dataContract['financeiro']['coverage_start']  = $rowFin['cov_start'] ?? null;
                    $dataContract['financeiro']['coverage_end']    = $rowFin['cov_end']   ?? null;
                }
            } catch (\Throwable) {}

            // last_success_at e last_reconcile_at via pipeline
            try {
                $pipeline = SyncPipelineRepository::boot($config, $logger);
                if ($pipeline !== null) {
                    $summary = $pipeline->summarizeOperationalState(5);
                    foreach (($summary['cursors'] ?? []) as $cursor) {
                        $domain    = strtolower(trim((string) ($cursor['domain_name'] ?? '')));
                        $successAt = $cursor['last_success_at'] ?? null;
                        if ($successAt === null) {
                            continue;
                        }
                        if ($domain === 'commercial' || $domain === 'comercial') {
                            if ($dataContract['comercial']['last_success_at'] === null
                                || strcmp($successAt, $dataContract['comercial']['last_success_at']) > 0) {
                                $dataContract['comercial']['last_success_at'] = $successAt;
                            }
                        } elseif ($domain === 'fiscal') {
                            if ($dataContract['fiscal']['last_success_at'] === null
                                || strcmp($successAt, $dataContract['fiscal']['last_success_at']) > 0) {
                                $dataContract['fiscal']['last_success_at'] = $successAt;
                            }
                        } elseif ($domain === 'finance' || $domain === 'financeiro') {
                            if ($dataContract['financeiro']['last_success_at'] === null
                                || strcmp($successAt, $dataContract['financeiro']['last_success_at']) > 0) {
                                $dataContract['financeiro']['last_success_at'] = $successAt;
                            }
                        }
                    }

                    foreach ($pipeline->listRecentRuns(60) as $run) {
                        if (strtolower(trim((string) ($run['run_type'] ?? ''))) !== 'reconcile') {
                            continue;
                        }
                        $status = strtolower(trim((string) ($run['status'] ?? '')));
                        if (!in_array($status, ['completed', 'completed_with_warnings'], true)) {
                            continue;
                        }
                        $domain      = strtolower(trim((string) ($run['domain_name'] ?? '')));
                        $reconcileAt = $run['finished_at'] ?? $run['period_end'] ?? null;
                        if ($reconcileAt === null) {
                            continue;
                        }
                        if ($domain === 'commercial' || $domain === 'comercial') {
                            if ($dataContract['comercial']['last_reconcile_at'] === null
                                || strcmp($reconcileAt, $dataContract['comercial']['last_reconcile_at']) > 0) {
                                $dataContract['comercial']['last_reconcile_at'] = $reconcileAt;
                            }
                        } elseif ($domain === 'fiscal') {
                            if ($dataContract['fiscal']['last_reconcile_at'] === null
                                || strcmp($reconcileAt, $dataContract['fiscal']['last_reconcile_at']) > 0) {
                                $dataContract['fiscal']['last_reconcile_at'] = $reconcileAt;
                            }
                        } elseif ($domain === 'finance' || $domain === 'financeiro') {
                            if ($dataContract['financeiro']['last_reconcile_at'] === null
                                || strcmp($reconcileAt, $dataContract['financeiro']['last_reconcile_at']) > 0) {
                                $dataContract['financeiro']['last_reconcile_at'] = $reconcileAt;
                            }
                        }
                    }
                }
            } catch (\Throwable) {}
        }
    } catch (\Throwable) {}

    $diag['data_contract'] = $dataContract;

    $diag['cron_path_expected'] = dirname(__FILE__) . '/cron/';

    if ($diag['ok'] !== true) {
        http_response_code(503);
    }

    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    $logger->error('Falha em healthz.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro interno no healthcheck.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
