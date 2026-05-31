<?php

declare(strict_types=1);

/**
 * tools/dev/geo_import_ipece_regioes.php — GEO.R1.1 Fase 3
 *
 * Importa/regenera as Regiões de Planejamento do IPECE para ceara_regioes.json.
 *
 * Estratégia atual: derivar bounding boxes das geometrias IBGE de municípios por região.
 * Fonte primária: ceara_mun.json (184 municípios com geometria oficial IBGE).
 * Mapeamento município → região: biomie_geo_municipios_ce.regiao_planejamento_id.
 *
 * Limitação documentada:
 *   O IPECE disponibiliza arquivo georreferenciado das Regiões de Planejamento,
 *   mas não via API pública REST. Quando o arquivo oficial for obtido (.shp ou .geojson),
 *   usar --from-file=/path/to/ipece_regioes.geojson para importar diretamente.
 *
 * Uso:
 *   php tools/dev/geo_import_ipece_regioes.php [--pretty] [--json] [--dry-run]
 *   php tools/dev/geo_import_ipece_regioes.php --from-file=/path/to/ipece.geojson --pretty
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts     = getopt('', ['pretty', 'json', 'dry-run', 'from-file:']);
$pretty   = array_key_exists('pretty',    $opts);
$jsonMode = array_key_exists('json',      $opts);
$dry      = array_key_exists('dry-run',   $opts);
$fromFile = $opts['from-file'] ?? null;

$root    = dirname(__DIR__, 2);
$cearaIn = $root . '/assets/geo/ceara_mun.json';
$regiOut = $root . '/assets/geo/ceara_regioes.json';

$steps  = [];
$issues = [];
$t0     = microtime(true);
$ok     = true;

// Nomes das 14 regiões IPECE
$regiaoNomes = [
    1=>'Grande Fortaleza', 2=>'Cariri', 3=>'Sertão de Sobral',
    4=>'Vale do Jaguaribe', 5=>'Serra da Ibiapaba', 6=>'Sertão Central',
    7=>'Litoral Oeste / Curu', 8=>'Maciço de Baturité', 9=>'Centro Sul',
    10=>'Litoral Norte', 11=>'Sertão dos Crateús', 12=>'Sertão de Canindé',
    13=>'Litoral Leste', 14=>'Sertão dos Inhamuns',
];

function geo_norm_r(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
}

// ── Mode A: from-file (official IPECE GeoJSON) ────────────────────────────────
if ($fromFile !== null) {
    $step = ['label' => "Importar arquivo oficial IPECE: {$fromFile}", 'pass' => false, 'detail' => ''];
    if (!is_file($fromFile)) {
        $step['detail'] = "Arquivo não encontrado: {$fromFile}";
        $issues[] = $step['detail'];
        $ok = false;
        $steps[] = $step;
        goto output;
    }
    $raw = file_get_contents($fromFile);
    $geo = $raw ? json_decode($raw, true) : null;
    if (!is_array($geo) || !isset($geo['features'])) {
        $step['detail'] = "GeoJSON inválido ou sem features: {$fromFile}";
        $issues[] = $step['detail'];
        $ok = false;
        $steps[] = $step;
        goto output;
    }

    $featureCount = count($geo['features']);
    if ($featureCount !== 14) {
        $issues[] = "Arquivo tem {$featureCount} features (esperado 14).";
    }

    // Enrich with regiao_id if file has regiao name but not ID
    foreach ($geo['features'] as &$feat) {
        $nome = $feat['properties']['regiao_nome'] ?? $feat['properties']['nome'] ?? '';
        if ($nome && !isset($feat['properties']['regiao_id'])) {
            foreach ($regiaoNomes as $id => $n) {
                if (strtolower($n) === strtolower($nome)) {
                    $feat['properties']['regiao_id']   = $id;
                    $feat['properties']['regiao_nome']  = $n;
                    $feat['properties']['regiao_norm']  = geo_norm_r($n);
                    break;
                }
            }
        }
        $feat['properties']['geometry_source'] = 'ipece_oficial';
    }
    unset($feat);

    $geo['metadata'] = [
        'source'         => 'ipece_oficial',
        'file_source'    => basename($fromFile),
        'features_total' => $featureCount,
        'features_expected' => 14,
        'imported_at'    => date(DATE_ATOM),
    ];

    $step['pass']   = true;
    $step['detail'] = "{$featureCount} regiões do arquivo oficial IPECE.";
    $steps[] = $step;

    if (!$dry) {
        if (is_file($regiOut)) @copy($regiOut, $regiOut . '.bak.' . date('Ymd_His'));
        file_put_contents($regiOut, json_encode($geo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    goto output;
}

// ── Mode B: derive from ceara_mun.json + biomie_geo_municipios_ce ─────────────

$step = ['label' => 'Carregar ceara_mun.json (base IBGE oficial)', 'pass' => false, 'detail' => ''];
if (!is_file($cearaIn)) {
    $step['detail'] = "ceara_mun.json não encontrado. Execute geo_import_ibge_ce_municipios.php primeiro.";
    $issues[] = $step['detail'];
    $ok = false;
    $steps[] = $step;
    goto output;
}
$ceGeo = json_decode((string) file_get_contents($cearaIn), true);
$featureCount = count($ceGeo['features'] ?? []);
$step['pass']   = $featureCount >= 100;
$step['detail'] = "{$featureCount} features em ceara_mun.json.";
if (!$step['pass']) { $issues[] = $step['detail']; $ok = false; }
$steps[] = $step;
if (!$ok) goto output;

// Load regiao_planejamento_id mapping from DB
$step = ['label' => 'Carregar mapeamento município → região de biomie_geo_municipios_ce', 'pass' => false, 'detail' => ''];
try {
    $pdo = \BIomie\Database::connect(new \BIomie\Config());
    $rows = $pdo->query(
        "SELECT ibge_code, regiao_planejamento_id FROM biomie_geo_municipios_ce WHERE regiao_planejamento_id IS NOT NULL"
    )->fetchAll(\PDO::FETCH_ASSOC);
    $munToRegiao = [];
    foreach ($rows as $r) $munToRegiao[(string)$r['ibge_code']] = (int)$r['regiao_planejamento_id'];
    $step['pass']   = count($munToRegiao) >= 30;
    $step['detail'] = count($munToRegiao) . " municípios com mapeamento de região.";
} catch (\Throwable $e) {
    $step['detail'] = "DB error: " . $e->getMessage();
    $ok = false;
}
$steps[] = $step;
if (!$ok) goto output;

// Build municipality lookup by ibge_code
$munByCode = [];
foreach ($ceGeo['features'] as $feat) {
    $code = (string) ($feat['properties']['municipio_ibge'] ?? '');
    if ($code) $munByCode[$code] = $feat;
}

// For each region, collect bounding box from constituent municipality polygons
$step = ['label' => 'Derivar bounding boxes das regiões a partir de polígonos IBGE', 'pass' => false, 'detail' => ''];

function allCoords(array $geom): array
{
    $out = [];
    if ($geom['type'] === 'Polygon') {
        foreach ($geom['coordinates'] as $ring) $out = array_merge($out, $ring);
    } elseif ($geom['type'] === 'MultiPolygon') {
        foreach ($geom['coordinates'] as $poly) foreach ($poly as $ring) $out = array_merge($out, $ring);
    }
    return $out;
}

$regionMunis = [];
foreach ($munToRegiao as $ibge => $regiao) {
    $regionMunis[$regiao][] = $ibge;
}

$features = [];
$regioesMissing = [];
foreach ($regiaoNomes as $id => $nome) {
    $ibgeCodes = $regionMunis[$id] ?? [];
    $allLngs = []; $allLats = [];
    $foundMunis = 0;
    foreach ($ibgeCodes as $code) {
        $feat = $munByCode[$code] ?? null;
        if ($feat && isset($feat['geometry'])) {
            $coords = allCoords($feat['geometry']);
            foreach ($coords as $c) { $allLngs[] = $c[0]; $allLats[] = $c[1]; }
            $foundMunis++;
        }
    }

    if (empty($allLngs)) {
        $regioesMissing[] = $id;
        continue;
    }

    $minLng = min($allLngs); $maxLng = max($allLngs);
    $minLat = min($allLats); $maxLat = max($allLats);
    $bbox = [[[$minLng,$minLat],[$maxLng,$minLat],[$maxLng,$maxLat],[$minLng,$maxLat],[$minLng,$minLat]]];

    $features[] = [
        'type' => 'Feature',
        'properties' => [
            'regiao_id'            => $id,
            'regiao_nome'          => $nome,
            'regiao_norm'          => geo_norm_r($nome),
            'municipios_incluidos' => $foundMunis,
            'geometry_source'      => 'ibge_bbox_derivado',
            'geometry_note'        => "Bounding box derivado de {$foundMunis} município(s) IBGE. Substituir por arquivo oficial IPECE quando disponível.",
        ],
        'geometry' => ['type' => 'Polygon', 'coordinates' => $bbox],
    ];
}

$step['pass']   = count($features) === 14;
$step['detail'] = count($features) . "/14 regiões derivadas." . (!empty($regioesMissing) ? " Sem municípios: " . implode(',', $regioesMissing) : '');
if (!$step['pass']) { $issues[] = $step['detail']; }
$steps[] = $step;

$geo = [
    'type'     => 'FeatureCollection',
    'features' => $features,
    'metadata' => [
        'source'            => 'ibge_bbox_derivado_de_municipios',
        'derived_from'      => 'ceara_mun.json + biomie_geo_municipios_ce',
        'features_total'    => count($features),
        'features_expected' => 14,
        'imported_at'       => date(DATE_ATOM),
        'warn'              => 'Bounding boxes derivados, não polígonos oficiais das regiões IPECE. Use --from-file com o arquivo IPECE oficial para substituir.',
    ],
];

if (!$dry) {
    if (is_file($regiOut)) @copy($regiOut, $regiOut . '.bak.' . date('Ymd_His'));
    file_put_contents($regiOut, json_encode($geo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$step2 = ['label' => 'Salvar ceara_regioes.json', 'pass' => true,
          'detail' => count($features) . " regiões, " . round(strlen(json_encode($geo)) / 1024, 1) . " KB." . ($dry ? ' [DRY RUN]' : '')];
$steps[] = $step2;

output:
$elapsed = round(microtime(true) - $t0, 2);
$ok = empty($issues);

if ($jsonMode) {
    echo json_encode([
        'geo_import_ipece_regioes' => [
            'overall' => $ok ? 'PASS' : ($fromFile ? 'PASS' : 'WARN'),
            'dry_run' => $dry,
            'mode'    => $fromFile ? 'from_file' : 'derived_from_ibge_bbox',
            'steps'   => $steps,
            'issues'  => $issues,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit(0);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  GEO.R1.1 — Importador Regiões IPECE\n";
    echo $hr . PHP_EOL . PHP_EOL;
    foreach ($steps as $s) {
        $b = $s['pass'] ? "\033[32mPASS\033[0m" : "\033[33mWARN\033[0m";
        echo "  [{$b}]  {$s['label']}\n         {$s['detail']}\n";
    }
    echo PHP_EOL . "  Nota: Para usar arquivo oficial IPECE:\n";
    echo "    php tools/dev/geo_import_ipece_regioes.php --from-file=/caminho/para/regioes.geojson\n";
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    echo "  concluído em {$elapsed}s\n" . PHP_EOL;
} else {
    echo (empty($issues) ? 'PASS' : 'WARN') . " elapsed={$elapsed}s\n";
}

exit(0);
