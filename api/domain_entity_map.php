<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;

require __DIR__ . '/bootstrap.php';

Auth::requireAdmin(true);

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco nao configurado.'], 500);
    }

    $pdo = Database::connect($config, $connection);
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $canonical->ensureFiscalSchema();

    Http::json([
        'ok' => true,
        'entity_map' => $canonical->getDomainEntityMap(),
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao consultar mapa de entidades do dominio.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao consultar o mapa de entidades.',
    ], 500);
}
