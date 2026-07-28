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

// Double-encoding patterns: legitimate Portuguese 'Ã' (C3 83) and 'Â' (C3 82) are fine.
// Real mojibake occurs when they are immediately followed by a second UTF-8 2-byte
// sequence starting with C2 (Latin Supplement range U+0080–U+00BF), e.g.:
//   Ã£ = double-encoded ã (C3 83 C2 A3)
//   Ã§ = double-encoded ç (C3 83 C2 A7)
//   Ã© = double-encoded é (C3 83 C2 A9)
// A bare C3 83 followed by an ASCII character is legitimate (e.g. CARTEIRIZAÇÃO).
$patterns = [
    'mojibake_double_enc_a_tilde'    => "\xC3\x83\xC2",
    'mojibake_double_enc_a_circumflex' => "\xC3\x82\xC2",
    'mojibake_dash_prefix'           => "\xC3\xA2\xE2\x82\xAC",
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
