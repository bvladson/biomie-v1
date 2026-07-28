<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — detalhe de um orçamento acompanhado.
 * GET ?omie_order_id=... · exige sales.quotes.followup.view · nunca consulta o Omie.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.view', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

$orderId = trim((string) Http::query('omie_order_id', ''));
if ($orderId === '') {
    Http::validationError('Parâmetro "omie_order_id" é obrigatório.');
}

// Escopo de vendedores: fora dele o detalhe é 404, igual a "de outra empresa".
$filters = quoteAgendaFilters();

$payload = quoteAgendaReadService()->readWithFreshness(
    static fn (QuoteAgendaReadService $s): ?array => $s->detail($orderId, $filters)
);

if ($payload['data'] === null) {
    quoteAgendaRespondRaw([
        'ok' => false,
        'error' => 'followup_not_found',
        'message' => 'Acompanhamento não encontrado nesta empresa.',
    ], 404);
}

quoteAgendaRespond($payload);
