<?php

declare(strict_types=1);

/**
 * tools/dev/geo_geometry_audit.php — GEO.R1 Fase 3
 *
 * Valida os arquivos GeoJSON de todas as camadas territoriais:
 *   - existência do arquivo;
 *   - JSON válido;
 *   - FeatureCollection com features;
 *   - UTF-8 válido;
 *   - tamanho aceitável para web;
 *   - propriedade de match presente;
 *   - feature count dentro do esperado;
 *   - geometria presente em cada feature;
 *   - tempo de leitura razoável.
 *
 * Regra: Fortaleza bairros nunca pode falhar (FAIL crítico).
 * Camadas opcionais geram WARN, não FAIL.
 *
 * Uso:
 *   php tools/dev/geo_geometry_audit.php [--pretty] [--json]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Geo\GeoMapLayerRegistry;

$opts     = getopt('', ['pretty', 'json']);
$pretty   = array_key_exists('pretty', $opts);
$jsonMode = array_key_exists('json',   $opts);

$root = dirname(__DIR__, 2);

$results    = [];
$criticalOk = true;

foreach (GeoMapLayerRegistry::all() as $layerId => $def) {
    $geojsonPath = $root . '/' . ($def['geojson_path'] ?? '');
    $isCritical  = ($def['preserve_existing'] ?? false) === true;
    $matchProp   = $def['geojson_match_property'] ?? '';
    $expectedCt  = $def['feature_count_expected'] ?? null;

    $r = [
        'layer_id'           => $layerId,
        'label'              => $def['label'] ?? $layerId,
        'critical'           => $isCritical,
        'geojson_path'       => $def['geojson_path'] ?? '',
        'file_exists'        => false,
        'file_size_kb'       => null,
        'valid_json'         => false,
        'valid_utf8'         => false,
        'is_feature_collection' => false,
        'feature_count'      => null,
        'feature_count_expected' => $expectedCt,
        'match_property'     => $matchProp,
        'match_property_present' => false,
        'features_with_geometry' => null,
        'features_without_key' => null,
        'read_ms'            => null,
        'status'             => 'MISS',
        'notes'              => [],
    ];

    if (!is_file($geojsonPath)) {
        $r['status'] = $isCritical ? 'FAIL' : 'WARN';
        $r['notes'][] = 'Arquivo não encontrado: ' . $def['geojson_path'];
        if ($def['geometry_note'] ?? null) $r['notes'][] = $def['geometry_note'];
        if ($isCritical) $criticalOk = false;
        $results[] = $r;
        continue;
    }

    $r['file_exists'] = true;
    $r['file_size_kb'] = round(filesize($geojsonPath) / 1024, 1);

    // Tamanho
    if ($r['file_size_kb'] > 15000) {
        $r['notes'][] = "Arquivo grande ({$r['file_size_kb']} KB) — considerar simplificação com Mapshaper.";
    }

    // UTF-8
    $t0  = microtime(true);
    $raw = file_get_contents($geojsonPath);
    $r['read_ms'] = round((microtime(true) - $t0) * 1000, 1);
    $r['valid_utf8'] = $raw !== false && mb_detect_encoding($raw, 'UTF-8', true) !== false;

    if (!$r['valid_utf8']) {
        $r['notes'][] = 'Encoding não é UTF-8 válido.';
    }

    // JSON parse
    $parsed = $raw ? json_decode($raw, true) : null;
    if (!is_array($parsed)) {
        $r['status'] = $isCritical ? 'FAIL' : 'WARN';
        $r['notes'][] = 'JSON inválido ou vazio.';
        if ($isCritical) $criticalOk = false;
        $results[] = $r;
        continue;
    }
    $r['valid_json'] = true;

    // FeatureCollection
    if (($parsed['type'] ?? '') !== 'FeatureCollection' || !is_array($parsed['features'] ?? null)) {
        $r['status'] = $isCritical ? 'FAIL' : 'WARN';
        $r['notes'][] = 'Não é um FeatureCollection válido.';
        if ($isCritical) $criticalOk = false;
        $results[] = $r;
        continue;
    }
    $r['is_feature_collection'] = true;

    $features = $parsed['features'];
    $r['feature_count'] = count($features);

    // Feature count expected
    if ($expectedCt !== null && $r['feature_count'] < $expectedCt) {
        $r['notes'][] = "Feature count {$r['feature_count']} < esperado {$expectedCt}. Importar malha completa.";
    }

    // Match property
    $matchOk = 0;
    $noKey   = 0;
    $noGeom  = 0;
    foreach ($features as $feat) {
        $props = $feat['properties'] ?? [];
        $geom  = $feat['geometry']   ?? null;
        if ($matchProp && isset($props[$matchProp])) $matchOk++;
        elseif ($matchProp) $noKey++;
        if ($geom === null || !isset($geom['type'])) $noGeom++;
    }
    $r['match_property_present']  = $matchProp === '' || $matchOk > 0;
    $r['features_with_geometry']  = $r['feature_count'] - $noGeom;
    $r['features_without_key']    = $noKey;

    if (!$r['match_property_present']) {
        $r['notes'][] = "Propriedade de match '{$matchProp}' ausente em todas as features.";
    }
    if ($noKey > 0) {
        $r['notes'][] = "{$noKey} feature(s) sem propriedade '{$matchProp}'.";
    }
    if ($noGeom > 0) {
        $r['notes'][] = "{$noGeom} feature(s) sem geometria.";
    }

    // Check geometry_source note for approximate polygons
    $firstProps = $features[0]['properties'] ?? [];
    if (($firstProps['geometry_source'] ?? '') === 'centroid_approximation') {
        $r['notes'][] = 'Polígonos aproximados (centroid+bbox). Substituir por malha IBGE/IPECE oficial para produção.';
    }

    // Status final
    $hasIssues = !empty($r['notes']);
    if (!$r['match_property_present'] && $isCritical) {
        $r['status'] = 'FAIL';
        $criticalOk = false;
    } elseif ($hasIssues && $isCritical && !empty(array_filter($r['notes'], fn($n) => str_contains($n, 'inváli')))) {
        $r['status'] = 'FAIL';
        $criticalOk = false;
    } elseif ($hasIssues) {
        $r['status'] = 'WARN';
    } else {
        $r['status'] = 'PASS';
    }

    $results[] = $r;
}

$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$warnCount = count(array_filter($results, fn($r) => $r['status'] === 'WARN'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));

$overall = $criticalOk ? ($failCount === 0 ? 'PASS' : 'FAIL') : 'FAIL';

if ($jsonMode) {
    echo json_encode([
        'geo_geometry_audit' => [
            'overall' => $overall,
            'pass'    => $passCount,
            'warn'    => $warnCount,
            'fail'    => $failCount,
            'layers'  => $results,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit($overall === 'PASS' ? 0 : 1);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  GEO.R1 — Auditoria de Geometria (Fase 3)\n";
    echo $hr . PHP_EOL . PHP_EOL;

    foreach ($results as $r) {
        $badge = match($r['status']) {
            'PASS' => "\033[32mPASS\033[0m",
            'WARN' => "\033[33mWARN\033[0m",
            'FAIL' => "\033[31mFAIL\033[0m",
            default=> "\033[33mMISS\033[0m",
        };
        $sz  = $r['file_size_kb'] !== null ? " {$r['file_size_kb']} KB" : '';
        $ct  = $r['feature_count'] !== null ? " — {$r['feature_count']} features" : '';
        echo "  [{$badge}]  {$r['label']}{$sz}{$ct}\n";
        if (!empty($r['notes'])) {
            foreach ($r['notes'] as $note) {
                echo "         ⚠ {$note}\n";
            }
        }
    }

    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    $ob = $overall === 'PASS' ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    echo "  [{$ob}]  {$passCount} PASS / {$warnCount} WARN / {$failCount} FAIL\n" . PHP_EOL;
} else {
    echo "{$overall} {$passCount}PASS/{$warnCount}WARN/{$failCount}FAIL\n";
}

exit($overall === 'PASS' ? 0 : ($failCount > 0 ? 1 : 0));
