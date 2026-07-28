<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — KPIs do topo (cobrar hoje, atrasados, em aberto, críticos).
 * GET · exige sales.quotes.followup.view · nunca consulta o Omie.
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

$filters = quoteAgendaFilters();
quoteAgendaRespond(
    quoteAgendaReadService()->readWithFreshness(
        static fn (QuoteAgendaReadService $s): array => $s->summary($filters)
    )
);
