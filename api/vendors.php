<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Vendors\VendorPayloadBuilder;
use BIomie\Modules\Sales\Application\Vendors\VendorRequest;
use BIomie\Modules\Sales\Application\Vendors\VendorSupport;
use BIomie\RequestContext;

$__vendorsDebugLog = dirname(__DIR__) . '/storage/logs/vendors_runtime_debug.log';

register_shutdown_function(static function () use ($__vendorsDebugLog): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $payload = [
        'type' => 'shutdown_fatal',
        'message' => $error['message'] ?? 'unknown fatal',
        'file' => $error['file'] ?? null,
        'line' => $error['line'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'time' => date(DATE_ATOM),
    ];

    @file_put_contents(
        $__vendorsDebugLog,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Falha fatal em api/vendors.php',
            'fatal' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

set_exception_handler(static function (Throwable $e) use ($__vendorsDebugLog): void {
    $payload = [
        'type' => 'uncaught_exception',
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'time' => date(DATE_ATOM),
    ];

    @file_put_contents(
        $__vendorsDebugLog,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Excecao nao tratada em api/vendors.php',
            'error' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

$debugEnabled = false;
$requestContext = [
    'competencia' => is_string($_GET['competencia'] ?? null) ? trim($_GET['competencia']) : null,
    'start' => is_string($_GET['start'] ?? null) ? trim($_GET['start']) : null,
    'end' => is_string($_GET['end'] ?? null) ? trim($_GET['end']) : null,
];
$flagsContext = [];
$sourceAttempted = 'bootstrap';

try {
    require __DIR__ . '/bootstrap.php';

    Auth::requireCapability('sales.view', true);

    $debugEnabled = (bool) $config->get('app.debug', false);
    $builder = new VendorPayloadBuilder($config, $logger, $analytics);
    $flagsContext = $builder->collectFlags();

    $request = VendorRequest::fromGlobals();
    $requestContext = [
        'competencia' => $request->competencia(),
        'start' => $request->start(),
        'end' => $request->end(),
    ];
    $sourceAttempted = 'builder';

    Http::json($builder->build($request));
} catch (InvalidArgumentException $exception) {
    Http::json([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 400);
} catch (Throwable $exception) {
    if (isset($logger)) {
        $logger->error('Falha em api/vendors.', [
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'exception_class' => $exception::class,
            'competencia' => $requestContext['competencia'] ?? null,
            'period' => [
                'start' => $requestContext['start'] ?? null,
                'end' => $requestContext['end'] ?? null,
            ],
            'source_attempted' => $sourceAttempted,
            'flags' => $flagsContext,
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }

    $payload = [
        'ok' => false,
        'message' => 'Erro interno ao carregar vendedores.',
    ];

    if ($debugEnabled) {
        $payload['debug'] = VendorSupport::summarizeThrowable($exception, 500);
    }

    Http::json($payload, 500);
}
