<?php

declare(strict_types=1);

/**
 * geo_ceara_regioes_ipece_probe.php
 *
 * Probe do GeoJSON das 14 Regiões de Planejamento IPECE (GEO.R1.6).
 * Confirma que substituiu o bbox derivado por geometria oficial.
 *
 * Uso:
 *   php tools/dev/geo_ceara_regioes_ipece_probe.php
 *   php tools/dev/geo_ceara_regioes_ipece_probe.php --json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) continue;
    [$k,$v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
    $opts[$k] = $v;
}

$root = dirname(__DIR__, 2);
$r    = runProbe($root);

if (isset($opts['json'])) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($r['ready_to_deploy'] ? 0 : 1);
}
printReport($r);
exit($r['ready_to_deploy'] ? 0 : 1);

// ──────────────────────────────────────────────────────────────────────────────

/** @return array<string,mixed> */
function runProbe(string $root): array
{
    $path = $root . '/assets/geo/ceara_regioes_ipece_oficial.geojson';

    $expectedNames = [
        'Grande Fortaleza', 'Litoral Norte', 'Litoral Oeste / Vale do Curu',
        'Litoral Leste', 'Maciço de Baturité', 'Sertão Central',
        'Sertão de Canindé', 'Serra da Ibiapaba', 'Sertão de Sobral',
        'Sertão dos Crateús', 'Sertão dos Inhamuns', 'Vale do Jaguaribe',
        'Centro-Sul', 'Cariri',
    ];
    $accentFields = ['Maciço de Baturité', 'Sertão de Canindé', 'Sertão dos Inhamuns'];

    $r = [
        'probe'           => 'geo_ceara_regioes_ipece_probe',
        'generated_at'    => date(DATE_ATOM),
        'path'            => $path,
        'exists'          => false,
        'valid_json'      => false,
        'count_14'        => false,
        'all_regions_ok'  => false,
        'no_bbox_derived' => false,
        'geometry_ok'     => false,
        'encoding_ok'     => false,
        'metadata_ok'     => false,
        'size_ok'         => false,
        'total_features'  => 0,
        'file_size_kb'    => 0,
        'found_names'     => [],
        'missing_names'   => [],
        'issues'          => [],
        'warnings'        => [],
        'ready_to_deploy' => false,
    ];

    if (!file_exists($path)) {
        $r['issues'][] = 'Arquivo não encontrado. Execute: python tools/dev/geo_generate_ceara_regioes_ipece.py';
        return $r;
    }
    $r['exists'] = true;

    $sizeKb = (int) round((filesize($path) ?: 0) / 1024);
    $r['file_size_kb'] = $sizeKb;
    $r['size_ok']      = $sizeKb < 3000;

    $raw = file_get_contents($path);
    $geo = json_decode($raw ?? '', true);
    if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection') {
        $r['issues'][] = 'Não é um GeoJSON FeatureCollection válido.';
        return $r;
    }
    $r['valid_json']     = true;
    $features            = $geo['features'];
    $r['total_features'] = count($features);

    // Check 1: 14 regiões
    $r['count_14'] = count($features) === 14;
    if (!$r['count_14']) {
        $r['issues'][] = sprintf('Esperado 14 features, encontrado %d.', count($features));
    }

    // Check 2: nomes esperados
    $foundNames = array_map(fn($f) => (string)($f['properties']['regiao_nome'] ?? ''), $features);
    $r['found_names']   = $foundNames;
    $r['missing_names'] = array_values(array_diff($expectedNames, $foundNames));
    $r['all_regions_ok'] = $r['missing_names'] === [];
    foreach ($r['missing_names'] as $n) {
        $r['issues'][] = "Região '{$n}' não encontrada no GeoJSON.";
    }

    // Check 3: sem bbox derivado
    $hasBboxDerived = false;
    foreach ($features as $feat) {
        $gs = (string)($feat['properties']['geometry_source'] ?? '');
        if (str_contains($gs, 'bbox') || str_contains($gs, 'derivado')) {
            $hasBboxDerived = true;
        }
    }
    $r['no_bbox_derived'] = !$hasBboxDerived;
    // Note: método "dissolved_ibge" é OK, não é bbox

    // Check 4: campos obrigatórios
    $required = ['regiao_nome','regiao_norm','source','source_year','geometry_source','municipios_count'];
    foreach ($required as $field) {
        $absent = count(array_filter($features, fn($f) => !array_key_exists($field, $f['properties'] ?? [])));
        if ($absent > 0) {
            $r['issues'][] = "Campo '{$field}' ausente em {$absent} features.";
        }
    }

    // Check 5: geometrias Polygon/MultiPolygon
    $badGeom = 0;
    foreach ($features as $feat) {
        $t = $feat['geometry']['type'] ?? '';
        if (!in_array($t, ['Polygon','MultiPolygon'], true)) $badGeom++;
        if (empty($feat['geometry']['coordinates'] ?? [])) $badGeom++;
    }
    $r['geometry_ok'] = $badGeom === 0;
    if ($badGeom > 0) $r['issues'][] = "{$badGeom} geometria(s) inválida(s).";

    // Check 6: encoding
    $encOk = true;
    foreach ($features as $feat) {
        $nome = (string)($feat['properties']['regiao_nome'] ?? '');
        if ($nome && !mb_check_encoding($nome, 'UTF-8')) {
            $r['issues'][] = "Encoding inválido: '{$nome}'.";
            $encOk = false;
            break;
        }
    }
    $r['encoding_ok'] = $encOk;

    // Check 7: metadata
    $meta = $geo['metadata'] ?? [];
    $r['metadata_ok'] = !empty($meta['source']) && !empty($meta['generated_at']);
    if (!$r['metadata_ok']) {
        $r['warnings'][] = 'Metadados incompletos (source ou generated_at ausente).';
    }

    $r['ready_to_deploy'] =
        $r['exists'] && $r['valid_json'] && $r['count_14'] &&
        $r['all_regions_ok'] && $r['geometry_ok'] &&
        $r['encoding_ok'] && $r['size_ok'] && $r['issues'] === [];

    return $r;
}

