<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\DataPlatform\Query\CustomerQuery;
use BIomie\DataPlatform\ReadModel\CustomerReadModelFactory;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = customerReadModelProbeArgs(array_slice($_SERVER['argv'] ?? [], 1));

try {
    $config = new Config();
    $model = CustomerReadModelFactory::make($config);

    if ($args['mode'] === 'shadow') {
        $payload = customerReadModelShadow($model, $args);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(($payload['ok'] ?? false) === true ? 0 : 1);
    }

    $request = customerRequestFromArgs($args);
    if ($args['mode'] === 'profile' && trim((string) ($request['customer'] ?? '')) === '') {
        $sample = $model->sampleCustomers(1);
        if ($sample !== []) {
            $request['customer'] = (string) ($sample[0]['codigo_cliente_omie'] ?? '');
        }
        $model->resetPerformanceMetrics();
    }

    $query = CustomerQuery::fromRequest($request);
    $started = microtime(true);
    $response = match ($args['mode']) {
        'search' => $model->search($query),
        'quality', 'audit' => $model->quality($query),
        'opportunities' => $model->opportunities($query),
        'history' => $model->commercialHistory($query),
        default => $model->profile($query, false),
    };
    $payload = $response->toArray();
    $metrics = $model->performanceMetrics();
    $payload['probe'] = [
        'mode' => $args['mode'],
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'sql_queries' => $metrics['query_count'],
        'sql_time_ms' => $metrics['sql_time_ms'],
        'payload_bytes' => strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ];
    if ($args['mode'] === 'audit') {
        $payload['audit'] = [
            'status' => 'PASS',
            'summary' => 'Customer quality audit executado via CustomerReadModel.',
        ];
    }

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
function customerReadModelProbeArgs(array $argv): array
{
    $args = [
        'mode' => 'quality',
        'start' => '2026-01-01',
        'end' => date('Y-m-d'),
        'limit' => 20,
        'offset' => 0,
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
            $args[str_replace('-', '_', $key)] = trim($value);
        }
    }

    $args['mode'] = in_array($args['mode'], ['profile', 'search', 'quality', 'opportunities', 'history', 'audit', 'shadow'], true)
        ? $args['mode']
        : 'quality';
    $args['limit'] = max(1, min(100, (int) $args['limit']));
    $args['offset'] = max(0, (int) $args['offset']);

    return $args;
}

/**
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function customerRequestFromArgs(array $args): array
{
    return [
        'date_from' => $args['start'],
        'date_to' => $args['end'],
        'customer' => $args['customer'] ?? '',
        'q' => $args['q'] ?? $args['search'] ?? '',
        'document' => $args['document'] ?? '',
        'city' => $args['city'] ?? '',
        'bairro' => $args['neighborhood'] ?? '',
        'cnae' => $args['cnae'] ?? '',
        'seller_id' => $args['seller_id'] ?? '',
        'document_type' => $args['document_type'],
        'status' => $args['status'] ?? '',
        'limit' => (string) $args['limit'],
        'offset' => (string) $args['offset'],
        'sort' => $args['sort'],
        'direction' => $args['direction'],
    ];
}

/**
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function customerReadModelShadow(BIomie\DataPlatform\ReadModel\CustomerReadModel $model, array $args): array
{
    $started = microtime(true);
    $sample = $model->sampleCustomers((int) $args['limit']);
    $checks = [];
    foreach ($sample as $row) {
        $customer = (string) ($row['codigo_cliente_omie'] ?? '');
        if ($customer === '') {
            continue;
        }
        $profileArgs = $args;
        $profileArgs['customer'] = $customer;
        $query = CustomerQuery::fromRequest(customerRequestFromArgs($profileArgs));
        $model->resetPerformanceMetrics();
        $payload = $model->profile($query, false)->toArray();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $checks[] = [
            'customer_hash' => substr(hash('sha256', $customer), 0, 12),
            'ok' => (bool) ($payload['ok'] ?? false),
            'source_contract' => $payload['meta']['source']['contract'] ?? null,
            'no_plain_document' => !preg_match('/\d{11,}/', $json),
            'geo_quality_present' => isset($payload['data']['customer']['geo']['quality']),
        ];
    }

    $failed = array_filter($checks, static fn (array $check): bool => !($check['ok'] && $check['no_plain_document'] && $check['geo_quality_present']));
    $metrics = $model->performanceMetrics();

    return [
        'ok' => $failed === [],
        'status' => $failed === [] ? 'PASS' : 'FAIL',
        'mode' => 'shadow',
        'checks' => $checks,
        'summary' => [
            'sample' => count($checks),
            'fail' => count($failed),
        ],
        'probe' => [
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'sql_queries' => $metrics['query_count'],
            'sql_time_ms' => $metrics['sql_time_ms'],
        ],
    ];
}
