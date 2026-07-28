<?php

declare(strict_types=1);

/**
 * GEO.R2 static acceptance probe.
 *
 * This probe is intentionally read-only and tolerant while GEO.R2 is being
 * implemented in parallel:
 *
 *   php tools/dev/geo_r2_acceptance_probe.php
 *   php tools/dev/geo_r2_acceptance_probe.php --json
 *   php tools/dev/geo_r2_acceptance_probe.php --list
 *   php tools/dev/geo_r2_acceptance_probe.php --strict
 *
 * Default mode exits with zero when criteria are still PENDING. Strict mode
 * exits with one until every criterion is PASS.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$args = parseGeoR2Args(array_slice($GLOBALS['argv'] ?? [], 1));
$json = isset($args['json']) || (($args['format'] ?? '') === 'json');
$strict = isset($args['strict']);
$listOnly = isset($args['list']);

$criteriaCatalog = [
    'GEO.R2.C01' => 'Cidade nula nao entra em filtro normal de cidade.',
    'GEO.R2.C02' => 'Bucket sem_geo permanece explicito no backend e no frontend.',
    'GEO.R2.C03' => 'Apenas um writer oficial escreve mart_compradores_por_geo no pipeline.',
    'GEO.R2.C04' => 'Existe somente um filtro de vendedor na pagina.',
    'GEO.R2.C05' => 'Existe somente uma barra ou superficie de modos do mapa.',
    'GEO.R2.C06' => 'Preset Este ano representa janeiro ate hoje.',
    'GEO.R2.C07' => 'Diagnostico tecnico e admin-only ou apresentado como cobertura comercial.',
    'GEO.R2.C08' => 'Defaults de geo_readmodel_v2 e geo_single_payload estao ativos.',
    'GEO.R2.C09' => 'Plugins avancados do mapa sao carregados sob demanda.',
    'GEO.R2.C10' => 'Cobertura geografica usa linguagem comercial para o usuario.',
];

if ($listOnly) {
    foreach ($criteriaCatalog as $id => $title) {
        echo "{$id} - {$title}" . PHP_EOL;
    }
    exit(0);
}

$required = [
    'geo_repository' => 'app/DataPlatform/Repository/GeoReadRepository.php',
    'geo_360_repository' => 'app/Modules/Sales/Infrastructure/Geo360ReadRepository.php',
    'geo_sales_builder' => 'app/Geo/GeoSalesLayerBuilder.php',
    'customer_detail_builder' => 'app/Modules/Customers/Application/CustomerDetail/CustomerDetailBuilder.php',
    'map_controller' => 'mapa_vendas.php',
    'map_view' => 'views/mapa_vendas.html',
    'map_js' => 'assets/js/biomie-map.js',
    'map_panel_js' => 'assets/js/biomie-map-panel.js',
    'env_config' => 'config/env.php',
];

$sources = [];
foreach ($required as $key => $relativePath) {
    $sources[$key] = readGeoR2File($root, $relativePath);
}

$checks = [];

// C01 - A normal city filter must never absorb rows with an empty city.
$normalCityReaders = [
    'app/DataPlatform/Repository/GeoReadRepository.php',
    'app/Modules/Sales/Infrastructure/Geo360ReadRepository.php',
    'app/Geo/GeoSalesLayerBuilder.php',
    'app/Modules/Customers/Application/CustomerDetail/CustomerDetailBuilder.php',
];
$cityConflations = [];
foreach ($normalCityReaders as $relativePath) {
    $content = readGeoR2File($root, $relativePath);
    if ($content === null) {
        continue;
    }
    foreach (findGeoR2CityConflations($content) as $line) {
        $cityConflations[] = "{$relativePath}:{$line}";
    }
}
addGeoR2Check(
    $checks,
    'GEO.R2.C01',
    $criteriaCatalog['GEO.R2.C01'],
    countExistingGeoR2Sources($root, $normalCityReaders) === count($normalCityReaders)
        ? ($cityConflations === [] ? 'PASS' : 'PENDING')
        : 'ERROR',
    $cityConflations === []
        ? 'Nenhum filtro normal combina cidade selecionada com cidade nula/vazia.'
        : 'Ainda existem filtros normais que absorvem cidade nula/vazia.',
    $cityConflations
);

// C02 - The unidentified-location bucket must survive the refactor.
$productionPhp = collectGeoR2Files($root, ['app', 'api'], ['php']);
$mapFrontFiles = collectGeoR2Files($root, ['assets/js', 'views'], ['js', 'html']);
$semGeoBackend = findGeoR2TokenLocations($productionPhp, 'sem_geo');
$semGeoFrontend = findGeoR2TokenLocations($mapFrontFiles, 'sem_geo');
$semGeoOk = $semGeoBackend !== [] && $semGeoFrontend !== [];
addGeoR2Check(
    $checks,
    'GEO.R2.C02',
    $criteriaCatalog['GEO.R2.C02'],
    ($productionPhp === [] || $mapFrontFiles === []) ? 'ERROR' : ($semGeoOk ? 'PASS' : 'PENDING'),
    $semGeoOk
        ? 'Bucket sem_geo possui contrato nos dois lados da aplicacao.'
        : 'Bucket sem_geo precisa continuar explicito no backend e no frontend.',
    array_slice(array_merge($semGeoBackend, $semGeoFrontend), 0, 8)
);

// C03 - Detect write-capable classes and whether more than one is wired in app/.
$appPhp = collectGeoR2Files($root, ['app'], ['php']);
$writers = detectGeoR2MartWriters($appPhp, 'mart_compradores_por_geo');
$activeWriters = [];
foreach ($writers as $writer) {
    $references = findGeoR2ClassReferences($appPhp, $writer['class'], $writer['path']);
    if ($references !== []) {
        $activeWriters[$writer['class']] = $references;
    }
}
$writerEvidence = [];
foreach ($activeWriters as $class => $references) {
    $writerEvidence[] = $class . ' <- ' . implode(', ', array_slice($references, 0, 4));
}
addGeoR2Check(
    $checks,
    'GEO.R2.C03',
    $criteriaCatalog['GEO.R2.C03'],
    $appPhp === [] ? 'ERROR' : (count($activeWriters) === 1 ? 'PASS' : 'PENDING'),
    count($activeWriters) === 1
        ? 'Um unico writer com capacidade de escrita esta ligado ao pipeline.'
        : 'Foram detectados ' . count($activeWriters) . ' writers com capacidade de escrita ligados ao pipeline.',
    $writerEvidence !== [] ? $writerEvidence : ['Writers detectados: ' . count($writers)]
);

// C04 - Only one seller filter control is allowed.
$mapView = $sources['map_view'];
$sellerFilterIds = $mapView === null ? [] : findGeoR2SellerFilterIds($mapView);
addGeoR2Check(
    $checks,
    'GEO.R2.C04',
    $criteriaCatalog['GEO.R2.C04'],
    $mapView === null ? 'ERROR' : (count($sellerFilterIds) === 1 ? 'PASS' : 'PENDING'),
    count($sellerFilterIds) === 1
        ? 'A pagina possui um unico filtro de vendedor.'
        : 'A pagina possui ' . count($sellerFilterIds) . ' controles de filtro de vendedor.',
    $sellerFilterIds
);

// C05 - A select and a chip bar count as two competing mode surfaces.
$modeSurfaces = $mapView === null ? [] : findGeoR2ModeSurfaces($mapView);
addGeoR2Check(
    $checks,
    'GEO.R2.C05',
    $criteriaCatalog['GEO.R2.C05'],
    $mapView === null ? 'ERROR' : (count($modeSurfaces) === 1 ? 'PASS' : 'PENDING'),
    count($modeSurfaces) === 1
        ? 'Existe uma unica superficie para trocar o modo do mapa.'
        : 'Existem ' . count($modeSurfaces) . ' superficies concorrentes para trocar o modo.',
    $modeSurfaces
);

// C06 - Follow the click handler to its preset function and verify YTD semantics.
$mapJs = $sources['map_js'];
$yearPreset = $mapJs === null ? ['ok' => false, 'evidence' => ['assets/js/biomie-map.js ausente']] : inspectGeoR2YearPreset($mapJs);
addGeoR2Check(
    $checks,
    'GEO.R2.C06',
    $criteriaCatalog['GEO.R2.C06'],
    $mapJs === null ? 'ERROR' : ($yearPreset['ok'] ? 'PASS' : 'PENDING'),
    $yearPreset['ok']
        ? 'O preset Este ano inicia em 01/01 e termina na data atual.'
        : 'Nao foi possivel confirmar estaticamente o comportamento janeiro-ate-hoje.',
    $yearPreset['evidence']
);

// C07 - Technical diagnostics can remain only behind an admin marker; otherwise
// the visible summary must be commercial.
$mapController = $sources['map_controller'];
$diagnostics = inspectGeoR2Diagnostics($mapView, $mapController);
addGeoR2Check(
    $checks,
    'GEO.R2.C07',
    $criteriaCatalog['GEO.R2.C07'],
    ($mapView === null || $mapController === null) ? 'ERROR' : ($diagnostics['ok'] ? 'PASS' : 'PENDING'),
    $diagnostics['message'],
    $diagnostics['evidence']
);

// C08 - Read model and single payload must be enabled by default.
$envConfig = $sources['env_config'];
$flagEvidence = [];
$flagsActive = true;
foreach ([
    'BIOMIE_GEO_READMODEL_V2' => 'geo_readmodel_v2',
    'BIOMIE_GEO_SINGLE_PAYLOAD' => 'geo_single_payload',
] as $envName => $configName) {
    $active = $envConfig !== null && geoR2FlagDefaultsActive($envConfig, $envName, $configName);
    $flagsActive = $flagsActive && $active;
    $flagEvidence[] = "{$configName}: " . ($active ? 'default ativo' : 'default ainda inativo/nao detectado');
}
addGeoR2Check(
    $checks,
    'GEO.R2.C08',
    $criteriaCatalog['GEO.R2.C08'],
    $envConfig === null ? 'ERROR' : ($flagsActive ? 'PASS' : 'PENDING'),
    $flagsActive
        ? 'Read model e payload unico iniciam ativos por padrao.'
        : 'Um ou mais defaults GEO ainda nao iniciam ativos.',
    $flagEvidence
);

// C09 - Advanced Leaflet plugins must not be eager in the page.
$mapJsFiles = collectGeoR2Files($root, ['assets/js'], ['js']);
$lazyPlugins = inspectGeoR2LazyPlugins($mapView, $mapJsFiles);
addGeoR2Check(
    $checks,
    'GEO.R2.C09',
    $criteriaCatalog['GEO.R2.C09'],
    $mapView === null ? 'ERROR' : ($lazyPlugins['ok'] ? 'PASS' : 'PENDING'),
    $lazyPlugins['message'],
    $lazyPlugins['evidence']
);

// C10 - Visible copy should explain coverage in business language.
$commercialLanguage = inspectGeoR2CommercialCoverageLanguage($mapView, $diagnostics['admin_restricted']);
addGeoR2Check(
    $checks,
    'GEO.R2.C10',
    $criteriaCatalog['GEO.R2.C10'],
    $mapView === null ? 'ERROR' : ($commercialLanguage['ok'] ? 'PASS' : 'PENDING'),
    $commercialLanguage['message'],
    $commercialLanguage['evidence']
);

$counts = ['PASS' => 0, 'PENDING' => 0, 'ERROR' => 0];
foreach ($checks as $check) {
    $counts[$check['status']]++;
}

$ready = $counts['PENDING'] === 0 && $counts['ERROR'] === 0;
$payload = [
    'ok' => $counts['ERROR'] === 0,
    'ready' => $ready,
    'mode' => $strict ? 'strict' : 'tolerant',
    'summary' => $counts,
    'checks' => $checks,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} else {
    echo PHP_EOL . 'GEO.R2 - Static Acceptance Probe' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    foreach ($checks as $check) {
        echo '[' . $check['status'] . '] ' . $check['id'] . ' - ' . $check['title'] . PHP_EOL;
        echo '  ' . $check['message'] . PHP_EOL;
        foreach ($check['evidence'] as $evidence) {
            echo '  - ' . $evidence . PHP_EOL;
        }
    }
    echo PHP_EOL . sprintf(
        'Resultado: %d PASS / %d PENDING / %d ERROR | modo %s',
        $counts['PASS'],
        $counts['PENDING'],
        $counts['ERROR'],
        $strict ? 'strict' : 'tolerant'
    ) . PHP_EOL;
    echo $ready
        ? '[PASS] GEO.R2 pronto para aceitacao.' . PHP_EOL
        : ($strict
            ? '[BLOCKED] Criterios pendentes impedem a aceitacao strict.' . PHP_EOL
            : '[OBSERVE] Pendencias registradas sem bloquear o trabalho paralelo.' . PHP_EOL);
}

if ($counts['ERROR'] > 0 || ($strict && !$ready)) {
    exit(1);
}
exit(0);

/**
 * @param list<string> $raw
 * @return array<string,string>
 */
