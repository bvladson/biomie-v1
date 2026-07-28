<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Board\BoardConsolidatedService;
use BIomie\RequestContext;

define('BIOMIE_API_BOOTSTRAP_PROFILE', 'board-read');
require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config definido em api/bootstrap.php */
/** @var \BIomie\Logger $logger definido em api/bootstrap.php */
/** @var \BIomie\Cache $cache definido em api/bootstrap.php */

Auth::requireCapability('sales.view', true);

try {
    // Período padrão: mês corrente até hoje (month-to-date) vs meta do mês.
    $start = date('Y-m-01');
    $end   = date('Y-m-d');
    $annualStart = date('Y-01-01');

    $userId = (int) (Auth::currentUser()['id'] ?? 0);

    $payload = (new BoardConsolidatedService($config, $logger, $cache))->build($userId, $start, $end, $annualStart);

    // O consolidado é recomputado no máximo a cada 5 min pelo cache privado do
    // serviço. A tela mantém apenas o ETag em memória e envia If-None-Match:
    // respostas iguais retornam 304 sem persistir dados autenticados no cache do
    // navegador. O valor abaixo é uma dica de cadência para a telemetria HTTP.
    Http::jsonWithEtag($payload, 300);
} catch (Throwable $exception) {
    $logger->error('Falha em api/board_consolidated.', [
        'message'    => $exception->getMessage(),
        'trace'      => $exception->getTraceAsString(),
        'request_id' => RequestContext::id(),
    ]);

    Http::json([
        'ok'         => false,
        'message'    => 'Erro interno ao carregar o painel da diretoria.',
        'request_id' => RequestContext::id(),
    ], 500);
}
