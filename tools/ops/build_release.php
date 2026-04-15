<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$args = $argv;
array_shift($args);

$dryRun = in_array('--dry-run', $args, true);
$json = in_array('--json', $args, true);
$outputOption = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputOption = substr($arg, 9);
    }
}

$excludedPrefixes = [
    '.claude/',
    '.vscode/',
    'docs/',
    'storage/',
    'tests/',
    'tools/',
    'vendor/',
];

$excludedExact = [
    '.gitignore',
    'phpstan-baseline.neon',
    'phpstan-bootstrap.php',
    'phpstan.core.neon',
    'phpstan.neon',
    'phpstan.shared.neon',
    'README_AUTH.md',
    'README_CANONICAL.md',
    'README_MATRIZ_VERDADE_OMIE.md',
    'README_MIGRATIONS.md',
    'README_REFACTOR_CLASS_MAP.md',
];

$files = collectReleaseFiles($root, $excludedPrefixes, $excludedExact);
$releaseName = 'biomie-release-' . date('Ymd-His');
$outputDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$zipPath = $outputOption !== null && $outputOption !== ''
    ? normalizeOutputPath($outputOption, $root)
    : $outputDir . DIRECTORY_SEPARATOR . $releaseName . '.zip';
$manifestPath = preg_replace('/\.zip$/i', '.manifest.json', $zipPath) ?: ($zipPath . '.manifest.json');

$manifest = [
    'generated_at' => date(DATE_ATOM),
    'root' => $root,
    'zip_path' => $zipPath,
    'dry_run' => $dryRun,
    'included_files_total' => count($files),
    'included_top_level' => summarizeTopLevel($files),
    'excluded_prefixes' => $excludedPrefixes,
    'excluded_exact' => $excludedExact,
    'files' => $files,
    'runtime_empty_dirs' => [
        'storage/cache',
        'storage/cache/jobs',
        'storage/logs',
    ],
];

if ($dryRun) {
    outputManifest($manifest, $json);
    exit(0);
}

if (!class_exists('ZipArchive')) {
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
    fwrite(STDERR, 'ZipArchive nao disponivel. Manifesto gravado em ' . $manifestPath . PHP_EOL);
    exit(2);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, 'Nao foi possivel criar o pacote em ' . $zipPath . PHP_EOL);
    exit(3);
}

foreach ($manifest['runtime_empty_dirs'] as $dir) {
    $zip->addEmptyDir(str_replace('\\', '/', $dir));
}

foreach ($files as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $zip->addFile($absolutePath, $relativePath);
}

$zip->close();
file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

outputManifest($manifest, $json);

/**
 * @param list<string> $excludedPrefixes
 * @param list<string> $excludedExact
 * @return list<string>
 */
function collectReleaseFiles(string $root, array $excludedPrefixes, array $excludedExact): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        if ($relativePath === false || $relativePath === '') {
            continue;
        }

        if (shouldExclude($relativePath, $excludedPrefixes, $excludedExact)) {
            continue;
        }

        $files[] = $relativePath;
    }

    sort($files, SORT_STRING);
    return $files;
}

/**
 * @param list<string> $excludedPrefixes
 * @param list<string> $excludedExact
 */
function shouldExclude(string $relativePath, array $excludedPrefixes, array $excludedExact): bool
{
    if (in_array($relativePath, $excludedExact, true)) {
        return true;
    }

    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $files
 * @return array<string,int>
 */
function summarizeTopLevel(array $files): array
{
    $summary = [];
    foreach ($files as $relativePath) {
        $segments = explode('/', $relativePath);
        $topLevel = $segments[0] ?? $relativePath;
        $summary[$topLevel] = ($summary[$topLevel] ?? 0) + 1;
    }

    ksort($summary, SORT_STRING);
    return $summary;
}

function normalizeOutputPath(string $outputOption, string $root): string
{
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputOption);
    if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1 || str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
        return $normalized;
    }

    return $root . DIRECTORY_SEPARATOR . $normalized;
}

/**
 * @param array<string,mixed> $manifest
 */
function outputManifest(array $manifest, bool $json): void
{
    if ($json) {
        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    echo 'Pacote BIomie' . PHP_EOL;
    echo 'Gerado em: ' . $manifest['generated_at'] . PHP_EOL;
    echo 'Arquivos incluidos: ' . $manifest['included_files_total'] . PHP_EOL;
    echo 'Dry-run: ' . ($manifest['dry_run'] ? 'sim' : 'nao') . PHP_EOL;
    echo 'Saida: ' . $manifest['zip_path'] . PHP_EOL . PHP_EOL;
    echo 'Top-level incluídos:' . PHP_EOL;

    foreach ($manifest['included_top_level'] as $topLevel => $total) {
        echo '- ' . $topLevel . ': ' . $total . PHP_EOL;
    }
}
