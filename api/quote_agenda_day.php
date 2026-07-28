<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — lista lateral de um dia.
 * GET ?date=YYYY-MM-DD · exige sales.quotes.followup.view · nunca consulta o Omie.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireAnyCapability([
    'sales.quotes.followup.view',
    'sales.quotes.followup.self',
], true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

$date = trim((string) Http::query('date', ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    Http::validationError('Parâmetro "date" obrigatório no formato YYYY-MM-DD.');
}

$filters = quoteAgendaFilters();
quoteAgendaRespond(
    quoteAgendaReadService()->readWithFreshness(
        static fn (QuoteAgendaReadService $s): array => [
            'date' => $date,
            'items' => $s->day($date, $filters),
        ]
    )
);
