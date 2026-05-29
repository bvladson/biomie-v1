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
$sample = max(1, min(300, (int) ($opts['sample'] ?? 80)));
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
$resolver = new ActiveVendorResolver($pdo, $alias);
$resolver->ensureLoaded();
$summary = $resolver->getSummary();
$dbRows = $resolver->getDbRoster();
$omieRows = $resolver->getOmieRoster();

function vasa_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function vasa_col_exists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function vasa_names(PDO $pdo, string $table, string $col, string $where = '', array $params = []): array
{
    if (!vasa_table_exists($pdo, $table) || !vasa_col_exists($pdo, $table, $col)) {
        return [];
    }
    $sql = "SELECT {$col} AS name, COUNT(*) AS rows_count FROM {$table} WHERE NULLIF(TRIM(COALESCE({$col}, '')), '') IS NOT NULL";
    if ($where !== '') $sql .= ' AND ' . $where;
    $sql .= " GROUP BY {$col} ORDER BY rows_count DESC, {$col}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$omieByName = [];
foreach ($omieRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name !== '') $omieByName[$alias->normalize($name)] = $row;
}

$dbByCode = [];
$divergences = [];
foreach ($dbRows as $row) {
    $code = (string) ($row['code'] ?? '');
    if ($code !== '') $dbByCode[$code] = $row;
}
foreach ($omieRows as $omie) {
    $code = (string) ($omie['code'] ?? '');
    if ($code === '' || !isset($dbByCode[$code])) continue;
    $db = $dbByCode[$code];
    if (($db['is_active'] ?? true) !== ($omie['is_active'] ?? true)) {
        $divergences[] = [
            'codigo_omie' => $code,
            'nome_biomie' => (string) ($db['name'] ?? ''),
            'nome_omie' => (string) ($omie['name'] ?? ''),
            'biomie_is_active' => (bool) ($db['is_active'] ?? true),
            'omie_is_active' => (bool) ($omie['is_active'] ?? true),
            'omie_status_field' => $omie['status_field'] ?? null,
            'omie_status_value' => $omie['status_value'] ?? null,
        ];
    }
}

$nfRows = vasa_names(
    $pdo,
    'biomie_notas_fiscais_canonicas',
    'nome_vendedor',
    "COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end AND status_fiscal = 'autorizada'",
    ['start' => $start, 'end' => $end]
);
$orderRows = vasa_names(
    $pdo,
    'biomie_pedidos_canonicos',
    'nome_vendedor',
    'data_emissao BETWEEN :start AND :end',
    ['start' => $start, 'end' => $end]
);
$ownerRows = vasa_names($pdo, 'biomie_clientes_dim', 'vendedor_responsavel_nome');

$ghosts = [];
$ambiguous = [];
foreach (['nf' => $nfRows, 'orders' => $orderRows, 'owner' => $ownerRows] as $source => $rows) {
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') continue;
        $resolved = $resolver->resolve($name, $name);
        if ($resolved['ghost_vendor']) {
            $ghosts[] = [
                'source' => $source,
                'name' => $name,
                'rows_count' => (int) ($row['rows_count'] ?? 0),
                'vendor_channel' => $resolved['vendor_channel'],
                'suggested_alias' => $resolved['suggested_alias'],
                'requires_manual_approval' => $resolved['requires_manual_approval'],
                'warnings' => $resolved['warnings'],
            ];
        }
        if (in_array(VendorAliasResolver::WARN_AMBIGUOUS_CHANNEL, $resolved['warnings'], true)) {
            $ambiguous[] = ['source' => $source, 'name' => $name, 'rows_count' => (int) ($row['rows_count'] ?? 0)];
        }
    }
}

$inactiveVendors = [];
foreach ($omieRows as $row) {
    if (($row['is_active'] ?? true) === false) {
        $inactiveVendors[] = [
            'codigo_omie' => (string) ($row['code'] ?? ''),
            'nome' => (string) ($row['name'] ?? ''),
            'status_source' => 'omie_bronze_payload',
            'status_field' => $row['status_field'] ?? null,
            'status_value' => $row['status_value'] ?? null,
        ];
    }
}

$activeVendors = [];
foreach ($omieRows as $row) {
    if (($row['is_active'] ?? true) === true) {
        $activeVendors[] = [
            'codigo_omie' => (string) ($row['code'] ?? ''),
            'nome' => (string) ($row['name'] ?? ''),
        ];
    }
}

