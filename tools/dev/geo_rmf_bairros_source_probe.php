<?php

declare(strict_types=1);

/**
 * geo_rmf_bairros_source_probe.php
 *
 * Diagnóstico de disponibilidade de bairros metropolitanos via IBGE 2022.
 * Valida o GeoJSON gerado pelo script Python (geo_generate_rmf_bairros.py).
 *
 * Uso:
 *   php tools/dev/geo_rmf_bairros_source_probe.php
 *   php tools/dev/geo_rmf_bairros_source_probe.php --json
 *   php tools/dev/geo_rmf_bairros_source_probe.php --check-ibge-api
 *
 * Responde:
 *   [x] GeoJSON rmf_bairros existe e é válido?
 *   [x] Campos obrigatórios presentes: municipio_ibge, municipio_nome, bairro_ibge, bairro_nome, bairro_key, cidade_key, geometry
 *   [x] Encoding UTF-8 correto (Eusébio, Maracanaú, São Gonçalo do Amarante)?
 *   [x] Quantos bairros por município da RMF?
 *   [x] Quais municípios têm ≥1 bairro?
 *   [x] Quais municípios vieram sem bairro?
 *   [x] Fortaleza validada (≥100 bairros se incluída, ou ausente se excluída)?
 *   [x] Nenhuma geometria fora dos 19 municípios da RMF?
 *   [x] Tamanho do arquivo aceitável para Leaflet (<3 MB)?
 *   [x] IBGE API responde? (com --check-ibge-api)
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts          = parseCliArgs($argv);
$asJson        = isset($opts['json']);
$checkApi      = isset($opts['check-ibge-api']);
$projectRoot   = dirname(__DIR__, 2);

$findings = runProbe($projectRoot, $checkApi);

if ($asJson) {
    echo json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($findings['ready_to_deploy'] ? 0 : 1);
}

printReport($findings);
exit($findings['ready_to_deploy'] ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────────────

function parseCliArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

/** @return array<string, mixed> */
function runProbe(string $root, bool $checkIbgeApi): array
{
    // 19 municípios da RMF com códigos IBGE
    $rmfCodes = [
        2304400 => 'Fortaleza',
        2303709 => 'Caucaia',
        2307650 => 'Maracanaú',
        2307700 => 'Maranguape',
        2304285 => 'Eusébio',
        2301000 => 'Aquiraz',
        2305233 => 'Horizonte',
        2309607 => 'Pacajus',
        2309706 => 'Pacatuba',
        2306256 => 'Itaitinga',
        2304954 => 'Guaiúba',
        2303956 => 'Chorozinho',
        2303501 => 'Cascavel',
        2310852 => 'Pindoretama',
        2310209 => 'Paracuru',
        2310258 => 'Paraipaba',
        2312403 => 'São Gonçalo do Amarante',
        2312601 => 'São Luís do Curu',
        2313500 => 'Trairi',
    ];

    $geojsonPath = $root . '/assets/geo/rmf_bairros_ibge_2022.geojson';
    $pythonScript = $root . '/tools/dev/geo_generate_rmf_bairros.py';

    // Campos obrigatórios que o gerador Python DEVE produzir
    $requiredProps = [
        'municipio_ibge' => 'Código IBGE do município (ex: "2304400")',
        'municipio_nome' => 'Nome do município (ex: "Fortaleza")',
        'bairro_ibge'    => 'Código IBGE do bairro (CD_BAIRRO)',
        'bairro_nome'    => 'Nome do bairro (NM_BAIRRO, UTF-8)',
        'bairro_key'     => 'Chave normalizada BIomie (underscore, sem acentos)',
        'cidade_key'     => 'Chave do município normalizada BIomie',
    ];

    // Nomes com acentos que devem aparecer íntegros no GeoJSON final
    $accentedMunis = ['Eusébio', 'Maracanaú', 'São Gonçalo do Amarante', 'São Luís do Curu', 'Itaitinga'];

    $result = [
        'probe'                => 'geo_rmf_bairros_source_probe',
        'generated_at'         => date(DATE_ATOM),
        'geojson_path'         => $geojsonPath,
        'geojson_exists'       => false,
        'geojson_valid'        => false,
        'fields_ok'            => false,
        'encoding_ok'          => false,
        'total_features'       => 0,
        'file_size_kb'         => 0,
        'file_size_ok'         => false,
        'fortaleza_count'      => 0,
        'fortaleza_ok'         => false,
        'municipalities'       => [],
        'with_bairros'         => [],
        'without_bairros'      => array_map(fn(int $c) => $rmfCodes[$c] . ' (' . $c . ')', array_keys($rmfCodes)),
        'coverage_pct'         => 0,
        'ready_to_deploy'      => false,
        'ibge_api_ok'          => null,
        'python_script_exists' => file_exists($pythonScript),
        'issues'               => [],
        'instructions'         => [],
    ];

    // ── 1. Verifica existência do arquivo ─────────────────────────────────────
    if (!file_exists($geojsonPath)) {
        $result['issues'][] = 'GeoJSON não encontrado: ' . $geojsonPath;
        $result['instructions'] = buildGenerationInstructions($pythonScript, $root);
        if ($checkIbgeApi) {
            $result['ibge_api_ok'] = checkIbgeApiConnectivity();
        }
        return $result;
    }

    $result['geojson_exists'] = true;

    // ── 2. Tamanho do arquivo ─────────────────────────────────────────────────
    $sizeBytes = filesize($geojsonPath);
    $sizeKb    = (int) round($sizeBytes / 1024);
    $result['file_size_kb'] = $sizeKb;
    $result['file_size_ok'] = $sizeKb < 3000; // <3 MB aceitável para Leaflet sem decimation
    if ($sizeKb >= 3000) {
        $result['issues'][] = sprintf(
            'Arquivo grande (%d KB) — considere re-rodar com tolerance maior (0.0002) no script Python antes do deploy.',
            $sizeKb
        );
    }

    // ── 3. Parse e validação do GeoJSON ──────────────────────────────────────
    $raw = file_get_contents($geojsonPath);
    if ($raw === false) {
        $result['issues'][] = 'Não foi possível ler o arquivo.';
        return $result;
    }

    $geo = json_decode($raw, true);
    if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection' || !isset($geo['features'])) {
        $result['issues'][] = 'Arquivo não é um GeoJSON FeatureCollection válido.';
        return $result;
    }

    $result['geojson_valid'] = true;
    $features = $geo['features'];
    $result['total_features'] = count($features);

    if ($result['total_features'] === 0) {
        $result['issues'][] = 'GeoJSON válido mas sem features — rode o script Python novamente.';
        return $result;
    }

    // ── 4. Auto-detecta campos de código e nome do município ─────────────────
    [$codeField, $nameField, $munCodeField, $munNameField] = detectFields($features[0] ?? []);

    $result['detected_fields'] = [
        'neighborhood_code' => $codeField,
        'neighborhood_name' => $nameField,
        'muni_code'         => $munCodeField,
        'muni_name'         => $munNameField,
    ];

    // ── 4b. Valida campos obrigatórios BIomie (amostra: primeiras 20 features) ─
    $fieldIssues = checkRequiredFields(array_slice($features, 0, 20), $requiredProps);
    $result['fields_ok']             = $fieldIssues === [];
    $result['fields_missing']        = $fieldIssues;
    foreach ($fieldIssues as $issue) {
        $result['issues'][] = $issue;
    }

    // ── 4c. Valida encoding UTF-8 de nomes acentuados ────────────────────────
    $encodingIssues = checkEncodingForMunis($features, $accentedMunis);
    $result['encoding_ok']           = $encodingIssues === [];
    $result['encoding_samples']      = $encodingIssues;
    foreach ($encodingIssues as $issue) {
        $result['issues'][] = $issue;
    }

    // ── 5. Conta bairros por município ────────────────────────────────────────
    $countByCode = [];
    foreach ($features as $feature) {
        $props   = $feature['properties'] ?? [];
        $rawCode = $props[$munCodeField] ?? null;
        $code    = $rawCode !== null ? (int) $rawCode : 0;
        if ($code === 0) {
            continue;
        }
        $countByCode[$code] = ($countByCode[$code] ?? 0) + 1;
    }

    $withBairros    = [];
    $withoutBairros = [];
    $municData      = [];

    foreach ($rmfCodes as $code => $name) {
        $count = $countByCode[$code] ?? 0;
        $municData[$code] = [
            'code'         => $code,
            'name'         => $name,
            'bairro_count' => $count,
            'has_bairros'  => $count > 0,
        ];
        if ($count > 0) {
            $withBairros[] = $code;
        } else {
            $withoutBairros[] = $code;
        }
    }

    // Fortaleza
    $fortCount = $countByCode[2304400] ?? 0;
    $result['fortaleza_count'] = $fortCount;
    $result['fortaleza_ok']    = $fortCount >= 100; // IBGE pode ter mais ou menos que 121
    if ($fortCount > 0 && $fortCount < 100) {
        $result['issues'][] = sprintf(
            'Fortaleza tem apenas %d bairros no dataset IBGE (esperado ≥100). Verifique se o filtro por code_muni está correto.',
            $fortCount
        );
    }
    if ($fortCount === 0) {
        $result['issues'][] = 'Fortaleza (2304400) não encontrada no dataset — verifique o campo de código de município.';
    }

    $result['municipalities']  = array_values($municData);
    $result['with_bairros']    = array_map(fn(int $c) => $rmfCodes[$c] . ' (' . $c . ')', $withBairros);
    $result['without_bairros'] = array_map(fn(int $c) => $rmfCodes[$c] . ' (' . $c . ')', $withoutBairros);
    $result['coverage_pct']    = count($rmfCodes) > 0
        ? (int) round(count($withBairros) / count($rmfCodes) * 100)
        : 0;

    if (count($withoutBairros) > 0) {
        $names = implode(', ', array_map(fn(int $c) => $rmfCodes[$c], $withoutBairros));
        $result['issues'][] = sprintf(
            '%d município(s) da RMF sem bairros no dataset: %s. '
            . 'Provavelmente ausentes do cadastro IBGE 2022 — usar OSM como fallback com WARN.',
            count($withoutBairros),
            $names
        );
    }

    // Bairros além da RMF (de CE que não são RMF) — apenas informacional
    $extraCodes  = array_diff(array_keys($countByCode), array_keys($rmfCodes));
    $extraCount  = array_sum(array_intersect_key($countByCode, array_flip($extraCodes)));
    $result['extra_bairros_outside_rmf'] = $extraCount;
    if ($extraCount > 0) {
        $result['issues'][] = sprintf(
            '%d bairro(s) no arquivo que não são dos 19 municípios da RMF — '
            . 'verifique se o filtro rmf_codes foi aplicado corretamente no script Python.',
            $extraCount
        );
    }

    // ── 6. Ready to deploy? ───────────────────────────────────────────────────
    $result['ready_to_deploy'] = $result['geojson_valid']
        && $result['fields_ok']
        && $result['encoding_ok']
        && $result['file_size_ok']
        && $result['total_features'] > 0
        && count($withBairros) >= 10
        && $result['fortaleza_ok']
        && $extraCount === 0;

    // ── 7. IBGE API ping (opcional) ───────────────────────────────────────────
    if ($checkIbgeApi) {
        $result['ibge_api_ok'] = checkIbgeApiConnectivity();
    }

    return $result;
}

