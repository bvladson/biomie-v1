<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\DataPlatform\Contracts\CnaeSourceContracts;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\ReadModel\CnaeReadModelFactory;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireModule('geo', true);

try {
    $startedAt = microtime(true);
    $request = $_GET;
    $request['document_type'] = 'cnpj';
    $request['status'] = 'missing_cnpj';
    $query = CnaeQuery::fromRequest($request);
    $model = CnaeReadModelFactory::make($config);
    $payload = $model->opportunities($query)->toArray();
    if (!method_exists(Auth::class, 'canViewFullDocuments') || !Auth::canViewFullDocuments()) {
        foreach (($payload['data']['items'] ?? []) as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = (string) ($row['documento'] ?? '');
            $masked = DocumentMask::maskDocument($doc, 'Nao informado') ?? 'Nao informado';
            $payload['data']['items'][$idx]['documento_mascarado'] = $masked;
            $payload['data']['items'][$idx]['documento'] = $masked;
        }
    }
    $payload['source_contract'] = CnaeSourceContracts::OPPORTUNITIES;
    $payload['request_id'] = RequestContext::id();

    ReadModelPerformanceLogger::logCnae(
        $config,
        $model,
        $query,
        'cnae_opportunities',
        CnaeSourceContracts::OPPORTUNITIES,
        'readmodel',
        $startedAt,
        $payload
    );

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('cnae_opportunities api error', ['exception' => $exception->getMessage()]);
    Http::json([
        'ok' => false,
        'error' => 'cnae_opportunities_error',
        'message' => 'Nao foi possivel carregar oportunidades CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
