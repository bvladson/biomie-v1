<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — histórico de cobranças de um acompanhamento (Fase 7).
 *
 * GET ?followup_id=... · exige sales.quotes.followup.view · nunca consulta o Omie.
 *
 * A trilha é a prova operacional de "quem cobrou, quando e qual o próximo
 * compromisso". Só eventos concluídos aparecem; retry idempotente não vira
 * evento novo. Segredos da idempotência (chave, fingerprint, corpo armazenado)
 * NÃO são expostos.
 *
 * Sem bloco `freshness`: o histórico é registro do BIomie, não dado derivado do
 * Omie — o frescor do snapshot não o invalida.
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

$followupId = (int) Http::query('followup_id', '0');
if ($followupId <= 0) {
    Http::validationError('Parâmetro "followup_id" é obrigatório.');
}

$history = quoteAgendaReadService()->history($followupId, quoteAgendaSellerScope());

// Followup de outra empresa OU fora do escopo de vendedores ⇒ 404 (nunca lista
// vazia, que vazaria existência).
if ($history === null) {
    quoteAgendaRespondRaw([
        'ok' => false,
        'error' => 'followup_not_found',
        'message' => 'Acompanhamento não encontrado nesta empresa.',
    ], 404);
}

quoteAgendaRespondRaw([
    'ok' => true,
    'data' => $history,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
