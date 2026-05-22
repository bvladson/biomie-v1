<?php

/**
 * tools/dev/product_360_debug.php — Product 360 CLI diagnostic.
 *
 * Usage:
 *   php tools/dev/product_360_debug.php --product=CODIGO [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]
 *
 * Output:
 *   - Summary calculado direto da canônica
 *   - NFs encontradas
 *   - Clientes compradores
 *   - Vendedores
 *   - Pedidos vinculados (bridge)
 *   - Timeline
 *   - LTV observado
 *   - Linhas em mart_produto_periodo
 *   - Freshness
 *   - Divergência mart vs canônico
 *   - Alertas automáticos
 *   - Com --explain: índices recomendados
 */

declare(strict_types=1);

use BIomie\Database;
use BIomie\Modules\Sales\Application\Products\Product360ReadService;
use BIomie\Modules\Sales\Infrastructure\Product360ReadRepository;

require_once __DIR__ . '/../../app/bootstrap_cli.php';

// ── Parse CLI args ─────────────────────────────────────────────────────────

$opts        = getopt('', ['product:', 'start:', 'end:', 'json', 'explain']);
$productCode = trim((string) ($opts['product'] ?? ''));
$start       = trim((string) ($opts['start'] ?? date('Y-m-01')));
$end         = trim((string) ($opts['end']   ?? date('Y-m-d')));
$jsonMode    = isset($opts['json']);
$explain     = isset($opts['explain']);

if ($productCode === '') {
    fwrite(STDERR, "Uso: php tools/dev/product_360_debug.php --product=CODIGO [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]\n");
    exit(1);
}

// ── Database ───────────────────────────────────────────────────────────────

$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $conn)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(1);
}
$pdo = Database::connect($config, $conn);

$repo    = new Product360ReadRepository($pdo);
$service = new Product360ReadService($repo);

// ── Fetch full payload ────────────────────────────────────────────────────

$payload = $service->build($productCode, $start, $end, 50, null);

// ── Mart divergence check ─────────────────────────────────────────────────

$martRows  = $repo->martRowCount($productCode);
$canonical = $payload['summary']['invoices_count'] ?? 0;

$alerts = [];

if ($martRows > 0 && $canonical === 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "mart_produto_periodo tem {$martRows} linha(s) para {$productCode}, mas canonico nao encontrou NFs no periodo. Possivel produto fantasma."];
}
if ($martRows === 0 && $canonical > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "Ha {$canonical} NFs canônicas, mas mart_produto_periodo nao tem linhas para {$productCode}. Execute ProductPeriodMartBuilder."];
}

$linkedCount = count($payload['linked_orders'] ?? []);
if ($canonical > 0 && $linkedCount === 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "invoices_count={$canonical} mas linked_orders_count=0. Verifique mart_bridge_pedido_nfe_titulo."];
}
if (!($payload['summary']['realized_total'] ?? null) && $canonical > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => 'realized_total nulo/zero mas invoices_count > 0. Verifique valor_total em biomie_notas_fiscais_itens_canonicos.'];
}

// Check: nota_key must be populated in invoices
foreach (($payload['invoices'] ?? []) as $inv) {
    if (empty($inv['nota_key'])) {
        $alerts[] = ['level' => 'warning', 'msg' => "NF numero_nf={$inv['numero_nf']} sem nota_key. data-nota-key nao sera preenchido no frontend."];
        break;
    }
}

// Check: never uses data_emissao for realized (verified by period_basis contract)
if (($payload['period_basis']['realized'] ?? '') !== 'data_competencia') {
    $alerts[] = ['level' => 'critical', 'msg' => 'CRITICAL: realizado nao usa data_competencia! Verificar Product360ReadService.'];
}

// ── JSON mode ─────────────────────────────────────────────────────────────

