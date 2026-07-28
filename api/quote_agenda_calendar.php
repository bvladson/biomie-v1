<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — calendário mensal agrupado por dia.
 * day_urgency = MAIOR urgência entre os itens não resolvidos do dia.
 * A API devolve SEMÂNTICA (urgency + urgency_rank), nunca cor/hex.
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

$range = Http::resolveDateRange();
Http::validateDateRange($range['start'], $range['end']);
$filters = quoteAgendaFilters();

quoteAgendaRespond(
    quoteAgendaReadService()->readWithFreshness(
        static fn (QuoteAgendaReadService $s): array => [
            'period' => ['start' => $range['start'], 'end' => $range['end']],
            'days' => $s->calendar($range['start'], $range['end'], $filters),
            // FONTE ÚNICA DA VERDADE de "em aberto": ponto-no-tempo, SEM período.
            // A grade é mensal por natureza, mas o total de orçamentos abertos
            // NÃO pode depender do mês exibido — senão um orçamento com cobrança
            // fora do mês some da conta e o card/miniagenda/Agenda divergem
            // (bug de 2026-07-22: card 56 vs miniagenda 55).
            'open_summary' => $s->summary($filters)['kpis'] ?? null,
        ]
    )
);
