<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — orçamentos em aberto de UM vendedor (drawer Vendor 360).
 *
 * GET · exige sales.quotes.followup.view (é o gestor abrindo o drawer do
 * vendedor). Escopo forçado ao seller_id pedido — reusa o filtro seller_ids do
 * read service, então itens de outro vendedor nunca aparecem. Nunca toca o Omie.
 *
 * Parâmetro: seller_id (código Omie do vendedor). Sem ele ⇒ nada (não a
 * carteira inteira): escopo vazio é zero por segurança.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\Support\SellerVisibilityScope;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/quote_agenda_common.php';

Auth::requireCapability('sales.quotes.followup.view', true);
quoteAgendaRequireEnabled();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

$sellerId = trim((string) Http::query('seller_id', ''));
// Escopo por seller_id — sempre uma LISTA, mesmo com um só código. Vazio ⇒
// $s->openItems(['seller_ids'=>[]]) devolve zero (trava de segurança).
$sellerIds = $sellerId !== '' && $sellerId !== '0' ? [$sellerId] : [];

// VISIBILIDADE: ter `.view` autoriza a ABRIR o drawer, não a escolher QUALQUER
// vendedor. Este endpoint recebe o código pela query string, então sem esta
// checagem bastaria trocar o seller_id na URL para ler a carteira alheia.
// Não passa por quoteAgendaFilters() — a validação precisa ser explícita aqui.
if ($sellerIds !== [] && !(new SellerVisibilityScope(quoteAgendaPdo()))->canSee(
    quoteAgendaCompanyId(),
    (int) (Auth::currentUser()['id'] ?? 0),
    $sellerId
)) {
    quoteAgendaRespondRaw([
        'ok' => false,
        'error' => 'seller_not_authorized',
        'message' => 'Vendedor não autorizado para este usuário nesta empresa.',
    ], 403);
}

quoteAgendaRespond(
    quoteAgendaReadService()->readWithFreshness(
        static function (QuoteAgendaReadService $s) use ($sellerIds): array {
            $items = $s->openItems(['seller_ids' => $sellerIds]);
            $summary = $s->summary(['seller_ids' => $sellerIds]);

            return [
                'seller_scoped' => true,
                'kpis'  => $summary['kpis'] ?? null,
                'items' => $items,
            ];
        }
    )
);
