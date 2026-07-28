<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\ReadModel\CnaeReadModelFactory;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = cnaeReadModelProbeArgs(array_slice($_SERVER['argv'] ?? [], 1));

try {
    $config = new Config();
    $request = [
        'date_from' => $args['start'],
        'date_to' => $args['end'],
        'limit' => (string) $args['limit'],
        'document_type' => $args['document_type'],
        'status' => $args['status'] ?? '',
        'cnae' => $args['cnae'] ?? '',
        'city' => $args['city'] ?? '',
        'bairro' => $args['neighborhood'] ?? '',
        'seller_id' => $args['seller_id'] ?? '',
        'sort' => $args['sort'],
        'direction' => $args['direction'],
    ];
    $query = CnaeQuery::fromRequest($request);
    $model = CnaeReadModelFactory::make($config);

    $started = microtime(true);
    $response = match ($args['mode']) {
        'ranking' => $model->ranking($query),
        'opportunities' => $model->opportunities($query),
        'buyers' => $model->buyers($query),
        'geo' => $model->geoDistribution($query),
        default => $model->coverage($query),
    };
    $payload = $response->toArray();
    $metrics = $model->performanceMetrics();
    $payload['probe'] = [
        'mode' => $args['mode'],
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'sql_queries' => $metrics['query_count'],
        'sql_time_ms' => $metrics['sql_time_ms'],
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

/**
 * @param list<string> $argv
 * @return array<string,mixed>
 */
function cnaeReadModelProbeArgs(array $argv): array
{
    $args = [
        'mode' => 'coverage',
        'start' => '2026-01-01',
        'end' => date('Y-m-d'),
        'limit' => 20,
        'document_type' => 'all',
        'sort' => 'revenue',
        'direction' => 'desc',
    ];

    foreach ($argv as $arg) {
        $arg = trim((string) $arg);
        if ($arg === '--json') {
            continue;
        }
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $key = str_replace('-', '_', $key);
            $args[$key] = trim($value);
        }
    }

    $args['mode'] = in_array($args['mode'], ['coverage', 'ranking', 'opportunities', 'buyers', 'geo'], true)
        ? $args['mode']
        : 'coverage';
    $args['limit'] = max(1, min(100, (int) $args['limit']));

    return $args;
}