$inactiveWithSales = [];
foreach ($inactiveVendors as $row) {
    $name = (string) $row['nome'];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS nfs, COALESCE(SUM(valor_mercadorias),0) AS valor
         FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
           AND status_fiscal = 'autorizada'
           AND nome_vendedor = :name"
    );
    $stmt->execute(['start' => $start, 'end' => $end, 'name' => $name]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($sales['nfs'] ?? 0) > 0) {
        $inactiveWithSales[] = [
            'vendor_name' => $name,
            'qtd_nfs' => (int) ($sales['nfs'] ?? 0),
            'valor' => round((float) ($sales['valor'] ?? 0), 2),
        ];
    }
}

$diagnosis = ($summary['active_status_mapping_gap'] ?? false) ? 'active_status_mapping_gap' : 'ok';

$report = [
    'vendor_active_status_audit' => [
        'version' => 'COM.R1.5.1',
        'period' => ['start' => $start, 'end' => $end],
        'generated_at' => date('Y-m-d H:i:s'),
        'elapsed_s' => round(microtime(true) - $t0, 2),
        'connection' => $conn,
        'diagnosis' => $diagnosis,
        'status_from_biomie_master' => [
            'total' => (int) ($summary['db_total'] ?? count($dbRows)),
            'active' => (int) ($summary['db_active'] ?? 0),
            'inactive' => (int) ($summary['db_inactive'] ?? 0),
            'trusted' => (bool) ($summary['db_status_trusted'] ?? false),
        ],
        'status_from_omie_payload' => [
            'total' => (int) ($summary['omie_total'] ?? count($omieRows)),
            'active' => (int) ($summary['omie_active'] ?? 0),
            'inactive' => (int) ($summary['omie_inactive'] ?? 0),
            'trusted' => (bool) ($summary['omie_status_trusted'] ?? false),
        ],
        'divergences_between_omie_and_biomie' => array_slice($divergences, 0, $sample),
        'active_vendors' => array_slice($activeVendors, 0, $sample),
        'inactive_vendors' => array_slice($inactiveVendors, 0, $sample),
        'inactive_with_recent_sales_warning' => array_slice($inactiveWithSales, 0, $sample),
        'ghost_vendors' => array_slice($ghosts, 0, $sample),
        'ambiguous_channel_vendors' => array_slice($ambiguous, 0, $sample),
        'note' => 'Read-only. Inactive vendors remain in history. Sales with inactive vendor are warnings, not blockers.',
    ],
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit(0);
}

$d = $report['vendor_active_status_audit'];
$hr = str_repeat('=', 78);
echo PHP_EOL . $hr . PHP_EOL;
echo "  COM.R1.5.1 - Vendor Active Status Audit\n";
echo "  Period: {$start} -> {$end}\n";
echo $hr . PHP_EOL . PHP_EOL;
echo '  Diagnosis              : ' . $d['diagnosis'] . PHP_EOL;
echo '  BIomie master          : ' . $d['status_from_biomie_master']['total'] . ' total, '
    . $d['status_from_biomie_master']['active'] . ' active, '
    . $d['status_from_biomie_master']['inactive'] . ' inactive, trusted='
    . ($d['status_from_biomie_master']['trusted'] ? 'yes' : 'no') . PHP_EOL;
echo '  Omie payload           : ' . $d['status_from_omie_payload']['total'] . ' total, '
    . $d['status_from_omie_payload']['active'] . ' active, '
    . $d['status_from_omie_payload']['inactive'] . ' inactive, trusted='
    . ($d['status_from_omie_payload']['trusted'] ? 'yes' : 'no') . PHP_EOL;
echo '  Divergences            : ' . count($d['divergences_between_omie_and_biomie']) . PHP_EOL;
echo '  Ghost vendors          : ' . count($d['ghost_vendors']) . PHP_EOL;
echo '  Ambiguous channel      : ' . count($d['ambiguous_channel_vendors']) . PHP_EOL;

if ($d['diagnosis'] === 'active_status_mapping_gap') {
    echo PHP_EOL . '  Finding: Omie payload shows inactive vendors, but BIomie master has 0 inactive.' . PHP_EOL;
}

if ($d['inactive_vendors']) {
    echo PHP_EOL . '  Inactive vendors from Omie payload' . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
    foreach ($d['inactive_vendors'] as $row) {
        echo '  [' . ($row['codigo_omie'] ?: '-') . '] ' . $row['nome']
            . ' via ' . ($row['status_field'] ?: '?') . '=' . (string) ($row['status_value'] ?? '') . PHP_EOL;
    }
}

if ($d['ghost_vendors']) {
    echo PHP_EOL . '  Ghost vendors' . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
    foreach ($d['ghost_vendors'] as $row) {
        $suggest = $row['suggested_alias'] ? ' -> candidate ' . $row['suggested_alias'] : '';
        echo '  [' . $row['source'] . '] ' . $row['name'] . $suggest . PHP_EOL;
    }
}

echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;
