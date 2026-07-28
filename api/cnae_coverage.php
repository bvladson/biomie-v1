<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Contracts\CnaeSourceContracts;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\ReadModel\CnaeReadModelFactory;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireModule('geo', true);

try {
    $startedAt = microtime(true);
    $query = CnaeQuery::fromRequest($_GET);
    $model = CnaeReadModelFactory::make($config);
    $payload = $model->coverage($query)->toArray();
    $payload['source_contract'] = CnaeSourceContracts::COVERAGE;
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCnae(
        $config,
        $model,
        $query,
        'cnae_coverage',
        CnaeSourceContracts::COVERAGE,
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('cnae_coverage api error', ['exception' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => 'cnae_coverage_error',
        'message' => 'Nao foi possivel carregar cobertura CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