function parseGeoR2Args(array $raw): array
{
    $args = [];
    foreach ($raw as $item) {
        $item = ltrim(trim($item), '-');
        if ($item === '') {
            continue;
        }
        if (str_contains($item, '=')) {
            [$key, $value] = explode('=', $item, 2);
            $args[str_replace('-', '_', $key)] = strtolower(trim($value));
        } else {
            $args[str_replace('-', '_', $item)] = '1';
        }
    }
    return $args;
}

function readGeoR2File(string $root, string $relativePath): ?string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * @param list<string> $relativePaths
 */
function countExistingGeoR2Sources(string $root, array $relativePaths): int
{
    $count = 0;
    foreach ($relativePaths as $relativePath) {
        if (is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
            $count++;
        }
    }
    return $count;
}

/**
 * @param list<string> $roots
 * @param list<string> $extensions
 * @return array<string,string>
 */
function collectGeoR2Files(string $root, array $roots, array $extensions): array
{
    $files = [];
    foreach ($roots as $relativeRoot) {
        $absoluteRoot = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
        if (!is_dir($absoluteRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relativePath] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);
    return $files;
}

/**
 * @param array<string,string> $files
 * @return list<string>
 */
function findGeoR2TokenLocations(array $files, string $token): array
{
    $locations = [];
    foreach ($files as $path => $content) {
        $offset = stripos($content, $token);
        if ($offset === false) {
            continue;
        }
        $locations[] = $path . ':' . geoR2LineNumber($content, $offset);
    }
    return $locations;
}

/**
 * @return list<int>
 */
function findGeoR2CityConflations(string $content): array
{
    $lines = [];
    if (!preg_match_all('/:[a-z_]*city\b/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[0] as $match) {
        $offset = (int) $match[1];
        $window = substr($content, max(0, $offset - 320), 760);
        $hasCityField = preg_match('/cidade_norm/i', $window) === 1;
        $hasEmptyAbsorption = preg_match(
            '/cidade_norm\s+IS\s+NULL|TRIM\s*\([^)]*cidade_norm[^)]*\)\s*=\s*[\'"]{2}/i',
            $window
        ) === 1;
        if ($hasCityField && $hasEmptyAbsorption) {
            $lines[] = geoR2LineNumber($content, $offset);
        }
    }
    return array_values(array_unique($lines));
}

function geoR2LineNumber(string $content, int $offset): int
{
    return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
}

/**
 * @param array<string,string> $files
 * @return list<array{class:string,path:string}>
 */
function detectGeoR2MartWriters(array $files, string $table): array
{
    $writers = [];
    foreach ($files as $path => $content) {
        $hasDelete = preg_match('/\bDELETE\s+FROM\s+`?' . preg_quote($table, '/') . '`?/i', $content) === 1;
        $hasInsert = preg_match('/\bINSERT\s+INTO\s+`?' . preg_quote($table, '/') . '`?/i', $content) === 1;
        if (!$hasDelete || !$hasInsert || preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/i', $content, $class) !== 1) {
            continue;
        }
        $writers[] = ['class' => $class[1], 'path' => $path];
    }
    return $writers;
}

/**
 * @param array<string,string> $files
 * @return list<string>
 */
function findGeoR2ClassReferences(array $files, string $class, string $classPath): array
{
    $references = [];
    foreach ($files as $path => $content) {
        if ($path === $classPath) {
            continue;
        }
        if (
            preg_match('/\bnew\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\)*' . preg_quote($class, '/') . '\b/', $content) === 1
            || preg_match('/\buse\s+[^;\\\\]*\\\\' . preg_quote($class, '/') . '\s*;/', $content) === 1
            || preg_match('/\b' . preg_quote($class, '/') . '\s+\$[A-Za-z_]/', $content) === 1
        ) {
            $references[] = $path;
        }
    }
    return array_values(array_unique($references));
}

/**
 * @return list<string>
 */
function findGeoR2SellerFilterIds(string $html): array
{
    $ids = [];
    preg_match_all('/<(?:input|select)[^>]*\bid\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
    foreach ($matches[1] ?? [] as $id) {
        if (preg_match('/(?:vendedor|seller).*filter|filter.*(?:vendedor|seller)/i', $id) === 1) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @return list<string>
 */
function findGeoR2ModeSurfaces(string $html): array
{
    $surfaces = [];
    if (preg_match('/<select[^>]*\bid\s*=\s*["\'][^"\']*(?:map)?mode[^"\']*["\'][^>]*>/i', $html, $select) === 1 && !geoR2TagHidden($select[0])) {
        $surfaces[] = 'seletor de modos';
    }
    $hasChipBar = preg_match('/<(?:div|nav)[^>]*(?:id\s*=\s*["\'][^"\']*ModeChips[^"\']*["\']|class\s*=\s*["\'][^"\']*geo-mode-bar[^"\']*["\'])[^>]*>/i', $html, $chips) === 1;
    if ($hasChipBar && !geoR2TagHidden($chips[0])) {
        $surfaces[] = 'barra de modos';
    }
    return array_values(array_unique($surfaces));
}

function geoR2TagHidden(string $tag): bool
{
    return preg_match('/\bhidden\b|aria-hidden\s*=\s*["\']true["\']|display\s*:\s*none|data-deprecated/i', $tag) === 1;
}

/**
 * @return array{ok:bool,evidence:list<string>}
 */
function inspectGeoR2YearPreset(string $js): array
{
    $offset = strpos($js, 'geoPresetThisYear');
    if ($offset === false) {
        return ['ok' => false, 'evidence' => ['Handler geoPresetThisYear nao encontrado.']];
    }
    $handlerWindow = substr($js, $offset, 700);
    preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*(?:Year|Month|Date|Preset)[A-Za-z0-9_]*)\s*\(/', $handlerWindow, $calls);
    $candidateBodies = [$handlerWindow];
    $functions = [];
    foreach ($calls[1] ?? [] as $function) {
        $body = extractGeoR2FunctionBody($js, $function);
        if ($body !== null) {
            $candidateBodies[] = $body;
            $functions[] = $function;
        }
    }
    $combined = implode("\n", $candidateBodies);
    $startsAtJanuary = preg_match('/-01-01|startOfYear|yearToDate|thisYear/i', $combined) === 1;
    $endsToday = preg_match('/getDate\s*\(|new\s+Date\s*\(|today|endOfToday|toISOString/i', $combined) === 1;

    return [
        'ok' => $startsAtJanuary && $endsToday,
        'evidence' => [
            'Funcoes inspecionadas: ' . ($functions === [] ? 'handler inline' : implode(', ', array_unique($functions))),
            'Inicio em janeiro: ' . ($startsAtJanuary ? 'sim' : 'nao detectado'),
            'Fim na data atual: ' . ($endsToday ? 'sim' : 'nao detectado'),
        ],
    ];
}

function extractGeoR2FunctionBody(string $js, string $function): ?string
{
    if (preg_match('/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)\s*\{/i', $js, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $start = (int) $match[0][1] + strlen($match[0][0]) - 1;
    $depth = 0;
    $length = strlen($js);
    for ($i = $start; $i < $length; $i++) {
        if ($js[$i] === '{') {
            $depth++;
        } elseif ($js[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($js, $start + 1, $i - $start - 1);
            }
        }
    }
    return null;
}

/**
 * @return array{ok:bool,admin_restricted:bool,message:string,evidence:list<string>}
 */
function inspectGeoR2Diagnostics(?string $html, ?string $controller): array
{
    if ($html === null || $controller === null) {
        return [
            'ok' => false,
            'admin_restricted' => false,
            'message' => 'Fontes necessarias para avaliar diagnosticos nao estao disponiveis.',
            'evidence' => [],
        ];
    }
    $diagnosticBlock = '';
    $diagnosticTag = '';
    if (
        preg_match(
            '/(<details[^>]*(?:technical|diagnostic)[^>]*>)(.*?)<\/details>/is',
            $html,
            $diagnosticMatch
        ) === 1
    ) {
        $diagnosticTag = $diagnosticMatch[1];
        $diagnosticBlock = $diagnosticMatch[0];
    }
    $normalized = normalizeGeoR2Text($diagnosticBlock);
    $hasDiagnostics = $diagnosticBlock !== ''
        || str_contains(normalizeGeoR2Text($html), 'diagnostico tecnico');
    if (!$hasDiagnostics) {
        return [
            'ok' => true,
            'admin_restricted' => false,
            'message' => 'Nao existe diagnostico tecnico visivel na pagina comercial.',
            'evidence' => ['Diagnostico tecnico ausente da pagina comercial.'],
        ];
    }
    $hasAdminMarker = preg_match('/admin-only|data-[^=\s>]*admin/i', $diagnosticTag) === 1;
    $hiddenByDefault = preg_match('/\bhidden\b|aria-hidden\s*=\s*["\']true["\']/i', $diagnosticTag) === 1;
    $controllerHasAdminContext = preg_match('/is_admin|isAdmin|Auth::isAdmin\s*\(/i', $controller) === 1;
    $adminRestricted = $hasAdminMarker && $hiddenByDefault && $controllerHasAdminContext;
    $summary = '';
    if (preg_match('/<summary[^>]*>(.*?)<\/summary>/is', $diagnosticBlock, $summaryMatch) === 1) {
        $summary = normalizeGeoR2Text(strip_tags($summaryMatch[1]));
    }
    $commercialSummary = preg_match('/cobertura|confiabilidade|qualidade (?:dos dados|das localizacoes)|dados do mapa/i', $summary) === 1
        && !str_contains($summary, 'diagnostico tecnico');

    return [
        'ok' => $adminRestricted || $commercialSummary,
        'admin_restricted' => $adminRestricted,
        'message' => $adminRestricted
            ? 'Diagnostico tecnico esta marcado para administracao e oculto por padrao.'
            : ($commercialSummary
                ? 'Diagnostico foi apresentado ao usuario com titulo comercial.'
                : 'Diagnostico tecnico ainda aparece sem restricao administrativa ou titulo comercial.'),
        'evidence' => [
            'Restricao admin detectada: ' . ($adminRestricted ? 'sim' : 'nao'),
            'Marcador admin no bloco: ' . ($hasAdminMarker ? 'sim' : 'nao'),
            'Oculto por padrao: ' . ($hiddenByDefault ? 'sim' : 'nao'),
            'Controller fornece contexto admin: ' . ($controllerHasAdminContext ? 'sim' : 'nao'),
            'Titulo comercial detectado: ' . ($commercialSummary ? 'sim' : 'nao'),
            'Summary atual: ' . ($summary !== '' ? $summary : 'nao encontrado'),
        ],
    ];
}

function geoR2FlagDefaultsActive(string $config, string $envName, string $configName): bool
{
    if (preg_match('/[\'"]' . preg_quote($configName, '/') . '[\'"]\s*=>\s*true\b/i', $config) === 1) {
        return true;
    }
    if (
        preg_match(
            '/env\s*\(\s*[\'"]' . preg_quote($envName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/i',
            $config,
            $match
        ) === 1
    ) {
        return in_array(strtolower(trim($match[1])), ['1', 'true', 'yes', 'y', 'sim', 'on'], true);
    }
    return false;
}

/**
 * @param array<string,string> $jsFiles
 * @return array{ok:bool,message:string,evidence:list<string>}
 */
function inspectGeoR2LazyPlugins(?string $html, array $jsFiles): array
{
    if ($html === null) {
        return ['ok' => false, 'message' => 'View do mapa indisponivel.', 'evidence' => []];
    }
    $pluginPattern = '/leaflet(?:[.-])(?:heat|draw)|leaflet\.heat|leaflet\.draw/i';
    preg_match_all('/<(?:script|link)[^>]+(?:leaflet(?:[.-])(?:heat|draw)|leaflet\.heat|leaflet\.draw)[^>]*>/i', $html, $eagerMatches);
    $eager = array_values(array_filter(
        $eagerMatches[0] ?? [],
        static fn (string $tag): bool => preg_match('/data-lazy|data-on-demand|type\s*=\s*["\']lazy["\']/i', $tag) !== 1
    ));

    $advancedPresent = preg_match('/heatmap|heatLayer|areaSelection|Control\.Draw|leaflet\.draw/i', implode("\n", $jsFiles)) === 1;
    $lazyLocations = [];
    foreach ($jsFiles as $path => $content) {
        if (preg_match_all($pluginPattern, $content, $matches, PREG_OFFSET_CAPTURE) !== 1 && ($matches[0] ?? []) === []) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $window = substr($content, max(0, $offset - 700), 1400);
            if (preg_match('/createElement\s*\(\s*[\'"]script|import\s*\(|loadScript|ensure[A-Za-z]*(?:Heat|Draw|Plugin)|lazy|onDemand/i', $window) === 1) {
                $lazyLocations[] = $path . ':' . geoR2LineNumber($content, $offset);
            }
        }
    }

    $ok = $eager === [] && (!$advancedPresent || $lazyLocations !== []);
    return [
        'ok' => $ok,
        'message' => $ok
            ? 'Plugins avancados nao bloqueiam o carregamento inicial.'
            : 'Plugins avancados ainda sao carregados cedo ou nao possuem loader lazy detectavel.',
        'evidence' => [
            'Tags eager encontradas: ' . count($eager),
            'Modos avancados detectados: ' . ($advancedPresent ? 'sim' : 'nao'),
            'Loaders lazy detectados: ' . count(array_unique($lazyLocations)),
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,evidence:list<string>}
 */
function inspectGeoR2CommercialCoverageLanguage(?string $html, bool $adminRestricted): array
{
    if ($html === null) {
        return ['ok' => false, 'message' => 'View do mapa indisponivel.', 'evidence' => []];
    }
    if ($adminRestricted) {
        $html = preg_replace('/<details[^>]*technical[^>]*>.*?<\/details>/is', '', $html) ?? $html;
    }
    $html = preg_replace('/<!--.*?-->|<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
    $visible = normalizeGeoR2Text(strip_tags($html));

    $commercialTerms = [];
    foreach (['cobertura', 'localizacao', 'endereco', 'territorio', 'oportunidade', 'faturamento'] as $term) {
        if (str_contains($visible, $term)) {
            $commercialTerms[] = $term;
        }
    }
    $technicalTerms = [];
    foreach (['geojson', 'endpoint', 'status http', 'tabela gold', 'mart', 'materializ', 'canonical', 'diagnostico tecnico', 'migration'] as $term) {
        if (str_contains($visible, $term)) {
            $technicalTerms[] = $term;
        }
    }
    $hasCoverageLanguage = in_array('cobertura', $commercialTerms, true)
        && (in_array('localizacao', $commercialTerms, true) || in_array('endereco', $commercialTerms, true));
    $ok = $hasCoverageLanguage && $technicalTerms === [];

    return [
        'ok' => $ok,
        'message' => $ok
            ? 'A pagina explica cobertura com termos comerciais e sem jargao tecnico visivel.'
            : 'A linguagem de cobertura ainda precisa ficar mais comercial e direta.',
        'evidence' => [
            'Termos comerciais: ' . ($commercialTerms === [] ? 'nenhum' : implode(', ', $commercialTerms)),
            'Termos tecnicos visiveis: ' . ($technicalTerms === [] ? 'nenhum' : implode(', ', $technicalTerms)),
        ],
    ];
}

function normalizeGeoR2Text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii)) {
            $text = $ascii;
        }
    }
    $text = strtolower($text);
    return preg_replace('/\s+/', ' ', $text) ?? $text;
}

/**
 * @param list<array<string,mixed>> $checks
 * @param list<string> $evidence
 */
function addGeoR2Check(
    array &$checks,
    string $id,
    string $title,
    string $status,
    string $message,
    array $evidence = []
): void {
    $checks[] = [
        'id' => $id,
        'title' => $title,
        'status' => $status,
        'message' => $message,
        'evidence' => array_values(array_unique(array_filter($evidence, static fn ($item): bool => trim((string) $item) !== ''))),
    ];
}
