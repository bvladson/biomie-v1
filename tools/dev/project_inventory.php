<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$json = in_array('--json', $argv, true);
$full = in_array('--full', $argv, true);

$rows = [];
$summary = [
    'produto' => 0,
    'operacao' => 0,
    'experimento' => 0,
    'legado' => 0,
    'temporario' => 0,
    'descartavel' => 0,
];
$topLevel = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $absolute = $item->getPathname();
    $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
    if ($relative === false || $relative === '') {
        continue;
    }

    if (str_starts_with($relative, '.git/')) {
        continue;
    }

    $classification = classifyPath($relative, $item->isDir());
    $summary[$classification] = ($summary[$classification] ?? 0) + 1;
    $rows[] = [
        'path' => $relative,
        'type' => $item->isDir() ? 'dir' : 'file',
        'classification' => $classification,
    ];

    $firstSegment = explode('/', $relative)[0] ?? $relative;
    if (!isset($topLevel[$firstSegment])) {
        $topLevel[$firstSegment] = [
            'path' => $firstSegment,
            'classification' => classifyPath($firstSegment, is_dir($root . DIRECTORY_SEPARATOR . $firstSegment)),
        ];
    }
}

usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

$payload = [
    'generated_at' => date(DATE_ATOM),
    'root' => $root,
    'summary' => $summary,
    'top_level' => array_values($topLevel),
    'rows' => $rows,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

echo 'Inventario do projeto BIomie' . PHP_EOL;
echo 'Gerado em: ' . $payload['generated_at'] . PHP_EOL . PHP_EOL;
foreach ($summary as $name => $total) {
    echo str_pad($name, 12) . ': ' . $total . PHP_EOL;
}

echo PHP_EOL . 'Raiz previsivel:' . PHP_EOL;
foreach ($payload['top_level'] as $row) {
    echo '[' . str_pad((string) $row['classification'], 11) . '] ' . $row['path'] . PHP_EOL;
}

if ($full) {
    echo PHP_EOL . 'Entradas classificadas:' . PHP_EOL;
    foreach ($rows as $row) {
        echo '[' . str_pad($row['classification'], 11) . '] ' . $row['path'] . PHP_EOL;
    }
} else {
    echo PHP_EOL . 'Use --full para listar todas as entradas ou --json para exportar o inventario completo.' . PHP_EOL;
}

/**
 * @return 'produto'|'operacao'|'experimento'|'legado'|'temporario'|'descartavel'
 */
function classifyPath(string $relativePath, bool $isDir): string
{
    $runtimePrefixes = ['app', 'api', 'assets', 'bin', 'config', 'cron', 'database', 'images', 'views'];
    foreach ($runtimePrefixes as $prefix) {
        if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
            return 'produto';
        }
    }

    if ($relativePath === 'docs/legacy' || str_starts_with($relativePath, 'docs/legacy/')) {
        return 'legado';
    }

    if ($relativePath === 'docs' || str_starts_with($relativePath, 'docs/')) {
        return 'operacao';
    }

    if ($relativePath === 'tools/debug' || str_starts_with($relativePath, 'tools/debug/')) {
        return 'experimento';
    }

    if (
        $relativePath === 'tools'
        || $relativePath === 'tools/dev'
        || $relativePath === 'tools/ops'
        || str_starts_with($relativePath, 'tools/dev/')
        || str_starts_with($relativePath, 'tools/ops/')
    ) {
        return 'operacao';
    }

    if ($relativePath === 'tests' || str_starts_with($relativePath, 'tests/')) {
        return 'operacao';
    }

    if ($relativePath === 'storage' || str_starts_with($relativePath, 'storage/')) {
        return 'temporario';
    }

    if ($relativePath === 'cronjobs' || str_starts_with($relativePath, 'cronjobs/')) {
        return 'operacao';
    }

    if (
        $relativePath === '.vscode'
        || $relativePath === '.claude'
        || str_starts_with($relativePath, '.vscode/')
        || str_starts_with($relativePath, '.claude/')
    ) {
        return 'experimento';
    }

    $rootProductFiles = [
        '.htaccess',
        'admin.php',
        'check.php',
        'composer.json',
        'financeiro.php',
        'healthz.php',
        'index.php',
        'integracoes.php',
        'login.php',
        'logout.php',
        'mercadorias.php',
        'observabilidade.php',
        'offline.html',
        'pedidos.php',
        'plano_voo.php',
        'plano_voo_metas.php',
        'reconciliacao.php',
        'site.webmanifest',
        'sw.js',
        'vendedores.php',
    ];
    if (in_array($relativePath, $rootProductFiles, true)) {
        return 'produto';
    }

    $rootLegacyRoutes = [
        'financeiro.html',
        'index.html',
        'integracoes.html',
        'mercadorias.html',
        'pedidos.html',
        'plano_voo.html',
        'reconciliacao.html',
        'vendedores.html',
    ];
    if (in_array($relativePath, $rootLegacyRoutes, true)) {
        return 'legado';
    }

    if (
        $relativePath === '.gitignore'
        || str_starts_with($relativePath, 'phpstan')
        || str_starts_with($relativePath, 'README_')
    ) {
        return 'operacao';
    }

    return $isDir ? 'operacao' : 'descartavel';
}
