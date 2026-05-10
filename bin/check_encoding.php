<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__);
$scanRoots = [
    $root . DIRECTORY_SEPARATOR . 'views',
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js',
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css',
    $root . DIRECTORY_SEPARATOR . 'app',
];

$allowedExtensions = ['html' => true, 'php' => true, 'js' => true, 'css' => true];
$excludedDirs = [
    DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
];

$patterns = [
    'mojibake_A_tilde' => "\xC3\x83",
    'mojibake_A_circumflex' => "\xC3\x82",
    'mojibake_dash_prefix' => "\xC3\xA2\xE2\x82\xAC",
];

$findings = [];

foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        if (basename($scanRoot) === 'app' && $fileInfo->getPath() !== $scanRoot) {
            continue;
        }

        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        foreach ($excludedDirs as $excludedDir) {
            if (str_contains($normalizedPath, $excludedDir)) {
                continue 2;
            }
        }

        $extension = strtolower($fileInfo->getExtension());
        if (!isset($allowedExtensions[$extension])) {
            continue;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }

        $relativePath = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $issues = [];
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $issues[] = 'BOM';
        }

        foreach ($patterns as $label => $pattern) {
            if (str_contains($contents, $pattern)) {
                $issues[] = $label;
            }
        }

        if ($issues !== []) {
            $findings[$relativePath] = $issues;
        }
    }
}

if ($findings === []) {
    echo "OK: nenhum BOM ou sequencia suspeita encontrada.\n";
    exit(0);
}

foreach ($findings as $path => $issues) {
    echo $path . ': ' . implode(', ', $issues) . "\n";
}

exit(1);
