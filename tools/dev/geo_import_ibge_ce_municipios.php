<?php

declare(strict_types=1);

/**
 * tools/dev/geo_import_ibge_ce_municipios.php — GEO.R1.1 Fase 1
 *
 * Importa/atualiza a malha municipal oficial do Ceará via API pública do IBGE.
 * Gera ceara_mun.json (184 municípios) e rmf_municipios.json (19 municípios RMF).
 *
 * Fontes:
 *   Geometria : servicodados.ibge.gov.br/api/v2/malhas/23?formato=geo+json&resolucao=5
 *   Nomes     : servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios
 *
 * Regras:
 *   - Não altera Fortaleza bairros.
 *   - Não altera dados comerciais, NFs, vendedores ou materialização.
 *   - Idempotente: pode ser rodado múltiplas vezes com segurança.
 *   - Backup automático do arquivo anterior antes de sobrescrever.
 *   - Só escreve se 184 municípios forem encontrados e enriquecidos.
 *
 * Uso:
 *   php tools/dev/geo_import_ibge_ce_municipios.php [--pretty] [--json] [--dry-run]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts   = getopt('', ['pretty', 'json', 'dry-run']);
$pretty = array_key_exists('pretty', $opts);
$json   = array_key_exists('json',   $opts);
$dry    = array_key_exists('dry-run',$opts);

$root        = dirname(__DIR__, 2);
$geoDir      = $root . '/assets/geo';
$cearaOut    = $geoDir . '/ceara_mun.json';
$rmfOut      = $geoDir . '/rmf_municipios.json';

// RMF IBGE codes — corretos conforme API IBGE (verificado em 2026-05-30)
$rmfCodes = [
    '2304400','2303709','2307650','2307700','2304285','2301000',
    '2305233','2309607','2309706','2306256','2304954','2303956',
    '2303501','2310852','2310209','2310258','2312403','2312601','2313500',
];

$t0 = microtime(true);
$steps = [];
$issues = [];
$ok = true;

// ── Helpers ───────────────────────────────────────────────────────────────────

function ibge_http_get(string $url): string|false
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: BIomie-GeoImport/1.0\r\n",
            'timeout' => 60,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return false;
    // Handle gzip
    if (str_starts_with($raw, "\x1f\x8b")) {
        $raw = gzdecode($raw);
    }
    return $raw ?: false;
}

function ibge_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
}

// ── Step 1: Download municipio names ─────────────────────────────────────────

$step = ['label' => 'Download nomes via IBGE LocalidadesAPI', 'pass' => false, 'detail' => ''];
$raw = ibge_http_get('https://servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios');
if ($raw === false) {
    $step['detail'] = 'Falha ao conectar com servicodados.ibge.gov.br — verifique acesso à internet.';
    $issues[] = $step['detail'];
    $ok = false;
} else {
    $municipios = json_decode($raw, true);
    if (!is_array($municipios) || count($municipios) !== 184) {
        $step['detail'] = 'LocalidadesAPI retornou ' . count((array)$municipios) . ' municípios (esperado 184).';
        $issues[] = $step['detail'];
        $ok = false;
    } else {
        $step['pass']   = true;
        $step['detail'] = '184 municípios com nome obtidos.';
    }
}
$steps[] = $step;

if (!$ok) goto output;

// Build name lookup
$munLookup = [];
foreach ($municipios as $m) {
    $munLookup[(string) $m['id']] = ['nome' => $m['nome'], 'nome_norm' => ibge_norm($m['nome'])];
}

// ── Step 2: Download geometries ───────────────────────────────────────────────

$step = ['label' => 'Download geometrias via IBGE MalhasAPI (resolucao=5)', 'pass' => false, 'detail' => ''];
$raw = ibge_http_get('https://servicodados.ibge.gov.br/api/v2/malhas/23?formato=application/vnd.geo+json&resolucao=5');
if ($raw === false) {
    $step['detail'] = 'Falha ao baixar malha municipal do IBGE.';
    $issues[] = $step['detail'];
    $ok = false;
} else {
    $geo = json_decode($raw, true);
    $featureCount = count($geo['features'] ?? []);
    if (!is_array($geo) || !isset($geo['features']) || $featureCount !== 184) {
        $step['detail'] = "MalhasAPI retornou {$featureCount} features (esperado 184).";
        $issues[] = $step['detail'];
        $ok = false;
    } else {
        $step['pass']   = true;
        $step['detail'] = "184 features GeoJSON com geometria oficial IBGE.";
    }
}
$steps[] = $step;

if (!$ok) goto output;

// ── Step 3: Enrich features ───────────────────────────────────────────────────

$step = ['label' => 'Enriquecimento de features com nomes e normalização', 'pass' => false, 'detail' => ''];
$enriched = 0;
$missing  = [];
foreach ($geo['features'] as &$feat) {
    $code = (string) ($feat['properties']['codarea'] ?? '');
    $info = $munLookup[$code] ?? null;
    if ($info) {
        $feat['properties']['municipio_nome'] = $info['nome'];
        $feat['properties']['municipio_ibge'] = $code;
        $feat['properties']['municipio_norm'] = $info['nome_norm'];
        $feat['properties']['uf_sigla']       = 'CE';
        $enriched++;
    } else {
        $missing[] = $code;
        $feat['properties']['municipio_nome'] = 'Desconhecido';
        $feat['properties']['municipio_ibge'] = $code;
        $feat['properties']['municipio_norm'] = 'desconhecido';
        $feat['properties']['uf_sigla']       = 'CE';
    }
}
unset($feat);

$step['pass']   = $enriched === 184;
$step['detail'] = "{$enriched}/184 features enriquecidas." . (count($missing) ? ' Missing: ' . implode(',', $missing) : '');
if (!$step['pass']) $issues[] = $step['detail'];
$steps[] = $step;

// ── Step 4: Validate geometries ───────────────────────────────────────────────

$step = ['label' => 'Validação de geometrias (tipo e coordenadas)', 'pass' => false, 'detail' => ''];
$noGeom = 0;
$types  = [];
foreach ($geo['features'] as $feat) {
    $gt = $feat['geometry']['type'] ?? 'none';
    $types[$gt] = ($types[$gt] ?? 0) + 1;
    if (!isset($feat['geometry']['coordinates'])) $noGeom++;
}
$step['pass']   = $noGeom === 0;
$step['detail'] = "Tipos: " . json_encode($types) . " | Sem coordenadas: {$noGeom}";
if ($noGeom > 0) $issues[] = "Geometrias sem coordenadas: {$noGeom}";
$steps[] = $step;

// ── Step 5: Add metadata and save ceara_mun.json ──────────────────────────────

$step = ['label' => 'Salvando ceara_mun.json (184 municípios)', 'pass' => false, 'detail' => ''];
$geo['metadata'] = [
    'source'            => 'IBGE_Malhas_v2_resolucao5',
    'api_geometry'      => 'servicodados.ibge.gov.br/api/v2/malhas/23?resolucao=5',
    'api_names'         => 'servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios',
    'features_total'    => count($geo['features']),
    'features_expected' => 184,
    'imported_at'       => date(DATE_ATOM),
    'note'              => 'Geometria oficial IBGE resolucao=5. Enriquecida com LocalidadesAPI.',
];

if (!$dry) {
    // Backup existing
    if (is_file($cearaOut)) {
        @copy($cearaOut, $cearaOut . '.bak.' . date('Ymd_His'));
    }
    file_put_contents($cearaOut, json_encode($geo, JSON_UNESCAPED_UNICODE)); // compact: menor para web
}

$sizeKb = round(strlen(json_encode($geo, JSON_UNESCAPED_UNICODE)) / 1024, 1);
$step['pass']   = true;
$step['detail'] = "{$enriched} municípios, {$sizeKb} KB." . ($dry ? ' [DRY RUN — não salvo]' : '');
$steps[] = $step;

// ── Step 6: Extract and save rmf_municipios.json ──────────────────────────────

$step = ['label' => 'Extraindo e salvando rmf_municipios.json (19 municípios RMF)', 'pass' => false, 'detail' => ''];
$rmfFeatures = array_values(array_filter($geo['features'], function ($feat) use ($rmfCodes) {
    return in_array((string) ($feat['properties']['municipio_ibge'] ?? ''), $rmfCodes, true);
}));
foreach ($rmfFeatures as &$feat) {
    $feat['properties']['is_rmf'] = true;
}
unset($feat);

$rmfGeo = [
    'type'     => 'FeatureCollection',
    'features' => $rmfFeatures,
    'metadata' => [
        'source'         => 'IBGE_Malhas_v2_resolucao5',
        'derived_from'   => 'ceara_mun.json',
        'lc_reference'   => 'LC 180/2018',
        'features_total' => count($rmfFeatures),
        'features_expected' => 19,
        'imported_at'    => date(DATE_ATOM),
        'note'           => 'RMF derivada da malha oficial IBGE. Não usa polígono manual.',
    ],
];

if (!$dry) {
    if (is_file($rmfOut)) @copy($rmfOut, $rmfOut . '.bak.' . date('Ymd_His'));
    file_put_contents($rmfOut, json_encode($rmfGeo, JSON_UNESCAPED_UNICODE)); // compact
}

$step['pass']   = count($rmfFeatures) === 19;
$step['detail'] = count($rmfFeatures) . "/19 municípios RMF." . ($dry ? ' [DRY RUN]' : '');
if (!$step['pass']) $issues[] = "RMF com {count($rmfFeatures)} municípios (esperado 19).";
$steps[] = $step;

$ok = empty($issues);

// ── Step 7: Top municipalities by revenue (informational) ─────────────────────
$topMunis = [];
try {
    require_once $root . '/app/Bootstrap.php';
    \BIomie\Bootstrap::init();
    $pdo = \BIomie\Database::connect(new \BIomie\Config());
    $stmt = $pdo->query(
        "SELECT cidade_norm, COALESCE(SUM(faturamento),0) fat
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN '2026-01' AND '2026-05'
         GROUP BY cidade_norm ORDER BY fat DESC LIMIT 20"
    );
    $topMunis = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable) {}

output:
$elapsed = round(microtime(true) - $t0, 2);

// ── Output ─────────────────────────────────────────────────────────────────────
if ($json) {
    echo json_encode([
        'geo_import_ibge_ce_municipios' => [
            'overall'  => $ok ? 'PASS' : 'FAIL',
            'dry_run'  => $dry,
            'elapsed_s' => $elapsed,
            'steps'    => $steps,
            'issues'   => $issues,
            'top_munis' => $topMunis,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit($ok ? 0 : 1);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  GEO.R1.1 — Importador Municípios CE (IBGE)\n";
    echo $hr . PHP_EOL . PHP_EOL;

    foreach ($steps as $s) {
        $b = $s['pass'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        echo "  [{$b}]  {$s['label']}\n";
        echo "         {$s['detail']}\n";
    }

    if (!empty($topMunis)) {
        echo PHP_EOL . "  TOP MUNICÍPIOS POR RECEITA (jan-mai 2026):\n";
        foreach (array_slice($topMunis, 0, 10) as $m) {
            printf("    %-28s R\$%s\n", ($m['cidade_norm'] ?? '?'), number_format((float)($m['fat'] ?? 0), 0, ',', '.'));
        }
    }

    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    $ob = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    echo "  [{$ob}]  GEO.R1.1 Import concluído em {$elapsed}s\n" . PHP_EOL;
} else {
    echo ($ok ? 'PASS' : 'FAIL') . " elapsed={$elapsed}s\n";
    foreach ($issues as $iss) echo "  ISSUE: {$iss}\n";
}

exit($ok ? 0 : 1);
