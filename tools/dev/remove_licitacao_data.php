<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$options = getopt('', ['apply', 'json']);
$apply = isset($options['apply']);

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function cleanupTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @return list<string> */
function cleanupColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function cleanupQuote(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * @param list<string> $values
 * @param list<mixed> $params
 */
function cleanupAddIn(array &$conditions, array &$params, string $column, array $values): void
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn (string $v): bool => $v !== '')));
    if ($values === []) {
        return;
    }

    $conditions[] = 'CAST(' . cleanupQuote($column) . ' AS CHAR) IN ('
        . implode(',', array_fill(0, count($values), '?')) . ')';
    array_push($params, ...$values);
}

/**
 * @param array<string,list<string>> $identifiers
 * @return array{sql:string,params:list<mixed>}
 */
function cleanupCondition(PDO $pdo, string $table, array $identifiers): array
{
    $columns = cleanupColumns($pdo, $table);
    $conditions = [];
    $params = [];

    if ($table === 'biomie_vendedores_omie') {
        if (in_array('codigo_omie', $columns, true)) {
            cleanupAddIn($conditions, $params, 'codigo_omie', $identifiers['vendor_code']);
        }
        if (in_array('nome', $columns, true)) {
            $conditions[] = "UPPER(COALESCE(nome, '')) LIKE '%LICITAC%'";
        }
    } else {
        if (in_array('nota_key', $columns, true)) {
            cleanupAddIn(
                $conditions,
                $params,
                'nota_key',
                array_merge($identifiers['nota_key'], $identifiers['chave_nfe'])
            );
        }
        foreach ([
            'nota_id_omie' => 'nota_id_omie',
            'chave_nfe' => 'chave_nfe',
            'codigo_vendedor_omie' => 'vendor_code',
        ] as $column => $identifierType) {
            if (in_array($column, $columns, true)) {
                cleanupAddIn($conditions, $params, $column, $identifiers[$identifierType]);
            }
        }
        if (in_array('nome_vendedor', $columns, true)) {
            $conditions[] = "UPPER(COALESCE(nome_vendedor, '')) LIKE '%LICITAC%'";
        }
    }

    if (in_array('business_unit_scope', $columns, true)) {
        $conditions[] = "business_unit_scope = 'licitacao'";
    }

    return [
        'sql' => $conditions !== [] ? '(' . implode(' OR ', $conditions) . ')' : '1 = 0',
        'params' => $params,
    ];
}

/** @return list<array<string,mixed>> */
function cleanupFetch(PDO $pdo, string $table, array $condition): array
{
    $stmt = $pdo->prepare('SELECT * FROM ' . cleanupQuote($table) . ' WHERE ' . $condition['sql']);
    $stmt->execute($condition['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cleanupWriteBackup(string $directory, string $table, array $rows): array
{
    $path = $directory . '/' . $table . '.json.gz';
    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, gzencode($json, 9), LOCK_EX) === false) {
        throw new RuntimeException('Falha ao gravar backup de ' . $table);
    }
    @chmod($path, 0600);

    return [
        'file' => $path,
        'rows' => count($rows),
        'sha256' => hash_file('sha256', $path),
    ];
}

if (!cleanupTableExists($pdo, 'biomie_data_exclusion_rules')) {
    throw new RuntimeException('Migration biomie_data_exclusion_rules ainda nao aplicada.');
}

$vendorRows = $pdo->query(
    "SELECT * FROM biomie_vendedores_omie
     WHERE UPPER(COALESCE(nome, '')) LIKE '%LICITAC%'
        OR business_unit_scope = 'licitacao'"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$vendorCodes = array_values(array_unique(array_filter(array_map(
    static fn (array $row): string => trim((string) ($row['codigo_omie'] ?? '')),
    $vendorRows
))));

$nfConditions = ["UPPER(COALESCE(nome_vendedor, '')) LIKE '%LICITAC%'", "business_unit_scope = 'licitacao'"];
$nfParams = [];
if ($vendorCodes !== []) {
    cleanupAddIn($nfConditions, $nfParams, 'codigo_vendedor_omie', $vendorCodes);
}
$nfStmt = $pdo->prepare(
    'SELECT * FROM biomie_notas_fiscais_canonicas WHERE ' . implode(' OR ', $nfConditions)
);
$nfStmt->execute($nfParams);
$nfRows = $nfStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$identifiers = [
    'vendor_code' => $vendorCodes,
    'nota_key' => [],
    'nota_id_omie' => [],
    'chave_nfe' => [],
    'pedido_id_omie' => [],
    'titulo_key' => [],
    'titulo_id_omie' => [],
    'xml_key' => [],
];
foreach ($nfRows as $row) {
    foreach ([
        'nota_key' => 'nota_key',
        'nota_id_omie' => 'nota_id_omie',
        'chave_nfe' => 'chave_nfe',
        'pedido_id_omie' => 'pedido_id_omie',
    ] as $column => $type) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
            $identifiers[$type][] = $value;
        }
    }
}
foreach ($identifiers as $type => $values) {
    $identifiers[$type] = array_values(array_unique($values));
}

