<?php

declare(strict_types=1);

/**
 * tools/dev/product_customer_repurchase_audit.php — PROD.R2 FASE 0
 *
 * Identifica clientes com probabilidade de recompra por produto.
 * Score: frequência + recência + valor monetário.
 *
 * Uso:
 *   php tools/dev/product_customer_repurchase_audit.php \
 *       --start=2026-01-01 --end=2026-05-30 [--product-code=XXX] [--min-score=40] [--pretty]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Product\ProductActionIntelligenceBuilder;

$opts       = getopt('', ['start:', 'end:', 'product-code:', 'min-score:', 'pretty', 'json']);
$pretty     = array_key_exists('pretty', $opts);
$jsonMode   = array_key_exists('json',   $opts);
$start      = (string) ($opts['start']        ?? date('Y-m-01'));
$end        = (string) ($opts['end']          ?? date('Y-m-d'));
$filterCode = isset($opts['product-code']) ? trim((string) $opts['product-code']) : '';
$minScore   = isset($opts['min-score'])    ? (int) $opts['min-score'] : 0;

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$builder = new ProductActionIntelligenceBuilder($pdo);

// When no product code, get top 10 products and run repurchase on each
$pass   = 0;
$issues = [];
$result = [];

if ($filterCode !== '') {
    $candidates = $builder->buildRepurchaseCandidates($filterCode, $start, $end, 50);
    if ($minScore > 0) {
        $candidates = array_values(array_filter($candidates, fn($r) => ($r['score_recompra'] ?? 0) >= $minScore));
    }
    $result[$filterCode] = $candidates;
} else {
    // Get top products from opportunity list
    $opps = $builder->buildProductOpportunities($start, $end, 20);
    foreach ($opps as $opp) {
        $code = $opp['product_code'] ?? '';
        if ($code === '' || $code === 'SEM_SKU') continue;
        $candidates = $builder->buildRepurchaseCandidates($code, $start, $end, 20);
        if (!empty($candidates)) {
            $result[$code] = [
                'product_name' => $opp['product_name'] ?? $code,
                'candidates'   => $candidates,
                'total'        => count($candidates),
            ];
        }
    }
}

// Count total candidates and issues
$totalCandidates = 0;
foreach ($result as $k => $v) {
    if (isset($v['candidates'])) {
        $totalCandidates += count($v['candidates']);
    } else {
        $totalCandidates += count($v);
    }
}

$pass = $totalCandidates > 0 ? 1 : 0;

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  Product Customer Repurchase Audit — PROD.R2\n";
    echo "  Período: {$start} → {$end}\n";
    echo $hr . PHP_EOL . PHP_EOL;
    echo "  Total candidatos de recompra: {$totalCandidates}\n\n";

    foreach ($result as $code => $data) {
        $pName = is_array($data) && isset($data['product_name']) ? $data['product_name'] : $code;
        $cands = is_array($data) && isset($data['candidates']) ? $data['candidates'] : (is_array($data) ? $data : []);
        if (empty($cands)) continue;

        echo "  Produto: {$pName} ({$code})\n";
        echo str_repeat('─', 52) . PHP_EOL;
        foreach (array_slice($cands, 0, 5) as $c) {
            $score = str_pad((string) ($c['score_recompra'] ?? 0), 3);
            $name  = mb_substr($c['cliente_nome'] ?? '', 0, 30);
            echo "    [{$score}] {$name}  |  {$c['n_compras']} compra(s)  |  {$c['dias_desde_ultima']} dias\n";
            echo "          {$c['motivo_score']}\n";
            echo "          Vendedor: {$c['vendedor_ultima_venda']}\n";
        }
        echo PHP_EOL;
    }

    $badge = $totalCandidates > 0 ? "\033[32m✔ PASS\033[0m" : "\033[33mWARN\033[0m";
    echo str_repeat('─', 72) . PHP_EOL;
    echo "  [{$badge}]  {$totalCandidates} candidato(s) de recompra identificado(s)\n";
    echo str_repeat('═', 72) . PHP_EOL . PHP_EOL;
}

if ($jsonMode || !$pretty) {
    echo json_encode([
        'ok'               => true,
        'audit'            => 'product_customer_repurchase',
        'period'           => ['start' => $start, 'end' => $end],
        'total_candidates' => $totalCandidates,
        'by_product'       => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit(0);
