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
    $request = $_GET;
    $request['document_type'] ??= 'cnpj';
    $query = CnaeQuery::fromRequest($request);
    $model = CnaeReadModelFactory::make($config);
    $payload = $model->ranking($query)->toArray();
    $payload['source_contract'] = CnaeSourceContracts::RANKING;
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCnae(
        $config,
        $model,
        $query,
        'cnae_ranking',
        CnaeSourceContracts::RANKING,
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('cnae_ranking api error', ['exception' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => 'cnae_ranking_error',
        'message' => 'Nao foi possivel carregar ranking CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
