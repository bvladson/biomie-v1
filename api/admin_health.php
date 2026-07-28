<?php

declare(strict_types=1);

use BIomie\Admin\SystemHealthService;
use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\RequestContext;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
Auth::boot($config, $logger);
Auth::requireCapability('logs.view', true);

try {
    [$pdo, $connectionName] = Database::getGeoConnection($config);
    $service = new SystemHealthService($pdo, $connectionName);

    Http::json([
        'ok' => true,
        'health' => $service->diagnose(),
        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $exception) {
    $logger->error('Falha ao carregar diagnóstico administrativo.', [
        'request_id' => RequestContext::id(),
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Não foi possível carregar o diagnóstico do sistema agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
