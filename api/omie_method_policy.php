<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\OmieMethodPolicyRegistry;

require __DIR__ . '/bootstrap.php';

Auth::requireAdmin(true);

try {
    $registry = new OmieMethodPolicyRegistry($config);

    Http::json([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'defaults' => [
            'request_timeout_seconds' => (int) $config->get('omie.request_timeout', 20),
            'fallback_page_size' => (int) $config->get('omie.default_page_size', 100),
        ],
        'policies' => $registry->all(),
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao consultar a politica tecnica da Omie.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao consultar a politica tecnica da Omie.',
    ], 500);
}
