<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';

use BIomie\Bootstrap;

Bootstrap::init();

$routes = [
    'system_health.php',
    'metric_catalog.php',
    'cockpit_interno.php',
    'index.php',
    'mapa_vendas.php',
    'vendedores.php',
    'pedidos.php',
    'mercadorias.php',
];
$asJson = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } elseif (str_starts_with($arg, '--route=')) {
        $routes = [substr($arg, 8)];
    }
}

$pages = [];
$failed = false;
foreach ($routes as $route) {
    $started = microtime(true);
    $path = Bootstrap::rootPath($route);
    $error = null;
    $status = 'ok';
    if (!is_file($path)) {
        $status = 'failed';
        $error = 'Arquivo nao encontrado.';
    } else {
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        if ($code !== 0) {
            $status = 'failed';
            $error = implode("\n", $output);
        }
    }
    if ($status === 'failed') {
        $failed = true;
    }
    $pages[] = [
        'page' => $route,
        'status' => $status,
        'error' => $error,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}

$payload = ['ok' => !$failed, 'pages' => $pages];
if ($asJson) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    foreach ($pages as $page) {
        printf("%-24s %-7s %s\n", $page['page'], $page['status'], $page['error'] ?? '');
    }
}

exit($failed ? 1 : 0);