if ($jsonMode) {
    $out = $payload;
    $out['_debug'] = [
        'mart_rows_for_product' => $martRows,
        'alerts'                => $alerts,
    ];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Human-readable output ─────────────────────────────────────────────────

$line  = str_repeat('─', 60);
$sep   = fn (string $title) => "\n\033[1;34m{$line}\n  {$title}\n{$line}\033[0m\n";
$kv    = fn (string $k, mixed $v) => sprintf("  %-32s %s\n", $k . ':', is_array($v) ? json_encode($v) : $v);
$warn  = fn (string $msg) => "\033[1;33m  ⚠ {$msg}\033[0m\n";
$crit  = fn (string $msg) => "\033[1;31m  ✗ {$msg}\033[0m\n";
$ok    = fn (string $msg) => "\033[1;32m  ✓ {$msg}\033[0m\n";

echo "\033[1mProduct 360 — Debug CLI\033[0m\n";
echo $kv('product_code', $productCode);
echo $kv('period', $start . ' → ' . $end);
echo $kv('realized_period_basis', $payload['period_basis']['realized'] ?? '-');

// ── Summary ───────────────────────────────────────────────────────────────

echo $sep('RESUMO');
$s = $payload['summary'] ?? [];
echo $kv('product_description',    $s['product_description'] ?? '-');
echo $kv('realized_total',         $s['realized_total'] ?? '-');
echo $kv('merchandise_total',      $s['merchandise_total'] ?? '-');
echo $kv('quantity_total',         $s['quantity_total'] ?? '-');
echo $kv('invoices_count',         $s['invoices_count'] ?? 0);
echo $kv('customers_count',        $s['customers_count'] ?? 0);
echo $kv('vendors_count',          $s['vendors_count'] ?? 0);
echo $kv('linked_orders_count',    $s['linked_orders_count'] ?? 0);
echo $kv('ticket_avg_per_invoice', $s['ticket_avg_per_invoice'] ?? '-');
echo $kv('avg_unit_price',         $s['avg_unit_price'] ?? '-');
echo $kv('first_sale_at',          $s['first_sale_at'] ?? '-');
echo $kv('last_sale_at',           $s['last_sale_at'] ?? '-');
echo $kv('source',                 $s['source'] ?? '-');

// ── NFs (top 5) ───────────────────────────────────────────────────────────

$invoices = $payload['invoices'] ?? [];
echo $sep('NFs AUTORIZADAS (' . count($invoices) . ' encontradas — exibindo ate 5)');
foreach (array_slice($invoices, 0, 5) as $inv) {
    echo sprintf("  NF %-10s  comp:%s  nota_key:%-20s  cliente:%s\n",
        $inv['numero_nf'] ?? '-',
        $inv['data_competencia'] ?? '-',
        $inv['nota_key'] ?? 'AUSENTE',
        $inv['customer_name'] ?? '-'
    );
}
if (!count($invoices)) echo "  (nenhuma NF encontrada)\n";

// ── Clientes (top 5) ──────────────────────────────────────────────────────

$customers = $payload['customers'] ?? [];
echo $sep('CLIENTES COMPRADORES (' . count($customers) . ')');
foreach (array_slice($customers, 0, 5) as $c) {
    echo sprintf("  %-30s  receita:%-12s  NFs:%d\n",
        $c['customer_name'] ?? '-',
        $c['revenue'] ?? '-',
        $c['invoices_count'] ?? 0
    );
}
if (!count($customers)) echo "  (nenhum cliente encontrado)\n";

// ── Vendedores (top 5) ────────────────────────────────────────────────────

$vendors = $payload['vendors'] ?? [];
echo $sep('VENDEDORES (' . count($vendors) . ')');
foreach (array_slice($vendors, 0, 5) as $v) {
    echo sprintf("  %-30s  receita:%-12s  particip:%.1f%%\n",
        $v['vendor_name'] ?? '-',
        $v['revenue'] ?? '-',
        $v['participation_pct'] ?? 0
    );
}
if (!count($vendors)) echo "  (nenhum vendedor encontrado)\n";

// ── Pedidos vinculados ────────────────────────────────────────────────────

$orders = $payload['linked_orders'] ?? [];
echo $sep('PEDIDOS VINCULADOS (bridge) (' . count($orders) . ')');
foreach (array_slice($orders, 0, 5) as $o) {
    echo sprintf("  pedido:%-12s  nota_key:%-20s  comp_nfe:%s\n",
        $o['order_code'] ?? '-',
        $o['nota_key'] ?? '-',
        $o['data_competencia_nfe'] ?? '-'
    );
}
if (!count($orders)) echo "  (nenhum pedido vinculado encontrado)\n";

// ── Timeline ──────────────────────────────────────────────────────────────

$timeline = $payload['timeline'] ?? [];
echo $sep('EVOLUCAO TEMPORAL (' . count($timeline) . ' buckets)');
foreach (array_slice($timeline, 0, 5) as $t) {
    echo sprintf("  %s  receita:%-12s  qtd:%.0f  NFs:%d\n",
        $t['bucket'] ?? '-',
        $t['revenue'] ?? '-',
        $t['quantity'] ?? 0,
        $t['invoices_count'] ?? 0
    );
}
if (!count($timeline)) echo "  (sem dados de evolucao)\n";

// ── LTV ───────────────────────────────────────────────────────────────────

$ltv = $payload['ltv'] ?? [];
echo $sep('LTV OBSERVADO');
if ($ltv) {
    echo $kv('lifetime_revenue',         $ltv['lifetime_revenue'] ?? '-');
    echo $kv('lifetime_customers_count', $ltv['lifetime_customers_count'] ?? 0);
    echo $kv('repeat_customers_count',   $ltv['repeat_customers_count'] ?? 0);
    echo $kv('repeat_customer_rate',     ($ltv['repeat_customer_rate'] ?? 0) . '%');
    echo $kv('lifetime_first_sale_at',   $ltv['lifetime_first_sale_at'] ?? '-');
    echo $kv('lifetime_last_sale_at',    $ltv['lifetime_last_sale_at'] ?? '-');
    echo $kv('note',                     $ltv['note'] ?? '-');
} else {
    echo "  (sem dados de LTV)\n";
}

// ── Mart divergence ───────────────────────────────────────────────────────

echo $sep('MART vs CANONICO');
echo $kv('mart_produto_periodo (produto)', $martRows . ' linha(s)');
echo $kv('canonical invoices_count',       $canonical);
if ($martRows > 0 && $canonical > 0) {
    echo $ok('Mart e canonico ambos possuem dados para este produto.');
} elseif ($martRows === 0 && $canonical === 0) {
    echo "  Produto sem dados em mart nem em canonico no periodo.\n";
}

// ── Freshness ─────────────────────────────────────────────────────────────

echo $sep('FRESHNESS');
$dq = $payload['data_quality'] ?? [];
echo $kv('freshness_status',         $dq['freshness_status'] ?? '-');
echo $kv('last_canonical_nf_at',     $dq['last_canonical_nf_at'] ?? '-');
echo $kv('last_gold_materialized_at', $dq['last_gold_materialized_at'] ?? '-');

// ── Alerts ────────────────────────────────────────────────────────────────

echo $sep('ALERTAS');
if (!$alerts) {
    echo $ok('Nenhum alerta detectado.');
} else {
    foreach ($alerts as $alert) {
        if ($alert['level'] === 'critical') {
            echo $crit($alert['msg']);
        } else {
            echo $warn($alert['msg']);
        }
    }
}

// ── Explain ───────────────────────────────────────────────────────────────

if ($explain) {
    echo $sep('INDICES RECOMENDADOS');
    $indexes = [
        "-- Itens por produto e nota\nCREATE INDEX idx_nf_itens_produto_nota\n  ON biomie_notas_fiscais_itens_canonicos (codigo_produto, nota_key);",
        "-- NFs por status e competência\nCREATE INDEX idx_nf_status_comp_nota\n  ON biomie_notas_fiscais_canonicas (status_fiscal, data_competencia, nota_key);",
        "-- NFs por vendedor e competência\nCREATE INDEX idx_nf_vendor_comp_status\n  ON biomie_notas_fiscais_canonicas (codigo_vendedor_omie, data_competencia, status_fiscal);",
        "-- Bridge por nota_key\nCREATE INDEX idx_bridge_nota_key\n  ON mart_bridge_pedido_nfe_titulo (nota_key);",
        "-- Mart produto por competência/produto\nCREATE INDEX idx_mart_produto_periodo_comp_prod\n  ON mart_produto_periodo (competencia, codigo_produto);",
    ];
    foreach ($indexes as $idx) {
        echo "\n" . $idx . "\n";
    }
    echo "\nNota: Usar migration Version20260516000001_Product360Indexes.php para aplicar com seguranca (maybeAddIndex).\n";
}

echo "\n";
exit(0);