function printReport(array $r): void
{
    $chk = fn(bool $v) => $v ? '[PASS]' : '[FAIL]';
    echo PHP_EOL;
    echo '╔════════════════════════════════════════════════════════════════╗' . PHP_EOL;
    echo '║  GEO — Probe Regiões IPECE Oficiais (GEO.R1.6)                ║' . PHP_EOL;
    echo '╚════════════════════════════════════════════════════════════════╝' . PHP_EOL . PHP_EOL;

    echo '── Arquivo ──────────────────────────────────────────────────────' . PHP_EOL;
    echo '  ' . $chk($r['exists'])     . '  ceara_regioes_ipece_oficial.geojson existe' . PHP_EOL;
    echo '  ' . $chk($r['valid_json']) . '  JSON válido' . PHP_EOL;
    echo '  ' . $chk($r['size_ok'])    . '  Tamanho: ' . $r['file_size_kb'] . ' KB' . PHP_EOL . PHP_EOL;

    if ($r['valid_json']) {
        echo '── Conteúdo ─────────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $chk($r['count_14'])       . '  14 regiões de planejamento' . PHP_EOL;
        echo '  ' . $chk($r['all_regions_ok']) . '  Todos os nomes IPECE presentes' . PHP_EOL;
        echo '  ' . $chk($r['no_bbox_derived']) . '  Sem geometry_source=bbox_derivado' . PHP_EOL;
        echo '  ' . $chk($r['geometry_ok'])    . '  Polygon/MultiPolygon válidos' . PHP_EOL;
        echo '  ' . $chk($r['encoding_ok'])    . '  Encoding UTF-8 válido' . PHP_EOL;
        echo '  ' . $chk($r['metadata_ok'])    . '  Metadados de fonte presentes' . PHP_EOL . PHP_EOL;

        if ($r['found_names']) {
            echo '── Regiões encontradas ───────────────────────────────────────────' . PHP_EOL;
            foreach ($r['found_names'] as $n) echo '    ✓ ' . $n . PHP_EOL;
            echo PHP_EOL;
        }
    }

    if (!empty($r['issues'])) {
        echo '── Issues ───────────────────────────────────────────────────────' . PHP_EOL;
        foreach ($r['issues'] as $i) echo '  [!] ' . $i . PHP_EOL;
        echo PHP_EOL;
    }

    echo '── Resultado ────────────────────────────────────────────────────' . PHP_EOL;
    echo ($r['ready_to_deploy'] ? '  [PASS] PRONTO PARA DEPLOY' : '  [FAIL] NÃO PRONTO') . PHP_EOL . PHP_EOL;
}
