<?php

declare(strict_types=1);

/**
 * tools/dev/commercial_dashboard_performance_audit.php
 *
 * EST.R1 Fase 0.5 — Auditoria de performance do dashboard comercial.
 *
 * Mede tempo de cada bloco de query do commercial_dashboard.php.
 * Alerta se bloco > 2s, CRÍTICO se API geral > 8s.
 *
 * Cenários:
 *   - Período mensal atual
 *   - Período anual
 *   - Período quebrado (15/02 → 15/03)
 *
 * Usage:
 *   php tools/dev/commercial_dashboard_performance_audit.php --pretty
 *   php tools/dev/commercial_dashboard_performance_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/commercial_dashboard_performance_audit.php --json
 *
 * Exit: 0=OK 1=warn/slow 2=DB error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO DB: ' . $e->getMessage();
    echo ($isJson ? json_encode(['error' => $msg]) : $msg) . "\n";
    exit(2);
}

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$baseSql = FiscalValueBasis::preComputedBaseSql('nf');

// ── Test scenarios ─────────────────────────────────────────────────────────────

$today = date('Y-m-d');
$scenarios = [
    ['label' => 'Período mensal', 'start' => date('Y-m-01'), 'end' => $today],
    ['label' => 'Período anual', 'start' => isset($opts['start']) ? $opts['start'] : date('Y-01-01'), 'end' => isset($opts['end']) ? $opts['end'] : $today],
    ['label' => 'Período quebrado (15/02→15/03)', 'start' => date('Y') . '-02-15', 'end' => date('Y') . '-03-15'],
];

// ── Query blocks ───────────────────────────────────────────────────────────────

function benchmarkQuery(PDO $pdo, string $label, callable $fn): array
{
    $t0 = microtime(true);
    $rows = 0;
    $err  = null;
    try {
        $result = $fn($pdo);
        $rows = is_array($result) ? count($result) : (int) $result;
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    $elapsed = round(microtime(true) - $t0, 3);
    return [
        'label'   => $label,
        'elapsed' => $elapsed,
        'rows'    => $rows,
        'error'   => $err,
        'status'  => $err ? 'ERROR' : ($elapsed >= 8 ? 'CRITICAL' : ($elapsed >= 2 ? 'WARN' : 'OK')),
    ];
}

$results = [];
$anyFail = false;

foreach ($scenarios as $scenario) {
    $start = $scenario['start'];
    $end   = $scenario['end'];
    $blocks = [];

    $dateExpr = 'COALESCE(nf.data_competencia, nf.data_emissao)';
    $whereBase = "nf.status_fiscal = 'autorizada'
       AND nf.operation_scope = 'commercial'
       AND {$dateExpr} BETWEEN :start AND :end
       AND {$visCond}";
    $p = [':start' => $start, ':end' => $end];

    // B1 — KPI summary
    $blocks[] = benchmarkQuery($pdo, 'B1 KPI summary', function (PDO $pdo) use ($whereBase, $p, $baseSql) {
        $st = $pdo->prepare("SELECT COUNT(*) AS nfs, SUM({$baseSql}) AS receita, COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes, COUNT(DISTINCT nf.nome_vendedor) AS vendedores FROM biomie_notas_fiscais_canonicas nf WHERE {$whereBase}");
        $st->execute($p);
        return (array) ($st->fetch(PDO::FETCH_ASSOC) ?: []);
    });

    // B2 — Daily evolution (heavy for annual period)
    $blocks[] = benchmarkQuery($pdo, 'B2 Evolução diária', function (PDO $pdo) use ($whereBase, $p, $baseSql, $dateExpr) {
        $st = $pdo->prepare("SELECT DATE({$dateExpr}) AS dia, COUNT(*) AS nfs, SUM({$baseSql}) AS receita FROM biomie_notas_fiscais_canonicas nf WHERE {$whereBase} GROUP BY dia ORDER BY dia");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });

    // B3 — Vendor ranking (top 20)
    $blocks[] = benchmarkQuery($pdo, 'B3 Ranking vendedores', function (PDO $pdo) use ($whereBase, $p, $baseSql) {
        $st = $pdo->prepare("SELECT nf.nome_vendedor, COUNT(*) AS nfs, SUM({$baseSql}) AS receita FROM biomie_notas_fiscais_canonicas nf WHERE {$whereBase} GROUP BY nf.nome_vendedor ORDER BY receita DESC LIMIT 20");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });

    // B4 — Top clients (top 20)
    $blocks[] = benchmarkQuery($pdo, 'B4 Top clientes', function (PDO $pdo) use ($whereBase, $p, $baseSql) {
        $st = $pdo->prepare("SELECT nf.nome_cliente, COUNT(*) AS nfs, SUM({$baseSql}) AS receita FROM biomie_notas_fiscais_canonicas nf WHERE {$whereBase} GROUP BY nf.nome_cliente ORDER BY receita DESC LIMIT 20");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    });

    // B5 — Pipeline / wallet
    $blocks[] = benchmarkQuery($pdo, 'B5 Pipeline carteira', function (PDO $pdo) use ($start, $end, $visCond) {
        if (!$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_mart_clientes_periodo_comercial' LIMIT 1")->fetchColumn()) {
            return 0;
        }
        $dateExpr = 'COALESCE(nf.data_competencia, nf.data_emissao)';
        $st = $pdo->prepare("SELECT COUNT(*) FROM biomie_mart_clientes_periodo_comercial WHERE periodo_start >= :start AND periodo_end <= :end LIMIT 1");
        $st->execute([':start' => $start, ':end' => $end]);
        return (int) $st->fetchColumn();
    });

    $scenarioElapsed = array_sum(array_column($blocks, 'elapsed'));
    $scenarioStatus  = $scenarioElapsed >= 8 ? 'CRITICAL' : ($scenarioElapsed >= 4 ? 'WARN' : 'OK');

    if ($scenarioStatus !== 'OK') $anyFail = true;

    $results[] = [
        'scenario'        => $scenario['label'],
        'period'          => ['start' => $start, 'end' => $end],
        'total_elapsed'   => round($scenarioElapsed, 3),
        'status'          => $scenarioStatus,
        'blocks'          => $blocks,
    ];
}

$output = [
    'tool'    => 'commercial_dashboard_performance_audit',
    'date'    => date('Y-m-d H:i:s'),
    'results' => $results,
    'summary' => [
        'pass' => !$anyFail,
        'note' => $anyFail ? 'Algum cenário superou limiar de 4s — considerar cache ou split progressivo.' : 'Performance OK em todos os cenários.',
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($anyFail ? 1 : 0);
}

$bar = str_repeat('═', 72);
$sep = str_repeat('─', 72);
echo "\n{$bar}\n Dashboard Comercial — Auditoria de Performance\n{$bar}\n\n";

foreach ($results as $r) {
    $sicon = match ($r['status']) {
        'CRITICAL' => "\033[31mCRITICAL\033[0m",
        'WARN'     => "\033[33mWARN\033[0m",
        default    => "\033[32mOK\033[0m",
    };
    printf(" %s  %s  [%.3fs total]\n", $sicon, $r['scenario'], $r['total_elapsed']);
    printf("   Período: %s → %s\n", $r['period']['start'], $r['period']['end']);
    foreach ($r['blocks'] as $b) {
        $bicon = match ($b['status']) {
            'ERROR'    => "\033[31mERROR\033[0m",
            'CRITICAL' => "\033[31mCRIT \033[0m",
            'WARN'     => "\033[33mWARN \033[0m",
            default    => "\033[32m OK  \033[0m",
        };
        printf("   [%s] %-28s  %.3fs  (%d rows)\n", $bicon, $b['label'], $b['elapsed'], $b['rows'] ?? 0);
        if ($b['error']) printf("         \033[31m→ %s\033[0m\n", $b['error']);
    }
    echo "\n";
}

echo "{$sep}\n";
printf(" %s  —  %s\n", $anyFail ? "\033[33m⚠ WARN\033[0m" : "\033[32m✔ PASS\033[0m", $output['summary']['note']);
echo "{$bar}\n\n";

exit($anyFail ? 1 : 0);
