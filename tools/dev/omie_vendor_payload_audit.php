<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\VendorAliasResolver;

$opts = getopt('', ['pretty', 'json', 'sample:']);
$jsonMode = isset($opts['json']);
$sample = max(1, min(1000, (int) ($opts['sample'] ?? 100)));
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
$statusFieldCandidates = ['ativo', 'inativo', 'bloqueado', 'situacao', 'status', 'cancelado', 'disabled', 'inactive', 'inativo_sn', 'ativo_sn'];

function ovpa_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ovpa_flatten(array $payload, string $prefix = ''): array
{
    $out = [];
    foreach ($payload as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        if (is_array($value)) {
            $out += ovpa_flatten($value, $path);
        } else {
            $out[$path] = $value;
        }
    }
    return $out;
}

function ovpa_first(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $current = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if ($current !== null && trim((string) $current) !== '') {
            return trim((string) $current);
        }
    }
    return '';
}

function ovpa_field_name(string $path): string
{
    $parts = explode('.', $path);
    return strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) end($parts)));
}

function ovpa_status_to_active(string $field, mixed $value, VendorAliasResolver $alias): ?bool
{
    $v = $alias->normalize((string) $value);
    if ($v === '') return null;

    $negative = in_array($field, ['inativo', 'bloqueado', 'cancelado', 'disabled', 'inactive', 'inativo_sn'], true);
    $positive = in_array($field, ['ativo', 'ativo_sn'], true);

    if ($negative) {
        if (in_array($v, ['s', 'sim', 'y', 'yes', 'true', '1', 'inativo', 'inactive', 'disabled', 'bloqueado', 'cancelado'], true)) return false;
        if (in_array($v, ['n', 'nao', 'no', 'false', '0', 'ativo', 'active'], true)) return true;
    }
    if ($positive) {
        if (in_array($v, ['s', 'sim', 'y', 'yes', 'true', '1', 'ativo', 'active'], true)) return true;
        if (in_array($v, ['n', 'nao', 'no', 'false', '0', 'inativo', 'inactive', 'disabled'], true)) return false;
    }
    if (in_array($field, ['situacao', 'status'], true)) {
        if (str_contains($v, 'inativo') || str_contains($v, 'inactive') || str_contains($v, 'bloque')) return false;
        if (str_contains($v, 'ativo') || str_contains($v, 'active')) return true;
    }

    return null;
}

function ovpa_payload_status(array $payload, array $candidates, VendorAliasResolver $alias): array
{
    foreach (ovpa_flatten($payload) as $path => $value) {
        $field = ovpa_field_name($path);
        if (!in_array($field, $candidates, true)) continue;
        $active = ovpa_status_to_active($field, $value, $alias);
        if ($active !== null) {
            return ['known' => true, 'active' => $active, 'field' => $path, 'value' => $value];
        }
    }
    return ['known' => false, 'active' => true, 'field' => null, 'value' => null];
}

function ovpa_vendor_row_from_payload(array $payload, array $candidates, VendorAliasResolver $alias): array
{
    $flat = ovpa_flatten($payload);
    $status = ovpa_payload_status($payload, $candidates, $alias);
    return [
        'codigo' => ovpa_first($payload, ['codigo', 'codigo_omie', 'codigo_vendedor', 'codVend']),
        'nome' => ovpa_first($payload, ['nome', 'nome_vendedor', 'vendedor_nome']),
        'email' => ovpa_first($payload, ['email', 'e_mail', 'email_vendedor']),
        'percentual_comissao' => ovpa_first($payload, ['comissao', 'percentual_comissao', 'perc_comissao']),
        'incluido_por' => ovpa_first($payload, ['incluido_por', 'incluidoPor', 'usuario_inclusao', 'uInc']),
        'alterado_por' => ovpa_first($payload, ['alterado_por', 'alteradoPor', 'usuario_alteracao', 'uAlt']),
        'data_inclusao' => ovpa_first($payload, ['data_inclusao', 'dt_inclusao', 'dInc', 'inclusao']),
        'ultima_alteracao' => ovpa_first($payload, ['ultima_alteracao', 'data_alteracao', 'dt_alteracao', 'dAlt']),
        'permissoes' => [
            'visualiza_pedido' => ovpa_first($payload, ['visualiza_pedido', 'visualizaPedido']),
            'fatura_pedido' => ovpa_first($payload, ['fatura_pedido', 'faturaPedido']),
            'raw' => array_intersect_key($flat, array_flip(array_filter(array_keys($flat), static fn (string $k): bool => str_contains(strtolower($k), 'pedido') || str_contains(strtolower($k), 'permiss')))),
        ],
        'status' => $status,
        'raw_field_count' => count($flat),
    ];
}

