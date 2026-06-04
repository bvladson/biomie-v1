<?php

declare(strict_types=1);

/**
 * Ranking de empresas/compradores por CNAE.
 *
 * CNAE.R1: endpoint fino. Regra semantica, CPF nao aplicavel e CNPJ sem CNAE
 * ficam centralizados no CnaeReadModel.
 */

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
    $payload = $model->legacyCompanyRankingPayload(
        $query,
        method_exists(Auth::class, 'canViewFullDocuments')
            ? Auth::canViewFullDocuments()
            : false
    );
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCnae(
        $config,
        $model,
        $query,
        'company_cnae_ranking',
        CnaeSourceContracts::CUSTOMER_STATUS,
        'readmodel_legacy_adapter',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('company_cnae_ranking api error', ['exception' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => 'company_cnae_ranking_error',
        'message' => 'Nao foi possivel carregar compradores por CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
