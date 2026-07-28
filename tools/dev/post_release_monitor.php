<?php

/**
 * tools/dev/post_release_monitor.php
 *
 * Post-release monitoring tool for FIN.R3/SYNC.R1.
 * Diagnoses the health of commercial_dashboard, RefreshQueue, sync Omie, Gold marts, and FIN.R3.1.
 *
 * Usage:
 *   php tools/dev/post_release_monitor.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--pretty] [--json]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\DeadLetterRepository;

// Parse CLI arguments
$opts = getopt('', ['start:', 'end:', 'pretty', 'json']);
$jsonMode = isset($opts['json']);
$pretty = isset($opts['pretty']);

$today = date('Y-m-d');
$start = trim((string) ($opts['start'] ?? date('Y-m-d', strtotime('-1 day'))));
$end = trim((string) ($opts['end'] ?? $today));

$dbStart = $start . ' 00:00:00';
$dbEnd = $end . ' 23:59:59';

$projectRoot = dirname(__DIR__, 2);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;
$dbConnected = false;

try {
    if (Database::isConfigured($config, $connection)) {
        $pdo = Database::connect($config, $connection);
        $dbConnected = true;
    }
} catch (\Throwable $e) {
    // Will handle DB unavailability in the checks below
}

$status = 'OK';
$checks = [];
$recommendations = [];

// Helper to determine status severity rank
function worstStatus(string $current, string $new): string {
    $ranks = ['OK' => 1, 'WARNING' => 2, 'CRITICAL' => 3];
    return ($ranks[$new] ?? 1) > ($ranks[$current] ?? 1) ? $new : $current;
}

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 1: api/commercial_dashboard.php errors
// ─────────────────────────────────────────────────────────────────────────────
$dashboardCheck = [
    'name' => 'Commercial Dashboard',
    'status' => 'OK',
    'message' => 'Nenhum erro detectado no período.',
    'metrics' => ['requests' => 0, 'errors' => 0, 'error_rate_pct' => 0],
];

if (!$dbConnected) {
    $dashboardCheck['status'] = 'CRITICAL';
    $dashboardCheck['message'] = 'Banco de dados inacessível para consultar spans operacionais.';
} else {
    try {
        // Count spans
        $spanStmt = $pdo->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN status_name != 'ok' THEN 1 ELSE 0 END) as errors 
            FROM biomie_operational_spans 
            WHERE span_name = 'http.commercial_dashboard' 
              AND started_at BETWEEN :start AND :end
        ");
        $spanStmt->execute(['start' => $dbStart, 'end' => $dbEnd]);
        $counts = $spanStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'errors' => 0];
        
        $totalReqs = (int) ($counts['total'] ?? 0);
        $totalErrors = (int) ($counts['errors'] ?? 0);
        $errorRate = $totalReqs > 0 ? round(($totalErrors / $totalReqs) * 100, 2) : 0.0;
        
        $dashboardCheck['metrics']['requests'] = $totalReqs;
        $dashboardCheck['metrics']['errors'] = $totalErrors;
        $dashboardCheck['metrics']['error_rate_pct'] = $errorRate;
        
        // Check for specific database errors (1054 or 42S22)
        $detailStmt = $pdo->prepare("
            SELECT status_name, result_json, started_at 
            FROM biomie_operational_spans 
            WHERE span_name = 'http.commercial_dashboard' 
              AND status_name != 'ok' 
              AND started_at BETWEEN :start AND :end 
            ORDER BY id DESC LIMIT 5
        ");
        $detailStmt->execute(['start' => $dbStart, 'end' => $dbEnd]);
        $errorDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        $hasColumnError = false;
        foreach ($errorDetails as $err) {
            $msg = (string) ($err['result_json'] ?? '');
            if (str_contains($msg, '1054') || str_contains($msg, '42S22') || str_contains($msg, 'Unknown column')) {
                $hasColumnError = true;
                break;
            }
        }
        
        // Scan local jsonl errors file for additional coverage
        $errorsFile = $projectRoot . '/storage/logs/biomie_errors.jsonl';
        $jsonlErrors = 0;
        $hasJsonlColumnError = false;
        if (is_file($errorsFile)) {
            $handle = @fopen($errorsFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $log = json_decode($line, true);
                    if (is_array($log)) {
                        $ts = strtotime($log['ts'] ?? '');
                        if ($ts >= strtotime($dbStart) && $ts <= strtotime($dbEnd)) {
                            if (str_contains((string)($log['route'] ?? ''), 'commercial_dashboard.php') || str_contains((string)($log['file'] ?? ''), 'commercial_dashboard.php')) {
                                $jsonlErrors++;
                                $msg = (string) ($log['message'] ?? '');
                                if (str_contains($msg, '1054') || str_contains($msg, '42S22') || str_contains($msg, 'Unknown column')) {
                                    $hasJsonlColumnError = true;
                                }
                            }
                        }
                    }
                }
                fclose($handle);
            }
        }
        
        $totalDashboardErrors = max($totalErrors, $jsonlErrors);
        $hasAnyColumnError = $hasColumnError || $hasJsonlColumnError;
        
        if ($hasAnyColumnError) {
            $dashboardCheck['status'] = 'CRITICAL';
            $dashboardCheck['message'] = 'Detector de colunas inválidas (erro 1054/42S22) acionou alerta CRITICAL no dashboard.';
            $recommendations[] = 'Revisar se colunas de dashboard (ex: nota_fiscal_id, cnpj_cliente) foram reinseridas na query SQL de api/commercial_dashboard.php.';
        } elseif ($totalDashboardErrors > 0 && $errorRate > 50) {
            $dashboardCheck['status'] = 'CRITICAL';
            $dashboardCheck['message'] = "Alta taxa de erro no dashboard ({$errorRate}%, total de {$totalDashboardErrors} erros).";
            $recommendations[] = 'Analisar logs detalhados em storage/logs/biomie_errors.jsonl para identificar falhas no dashboard.';
        } elseif ($totalDashboardErrors > 0) {
            $dashboardCheck['status'] = 'WARNING';
            $dashboardCheck['message'] = "Dashboard registrou {$totalDashboardErrors} erro(s) no período de análise.";
            $recommendations[] = 'Verificar se há oscilações na rede ou lentidão no banco de dados.';
        } else {
            $dashboardCheck['message'] = 'Dashboard comercial saudável. Nenhuma falha recente.';
        }
    } catch (\Throwable $e) {
        $dashboardCheck['status'] = 'WARNING';
        $dashboardCheck['message'] = 'Erro ao consultar telemetria do dashboard: ' . $e->getMessage();
    }
}
$status = worstStatus($status, $dashboardCheck['status']);
$checks[] = $dashboardCheck;

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 2: RefreshQueue
// ─────────────────────────────────────────────────────────────────────────────
$queueCheck = [
    'name' => 'RefreshQueue & Workers',
    'status' => 'OK',
    'message' => 'Fila de processamento saudável.',
    'metrics' => [
        'general_queued' => 0, 'general_running' => 0, 'general_stuck' => 0,
        'refresh_queued' => 0, 'refresh_running' => 0, 'refresh_stuck' => 0,
        'failed_terminal' => 0, 'dead_letter_unresolved' => 0
    ]
];

try {
    $jobsDir = $projectRoot . '/storage/cache/jobs';
    
    // Scan queued/running/failed files
    $queuedGeneral = glob($jobsDir . '/queued_job_*.json') ?: [];
    $runningGeneral = glob($jobsDir . '/running_job_*.json') ?: [];
    $failedGeneral = glob($jobsDir . '/failed_job_*.json') ?: [];
    
    $queuedRefresh = array_merge(
        glob($jobsDir . '/queued_sales_*.json') ?: [],
        glob($jobsDir . '/queued_finance_*.json') ?: []
    );
    $runningRefresh = array_merge(
        glob($jobsDir . '/running_sales_*.json') ?: [],
        glob($jobsDir . '/running_finance_*.json') ?: []
    );
    
    $failedTerminal = count(glob($jobsDir . '/failed_terminal_*.json') ?: []);
    
    // Check stuck jobs (running for more than 30 mins)
    $threshold = time() - 1800;
    $stuckGeneral = 0;
    foreach ($runningGeneral as $path) {
        $j = json_decode((string) @file_get_contents($path), true);
        $started = strtotime((string) ($j['started_at'] ?? ''));
        if ($started && $started < $threshold) $stuckGeneral++;
    }
    
    $stuckRefresh = 0;
    foreach ($runningRefresh as $path) {
        $j = json_decode((string) @file_get_contents($path), true);
        $started = strtotime((string) ($j['started_at'] ?? ''));
        if ($started && $started < $threshold) $stuckRefresh++;
    }
    
    $unresolvedDead = 0;
    if ($dbConnected) {
        try {
            $unresolvedDead = (int) (new DeadLetterRepository($pdo))->counts()['unresolved'];
        } catch (\Throwable $t) {}
    }
    
    $queueCheck['metrics'] = [
        'general_queued' => count($queuedGeneral),
        'general_running' => count($runningGeneral),
        'general_stuck' => $stuckGeneral,
        'refresh_queued' => count($queuedRefresh),
        'refresh_running' => count($runningRefresh),
        'refresh_stuck' => $stuckRefresh,
        'failed_terminal' => $failedTerminal,
        'dead_letter_unresolved' => $unresolvedDead
    ];
    
    $totPending = count($queuedGeneral) + count($queuedRefresh);
    
    if ($stuckGeneral > 0 || $stuckRefresh > 0) {
        $queueCheck['status'] = 'WARNING';
        $queueCheck['message'] = "Há " . ($stuckGeneral + $stuckRefresh) . " job(s) travado(s) em processamento há mais de 30 minutos.";
        $recommendations[] = 'Usar php tools/dev/worker_queue_debug.php --json para analisar jobs travados e re-enfileirar se aplicável.';
    } elseif ($unresolvedDead > 0 || $failedTerminal > 0) {
        $queueCheck['status'] = 'WARNING';
        $queueCheck['message'] = "Fila possui {$failedTerminal} job(s) failed_terminal e {$unresolvedDead} dead-letters sem solução.";
        $recommendations[] = 'Utilizar php tools/dev/dead_letter_report.php --pretty para auditar falhas permanentes e executar redrive se corrigidas.';
    } elseif ($totPending > 15) {
        $queueCheck['status'] = 'WARNING';
        $queueCheck['message'] = "Acúmulo incomum na fila de jobs ({$totPending} pendentes).";
        $recommendations[] = 'Confirmar se o worker cron está sendo disparado automaticamente ou rodar manualmente php cron/worker.php.';
    } else {
        $queueCheck['message'] = 'Fila operando normalmente. Sem travamentos ou colisões de workers.';
    }
} catch (\Throwable $e) {
    $queueCheck['status'] = 'WARNING';
    $queueCheck['message'] = 'Erro ao auditar fila de cache/jobs: ' . $e->getMessage();
}
$status = worstStatus($status, $queueCheck['status']);
$checks[] = $queueCheck;

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 3: Sync Omie Freshness
// ─────────────────────────────────────────────────────────────────────────────
$syncCheck = [
    'name' => 'Sync Omie & Freshness',
    'status' => 'OK',
    'message' => 'Sincronização Omie em dia.',
    'metrics' => [
        'commercial_lag_minutes' => null,
        'finance_lag_minutes' => null,
    ]
];

if (!$dbConnected) {
    $syncCheck['status'] = 'CRITICAL';
    $syncCheck['message'] = 'Sem acesso ao banco para consultar freshness de sincronização.';
} else {
    try {
        $syncRunsTableExists = (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_sync_runs'
        ")->fetchColumn();
        
        if (!$syncRunsTableExists) {
            $syncCheck['status'] = 'CRITICAL';
            $syncCheck['message'] = 'Tabela biomie_sync_runs não encontrada. Histórico de sync indisponível.';
        } else {
            $stmt = $pdo->query("
                SELECT domain_name, MAX(finished_at) as last_success
                FROM biomie_sync_runs
                WHERE status IN ('completed', 'completed_with_warnings')
                GROUP BY domain_name
            ");
            $runs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            
            $successMap = [];
            foreach ($runs as $r) {
                $successMap[$r['domain_name']] = $r['last_success'];
            }
            
            $commSuccess = $successMap['commercial'] ?? null;
            $finSuccess = $successMap['finance'] ?? null;
            
            $commLag = $commSuccess ? (int) round((time() - strtotime($commSuccess)) / 60) : null;
            $finLag = $finSuccess ? (int) round((time() - strtotime($finSuccess)) / 60) : null;
            
            $syncCheck['metrics']['commercial_lag_minutes'] = $commLag;
            $syncCheck['metrics']['finance_lag_minutes'] = $finLag;
            
            $isCommCritical = $commLag === null || $commLag > 2160; // 36h
            $isFinCritical = $finLag === null || $finLag > 2880; // 48h
            
            $isCommWarning = $commLag !== null && $commLag > 360; // 6h
            $isFinWarning = $finLag !== null && $finLag > 480; // 8h
            
            if ($isCommCritical || $isFinCritical) {
                $syncCheck['status'] = 'CRITICAL';
                $syncCheck['message'] = sprintf(
                    "Desfasagem crítica detectada. Lag Comercial: %s min. Lag Financeiro: %s min.",
                    $commLag ?? 'N/A',
                    $finLag ?? 'N/A'
                );
                $recommendations[] = 'Rodar manual de sincronização: php cron/sync_realtime_pipeline.php --domains=commercial,finance --json';
            } elseif ($isCommWarning || $isFinWarning) {
                $syncCheck['status'] = 'WARNING';
                $syncCheck['message'] = sprintf(
                    "Sincronização atrasada. Lag Comercial: %s min. Lag Financeiro: %s min.",
                    $commLag ?? 'N/A',
                    $finLag ?? 'N/A'
                );
                $recommendations[] = 'Verificar se o agendador cron no Hostinger está rodando.';
            } else {
                $syncCheck['message'] = 'Freshness de sincronização com o Omie saudável.';
            }
        }
    } catch (\Throwable $e) {
        $syncCheck['status'] = 'WARNING';
        $syncCheck['message'] = 'Erro ao verificar freshness do sync Omie: ' . $e->getMessage();
    }
}
$status = worstStatus($status, $syncCheck['status']);
$checks[] = $syncCheck;

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 4: Gold Marts Materialization
// ─────────────────────────────────────────────────────────────────────────────
$goldCheck = [
    'name' => 'Gold Layer Materialization',
    'status' => 'OK',
    'message' => 'Materialização Gold em dia.',
    'metrics' => []
];

if (!$dbConnected) {
    $goldCheck['status'] = 'CRITICAL';
    $goldCheck['message'] = 'Sem acesso ao banco para consultar tabelas Gold.';
} else {
    try {
        $marts = [
            'mart_cliente_periodo',
            'mart_vendedor_dia',
            'mart_produto_periodo',
            'mart_compradores_por_geo',
            'mart_vendas_por_cnae_cnpj'
        ];
        
        $missing = [];
        $empty = [];
        $counts = [];
        
        foreach ($marts as $m) {
            $exists = (bool) $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$m}'
            ")->fetchColumn();
            
            if (!$exists) {
                $missing[] = $m;
                $counts[$m] = null;
            } else {
                $rows = (int) $pdo->query("SELECT COUNT(*) FROM `{$m}`")->fetchColumn();
                $counts[$m] = $rows;
                if ($rows === 0) {
                    $empty[] = $m;
                }
            }
        }
        
        $goldCheck['metrics'] = $counts;
        
        // Check last successful materialization run time
        $lastMatRun = null;
        $syncRunsExists = (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_sync_runs'
        ")->fetchColumn();
        
        if ($syncRunsExists) {
            $lastMatRun = $pdo->query("
                SELECT MAX(finished_at) 
                FROM biomie_sync_runs 
                WHERE run_type = 'materialize' 
                  AND status IN ('completed', 'completed_with_warnings')
            ")->fetchColumn() ?: null;
        }
        
        $goldCheck['metrics']['last_materialize_finished_at'] = $lastMatRun;
        
        if (count($missing) > 0) {
            $goldCheck['status'] = 'CRITICAL';
            $goldCheck['message'] = 'Tabelas Gold críticas ausentes no banco de dados: ' . implode(', ', $missing);
            $recommendations[] = 'Criar tabelas Gold ausentes executando as migrations necessárias.';
        } elseif (count($empty) > 0) {
            $goldCheck['status'] = 'CRITICAL';
            $goldCheck['message'] = 'Tabelas Gold vazias (sem dados materializados): ' . implode(', ', $empty);
            $recommendations[] = 'Rodar materializador manual das tabelas Gold: php bin/materialize_marts.php --force';
        } else {
            $goldCheck['message'] = 'Todos os marts Gold materializados possuem dados consistentes.';
        }
    } catch (\Throwable $e) {
        $goldCheck['status'] = 'WARNING';
        $goldCheck['message'] = 'Erro ao verificar marts Gold: ' . $e->getMessage();
    }
}
$status = worstStatus($status, $goldCheck['status']);
$checks[] = $goldCheck;

// ─────────────────────────────────────────────────────────────────────────────
// CHECK 5: FIN.R3.1 Endpoint Health
// ─────────────────────────────────────────────────────────────────────────────
$finCheck = [
    'name' => 'FIN.R3.1 Collection API',
    'status' => 'OK',
    'message' => 'API de cobrança respondendo.',
    'metrics' => [
        'open_titles_count' => 0,
        'missing_vendor_titles_count' => 0,
    ]
];

if (!$dbConnected) {
    $finCheck['status'] = 'CRITICAL';
    $finCheck['message'] = 'Sem acesso ao banco para ler títulos financeiros da carteira.';
} else {
    try {
        $titlesTableExists = (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_titulos_financeiros_canonicos'
        ")->fetchColumn();
        
        if (!$titlesTableExists) {
            $finCheck['status'] = 'WARNING';
            $finCheck['message'] = 'Tabela de títulos financeiros canônicos ausente. API não retornará dados.';
        } else {
            // Count total open and missing vendor titles
            $openCount = (int) $pdo->query("
                SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos 
                WHERE status_titulo = 'aberto' AND COALESCE(valor_aberto, 0) > 0
            ")->fetchColumn();
            
            $missingVendorCount = (int) $pdo->query("
                SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos 
                WHERE status_titulo = 'aberto' AND COALESCE(valor_aberto, 0) > 0
                  AND (codigo_vendedor_omie IS NULL OR codigo_vendedor_omie = '' OR codigo_vendedor_omie = '0')
            ")->fetchColumn();
            
            $finCheck['metrics']['open_titles_count'] = $openCount;
            $finCheck['metrics']['missing_vendor_titles_count'] = $missingVendorCount;
            
            // Check if endpoint file exists
            $endpointFile = $projectRoot . '/api/finance_collection_by_vendor.php';
            if (!is_file($endpointFile)) {
                $finCheck['status'] = 'CRITICAL';
                $finCheck['message'] = 'Endpoint api/finance_collection_by_vendor.php não existe!';
            } else {
                $finCheck['message'] = sprintf(
                    "API ok. Títulos abertos: %s, Sem vendedor: %s.",
                    $openCount,
                    $missingVendorCount
                );
            }
        }
    } catch (\Throwable $e) {
        $finCheck['status'] = 'WARNING';
        $finCheck['message'] = 'Erro ao validar estado financeiro canônico: ' . $e->getMessage();
    }
}
$status = worstStatus($status, $finCheck['status']);
$checks[] = $finCheck;

// Output formatting
$output = [
    'status' => $status,
    'start_date' => $start,
    'end_date' => $end,
    'checks' => $checks,
    'recommendations' => array_values(array_unique($recommendations)),
];

if ($jsonMode) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status === 'CRITICAL' ? 1 : 0);
}

// Pretty print CLI formatting
$cGreen = "\033[32m";
$cYellow = "\033[33m";
$cRed = "\033[31m";
$cReset = "\033[0m";

$statusColor = $status === 'OK' ? $cGreen : ($status === 'WARNING' ? $cYellow : $cRed);

echo "\n";
echo "========================================================================\n";
echo "          BIOMIE POST-RELEASE MONITOR (FIN.R3 / SYNC.R1)\n";
echo "========================================================================\n";
echo "Período analisado: {$start} até {$end}\n";
echo "Status Geral: {$statusColor}{$status}{$cReset}\n";
echo "------------------------------------------------------------------------\n\n";

foreach ($checks as $c) {
    $cColor = $c['status'] === 'OK' ? $cGreen : ($c['status'] === 'WARNING' ? $cYellow : $cRed);
    echo "  [{$cColor}{$c['status']}{$cReset}]  {$c['name']}\n";
    echo "         {$c['message']}\n";
    if ($pretty && !empty($c['metrics'])) {
        echo "         Métricas:\n";
        foreach ($c['metrics'] as $mk => $mv) {
            $mvVal = is_bool($mv) ? ($mv ? 'true' : 'false') : ($mv ?? 'null');
            echo "           - {$mk}: {$mvVal}\n";
        }
    }
    echo "\n";
}

if (!empty($recommendations)) {
    echo "------------------------------------------------------------------------\n";
    echo "RECOMENDAÇÕES DE MITIGAÇÃO:\n";
    foreach ($recommendations as $idx => $rec) {
        $num = $idx + 1;
        echo "  {$num}. {$rec}\n";
    }
    echo "\n";
}
echo "========================================================================\n\n";

exit($status === 'CRITICAL' ? 1 : 0);