$bronzeAvailable = ovpa_table_exists($pdo, 'biomie_omie_bronze');
$vendorTableAvailable = ovpa_table_exists($pdo, 'biomie_vendedores_omie');
$rawCalls = [];
$latestPayloadRows = [];
$payloadFields = [];
$statusFieldsFound = [];
$samplePayloads = [];

if ($bronzeAvailable) {
    $rawCalls = $pdo->query(
        "SELECT omie_method, endpoint, domain_name, entity_name, COUNT(*) AS rows_count, MIN(ingested_at) AS first_seen, MAX(ingested_at) AS last_seen
         FROM biomie_omie_bronze
         WHERE domain_name = 'vendors'
            OR entity_name = 'vendor'
            OR omie_method LIKE '%Vendedor%'
            OR endpoint LIKE '%vendedor%'
         GROUP BY omie_method, endpoint, domain_name, entity_name
         ORDER BY rows_count DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query(
        "SELECT b.id, b.provider_key, b.natural_key, b.payload_json, b.ingested_at
         FROM biomie_omie_bronze b
         INNER JOIN (
             SELECT COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash) AS roster_key, MAX(id) AS max_id
             FROM biomie_omie_bronze
             WHERE domain_name = 'vendors'
                OR entity_name = 'vendor'
                OR omie_method LIKE '%Vendedor%'
                OR endpoint LIKE '%vendedor%'
             GROUP BY COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash)
         ) latest ON latest.max_id = b.id
         ORDER BY b.id"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) continue;
        $latest = ovpa_vendor_row_from_payload($payload, $statusFieldCandidates, $alias);
        if ($latest['codigo'] === '') {
            $latest['codigo'] = trim((string) ($row['provider_key'] ?? $row['natural_key'] ?? ''));
        }
        $latest['bronze_id'] = (int) ($row['id'] ?? 0);
        $latest['ingested_at'] = (string) ($row['ingested_at'] ?? '');
        $latestPayloadRows[] = $latest;

        foreach (ovpa_flatten($payload) as $path => $value) {
            $payloadFields[$path] = true;
            $field = ovpa_field_name($path);
            if (in_array($field, $statusFieldCandidates, true)) {
                $statusFieldsFound[$path] = true;
            }
        }
        if (count($samplePayloads) < $sample) {
            $samplePayloads[] = $latest;
        }
    }
}

$dbRows = [];
$dbActive = 0;
$dbInactive = 0;
if ($vendorTableAvailable) {
    $stmt = $pdo->query('SELECT codigo_omie, nome, email, comissao, ativo, sincronizado_em FROM biomie_vendedores_omie ORDER BY nome');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dbRows[] = $row;
        ((int) ($row['ativo'] ?? 1) === 1) ? $dbActive++ : $dbInactive++;
    }
}

$omieActive = 0;
$omieInactive = 0;
$omieUnknown = 0;
foreach ($latestPayloadRows as $row) {
    $status = $row['status'] ?? ['known' => false, 'active' => true];
    if (!($status['known'] ?? false)) {
        $omieUnknown++;
    } elseif (($status['active'] ?? true) === true) {
        $omieActive++;
    } else {
        $omieInactive++;
    }
}

$dbCodes = [];
$dbNames = [];
foreach ($dbRows as $row) {
    $dbCodes[(string) ($row['codigo_omie'] ?? '')] = true;
    $dbNames[$alias->normalize((string) ($row['nome'] ?? ''))] = true;
}

$missingInDb = [];
foreach ($latestPayloadRows as $row) {
    $code = (string) ($row['codigo'] ?? '');
    $nameKey = $alias->normalize((string) ($row['nome'] ?? ''));
    if (($code !== '' && !isset($dbCodes[$code])) || ($code === '' && $nameKey !== '' && !isset($dbNames[$nameKey]))) {
        $missingInDb[] = [
            'codigo' => $code,
            'nome' => (string) ($row['nome'] ?? ''),
            'status' => $row['status'] ?? null,
        ];
    }
}