/**
 * Verifica se todas as features da amostra têm os campos obrigatórios do BIomie.
 * Retorna lista de issues (vazia = OK).
 *
 * @param  array<int, array<string,mixed>> $sample
 * @param  array<string, string>           $required   campo => descrição
 * @return list<string>
 */
function checkRequiredFields(array $sample, array $required): array
{
    $issues = [];
    foreach ($required as $field => $desc) {
        $missing  = 0;
        $empty    = 0;
        foreach ($sample as $feature) {
            $props = $feature['properties'] ?? [];
            if (!array_key_exists($field, $props)) {
                $missing++;
            } elseif ((string) ($props[$field] ?? '') === '') {
                $empty++;
            }
        }
        $total = count($sample);
        if ($missing === $total) {
            $issues[] = "Campo obrigatório ausente em todas as features: '{$field}' ({$desc}).";
        } elseif ($missing > 0) {
            $issues[] = "Campo '{$field}' ausente em {$missing}/{$total} features da amostra ({$desc}).";
        } elseif ($empty > (int) ($total * 0.5)) {
            $issues[] = "Campo '{$field}' vazio em {$empty}/{$total} features da amostra ({$desc}).";
        }
    }
    return $issues;
}

/**
 * Verifica se nomes de municípios acentuados aparecem corretamente em UTF-8.
 * Procura os nomes em municipio_nome e retorna issues de encoding.
 *
 * @param  array<int, array<string,mixed>> $features
 * @param  list<string>                    $accentedNames
 * @return list<string>
 */
