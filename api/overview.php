<?php

declare(strict_types=1);

use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Overview\OverviewPayloadBuilder;
use BIomie\Modules\Sales\Application\Overview\OverviewRequest;
use BIomie\Modules\Sales\Application\Overview\OverviewResponseException;
use BIomie\RequestContext;
use BIomie\Support\GlobalHomeDataGuard;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);
GlobalHomeDataGuard::requireUnrestricted();

try {
    $request = OverviewRequest::fromGlobals();
    $payload = (new OverviewPayloadBuilder($config, $logger, $cache, $analytics))->build($request);

    Http::json(DataMasker::applyToPayload($payload, Auth::canViewFullDocuments()));
} catch (OverviewResponseException $exception) {
    Http::json(
        DataMasker::applyToPayload($exception->payload(), Auth::canViewFullDocuments()),
        $exception->statusCode()
    );
} catch (InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    $logger->error('Falha em api/overview.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar o painel. Tente novamente.',
        'request_id' => RequestContext::id(),
    ], 500);
}
