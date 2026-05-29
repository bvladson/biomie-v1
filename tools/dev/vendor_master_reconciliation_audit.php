<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ActiveVendorResolver;
use BIomie\Support\VendorAliasResolver;

$opts = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:']);
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$jsonMode = isset($opts['json']);
$sample = max(1, min(500, (int) ($opts['sample'] ?? 80)));
$t0 = microtime(true);

try {
    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$alias = new VendorAliasResolver();
$activeResolver = new ActiveVendorResolver($pdo, $alias);
$activeResolver->ensureLoaded();
$summary = $activeResolver->getSummary();
$omieRows = $activeResolver->getOmieRoster();
$dbRows = $activeResolver->getDbRoster();

function vmra_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function vmra_col_exists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function vmra_source_names(PDO $pdo, string $table, string $nameCol, string $where = '', array $params = []): array
{
    if (!vmra_table_exists($pdo, $table) || !vmra_col_exists($pdo, $table, $nameCol)) {
        return ['available' => false, 'names' => [], 'rows' => []];
    }

    $tableSql = '`' . str_replace('`', '``', $table) . '`';
    $nameSql = '`' . str_replace('`', '``', $nameCol) . '`';
    $sql = "SELECT {$nameSql} AS vendor_name, COUNT(*) AS rows_count FROM {$tableSql} WHERE NULLIF(TRIM(COALESCE({$nameSql}, '')), '') IS NOT NULL";
    if ($where !== '') {
        $sql .= ' AND ' . $where;
    }
    $sql .= " GROUP BY {$nameSql} ORDER BY rows_count DESC, {$nameSql}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['vendor_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return ['available' => true, 'names' => $names, 'rows' => $rows];
}

function vmra_count_recent_sales(PDO $pdo, string $table, string $nameCol, string $dateExpr, string $name, string $start, string $end): array
{
    if (!vmra_table_exists($pdo, $table) || !vmra_col_exists($pdo, $table, $nameCol)) {
        return ['count' => 0, 'value' => 0.0];
    }
    $valueExpr = vmra_col_exists($pdo, $table, 'valor_mercadorias') ? 'COALESCE(SUM(valor_mercadorias),0)' : '0';
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS rows_count, {$valueExpr} AS value_sum
         FROM {$table}
         WHERE {$dateExpr} BETWEEN :start AND :end
           AND {$nameCol} = :name"
    );
    $stmt->execute(['start' => $start, 'end' => $end, 'name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['count' => (int) ($row['rows_count'] ?? 0), 'value' => (float) ($row['value_sum'] ?? 0)];
}

$omieNameSet = [];
$inactiveNameSet = [];
foreach ($omieRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') continue;
    $key = $alias->normalize($name);
    $omieNameSet[$key] = $row;
    if (($row['is_active'] ?? true) === false) {
        $inactiveNameSet[$key] = $row;
    }
}

$dbNameSet = [];
foreach ($dbRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name !== '') $dbNameSet[$alias->normalize($name)] = $row;
}

$sources = [
    'nf' => vmra_source_names($pdo, 'biomie_notas_fiscais_canonicas', 'nome_vendedor', "COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end AND status_fiscal = 'autorizada'", ['start' => $start, 'end' => $end]),
    'orders' => vmra_source_names($pdo, 'biomie_pedidos_canonicos', 'nome_vendedor', 'data_emissao BETWEEN :start AND :end', ['start' => $start, 'end' => $end]),
    'customer_owner' => vmra_source_names($pdo, 'biomie_clientes_dim', 'vendedor_responsavel_nome'),
    'mart_vendedor_dia' => vmra_source_names($pdo, 'mart_vendedor_dia', 'nome_vendedor', 'dia BETWEEN :start AND :end', ['start' => $start, 'end' => $end]),
    'mart_comercial_pedido' => vmra_source_names($pdo, 'mart_comercial_pedido', 'nome_vendedor', 'data_emissao BETWEEN :start AND :end', ['start' => $start, 'end' => $end]),
    'mart_fiscal_nfe' => vmra_source_names($pdo, 'mart_fiscal_nfe', 'nome_vendedor', 'COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end', ['start' => $start, 'end' => $end]),
    'plano_voo_targets' => vmra_source_names($pdo, 'biomie_sales_targets_monthly', 'vendor_name', '`year_month` BETWEEN :ym_start AND :ym_end', ['ym_start' => substr($start, 0, 7), 'ym_end' => substr($end, 0, 7)]),
];

$allNames = [];
foreach ($sources as $sourceName => $source) {
    foreach ($source['names'] as $name) {
        $allNames[$name] = true;
    }
}

function vmra_ghosts(array $names, array $omieNameSet, VendorAliasResolver $alias): array
{
    $ghosts = [];
    foreach ($names as $name) {
        $key = $alias->normalize($name);
        $resolved = $alias->resolve($name);
        if ($key === '' || isset($omieNameSet[$key]) || ($resolved['resolved_by'] ?? '') === 'generic') {
            continue;
        }
        $ghosts[] = [
            'name' => $name,
            'vendor_channel' => $resolved['vendor_channel'],
            'alias_candidate' => $resolved['alias_candidate'],
            'requires_manual_approval' => $resolved['requires_manual_approval'],
            'channel_match' => $resolved['channel_match'],
            'suggested_alias' => $resolved['suggested_alias'],
            'warnings' => $resolved['warnings'],
        ];
    }
    return $ghosts;
}

$ghostNf = vmra_ghosts($sources['nf']['names'], $omieNameSet, $alias);
$ghostOrders = vmra_ghosts($sources['orders']['names'], $omieNameSet, $alias);
$ghostOwners = vmra_ghosts($sources['customer_owner']['names'], $omieNameSet, $alias);

$inactiveWithRecentSales = [];
foreach ($inactiveNameSet as $row) {
    $name = (string) ($row['name'] ?? '');
    $sales = vmra_count_recent_sales($pdo, 'biomie_notas_fiscais_canonicas', 'nome_vendedor', 'COALESCE(data_competencia, data_emissao)', $name, $start, $end);
    if ($sales['count'] > 0) {
        $inactiveWithRecentSales[] = ['vendor_name' => $name, 'nf_count' => $sales['count'], 'value' => round($sales['value'], 2)];
    }
}

$inactiveAsOwner = [];
foreach ($sources['customer_owner']['names'] as $name) {
    $key = $alias->normalize($name);
    if (isset($inactiveNameSet[$key])) {
        $inactiveAsOwner[] = $name;
    }
}

$caio = ['rev' => [], 'var' => [], 'ambiguous' => []];
$crossChannelConflicts = 0;
$allNameList = array_keys($allNames);
foreach ($allNameList as $name) {
    $resolved = $alias->resolve($name);
    $base = $alias->normalize((string) ($resolved['base_name'] ?? ''));
    if (!str_contains($base, 'caio')) continue;
    if ($resolved['vendor_channel'] === VendorAliasResolver::CHANNEL_REV) {
        $caio['rev'][] = $name;
    } elseif ($resolved['vendor_channel'] === VendorAliasResolver::CHANNEL_VAR) {
        $caio['var'][] = $name;
    } else {
        $caio['ambiguous'][] = $name;
    }
}
foreach ($caio['rev'] as $revName) {
    foreach ($caio['var'] as $varName) {
        if ($alias->areEquivalent($revName, $varName)) {
            $crossChannelConflicts++;
        }
    }
}

$sourceCounts = [];
foreach ($sources as $sourceName => $source) {
    $sourceCounts[$sourceName] = [
        'available' => (bool) $source['available'],
        'distinct_vendors' => count($source['names']),
    ];
}

$report = [
    'vendor_master_reconciliation_audit' => [
        'version' => 'COM.R1.5.1',
        'period' => ['start' => $start, 'end' => $end],
        'generated_at' => date('Y-m-d H:i:s'),
        'elapsed_s' => round(microtime(true) - $t0, 2),
        'connection' => $conn,
        'vendor_status_summary' => $summary,
        'metrics' => [
            'vendors_in_omie_master' => count($omieRows),
            'vendors_active_omie' => (int) ($summary['omie_active'] ?? 0),
            'vendors_inactive_omie' => (int) ($summary['omie_inactive'] ?? 0),
            'vendors_seen_in_nf' => count($sources['nf']['names']),
            'vendors_seen_in_orders' => count($sources['orders']['names']),
            'vendors_seen_as_customer_owner' => count($sources['customer_owner']['names']),
            'ghost_vendors_in_nf' => count($ghostNf),
            'ghost_vendors_in_orders' => count($ghostOrders),
            'ghost_vendors_as_owner' => count($ghostOwners),
            'inactive_with_recent_sales' => count($inactiveWithRecentSales),
            'inactive_as_customer_owner' => count($inactiveAsOwner),
            'caio_rev_count' => count(array_unique($caio['rev'])),
            'caio_var_count' => count(array_unique($caio['var'])),
            'caio_ambiguous_count' => count(array_unique($caio['ambiguous'])),
            'caio_cross_channel_conflicts' => $crossChannelConflicts,
        ],
        'source_counts' => $sourceCounts,
        'ghost_vendors_in_nf' => array_slice($ghostNf, 0, $sample),
        'ghost_vendors_in_orders' => array_slice($ghostOrders, 0, $sample),
        'ghost_vendors_as_owner' => array_slice($ghostOwners, 0, $sample),
        'inactive_with_recent_sales_rows' => array_slice($inactiveWithRecentSales, 0, $sample),
        'inactive_as_customer_owner_rows' => array_slice(array_values(array_unique($inactiveAsOwner)), 0, $sample),
        'caio_records' => [
            'rev' => array_values(array_unique($caio['rev'])),
            'var' => array_values(array_unique($caio['var'])),
            'ambiguous' => array_values(array_unique($caio['ambiguous'])),
        ],
        'diagnosis' => ($summary['active_status_mapping_gap'] ?? false) ? 'active_status_mapping_gap' : 'ok',
    ],
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit(0);
}

$d = $report['vendor_master_reconciliation_audit'];
$m = $d['metrics'];
$hr = str_repeat('=', 78);
echo PHP_EOL . $hr . PHP_EOL;
echo "  COM.R1.5.1 - Vendor Master Reconciliation Audit\n";
echo "  Period: {$start} -> {$end}\n";
echo $hr . PHP_EOL . PHP_EOL;
echo '  Omie master          : ' . $m['vendors_in_omie_master'] . ' total, ' . $m['vendors_active_omie'] . ' active, ' . $m['vendors_inactive_omie'] . ' inactive' . PHP_EOL;
echo '  BIomie status source : ' . ($summary['source'] ?? 'unknown') . PHP_EOL;
echo '  Diagnosis            : ' . $d['diagnosis'] . PHP_EOL . PHP_EOL;
echo '  Seen in NF           : ' . $m['vendors_seen_in_nf'] . ' distinct; ghosts=' . $m['ghost_vendors_in_nf'] . PHP_EOL;
echo '  Seen in orders       : ' . $m['vendors_seen_in_orders'] . ' distinct; ghosts=' . $m['ghost_vendors_in_orders'] . PHP_EOL;
echo '  Customer owners      : ' . $m['vendors_seen_as_customer_owner'] . ' distinct; ghosts=' . $m['ghost_vendors_as_owner'] . PHP_EOL;
echo '  Inactive recent sales: ' . $m['inactive_with_recent_sales'] . PHP_EOL;
echo '  Inactive owners      : ' . $m['inactive_as_customer_owner'] . PHP_EOL;
echo '  CAIO REV/VAR/ambig   : ' . $m['caio_rev_count'] . '/' . $m['caio_var_count'] . '/' . $m['caio_ambiguous_count'] . PHP_EOL;
echo '  CAIO cross alias bugs: ' . $m['caio_cross_channel_conflicts'] . PHP_EOL;

if ($d['ghost_vendors_in_nf']) {
    echo PHP_EOL . '  Ghost vendors in NF (sample)' . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
    foreach ($d['ghost_vendors_in_nf'] as $row) {
        $suggest = $row['suggested_alias'] ? ' -> candidate ' . $row['suggested_alias'] : '';
        echo '  ' . $row['name'] . $suggest . PHP_EOL;
    }
}

echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;
