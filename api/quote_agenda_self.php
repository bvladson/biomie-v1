<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — carteira do PRÓPRIO vendedor logado (Fase 10).
 *
 * GET · exige sales.quotes.followup.self (papéis de vendedor) · read-only ·
 * nunca consulta o Omie · nunca cobra.
 *
 * O escopo é RESOLVIDO pelo servidor a partir do usuário logado (vínculos em
 * biomie_quote_seller_binding) — o cliente NÃO informa seller_id. Sem vínculo,
 * o vendedor vê NADA (nunca a carteira inteira): é a mesma trava do read
 * service (seller_ids vazio ⇒ zero).
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\QuoteAgenda\SellerBindingResolver;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.self', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

$userId    = (int) (Auth::currentUser()['id'] ?? 0);
$companyId = (int) TenantContext::companyId();

// Escopo do PRÓPRIO usuário na empresa atual. Nunca vem do cliente.
$sellerIds = (new SellerBindingResolver(quoteAgendaPdo()))->sellerIdsFor($userId, $companyId);

quoteAgendaRespond(
    quoteAgendaReadService()->readWithFreshness(
        static function (QuoteAgendaReadService $s) use ($sellerIds): array {
            return [
                'self_scoped' => true,
                'is_bound'    => $sellerIds !== [],
                'kpis'        => $s->summary(['seller_ids' => $sellerIds])['kpis'] ?? null,
                'items'       => $s->openItems(['seller_ids' => $sellerIds]),
            ];
        }
    )
);