$targets = [
    'biomie_notas_fiscais_itens_canonicos',
    'biomie_documentos_xml_canonicos',
    'biomie_titulos_financeiros_canonicos',
    'biomie_vendas_geo_cliente_fact',
    'mart_bridge_pedido_nfe_titulo',
    'mart_comercial_pedido',
    'mart_fiscal_nfe',
    'biomie_pedidos_canonicos',
    'biomie_notas_fiscais_canonicas',
    'biomie_vendedores_omie',
];

$conditions = [];
$rowsByTable = [];
foreach ($targets as $table) {
    if (!cleanupTableExists($pdo, $table)) {
        continue;
    }
    $conditions[$table] = cleanupCondition($pdo, $table, $identifiers);
    $rowsByTable[$table] = cleanupFetch($pdo, $table, $conditions[$table]);
}

foreach ($rowsByTable['biomie_titulos_financeiros_canonicos'] ?? [] as $row) {
    foreach (['titulo_key' => 'titulo_key', 'titulo_id_omie' => 'titulo_id_omie'] as $column => $type) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
            $identifiers[$type][] = $value;
        }
    }
}
foreach ($rowsByTable['biomie_documentos_xml_canonicos'] ?? [] as $row) {
    $value = trim((string) ($row['xml_key'] ?? ''));
    if ($value !== '') {
        $identifiers['xml_key'][] = $value;
    }
}
foreach ($identifiers as $type => $values) {
    $identifiers[$type] = array_values(array_unique($values));
}

$report = [
    'ok' => true,
    'mode' => $apply ? 'apply' : 'dry-run',
    'generated_at' => date(DATE_ATOM),
    'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    'identifiers' => $identifiers,
    'planned_rows' => array_map('count', $rowsByTable),
    'duplicates_removed' => 0,
    'raw_bronze_preserved' => true,
    'backup' => null,
    'deleted_rows' => [],
    'post_cleanup_rows' => [],
];

if (!$apply) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$home = rtrim((string) (getenv('HOME') ?: dirname(__DIR__, 4)), '/');
$backupDirectory = $home . '/biomie_backups/licitacao_cleanup_' . date('Ymd_His');
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Falha ao criar diretorio de backup: ' . $backupDirectory);
}
@chmod($backupDirectory, 0700);

$backupManifest = [];
foreach ($rowsByTable as $table => $rows) {
    $backupManifest[$table] = cleanupWriteBackup($backupDirectory, $table, $rows);
}

$rules = [];
foreach ($identifiers as $type => $values) {
    foreach ($values as $value) {
        $rules[] = [
            'entity_type' => match ($type) {
                'vendor_code' => 'vendor',
                'titulo_key', 'titulo_id_omie' => 'financial_title',
                'xml_key' => 'xml_document',
                'pedido_id_omie' => 'order',
                default => 'invoice',
            },
            'identifier_type' => $type,
            'identifier_value' => $value,
        ];
    }
}

$pdo->beginTransaction();
try {
    $insertRule = $pdo->prepare(
        'INSERT INTO biomie_data_exclusion_rules
            (entity_type, identifier_type, identifier_value, reason, active, created_at, updated_at)
         VALUES (:entity_type, :identifier_type, :identifier_value, :reason, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE active = 1, reason = VALUES(reason), updated_at = NOW()'
    );
    foreach ($rules as $rule) {
        $insertRule->execute($rule + ['reason' => 'Remocao solicitada: dados de Licitacao']);
    }

    foreach ($targets as $table) {
        if (!isset($conditions[$table])) {
            continue;
        }
        $stmt = $pdo->prepare('DELETE FROM ' . cleanupQuote($table) . ' WHERE ' . $conditions[$table]['sql']);
        $stmt->execute($conditions[$table]['params']);
        $report['deleted_rows'][$table] = $stmt->rowCount();
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

foreach ($conditions as $table => $condition) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . cleanupQuote($table) . ' WHERE ' . $condition['sql']);
    $stmt->execute($condition['params']);
    $report['post_cleanup_rows'][$table] = (int) $stmt->fetchColumn();
}

$report['backup'] = [
    'directory' => $backupDirectory,
    'tables' => $backupManifest,
];
$manifestPath = $backupDirectory . '/manifest.json';
file_put_contents(
    $manifestPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);
@chmod($manifestPath, 0600);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
