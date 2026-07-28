<?php

/**
 * tools/dev/geo_holistic_audit.php
 *
 * Diagnóstico holístico do pipeline geo: "sem bairro", qualidade de endereço,
 * integridade do mart e discrepâncias entre os dois builders.
 *
 * SOMENTE LEITURA — não altera banco, não escreve arquivos, não chama APIs.
 *
 * Uso:
 *   php tools/dev/geo_holistic_audit.php --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/geo_holistic_audit.php --start=2026-05-01 --end=2026-05-31 --json
 *   php tools/dev/geo_holistic_audit.php --start=2026-05-01 --end=2026-05-31 --only-missing-bairro
 *   php tools/dev/geo_holistic_audit.php --start=2026-05-01 --end=2026-05-31 --sample=50
 *   php tools/dev/geo_holistic_audit.php --city=Caucaia --start=2026-01-01 --end=2026-05-31
 *   php tools/dev/geo_holistic_audit.php --document=12345678000100
 *   php tools/dev/geo_holistic_audit.php --help
 *
 * geo_issue_type values:
 *   NO_GEO_DIM_RECORD          — cliente não existe em biomie_clientes_geo_dim (sync gap)
 *   NO_CITY_DATA               — cidade_norm NULL/vazia no mart (classificado como Sem localização)
 *   BAIRRO_OFICIAL_NOT_IN_MART — geo_dim tem bairro_oficial_nome_fortaleza mas mart mostra vazio
 *                                 (bug: GeoBuyerMartBuilder ignora bairro_oficial_nome_fortaleza)
 *   BAIRRO_NORM_NOT_PROPAGATED — geo_dim tem bairro_norm mas mart mostra vazio (inconsistência)
 *   INSIDE_RMF_NO_BAIRRO       — cliente na RMF (CE, não Fortaleza), sem bairro (esperado: mapa cobre só Fortaleza)
 *   OUTSIDE_CE_NO_BAIRRO       — cliente fora do CE, sem bairro (esperado: fora do escopo)
 *   FORTALEZA_GEO_DIM_NO_BAIRRO — cliente em Fortaleza mas geo_dim sem bairro (dado faltando no Omie/geo_dim)
 *   FORTALEZA_NO_GEO_DIM       — cliente com cidade=Fortaleza mas sem geo_dim (sync gap específico)
 *   TRULY_MISSING              — sem endereço em nenhuma fonte
 */

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Support\Geo\GeoKeyNormalizer;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

// ── Args ──────────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'start:', 'end:', 'city:', 'document:',
    'sample:', 'only-missing-bairro', 'json', 'help',
]);

if (isset($opts['help'])) {
    echo <<<HELP
Usage: php tools/dev/geo_holistic_audit.php [options]

Options:
  --start=YYYY-MM-DD        Start date (default: first day of current month)
  --end=YYYY-MM-DD          End date   (default: last day of current month)
  --city=Fortaleza          Restrict to a specific city (mart cidade_norm)
  --document=12345678000100 Restrict to a specific client document
  --sample=N                Max records per issue type in output (default: 20)
  --only-missing-bairro     Only show sem-bairro records (suppress city ranking)
  --json                    Output raw JSON (exit 0=ok, exit 2=errors)
  --help                    Show this help

geo_issue_type classification:
  NO_GEO_DIM_RECORD          Cliente nao existe em biomie_clientes_geo_dim
  NO_CITY_DATA               cidade_norm NULL/vazia no mart (Sem localizacao)
  BAIRRO_OFICIAL_NOT_IN_MART geo_dim tem bairro_oficial mas mart esta vazio (bug builder)
  BAIRRO_NORM_NOT_PROPAGATED geo_dim tem bairro_norm mas mart esta vazio
  INSIDE_RMF_NO_BAIRRO       Cliente na RMF/CE, sem bairro (mapa so cobre Fortaleza)
  OUTSIDE_CE_NO_BAIRRO       Cliente fora do CE, sem bairro (fora do escopo)
  FORTALEZA_GEO_DIM_NO_BAIRRO Cliente em Fortaleza, geo_dim sem bairro (dado Omie ausente)
  FORTALEZA_NO_GEO_DIM       Cliente cidade=Fortaleza, sem geo_dim (sync gap)
  TRULY_MISSING              Sem endereco em nenhuma fonte conhecida

HELP;
    exit(0);
}

$firstDayMonth = date('Y-m-01');
$lastDayMonth  = date('Y-m-t');

$startDate      = trim((string) ($opts['start'] ?? $firstDayMonth));
$endDate        = trim((string) ($opts['end']   ?? $lastDayMonth));
$cityFilter     = trim((string) ($opts['city']  ?? ''));
$documentFilter = trim((string) ($opts['document'] ?? ''));
$sampleSize     = max(1, min(500, (int) ($opts['sample'] ?? 20)));
$onlyMissing    = isset($opts['only-missing-bairro']);
$jsonMode       = isset($opts['json']);

$startComp = substr($startDate, 0, 7);
$endComp   = substr($endDate, 0, 7);

// ── RMF municipalities (normalized, lowercase, no accents) ───────────────────

const RMF_CITIES = [
    'aquiraz', 'cascavel', 'caucaia', 'chorozinho', 'eusebio', 'guaiuba',
    'horizonte', 'itaitinga', 'maracanau', 'maranguape', 'pacajus', 'pacatuba',
    'pindoretama', 'sao goncalo do amarante', 'sao luis do curu', 'trairi',
    'paracuru', 'paraipaba', 'pentecoste', 'tejucoca', 'itapipoca', 'miraima',
    'general sampaio', 'itatira', 'apuiares',
];

// ── Output accumulator ───────────────────────────────────────────────────────