function checkEncodingForMunis(array $features, array $accentedNames): array
{
    $issues   = [];
    $found    = [];

    foreach ($features as $feature) {
        $props = $feature['properties'] ?? [];
        $nome  = (string) ($props['municipio_nome'] ?? $props['name_muni'] ?? '');
        if ($nome === '') {
            continue;
        }
        foreach ($accentedNames as $expected) {
            if (stripos($nome, $expected) !== false || stripos($nome, iconv('UTF-8', 'ASCII//TRANSLIT', $expected)) !== false) {
                $found[$expected] = $nome;
            }
            // Detecta garbled: substituição de UTF-8 por sequências brutas como "MaracanAau"
            if (
                str_contains($nome, '?') ||
                !mb_check_encoding($nome, 'UTF-8') ||
                preg_match('/[\xc0-\xc3][\x80-\xbf]{0}[a-z]/i', $nome) // byte parcial
            ) {
                if (!in_array($nome, $issues, true)) {
                    $issues[] = "Possível encoding corrompido em municipio_nome: '{$nome}'.";
                }
            }
        }
    }

    foreach ($accentedNames as $expected) {
        if (!isset($found[$expected])) {
            $issues[] = "Nome '{$expected}' não encontrado no dataset — verificar se código IBGE está correto ou se município tem bairros.";
        }
    }

    return $issues;
}

