<?php

declare(strict_types=1);

/**
 * tools/dev/product_vendor_affinity_audit.php — PROD.R2 FASE 0
 *
 * Mapeia aderência vendedor × produto.
 * Identifica: produtos fortes, oportunidades de cross-sell, concentração, gaps.
 *
 * Uso:
 *   php tools/dev/product_vendor_affinity_audit.php \
 *       --start=2026-01-01 --end=2026-05-30 [--vendor-code=XXX] [--pretty]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Product\ProductActionIntelligenceBuilder;

$opts        = getopt('', ['start:', 'end:', 'vendor-code:', 'pretty', 'json']);
$pretty      = array_key_exists('pretty', $opts);
$jsonMode    = array_key_exists('json',   $opts);
$start       = (string) ($opts['start']       ?? date('Y-m-01'));
$end         = (string) ($opts['end']         ?? date('Y-m-d'));
$vendorCode  = isset($opts['vendor-code']) ? trim((string) $opts['vendor-code']) : null;

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$builder  = new ProductActionIntelligenceBuilder($pdo);
$affinity = $builder->buildVendorProductAffinity($start, $end, $vendorCode);

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  Product Vendor Affinity Audit — PROD.R2\n";
    echo "  Período: {$start} → {$end}\n";
    echo $hr . PHP_EOL . PHP_EOL;
    echo '  Vendedores analisados: ' . count($affinity) . PHP_EOL . PHP_EOL;

    foreach ($affinity as $v) {
        $rev = number_format((float) ($v['receita_total'] ?? 0), 2, ',', '.');
        echo "  Vendedor: {$v['vendor_name']} [{$v['vendor_id']}]  |  Receita: R\$ {$rev}  |  Produtos: {$v['n_produtos']}\n";
        echo str_repeat('─', 52) . PHP_EOL;

        $fortes = $v['produtos_fortes'] ?? [];
        if (!empty($fortes)) {
            echo "  Produtos FORTES:\n";
            foreach ($fortes as $p) {
                $pRev = number_format((float) ($p['receita'] ?? 0), 2, ',', '.');
                echo "    \033[32m✔\033[0m {$p['product_name']}  |  R\$ {$pRev}  |  {$p['participacao_vendedor_pct']}% receita  |  {$p['clientes']} clientes\n";
            }
        }

        $opps = $v['produtos_oportunidade'] ?? [];
        if (!empty($opps)) {
            echo "  Oportunidades:\n";
            foreach (array_slice($opps, 0, 3) as $p) {
                $pRev = number_format((float) ($p['receita'] ?? 0), 2, ',', '.');
                echo "    \033[33m○\033[0m {$p['product_name']}  |  R\$ {$pRev}  |  {$p['share_produto']}% do mercado\n";
            }
        }
        echo PHP_EOL;
    }

    $badge = count($affinity) > 0 ? "\033[32m✔ PASS\033[0m" : "\033[33mWARN\033[0m";
    echo str_repeat('─', 72) . PHP_EOL;
    echo "  [{$badge}]  " . count($affinity) . " vendedor(es) mapeado(s)\n";
    echo str_repeat('═', 72) . PHP_EOL . PHP_EOL;
}

if ($jsonMode || !$pretty) {
    echo json_encode([
        'ok'      => true,
        'audit'   => 'product_vendor_affinity',
        'period'  => ['start' => $start, 'end' => $end],
        'total'   => count($affinity),
        'vendors' => $affinity,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit(0);
