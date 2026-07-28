<?php

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$companies = $pdo->query(
    'SELECT id, slug, nome_fantasia FROM biomie_companies WHERE ativo = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$companyIds = array_map('intval', array_column($companies, 'id'));
$companyList = implode(',', $companyIds);

$metricSql = [
    'orders' => 'SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE company_id = :company_id',
    'invoices' => 'SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE company_id = :company_id',
    'invoice_revenue' => "SELECT COALESCE(SUM(valor_total), 0) FROM biomie_notas_fiscais_canonicas WHERE company_id = :company_id AND status_fiscal = 'autorizada'",
    'titles' => 'SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE company_id = :company_id',
    'open_receivables' => 'SELECT COALESCE(SUM(valor_aberto), 0) FROM biomie_titulos_financeiros_canonicos WHERE company_id = :company_id',
    'customers' => 'SELECT COUNT(*) FROM biomie_clientes_dim WHERE company_id = :company_id',
    'customer_geo' => 'SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE company_id = :company_id',
    'product_mart_rows' => 'SELECT COUNT(*) FROM mart_produto_periodo WHERE company_id = :company_id',
    'product_mart_revenue' => 'SELECT COALESCE(SUM(valor_total), 0) FROM mart_produto_periodo WHERE company_id = :company_id',
    'geo_mart_rows' => 'SELECT COUNT(*) FROM mart_compradores_por_geo WHERE company_id = :company_id',
    'geo_mart_revenue' => 'SELECT COALESCE(SUM(faturamento), 0) FROM mart_compradores_por_geo WHERE company_id = :company_id',
    'fiscal_item_rows' => 'SELECT COUNT(*) FROM mart_fiscal_items WHERE company_id = :company_id',
    'forecasts' => 'SELECT COUNT(*) FROM mart_forecasts WHERE company_id = :company_id',
    'opportunities' => 'SELECT COUNT(*) FROM mart_oportunidades WHERE company_id = :company_id',
];

$results = [];
foreach ($companies as $company) {
    $companyId = (int) $company['id'];
    $metrics = [];
    foreach ($metricSql as $name => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        $value = $stmt->fetchColumn();
        $metrics[$name] = str_contains($name, 'revenue') || $name === 'open_receivables'
            ? round((float) $value, 2)
            : (int) $value;
    }
    $productSourceStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(i.valor_total), 0)
         FROM biomie_notas_fiscais_itens_canonicos i
         JOIN biomie_notas_fiscais_canonicas n
           ON n.company_id = i.company_id AND n.nota_key = i.nota_key
         WHERE n.company_id = :company_id
           AND n.status_fiscal = 'autorizada'
           AND n.operation_scope = 'commercial'"
    );
    $productSourceStmt->execute(['company_id' => $companyId]);
    $productSource = round((float) $productSourceStmt->fetchColumn(), 2);
    $metrics['product_source_revenue'] = $productSource;
    $metrics['product_source_difference'] = round(
        (float) $metrics['product_mart_revenue'] - $productSource,
        2
    );
    $results[] = [
        'company_id' => $companyId,
        'slug' => (string) $company['slug'],
        'name' => (string) $company['nome_fantasia'],
        'metrics' => $metrics,
    ];
}

$invalidRows = [];
$tenantTables = $pdo->query(
    "SELECT TABLE_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($tenantTables as $table) {
    $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$quoted}
         WHERE company_id IS NULL OR company_id NOT IN ({$companyList})"
    )->fetchColumn();
    if ($count > 0) {
        $invalidRows[(string) $table] = $count;
    }
}

$integrity = [
    'invoice_items_without_same_company_invoice' => (int) $pdo->query(
        'SELECT COUNT(*)
         FROM biomie_notas_fiscais_itens_canonicos i
         LEFT JOIN biomie_notas_fiscais_canonicas n
           ON n.company_id = i.company_id AND n.nota_key = i.nota_key
         WHERE n.id IS NULL'
    )->fetchColumn(),
    'geo_rows_without_same_company_customer' => (int) $pdo->query(
        'SELECT COUNT(*)
         FROM biomie_clientes_geo_dim g
         LEFT JOIN biomie_clientes_dim d
           ON d.company_id = g.company_id
          AND d.codigo_cliente_omie = g.codigo_cliente_omie
         WHERE d.id IS NULL'
    )->fetchColumn(),
    'stale_running_syncs' => (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_sync_runs
         WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    )->fetchColumn(),
];

$consolidated = [];
foreach ([
    'invoice_revenue' => "SELECT COALESCE(SUM(valor_total), 0) FROM biomie_notas_fiscais_canonicas WHERE company_id IN ({$companyList}) AND status_fiscal = 'autorizada'",
    'open_receivables' => "SELECT COALESCE(SUM(valor_aberto), 0) FROM biomie_titulos_financeiros_canonicos WHERE company_id IN ({$companyList})",
    'product_mart_revenue' => "SELECT COALESCE(SUM(valor_total), 0) FROM mart_produto_periodo WHERE company_id IN ({$companyList})",
    'geo_mart_revenue' => "SELECT COALESCE(SUM(faturamento), 0) FROM mart_compradores_por_geo WHERE company_id IN ({$companyList})",
] as $name => $sql) {
    $direct = round((float) $pdo->query($sql)->fetchColumn(), 2);
    $individual = round(array_sum(array_map(
        static fn (array $row): float => (float) $row['metrics'][$name],
        $results
    )), 2);
    $consolidated[$name] = [
        'individual_sum' => $individual,
        'consolidated_query' => $direct,
        'difference' => round($direct - $individual, 2),
        'ok' => abs($direct - $individual) < 0.01,
    ];
}

$ok = $invalidRows === []
    && !in_array(false, array_column($consolidated, 'ok'), true)
    && count(array_filter(
        $results,
        static fn (array $row): bool => abs((float) $row['metrics']['product_source_difference']) >= 0.01
    )) === 0
    && $integrity['invoice_items_without_same_company_invoice'] === 0
    && $integrity['stale_running_syncs'] === 0;

echo json_encode([
    'ok' => $ok,
    'generated_at' => date(DATE_ATOM),
    'companies' => $results,
    'consolidated_reconciliation' => $consolidated,
    'invalid_company_rows' => $invalidRows,
    'integrity' => $integrity,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