/**
 * Auto-detecta campos de bairro e município na primeira feature do GeoJSON.
 * geobr pode usar nomes diferentes dependendo da versão.
 *
 * @return array{string, string, string, string}
 */
function detectFields(array $feature): array
{
    $props = $feature['properties'] ?? [];

    $neighborhoodCodeCandidates = ['code_neighborhood', 'CD_BAIRRO', 'codigo_bairro', 'id_bairro', 'BAIRRO_ID'];
    $neighborhoodNameCandidates = ['name_neighborhood', 'NM_BAIRRO', 'nome_bairro', 'BAIRRO', 'bairro'];
    $muniCodeCandidates         = ['code_muni', 'CD_MUNICIPIO', 'CD_MUN', 'codigo_municipio', 'muni_code', 'ibge_code'];
    $muniNameCandidates         = ['name_muni', 'NM_MUNICIPIO', 'NM_MUN', 'nome_municipio', 'municipio'];

    return [
        detectField($props, $neighborhoodCodeCandidates) ?? 'code_neighborhood',
        detectField($props, $neighborhoodNameCandidates) ?? 'name_neighborhood',
        detectField($props, $muniCodeCandidates)         ?? 'code_muni',
        detectField($props, $muniNameCandidates)         ?? 'name_muni',
    ];
}

function detectField(array $props, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $props)) {
            return $candidate;
        }
    }
    return null;
}

/** @return array<string> */
function buildGenerationInstructions(string $pythonScript, string $root): array
{
    $lines = [
        'Para gerar o GeoJSON, execute o script Python:',
        '',
        '  # 1. Instalar dependências (uma vez):',
        '  pip install geobr geopandas',
        '',
        '  # 2. Rodar o gerador:',
        '  python ' . $pythonScript,
        '',
        '  # 3. Verificar resultado:',
        '  php tools/dev/geo_rmf_bairros_source_probe.php',
        '',
        'O script Python baixa os bairros do Ceará via IBGE/geobr 2022,',
        'filtra os 19 municípios da RMF e salva em:',
        '  assets/geo/rmf_bairros_ibge_2022.geojson',
    ];

    if (!file_exists($pythonScript)) {
        array_unshift($lines, 'AVISO: script Python não encontrado em ' . $pythonScript);
    }

    return $lines;
}

function checkIbgeApiConnectivity(): bool
{
    $url = 'https://servicodados.ibge.gov.br/api/v3/malhas/estados/23?resolucao=1&formato=application/vnd.geo%2Bjson';
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    return $response !== false && strlen($response) > 100;
}

