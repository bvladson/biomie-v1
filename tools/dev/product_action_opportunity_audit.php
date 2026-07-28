<?php

declare(strict_types=1);

/**
 * tools/dev/product_action_opportunity_audit.php — PROD.R2 FASE 0
 *
 * Lista oportunidades comerciais por produto no período.
 * Identifica: campeões, reativar, recorrentes, concentrados, dormentes.
 *
 * Uso:
 *   php tools/dev/product_action_opportunity_audit.php \
 *       --start=2026-01-01 --end=2026-05-30 [--product-code=XXX] [--pretty] [--json]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Product\ProductActionIntelligenceBuilder;

$opts        = getopt('', ['start:', 'end:', 'product-code:', 'pretty', 'json']);
$pretty      = array_key_exists('pretty', $opts);
$jsonMode    = array_key_exists('json',   $opts);
$start       = (string) ($opts['start']        ?? date('Y-m-01'));
$end         = (string) ($opts['end']          ?? date('Y-m-d'));
$filterCode  = isset($opts['product-code']) ? trim((string) $opts['product-code']) : '';

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$builder      = new ProductActionIntelligenceBuilder($pdo);
$opportunities = $builder->buildProductOpportunities($start, $end, 200);

if ($filterCode !== '') {
    $opportunities = array_values(array_filter(
        $opportunities,
        fn($r) => stripos((string) ($r['product_code'] ?? ''), $filterCode) !== false
                || stripos((string) ($r['product_name'] ?? ''), $filterCode) !== false
    ));
}

$summary = $builder->buildExecutiveSummary($start, $end);

// ── Pretty output ──────────────────────────────────────────────────────────────

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  Product Action Opportunity Audit — PROD.R2\n";
    echo "  Período: {$start} → {$end}\n";
    echo $hr . PHP_EOL . PHP_EOL;

    echo "  RESUMO EXECUTIVO\n";
    echo "  ─────────────────────────────────────────────────\n";
    echo "  Total produtos analisados : {$summary['total_products']}\n";
    echo "  Campeões                  : {$summary['campeoes']}\n";
    echo "  Para reativar             : {$summary['para_reativar']}\n";
    echo "  Recorrentes (2+ meses)    : {$summary['recorrentes']}\n";
    echo "  Concentrados (>= 60%)     : {$summary['concentrados']}\n";
    echo "  Dormentes (>= 90 dias)    : {$summary['dormentes']}\n";
    echo "  Clientes para recompra    : {$summary['clientes_recompra']}\n";
    echo PHP_EOL;

    $catColors = [
        'Produto campeão'              => "\033[32m",
        'Reativar produto'             => "\033[31m",
        'Acompanhar concentração'      => "\033[33m",
        'Campanha para clientes antigos' => "\033[36m",
        'Cross-sell com vendedores fortes' => "\033[34m",
        'Produto sazonal'              => "\033[35m",
        'Baixo giro'                   => "\033[37m",
        'Monitorar'                    => "\033[37m",
    ];
    $reset = "\033[0m";

    echo "  TOP OPORTUNIDADES POR PRODUTO\n";
    echo str_repeat('─', 72) . PHP_EOL;

    foreach (array_slice($opportunities, 0, 30) as $r) {
        $cat    = $r['categoria'] ?? '';
        $color  = $catColors[$cat] ?? '';
        $name   = mb_substr($r['product_name'] ?? '', 0, 30);
        $score  = str_pad((string) ($r['score_acao'] ?? 0), 3);
        $catLbl = str_pad($cat, 32);
        echo "  [{$score}] {$color}{$catLbl}{$reset}  {$name}\n";
        echo "         SKU: {$r['product_code']}";
        echo "  |  R\$ " . number_format((float) ($r['receita_periodo'] ?? 0), 2, ',', '.');
        echo "  |  Clientes: {$r['clientes_periodo']}";
        echo "  |  Meses: {$r['meses_ativos']}";
        if (($r['dias_sem_venda'] ?? 0) >= 90) {
            echo "  |  \033[31mParado há {$r['dias_sem_venda']} dias\033[0m";
        }
        if (($r['top_cliente_share_pct'] ?? 0) >= 60) {
            echo "  |  \033[33mConcentração {$r['top_cliente_share_pct']}%\033[0m";
        }
        echo PHP_EOL;
        echo "         Ação: {$r['acao_sugerida']}\n";
        echo PHP_EOL;
    }

    echo str_repeat('═', 72) . PHP_EOL . PHP_EOL;
}

// ── JSON / default output ─────────────────────────────────────────────────────

if ($jsonMode || !$pretty) {
    echo json_encode([
        'ok'           => true,
        'audit'        => 'product_action_opportunity',
        'period'       => ['start' => $start, 'end' => $end],
        'summary'      => $summary,
        'opportunities' => $opportunities,
        'total'        => count($opportunities),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit(0);
