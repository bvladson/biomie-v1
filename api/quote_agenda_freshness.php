<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — frescor do snapshot vivo (polling leve do banner).
 * Mesma semântica do bloco embutido nas demais respostas.
 * GET · exige sales.quotes.followup.view · nunca consulta o Omie.
 */

use BIomie\Auth;
use BIomie\Http;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.view', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

quoteAgendaRespondRaw([
    'ok' => true,
    'freshness' => quoteAgendaReadService()->freshness(),
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