$report = [
    'generated_at' => date('c'),
    'period'       => [
        'start'      => $startDate,
        'end'        => $endDate,
        'start_comp' => $startComp,
        'end_comp'   => $endComp,
    ],
    'params' => [
        'city_filter'     => $cityFilter ?: null,
        'document_filter' => $documentFilter ?: null,
        'sample_size'     => $sampleSize,
        'only_missing'    => $onlyMissing,
    ],
    'connection'          => [],
    'schema_probe'        => [],
    'summary'             => [],
    'pipeline_integrity'  => [],
    'city_ranking'        => [],
    'bairro_source_audit' => [],
    'issue_types'         => [],
    'records'             => [],
    'message_locations'   => [],
    'analysis_notes'      => [],
];

// ── Connect ───────────────────────────────────────────────────────────────────

$pdo = null;
try {
    [$pdo, $connName] = Database::getGeoConnection($config);
    $report['connection'] = ['ok' => true, 'name' => $connName];
} catch (\Throwable $e) {
    $report['connection'] = ['ok' => false, 'error' => $e->getMessage()];
    outputAndExit($report, $jsonMode, 2);
}

// ── Helper functions ──────────────────────────────────────────────────────────

function safeRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [['_error' => $e->getMessage()]];
    }
}

function safeRow(PDO $pdo, string $sql, array $params = []): array
{
    $rows = safeRows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function colExists(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function normCity(string $v): string
{
    $v = mb_strtolower(trim($v), 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    return strtr($v, $map);
}

function classifyIssue(array $row): string
{
    $martCidade       = normCity((string) ($row['mart_cidade'] ?? ''));
    $geoBairroNorm    = trim((string) ($row['geo_bairro_norm'] ?? ''));
    $geoBairroOficial = trim((string) ($row['geo_bairro_oficial'] ?? ''));
    $geoCidade        = normCity((string) ($row['geo_cidade'] ?? ''));
    $geoUf            = mb_strtolower(trim((string) ($row['geo_uf'] ?? '')), 'UTF-8');
    $geoDimExists     = ((int) ($row['geo_exists'] ?? 0)) === 1;
    if (in_array(normCity($geoBairroNorm), ['nao informado', 'nao_informado', 'sem bairro', 'sem_bairro'], true)) {
        $geoBairroNorm = '';
    }

    // 1. No geo_dim record at all
    if (!$geoDimExists) {
        if ($martCidade === 'fortaleza') {
            return 'FORTALEZA_NO_GEO_DIM';
        }
        return 'NO_GEO_DIM_RECORD';
    }

    // 2. geo_dim has bairro_oficial but mart is empty → builder bug
    if ($geoBairroOficial !== '') {
        return 'BAIRRO_OFICIAL_NOT_IN_MART';
    }

    // 3. geo_dim has bairro_norm but mart is empty → propagation gap
    if ($geoBairroNorm !== '') {
        return 'BAIRRO_NORM_NOT_PROPAGATED';
    }

    // 4. No city data in mart
    if ($martCidade === '') {
        if ($geoCidade !== '' && $geoCidade !== 'fortaleza') {
            $geoUfNorm = normCity($geoUf);
            if ($geoUfNorm === 'ce') {
                return in_array($geoCidade, RMF_CITIES, true) ? 'INSIDE_RMF_NO_BAIRRO' : 'OUTSIDE_CE_NO_BAIRRO';
            }
            return 'OUTSIDE_CE_NO_BAIRRO';
        }
        return 'NO_CITY_DATA';
    }

    // 5. City is not Fortaleza
    if ($martCidade !== 'fortaleza') {
        $ufNorm = normCity((string) ($row['mart_uf'] ?? ''));
        if ($ufNorm === 'ce' || $geoUf === 'ce') {
            return in_array($martCidade, RMF_CITIES, true) ? 'INSIDE_RMF_NO_BAIRRO' : 'OUTSIDE_CE_NO_BAIRRO';
        }
        return 'OUTSIDE_CE_NO_BAIRRO';
    }

    // 6. City = Fortaleza, geo_dim exists but no bairro anywhere
    return 'FORTALEZA_GEO_DIM_NO_BAIRRO';
}

function outputAndExit(array $report, bool $jsonMode, int $code = 0): never
{
    if ($jsonMode) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        printHumanReport($report);
    }
    exit($code);
}

// ── Schema probe ──────────────────────────────────────────────────────────────

$tables = [
    'mart_compradores_por_geo', 'biomie_clientes_geo_dim', 'biomie_clientes_dim',
    'biomie_bairros_fortaleza_dim', 'biomie_notas_fiscais_canonicas',
];
$schemaProbe = [];
foreach ($tables as $t) {
    $schemaProbe[$t] = tableExists($pdo, $t);
}

$hasBairroKey    = colExists($pdo, 'mart_compradores_por_geo', 'bairro_key');
$hasCidadeKey    = colExists($pdo, 'mart_compradores_por_geo', 'cidade_key');
$hasRegiaoNome   = colExists($pdo, 'mart_compradores_por_geo', 'regiao_nome');
$hasBairroRaw    = colExists($pdo, 'biomie_clientes_geo_dim', 'bairro_raw');
$hasLogradouro   = colExists($pdo, 'biomie_clientes_geo_dim', 'logradouro');
$hasBairroOficial = colExists($pdo, 'biomie_clientes_geo_dim', 'bairro_oficial_nome_fortaleza');
$hasCdBairro     = colExists($pdo, 'biomie_clientes_dim', 'bairro');
$hasCdBairroNorm = colExists($pdo, 'biomie_clientes_dim', 'bairro_normalizado');

$report['schema_probe'] = array_merge($schemaProbe, [
    'mart_has_bairro_key'              => $hasBairroKey,
    'mart_has_cidade_key'              => $hasCidadeKey,
    'mart_has_regiao_nome'             => $hasRegiaoNome,
    'geo_dim_has_bairro_raw'           => $hasBairroRaw,
    'geo_dim_has_logradouro'           => $hasLogradouro,
    'geo_dim_has_bairro_oficial_fortaleza' => $hasBairroOficial,
    'clientes_dim_has_bairro'          => $hasCdBairro,
    'clientes_dim_has_bairro_normalizado' => $hasCdBairroNorm,
    'note_builder_fallback_active'     => ($hasCdBairro || $hasCdBairroNorm)
        ? 'SIM — GeoAggregateBuilder pode usar clientes_dim como fallback de bairro'
        : 'NAO — biomie_clientes_dim nao tem colunas de bairro; fallback nao ativo',
]);

if (!$schemaProbe['mart_compradores_por_geo']) {
    $report['summary'] = ['error' => 'mart_compradores_por_geo nao existe; execute a materializacao primeiro.'];
    outputAndExit($report, $jsonMode, 2);
}

// ── Build WHERE clauses ───────────────────────────────────────────────────────

$baseParams = ['start_comp' => $startComp, 'end_comp' => $endComp];
$baseWhere  = 'competencia BETWEEN :start_comp AND :end_comp';

$cityParams = $baseParams;
$cityWhere  = $baseWhere;
if ($cityFilter !== '') {
    $cityNorm = normCity($cityFilter);
    $cityWhere .= ' AND LOWER(TRIM(cidade_norm)) = :city_filter';
    $cityParams['city_filter'] = $cityNorm;
}

$docWhere  = $baseWhere;
$docParams = $baseParams;
if ($documentFilter !== '') {
    $docWhere .= ' AND documento = :doc_filter';
    $docParams['doc_filter'] = $documentFilter;
}

$missingSentinel = "(bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR bairro_norm = 'NAO INFORMADO')";

// ── 1. Summary counts ─────────────────────────────────────────────────────────

$summaryRow = safeRow($pdo, "
    SELECT
        COUNT(*) AS total_rows,
        COUNT(DISTINCT codigo_cliente_omie) AS total_clients,
        SUM(CASE WHEN {$missingSentinel} THEN 1 ELSE 0 END) AS rows_missing_bairro,
        COUNT(DISTINCT CASE WHEN {$missingSentinel} THEN codigo_cliente_omie END) AS clients_missing_bairro,
        COUNT(DISTINCT CASE WHEN {$missingSentinel} AND (cidade_norm IS NULL OR TRIM(cidade_norm) = '') THEN codigo_cliente_omie END) AS null_cidade_conflated,
        ROUND(SUM(faturamento), 2) AS faturamento_total,
        ROUND(SUM(CASE WHEN {$missingSentinel} THEN faturamento ELSE 0 END), 2) AS faturamento_missing_bairro
    FROM mart_compradores_por_geo
    WHERE {$cityWhere}
", $cityParams);

$totalMissing = (int) ($summaryRow['clients_missing_bairro'] ?? 0);
$totalClients = (int) ($summaryRow['total_clients'] ?? 0);
$pctMissing   = $totalClients > 0 ? round($totalMissing / $totalClients * 100, 1) : 0;

$report['summary'] = [
    'total_rows_in_mart'         => (int) ($summaryRow['total_rows'] ?? 0),
    'total_clients_in_mart'      => $totalClients,
    'total_missing_bairro_mart'  => $totalMissing,
    'pct_missing_bairro'         => "{$pctMissing}%",
    'unlocated_customers'        => (int) ($summaryRow['null_cidade_conflated'] ?? 0),
    'null_cidade_conflated'      => 0,
    'faturamento_total'          => (float) ($summaryRow['faturamento_total'] ?? 0),
    'faturamento_missing_bairro' => (float) ($summaryRow['faturamento_missing_bairro'] ?? 0),
    'note_null_cidade'           => 'clientes sem cidade ficam isolados em Sem localização e não entram em Fortaleza',
];

// ── 2. City ranking (all) ─────────────────────────────────────────────────────

if (!$onlyMissing) {
    $cityRankingRows = safeRows($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(cidade_norm), ''), 'SEM_CIDADE') AS cidade,
            COALESCE(NULLIF(TRIM(uf), ''), '??') AS uf,
            COUNT(DISTINCT codigo_cliente_omie) AS total_clientes,
            COUNT(DISTINCT CASE WHEN {$missingSentinel} THEN codigo_cliente_omie END) AS clientes_sem_bairro,
            COUNT(DISTINCT CASE WHEN NOT ({$missingSentinel}) THEN codigo_cliente_omie END) AS clientes_com_bairro,
            ROUND(SUM(faturamento), 2) AS faturamento_total,
            ROUND(SUM(CASE WHEN {$missingSentinel} THEN faturamento ELSE 0 END), 2) AS faturamento_sem_bairro
        FROM mart_compradores_por_geo
        WHERE {$cityWhere}
        GROUP BY cidade, uf
        ORDER BY total_clientes DESC
        LIMIT 40
    ", $cityParams);
    $report['city_ranking'] = $cityRankingRows;
}

// City ranking restricted to sem-bairro clients
$cityMissingRows = safeRows($pdo, "
    SELECT
        COALESCE(NULLIF(TRIM(cidade_norm), ''), 'SEM_CIDADE') AS cidade,
        COALESCE(NULLIF(TRIM(uf), ''), '??') AS uf,
        COUNT(DISTINCT codigo_cliente_omie) AS clientes_sem_bairro,
        ROUND(SUM(faturamento), 2) AS faturamento_sem_bairro
    FROM mart_compradores_por_geo
    WHERE {$cityWhere}
      AND {$missingSentinel}
    GROUP BY cidade, uf
    ORDER BY clientes_sem_bairro DESC
    LIMIT 30
", $cityParams);
$report['city_ranking_missing_bairro'] = $cityMissingRows;

// ── 3. Bairro source audit in biomie_clientes_geo_dim ────────────────────────

if ($schemaProbe['biomie_clientes_geo_dim']) {
    $bairroAudit = safeRow($pdo, "
        SELECT
            COUNT(*) AS total_records,
            COUNT(CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' THEN 1 END) AS has_bairro_norm,
            COUNT(CASE WHEN bairro_raw IS NOT NULL AND TRIM(bairro_raw) <> '' THEN 1 END) AS has_bairro_raw,
            COUNT(CASE WHEN bairro_oficial_nome_fortaleza IS NOT NULL AND TRIM(bairro_oficial_nome_fortaleza) <> '' THEN 1 END) AS has_bairro_oficial,
            COUNT(CASE WHEN (bairro_norm IS NULL OR TRIM(bairro_norm) = '') AND bairro_oficial_nome_fortaleza IS NOT NULL AND TRIM(bairro_oficial_nome_fortaleza) <> '' THEN 1 END) AS has_oficial_but_no_norm,
            COUNT(CASE WHEN (bairro_norm IS NULL OR TRIM(bairro_norm) = '') AND (bairro_oficial_nome_fortaleza IS NULL OR TRIM(bairro_oficial_nome_fortaleza) = '') THEN 1 END) AS has_neither_bairro,
            COUNT(CASE WHEN cidade_norm IS NULL OR TRIM(cidade_norm) = '' THEN 1 END) AS no_cidade_norm,
            COUNT(CASE WHEN LOWER(TRIM(cidade_norm)) = 'fortaleza' THEN 1 END) AS is_fortaleza,
            COUNT(CASE WHEN uf IS NOT NULL AND TRIM(uf) <> '' AND LOWER(TRIM(uf)) = 'ce' AND LOWER(TRIM(cidade_norm)) <> 'fortaleza' THEN 1 END) AS is_ce_not_fortaleza,
            COUNT(CASE WHEN uf IS NOT NULL AND LOWER(TRIM(uf)) <> 'ce' AND TRIM(uf) <> '' THEN 1 END) AS is_other_uf
        FROM biomie_clientes_geo_dim
    ");

    $report['bairro_source_audit'] = array_merge($bairroAudit, [
        'note_has_oficial_but_no_norm' =>
            'has_oficial_but_no_norm: clientes com match Fortaleza em bairro_oficial_nome_fortaleza ' .
            'mas bairro_norm vazio. GeoBuyerMartBuilder ignora bairro_oficial → esses clientes aparecem ' .
            'como sem bairro no mart quando GeoBuyerMartBuilder roda por ultimo.',
    ]);
}

// ── 4. Pipeline integrity ─────────────────────────────────────────────────────

$integrity = [];

// 4a. NF clients with no geo_dim record
if ($schemaProbe['biomie_notas_fiscais_canonicas'] && $schemaProbe['biomie_clientes_geo_dim']) {
    $r = safeRow($pdo, "
        SELECT COUNT(DISTINCT nf.codigo_cliente_omie) AS nf_clients_no_geo_dim
        FROM biomie_notas_fiscais_canonicas nf
        LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE nf.status_fiscal = 'autorizada'
          AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
          AND g.codigo_cliente_omie IS NULL
    ", ['start' => $startDate, 'end' => $endDate]);
    $integrity['nf_clients_with_no_geo_dim_record'] = (int) ($r['nf_clients_no_geo_dim'] ?? -1);
}

// 4b. Clients in mart sem bairro that have bairro_oficial in geo_dim (builder bug)
if ($schemaProbe['biomie_clientes_geo_dim'] && $hasBairroOficial) {
    $r = safeRow($pdo, "
        SELECT COUNT(DISTINCT m.codigo_cliente_omie) AS count
        FROM mart_compradores_por_geo m
        JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = m.codigo_cliente_omie
        WHERE m.competencia BETWEEN :start_comp AND :end_comp
          AND (m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '')
          AND g.bairro_oficial_nome_fortaleza IS NOT NULL AND TRIM(g.bairro_oficial_nome_fortaleza) <> ''
    ", $baseParams);
    $integrity['sem_bairro_in_mart_but_has_bairro_oficial_in_geo_dim'] = (int) ($r['count'] ?? -1);
    $integrity['note_builder_bug'] = 'Clientes acima perderam o match porque GeoBuyerMartBuilder usa ' .
        'COALESCE(geo.bairro_norm, \'\') e ignora bairro_oficial_nome_fortaleza. ' .
        'GeoAggregateBuilder usa COALESCE(bairro_oficial_nome_fortaleza, bairro_norm, ...). ' .
        'O ultimo builder a rodar sobrescreve o resultado do outro.';
}

// 4c. Clients in mart sem bairro that have bairro_norm in geo_dim (propagation gap)
if ($schemaProbe['biomie_clientes_geo_dim']) {
    $r = safeRow($pdo, "
        SELECT COUNT(DISTINCT m.codigo_cliente_omie) AS count
        FROM mart_compradores_por_geo m
        JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = m.codigo_cliente_omie
        WHERE m.competencia BETWEEN :start_comp AND :end_comp
          AND (m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '')
          AND g.bairro_norm IS NOT NULL AND TRIM(g.bairro_norm) <> ''
    ", $baseParams);
    $integrity['sem_bairro_in_mart_but_has_bairro_norm_in_geo_dim'] = (int) ($r['count'] ?? -1);
}

// 4d. Mart clients with NULL cidade_norm (conflation issue)
$r = safeRow($pdo, "
    SELECT COUNT(DISTINCT codigo_cliente_omie) AS count
    FROM mart_compradores_por_geo
    WHERE competencia BETWEEN :start_comp AND :end_comp
      AND (cidade_norm IS NULL OR TRIM(cidade_norm) = '')
", $baseParams);
$integrity['mart_clients_with_null_cidade_norm'] = (int) ($r['count'] ?? -1);
$integrity['note_null_cidade'] = 'Estes clientes ficam isolados em Sem localização. '
    . 'Os filtros de cidades nomeadas exigem correspondência exata e não incluem cidade_norm nula.';

$report['pipeline_integrity'] = $integrity;

// ── 5. Per-client multi-source lookup ────────────────────────────────────────

$selectExtra = '';
if ($hasBairroRaw) {
    $selectExtra .= ', MAX(g.bairro_raw) AS geo_bairro_raw';
}
if ($hasLogradouro) {
    $selectExtra .= ', MAX(g.logradouro) AS geo_logradouro, MAX(g.numero) AS geo_numero, MAX(g.cep) AS geo_cep';
}
$bairroOficialSelect = $hasBairroOficial
    ? ', MAX(g.bairro_oficial_nome_fortaleza) AS geo_bairro_oficial'
    : ', NULL AS geo_bairro_oficial';

// Apply additional doc/city filters for record-level query
$recordWhere  = $cityWhere;
$recordParams = $cityParams;
$recordParams['sample'] = $sampleSize;
$recordMissingSentinel = str_replace('bairro_norm', 'm.bairro_norm', $missingSentinel);
if ($documentFilter !== '') {
    $recordWhere .= ' AND m.documento = :doc_filter';
    $recordParams['doc_filter'] = $documentFilter;
}

$rawRecords = safeRows($pdo, "
    SELECT
        m.codigo_cliente_omie,
        MAX(m.cliente_nome)  AS cliente_nome,
        MAX(m.documento)     AS documento,
        MAX(m.cidade_norm)   AS mart_cidade,
        MAX(m.bairro_norm)   AS mart_bairro,
        MAX(m.uf)            AS mart_uf,
        ROUND(SUM(m.faturamento), 2) AS faturamento_total,
        MAX(m.ultima_compra) AS ultima_compra,
        CASE WHEN MAX(g.codigo_cliente_omie) IS NOT NULL THEN 1 ELSE 0 END AS geo_exists,
        MAX(g.cidade_norm)   AS geo_cidade,
        MAX(g.bairro_norm)   AS geo_bairro_norm,
        MAX(g.uf)            AS geo_uf
        {$bairroOficialSelect}
        {$selectExtra}
    FROM mart_compradores_por_geo m
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = m.codigo_cliente_omie
    WHERE {$recordWhere}
      AND {$recordMissingSentinel}
    GROUP BY m.codigo_cliente_omie
    ORDER BY faturamento_total DESC
    LIMIT :sample
", $recordParams);

// Classify and build records output
$issueTypeCounts = [];
$records         = [];

foreach ($rawRecords as $row) {
    if (isset($row['_error'])) {
        $report['records_query_error'] = (string) $row['_error'];
        continue;
    }
    $issueType = classifyIssue($row);
    $issueTypeCounts[$issueType] = ($issueTypeCounts[$issueType] ?? 0) + 1;
    $records[] = [
        'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
        'cliente_nome'        => $row['cliente_nome'] ?? null,
        'documento'           => $row['documento'] ?? null,
        'mart_cidade'         => $row['mart_cidade'] ?? null,
        'mart_bairro'         => $row['mart_bairro'] ?? null,
        'mart_uf'             => $row['mart_uf'] ?? null,
        'faturamento_total'   => $row['faturamento_total'] ?? null,
        'ultima_compra'       => $row['ultima_compra'] ?? null,
        'geo_dim_exists'      => (bool) ($row['geo_exists'] ?? false),
        'geo_dim_cidade'      => $row['geo_cidade'] ?? null,
        'geo_dim_bairro_norm' => $row['geo_bairro_norm'] ?? null,
        'geo_dim_bairro_oficial' => $row['geo_bairro_oficial'] ?? null,
        'geo_dim_bairro_raw'  => $row['geo_bairro_raw'] ?? null,
        'geo_dim_uf'          => $row['geo_uf'] ?? null,
        'geo_dim_logradouro'  => $row['geo_logradouro'] ?? null,
        'geo_dim_cep'         => $row['geo_cep'] ?? null,
        'geo_issue_type'      => $issueType,
    ];
}

arsort($issueTypeCounts);
$report['summary']['by_issue_type'] = $issueTypeCounts;

// Derived aggregate counts for quick triage.
$report['summary']['builder_propagation_bug_count'] = ($issueTypeCounts['BAIRRO_OFICIAL_NOT_IN_MART'] ?? 0)
    + ($issueTypeCounts['BAIRRO_NORM_NOT_PROPAGATED'] ?? 0);

$report['summary']['outside_current_mesh_count'] = ($issueTypeCounts['INSIDE_RMF_NO_BAIRRO'] ?? 0)
    + ($issueTypeCounts['OUTSIDE_CE_NO_BAIRRO'] ?? 0);

$report['summary']['local_cache_gap_count'] = ($issueTypeCounts['NO_GEO_DIM_RECORD'] ?? 0)
    + ($issueTypeCounts['FORTALEZA_NO_GEO_DIM'] ?? 0);

$report['summary']['probably_omie_missing_count'] = ($issueTypeCounts['FORTALEZA_GEO_DIM_NO_BAIRRO'] ?? 0)
    + ($issueTypeCounts['TRULY_MISSING'] ?? 0);

$report['summary']['triage_note'] = [
    'builder_propagation_bug_count' => 'Fix: rematerialize — now resolved (GeoBuyerMartBuilder patch 2026-05-18)',
    'outside_current_mesh_count'    => 'Expected: mapa atual cobre apenas Fortaleza. Não é bug.',
    'local_cache_gap_count'         => 'Fix: enriquecer clientes pendentes (biomie_clientes_para_enriquecer)',
    'probably_omie_missing_count'   => 'Fix: atualizar endereço do cliente no Omie (dado genuinamente ausente)',
];

$report['records'] = $records;

// ── 6. Issue type summary with samples ───────────────────────────────────────

$issueTypeSummary = [];
foreach (array_keys($issueTypeCounts) as $type) {
    $sample = array_values(array_filter($records, fn($r) => $r['geo_issue_type'] === $type));
    $issueTypeSummary[$type] = [
        'count'       => $issueTypeCounts[$type],
        'description' => issueTypeDescription($type),
        'sample'      => array_slice($sample, 0, min(5, count($sample))),
    ];
}
$report['issue_types'] = $issueTypeSummary;

// ── 7. Message locations (hardcoded strings in codebase) ─────────────────────

$report['message_locations'] = [
    [
        'file'     => 'api/geo_summary.php',
        'line'     => '~374',
        'current'  => "'reason' => 'Clientes sem bairro informado na fonte Omie'",
        'problem'  => 'Assume que todos os sem-bairro são falta de dado no Omie. ' .
                      'Na realidade incluem: clientes de outras cidades, conflação IS NULL, bug do builder.',
        'proposed' => "'reason' => 'Endereço fora da malha de Fortaleza ou bairro não sincronizado'",
    ],
    [
        'file'     => 'api/geo_summary.php',
        'line'     => '~382',
        'current'  => "'label' => 'Sem bairro'",
        'problem'  => 'Rótulo genérico para todos os casos.',
        'proposed' => "'label' => 'Bairro não identificado'",
    ],
    [
        'file'     => 'api/top_buyers_by_geo.php',
        'line'     => '~54',
        'current'  => "bairro_key=__sem_bairro__: 'Omie data quality gap'",
        'problem'  => 'Atribui todos os casos à qualidade do dado Omie.',
        'proposed' => 'Distinguir: fora_malha_fortaleza | sem_cidade_cadastrada | sem_bairro_omie',
    ],
    [
        'file'     => 'tools/dev/geo_sales_debug.php',
        'line'     => '~774',
        'current'  => '"qualidade de cadastro Omie"',
        'problem'  => 'Diagnóstico incorreto baked no código de diagnóstico.',
        'proposed' => 'Usar geo_issue_type do geo_holistic_audit para classificar.',
    ],
];

// ── 8. Analysis notes ─────────────────────────────────────────────────────────

$report['analysis_notes'] = [
    'two_builders_conflict' => [
        'title'   => 'DOIS BUILDERS ESCREVEM NO MESMO MART — CONFLITO POTENCIAL',
        'details' => [
            'GeoBuyerMartBuilder (GoldRefreshCoordinator)' =>
                'Lê de biomie_notas_fiscais_canonicas. ' .
                'Bairro = COALESCE(geo.bairro_norm, \'\') — IGNORA bairro_oficial_nome_fortaleza.',
            'GeoAggregateBuilder (GeoCnaeMaterializationRunner)' =>
                'Lê de mart_cliente_periodo. ' .
                'Bairro = COALESCE(bairro_oficial_nome_fortaleza, bairro_norm, [clientes_dim fallbacks], \'\').',
            'impacto' =>
                'O builder que roda por ÚLTIMO sobrescreve o resultado do outro (ambos DELETE+INSERT). ' .
                'Se GeoBuyerMartBuilder roda depois, clientes com bairro_oficial_nome_fortaleza ' .
                'perdem o bairro correto e aparecem como "sem bairro".',
        ],
    ],
    'geo_mesh_proposal' => [
        'title'  => 'Tarefa 5: Proposta de malha geográfica em 4 camadas',
        'layers' => [
            '1_bairros_fortaleza' => [
                'scope'  => 'geo_scope = bairro_fortaleza',
                'table'  => 'biomie_bairros_fortaleza_dim (121 bairros ativos — já existe)',
                'match'  => 'bairro_oficial_nome_fortaleza em biomie_clientes_geo_dim',
                'action' => 'Manter. Corrigir GeoBuyerMartBuilder para usar bairro_oficial_nome_fortaleza.',
            ],
            '2_municipios_rmf' => [
                'scope'  => 'geo_scope = municipio_rmf',
                'table'  => 'biomie_municipios_rmf_dim (NOVA — ~25 municípios da RMF)',
                'match'  => 'cidade_norm IN (caucaia, maracanau, eusebio, ...)',
                'action' => 'Criar dim. Adicionar camada "RMF" no mapa com choropleth por município.',
            ],
            '3_municipios_ce' => [
                'scope'  => 'geo_scope = municipio_ce',
                'table'  => 'biomie_municipios_ce_dim (NOVA — 184 municípios CE com IBGE)',
                'match'  => 'uf = CE e cidade_norm',
                'action' => 'Criar dim. Usar IBGE shapefile. Camada opcional no mapa.',
            ],
            '4_uf_brasil' => [
                'scope'  => 'geo_scope = uf_brasil',
                'table'  => 'Coluna uf já existe no mart',
                'match'  => 'uf field',
                'action' => 'Já disponível. Adicionar visualização por UF no painel.',
            ],
        ],
    ],
    'new_fields_proposal' => [
        'title'  => 'Tarefa 6: Proposta de novos campos (NÃO aplicar migração ainda)',
        'fields' => [
            'biomie_clientes_geo_dim.geo_scope' => [
                'type'    => "ENUM('bairro_fortaleza','municipio_rmf','municipio_ce','uf_brasil','fora_brasil','sem_dados')",
                'purpose' => 'Determina em qual camada do mapa o cliente aparece.',
                'how'     => 'Derivado de: se bairro_oficial_nome_fortaleza → bairro_fortaleza; ' .
                             'se cidade RMF → municipio_rmf; se uf=CE → municipio_ce; etc.',
            ],
            'biomie_clientes_geo_dim.geo_quality' => [
                'type'    => "ENUM('alto','medio','baixo','sem_dados')",
                'purpose' => 'Qualidade do dado de endereço. Orienta mensagem ao usuário.',
                'how'     => 'alto = match_oficial Fortaleza; medio = bairro_norm sem match_oficial; ' .
                             'baixo = apenas cidade; sem_dados = sem endereço.',
            ],
            'biomie_clientes_geo_dim.address_source' => [
                'type'    => "VARCHAR(40)",
                'purpose' => 'Rastreabilidade de onde veio o endereço.',
                'values'  => "omie_sync | geocoding_cep | geocoding_address | manual | inferido",
            ],
            'mart_compradores_por_geo.geo_scope' => [
                'type'    => "VARCHAR(30)",
                'purpose' => 'Cópia desnormalizada do geo_scope do cliente para consultas no mart.',
                'how'     => 'Preenchido na materialização a partir de biomie_clientes_geo_dim.geo_scope.',
            ],
            'biomie_bairros_fortaleza_dim.regiao_comercial_key' => [
                'type'    => "VARCHAR(40)",
                'purpose' => "Chave da região comercial (ex: 'centro','praias','sul','leste'). " .
                             'Permite agregações por zona sem depender de tabela de regiões separada.',
                'how'     => 'Preenchido manualmente ou via mapeamento bairro→região.',
            ],
        ],
    ],
    'acceptance_criteria' => [
        'title'  => 'Tarefa 9: Critérios de aceite do diagnóstico geo',
        'criteria' => [
            'AC1' => 'geo_holistic_audit.php --json roda sem erros e produz JSON válido com todas as seções.',
            'AC2' => 'summary.total_missing_bairro_mart está populado e é >= 0.',
            'AC3' => 'summary.by_issue_type contém ao menos um tipo classificado.',
            'AC4' => 'pipeline_integrity.sem_bairro_in_mart_but_has_bairro_oficial_in_geo_dim revela se bug GeoBuyerMartBuilder está ativo.',
            'AC5' => 'bairro_source_audit.has_oficial_but_no_norm revela quantos clientes têm bairro_oficial mas não bairro_norm.',
            'AC6' => 'city_ranking_missing_bairro lista cidades com mais clientes sem bairro em ordem decrescente.',
            'AC7' => 'records contém campos: geo_dim_exists, geo_dim_bairro_norm, geo_dim_bairro_oficial, geo_issue_type.',
            'AC8' => 'message_locations lista todos os locais do código com mensagens "sem bairro" incorretas.',
            'AC9' => 'analysis_notes.two_builders_conflict descreve o conflito entre GeoBuyerMartBuilder e GeoAggregateBuilder.',
            'AC10' => 'Ferramenta não escreve nada no banco (zero INSERT/UPDATE/DELETE).',
        ],
    ],
];

// ── Output ────────────────────────────────────────────────────────────────────

outputAndExit($report, $jsonMode, 0);

// ── Human-readable printer ────────────────────────────────────────────────────

function printHumanReport(array $r): void
{
    $line = str_repeat('─', 72);
    $c    = function (string $k, array $a): string { return (string) ($a[$k] ?? '?'); };

    echo "\n{$line}\n";
    echo " GEO HOLISTIC AUDIT — " . $c('start', $r['period']) . " → " . $c('end', $r['period']) . "\n";
    echo " Gerado em: " . ($r['generated_at'] ?? '') . "\n";
    echo "{$line}\n\n";

    // Connection
    $conn = $r['connection'];
    echo "CONEXÃO: " . ($conn['ok'] ? "OK ({$conn['name']})" : "ERRO: {$conn['error']}") . "\n\n";

    // Summary
    $s = $r['summary'];
    echo "RESUMO\n";
    echo "  Clientes no mart (período):  " . ($s['total_clients_in_mart'] ?? '?') . "\n";
    echo "  Clientes sem bairro:         " . ($s['total_missing_bairro_mart'] ?? '?') . " ({$s['pct_missing_bairro']})\n";
    echo "  Clientes sem localização:     " . ($s['unlocated_customers'] ?? '?') . "\n";
    echo "  Faturamento sem bairro:      R$ " . number_format((float)($s['faturamento_missing_bairro'] ?? 0), 2, ',', '.') . "\n\n";

    // Issue type breakdown
    if (!empty($s['by_issue_type'])) {
        echo "CLASSIFICAÇÃO POR TIPO DE PROBLEMA\n";
        foreach ($s['by_issue_type'] as $type => $count) {
            echo "  " . str_pad($type, 40) . " {$count}\n";
        }
        echo "\n";
    }

    // Schema probe warnings
    $schema = $r['schema_probe'] ?? [];
    if (!($schema['biomie_clientes_geo_dim'] ?? false)) {
        echo "AVISO: biomie_clientes_geo_dim nao existe!\n";
    }
    if (!($schema['geo_dim_has_bairro_oficial_fortaleza'] ?? false)) {
        echo "AVISO: geo_dim nao tem coluna bairro_oficial_nome_fortaleza.\n";
    }

    // Pipeline integrity
    $pi = $r['pipeline_integrity'] ?? [];
    if (!empty($pi)) {
        echo "INTEGRIDADE DO PIPELINE\n";
        $keys = [
            'nf_clients_with_no_geo_dim_record'            => 'NFs sem registro em geo_dim',
            'sem_bairro_in_mart_but_has_bairro_oficial_in_geo_dim' => 'Bug builder (bairro_oficial perdido)',
            'sem_bairro_in_mart_but_has_bairro_norm_in_geo_dim'    => 'bairro_norm nao propagado',
            'mart_clients_with_null_cidade_norm'           => 'Clientes com cidade_norm nula no mart',
        ];
        foreach ($keys as $k => $label) {
            if (isset($pi[$k])) {
                echo "  " . str_pad($label, 48) . " {$pi[$k]}\n";
            }
        }
        echo "\n";
    }

    // Bairro source audit
    $ba = $r['bairro_source_audit'] ?? [];
    if (!empty($ba)) {
        echo "AUDITORIA DE FONTES DE BAIRRO (biomie_clientes_geo_dim)\n";
        echo "  Total registros:           " . ($ba['total_records'] ?? '?') . "\n";
        echo "  Tem bairro_norm:           " . ($ba['has_bairro_norm'] ?? '?') . "\n";
        echo "  Tem bairro_raw:            " . ($ba['has_bairro_raw'] ?? '?') . "\n";
        echo "  Tem bairro_oficial:        " . ($ba['has_bairro_oficial'] ?? '?') . "\n";
        echo "  Tem oficial mas sem norm:  " . ($ba['has_oficial_but_no_norm'] ?? '?') . " ← candidatos ao bug builder\n";
        echo "  Sem bairro em nenhuma fonte: " . ($ba['has_neither_bairro'] ?? '?') . "\n";
        echo "  Clientes Fortaleza:        " . ($ba['is_fortaleza'] ?? '?') . "\n";
        echo "  Clientes CE (não Fortaleza):" . ($ba['is_ce_not_fortaleza'] ?? '?') . "\n";
        echo "  Clientes outra UF:         " . ($ba['is_other_uf'] ?? '?') . "\n\n";
    }

    // City ranking - missing bairro
    if (!empty($r['city_ranking_missing_bairro'])) {
        echo "CIDADES COM MAIS CLIENTES SEM BAIRRO (top 15)\n";
        echo "  " . str_pad('Cidade', 28) . str_pad('UF', 5) . str_pad('Clientes', 10) . "Faturamento\n";
        foreach (array_slice($r['city_ranking_missing_bairro'], 0, 15) as $row) {
            echo "  " .
                str_pad((string)($row['cidade'] ?? '?'), 28) .
                str_pad((string)($row['uf'] ?? '?'), 5) .
                str_pad((string)($row['clientes_sem_bairro'] ?? '?'), 10) .
                "R$ " . number_format((float)($row['faturamento_sem_bairro'] ?? 0), 2, ',', '.') . "\n";
        }
        echo "\n";
    }

    // Records sample
    if (!empty($r['records'])) {
        echo "AMOSTRA DE REGISTROS SEM BAIRRO (top " . count($r['records']) . " por faturamento)\n";
        echo str_repeat('-', 72) . "\n";
        foreach (array_slice($r['records'], 0, 10) as $rec) {
            echo "  {$rec['cliente_nome']}\n";
            echo "    Doc: {$rec['documento']}  |  Cidade mart: " . ($rec['mart_cidade'] ?: 'NULL') . "  |  UF: " . ($rec['mart_uf'] ?: 'NULL') . "\n";
            echo "    geo_dim: " . ($rec['geo_dim_exists'] ? 'SIM' : 'NAO') . "";
            if ($rec['geo_dim_exists']) {
                echo "  |  geo_bairro_norm: " . ($rec['geo_dim_bairro_norm'] ?: 'VAZIO');
                echo "  |  geo_bairro_oficial: " . ($rec['geo_dim_bairro_oficial'] ?: 'VAZIO');
            }
            echo "\n    TIPO: \033[1;33m{$rec['geo_issue_type']}\033[0m";
            echo "  |  Fat: R$ " . number_format((float)$rec['faturamento_total'], 2, ',', '.') . "\n";
            echo "\n";
        }
    }

    // Two builders warning
    $conflict = $r['analysis_notes']['two_builders_conflict'] ?? null;
    if ($conflict) {
        echo "\033[1;31mATENÇÃO — CONFLITO ENTRE BUILDERS\033[0m\n";
        echo $conflict['title'] . "\n";
        foreach ($conflict['details'] as $k => $v) {
            echo "  [{$k}] {$v}\n";
        }
        echo "\n";
    }

    echo "{$line}\n";
    echo " Execute com --json para saída completa (inclui propostas de campos e malha geo).\n";
    echo "{$line}\n\n";
}

function issueTypeDescription(string $type): string
{
    return match ($type) {
        'NO_GEO_DIM_RECORD'          => 'Cliente sem registro em biomie_clientes_geo_dim (sync gap)',
        'NO_CITY_DATA'               => 'cidade_norm NULL/vazia no mart — classificado em Sem localização',
        'BAIRRO_OFICIAL_NOT_IN_MART' => 'geo_dim tem bairro_oficial_nome_fortaleza mas mart está vazio (bug GeoBuyerMartBuilder)',
        'BAIRRO_NORM_NOT_PROPAGATED' => 'geo_dim tem bairro_norm mas mart está vazio (inconsistência de propagação)',
        'INSIDE_RMF_NO_BAIRRO'       => 'Cliente na RMF/CE (não Fortaleza) — mapa cobre só bairros de Fortaleza',
        'OUTSIDE_CE_NO_BAIRRO'       => 'Cliente fora do CE — fora do escopo do mapa',
        'FORTALEZA_GEO_DIM_NO_BAIRRO' => 'Cliente em Fortaleza, geo_dim existe mas sem bairro em nenhum campo',
        'FORTALEZA_NO_GEO_DIM'       => 'Cliente com cidade=Fortaleza mas sem registro em biomie_clientes_geo_dim',
        'TRULY_MISSING'              => 'Sem endereço em nenhuma fonte — dado ausente no Omie',
        default                      => $type,
    };
}
