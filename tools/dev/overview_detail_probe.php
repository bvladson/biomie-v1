<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap.php';
require_once __DIR__ . '/../../app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php';

use BIomie\Bootstrap;
use BIomie\Modules\Sales\Application\Overview\OverviewDetailBuilder;

Bootstrap::init();

$start = '';
$end = '';
$asJson = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--start=')) {
        $start = substr($arg, 8);
    } elseif (str_starts_with($arg, '--end=')) {
        $end = substr($arg, 6);
    } elseif ($arg === '--json') {
        $asJson = true;
    }
}

$start = $start !== '' ? $start : date('Y-m-01');
$end = $end !== '' ? $end : date('Y-m-t');

$results = [];
$failed = false;
$builder = null;
$bootError = null;
try {
    $builder = OverviewDetailBuilder::boot();
} catch (Throwable $e) {
    $failed = true;
    $bootError = $e->getMessage();
}

foreach (OverviewDetailBuilder::supportedChartIds() as $chartId) {
    $started = microtime(true);
    $status = 'failed';
    $rows = 0;
    $error = null;
    $handler = str_replace(' ', '', ucwords(str_replace('_', ' ', $chartId))) . 'DetailHandler';

    try {
        if (!$builder instanceof OverviewDetailBuilder) {
            throw new RuntimeException($bootError ?: 'OverviewDetailBuilder indisponivel.');
        }
        $payload = $builder->build($chartId, $start, $end, [], 50, 0);
        $rowSet = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $rows = count($rowSet);
        $status = ($payload['ok'] ?? false) === false ? 'failed' : ($rows > 0 ? 'ok' : 'empty');
        if ($status === 'failed') {
            $error = (string) ($payload['message'] ?? 'Handler retornou ok=false.');
            $failed = true;
        }
    } catch (Throwable $e) {
        $failed = true;
        $error = $e->getMessage();
    }

    $results[] = [
        'chart_id' => $chartId,
        'status' => $status,
        'rows' => $rows,
        'handler' => $handler,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'error' => $error,
    ];
}

$payload = [
    'ok' => !$failed,
    'period' => ['start' => $start, 'end' => $end],
    'boot_error' => $bootError,
    'results' => $results,
];

if ($asJson) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    foreach ($results as $row) {
        printf("%-20s %-7s rows=%d %dms %s\n", $row['chart_id'], $row['status'], $row['rows'], $row['duration_ms'], $row['error'] ?? '');
    }
}

exit($failed ? 1 : 0);