$diagnosis = 'ok';
if ($omieInactive > 0 && $dbInactive === 0) {
    $diagnosis = 'active_status_mapping_gap';
} elseif ($omieInactive > 0 && count($missingInDb) > 0) {
    $diagnosis = 'possible_active_only_sync';
}

$writerFile = dirname(__DIR__, 2) . '/app/Canonical/CanonicalWriteRepository.php';
$writerSource = is_file($writerFile) ? (string) file_get_contents($writerFile) : '';
$staticEvidence = [
    'upsert_vendor_sets_ativo_true' => str_contains($writerSource, 'ativo, sincronizado_em') && str_contains($writerSource, 'ativo = 1'),
    'source_file' => 'app/Canonical/CanonicalWriteRepository.php',
];

$report = [
    'omie_vendor_payload_audit' => [
        'version' => 'COM.R1.5.1',
        'generated_at' => date('Y-m-d H:i:s'),
        'elapsed_s' => round(microtime(true) - $t0, 2),
        'connection' => $conn,
        'bronze_available' => $bronzeAvailable,
        'vendor_table_available' => $vendorTableAvailable,
        'raw_vendor_calls_found' => $rawCalls,
        'payload_fields' => array_values(array_keys($payloadFields)),
        'possible_status_fields' => array_values(array_keys($statusFieldsFound)),
        'status_field_candidates' => $statusFieldCandidates,
        'omie_latest_count' => count($latestPayloadRows),
        'omie_active' => $omieActive,
        'omie_inactive' => $omieInactive,
        'omie_status_unknown' => $omieUnknown,
        'biomie_master_count' => count($dbRows),
        'biomie_master_active' => $dbActive,
        'biomie_master_inactive' => $dbInactive,
        'missing_from_biomie_master' => $missingInDb,
        'diagnosis' => $diagnosis,
        'sync_importing_only_active_candidate' => $omieInactive > 0 && count($missingInDb) > 0,
        'status_mapping_gap' => $omieInactive > 0 && $dbInactive === 0,
        'static_evidence' => $staticEvidence,
        'sample' => $samplePayloads,
    ],
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit(0);
}

$d = $report['omie_vendor_payload_audit'];
$hr = str_repeat('=', 78);
echo PHP_EOL . $hr . PHP_EOL;
echo "  COM.R1.5.1 - Omie Vendor Payload Audit\n";
echo $hr . PHP_EOL . PHP_EOL;
echo '  Bronze vendor calls      : ' . count($d['raw_vendor_calls_found']) . PHP_EOL;
echo '  Payload fields found     : ' . count($d['payload_fields']) . PHP_EOL;
echo '  Status fields found      : ' . implode(', ', $d['possible_status_fields']) . PHP_EOL;
echo '  Omie latest roster       : ' . $d['omie_latest_count'] . ' total, ' . $d['omie_active'] . ' active, ' . $d['omie_inactive'] . ' inactive' . PHP_EOL;
echo '  BIomie vendor master     : ' . $d['biomie_master_count'] . ' total, ' . $d['biomie_master_active'] . ' active, ' . $d['biomie_master_inactive'] . ' inactive' . PHP_EOL;
echo '  Diagnosis                : ' . $d['diagnosis'] . PHP_EOL;
if ($d['status_mapping_gap']) {
    echo '  Finding                  : Omie payload has inactive vendors, but biomie_vendedores_omie has 0 inactive.' . PHP_EOL;
}
if ($d['static_evidence']['upsert_vendor_sets_ativo_true']) {
    echo '  Static evidence          : upsertVendor currently writes/keeps vendor ativo=1.' . PHP_EOL;
}

if ($d['sample']) {
    echo PHP_EOL . '  Sample payload rows' . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
    foreach (array_slice($d['sample'], 0, $sample) as $row) {
        $st = $row['status'] ?? [];
        $statusText = ($st['known'] ?? false)
            ? (($st['active'] ?? true) ? 'active' : 'inactive') . ' via ' . ($st['field'] ?? '?') . '=' . (string) ($st['value'] ?? '')
            : 'unknown';
        echo '  [' . ($row['codigo'] ?: '-') . '] ' . ($row['nome'] ?: '-') . ' | ' . $statusText . PHP_EOL;
    }
}

echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;