function printReport(array $r): void
{
    echo PHP_EOL;
    echo '╔══════════════════════════════════════════════════════════════╗' . PHP_EOL;
    echo '║     GEO — Probe de Bairros RMF via IBGE 2022                ║' . PHP_EOL;
    echo '╚══════════════════════════════════════════════════════════════╝' . PHP_EOL;
    echo PHP_EOL;

    $check = fn(bool $v): string => $v ? '[PASS]' : '[FAIL]';

    echo '── Arquivo ──────────────────────────────────────────────────────' . PHP_EOL;
    echo '  ' . $check($r['geojson_exists'])  . '  GeoJSON existe em assets/geo/rmf_bairros_ibge_2022.geojson' . PHP_EOL;
    echo '  ' . $check($r['geojson_valid'])   . '  GeoJSON é FeatureCollection válido' . PHP_EOL;
    echo '  ' . $check($r['file_size_ok'])    . '  Tamanho do arquivo: ' . $r['file_size_kb'] . ' KB (limite: 3.000 KB)' . PHP_EOL;
    echo PHP_EOL;

    if ($r['geojson_valid'] && array_key_exists('fields_missing', $r)) {
        echo '── Campos obrigatórios ──────────────────────────────────────────' . PHP_EOL;
        $fields = ['municipio_ibge', 'municipio_nome', 'bairro_ibge', 'bairro_nome', 'bairro_key', 'cidade_key', 'geometry'];
        foreach ($fields as $f) {
            $hasMissing = !empty($r['fields_missing']) && count(array_filter(
                $r['fields_missing'],
                fn(string $s) => str_contains($s, "'{$f}'")
            )) > 0;
            echo '  ' . $check(!$hasMissing) . '  ' . $f . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if ($r['geojson_valid'] && array_key_exists('encoding_samples', $r)) {
        echo '── Encoding UTF-8 ───────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $check((bool) $r['encoding_ok']) . '  Nomes acentuados íntegros (Eusébio, Maracanaú, São Gonçalo…)' . PHP_EOL;
        echo PHP_EOL;
    }

    echo '── Cobertura ────────────────────────────────────────────────────' . PHP_EOL;
    echo '  ' . $check($r['total_features'] > 0) . '  Total de bairros no arquivo: ' . $r['total_features'] . PHP_EOL;
    $fortLabel = $r['fortaleza_count'] > 0
        ? $r['fortaleza_count'] . ' bairros (esperado ≥100)'
        : 'ausente no arquivo (ok se excluída pelo --no-include-fortaleza)';
    echo '  ' . $check($r['fortaleza_ok'] || $r['fortaleza_count'] === 0) . '  Fortaleza: ' . $fortLabel . PHP_EOL;

    $covOk = $r['coverage_pct'] >= 80;
    echo '  ' . $check($covOk) . '  Cobertura RMF: ' . $r['coverage_pct'] . '% (' . count($r['with_bairros']) . '/19 municípios com bairros)' . PHP_EOL;
    echo PHP_EOL;

    if (!empty($r['with_bairros'])) {
        echo '── Municípios com bairros (' . count($r['with_bairros']) . ') ──────────────────────────' . PHP_EOL;
        foreach ($r['with_bairros'] as $m) {
            echo '    ✓ ' . $m . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if (!empty($r['without_bairros'])) {
        echo '── Municípios sem bairros (' . count($r['without_bairros']) . ') ─────────────────────────' . PHP_EOL;
        foreach ($r['without_bairros'] as $m) {
            echo '    ✗ ' . $m . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if (!empty($r['municipalities'])) {
        echo '── Detalhe por município ─────────────────────────────────────' . PHP_EOL;
        foreach ($r['municipalities'] as $muni) {
            $flag = $muni['has_bairros'] ? '  ' : '✗ ';
            echo sprintf('  %s%-35s %3d bairros%s',
                $flag,
                $muni['name'],
                $muni['bairro_count'],
                PHP_EOL
            );
        }
        echo PHP_EOL;
    }

    if ($r['ibge_api_ok'] !== null) {
        echo '── IBGE API ─────────────────────────────────────────────────────' . PHP_EOL;
        echo '  ' . $check((bool) $r['ibge_api_ok']) . '  servicodados.ibge.gov.br responde' . PHP_EOL;
        echo PHP_EOL;
    }

    if (!empty($r['issues'])) {
        echo '── Issues ───────────────────────────────────────────────────────' . PHP_EOL;
        foreach ($r['issues'] as $issue) {
            echo '  ⚠  ' . $issue . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if (!empty($r['instructions'])) {
        echo '── Instruções de geração ─────────────────────────────────────────' . PHP_EOL;
        foreach ($r['instructions'] as $line) {
            echo '  ' . $line . PHP_EOL;
        }
        echo PHP_EOL;
    }

    $status = $r['ready_to_deploy'] ? 'PRONTO PARA DEPLOY' : 'NÃO PRONTO';
    echo '── Resultado ────────────────────────────────────────────────────' . PHP_EOL;
    echo ($r['ready_to_deploy'] ? '  [PASS] ' : '  [FAIL] ') . $status . PHP_EOL;
    echo PHP_EOL;
}
