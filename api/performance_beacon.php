<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\Observability\EndpointMetricsCollector;

require __DIR__ . '/bootstrap.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Http::json(['ok' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || !Auth::verifyCsrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null)) {
    Http::json(['ok' => false, 'message' => 'CSRF invalido.'], 419);
}

$records = is_array($body['records'] ?? null) ? array_values($body['records']) : [];
$collector = new EndpointMetricsCollector(
    dirname(__DIR__) . '/storage/logs/endpoint_requests.jsonl',
    dirname(__DIR__) . '/storage/logs/endpoint_browser.jsonl',
);

Http::json(['ok' => true, 'accepted' => $collector->appendBrowserRecords($records)]);
