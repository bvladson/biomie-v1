<?php

declare(strict_types=1);

/**
 * PRIORIDADE DE ORÇAMENTOS — resumo para a home (GET).
 *
 * Devolve os orçamentos que merecem ação HOJE, já ordenados, com os motivos.
 *
 * POR QUE NA HOME: o gestor abre o sistema e vê números do mês — receita, meta,
 * pedidos. Nenhum deles responde "o que eu faço agora?". A Agenda responde, mas
 * exige abrir outra tela e ler 188 linhas. Este endpoint traz as poucas que
 * mudam o resultado do dia.
 *
 * Respeita o MESMO escopo de vendedores da Agenda: quem só vê a própria carteira
 * não recebe prioridade de outro vendedor por um caminho lateral.
 *
 * Nunca expõe `run_mode = 'shadow'` — a barreira mora em QuotePriorityReadService.
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

$limite = (int) ($_GET['limit'] ?? 5);
$limite = max(1, min($limite, 20));

$envelope = quoteAgendaReadService()->readWithFreshness(
    static function (QuoteAgendaReadService $s) use ($limite): array {
        $itens = $s->openItems(quoteAgendaFilters());

        $comPrioridade = array_values(array_filter(
            $itens,
            static fn (array $i): bool => ($i['priority'] ?? null) !== null
        ));

        usort(
            $comPrioridade,
            static fn (array $a, array $b): int => $b['priority']['score'] <=> $a['priority']['score']
        );

        $contagem = ['alta' => 0, 'media' => 0, 'baixa' => 0];
        foreach ($comPrioridade as $i) {
            $banda = (string) $i['priority']['band'];
            if (isset($contagem[$banda])) {
                $contagem[$banda]++;
            }
        }

        $topo = [];
        foreach (array_slice($comPrioridade, 0, $limite) as $i) {
            $topo[] = [
                'omie_order_id' => $i['omie_order_id'],
                'numero_pedido' => $i['numero_pedido'],
                'customer' => $i['customer'],
                'seller_name' => $i['seller_name'],
                'value' => $i['value'],
                'score' => $i['priority']['score'],
                'band' => $i['priority']['band'],
                // Só os 3 primeiros: a home é resumo, não a tela de detalhe.
                'reasons' => array_slice($i['priority']['reasons'], 0, 3),
            ];
        }

        return [
            'total_abertos' => count($itens),
            // Diferença deliberada: sem score ≠ prioridade baixa. Se o job ainda
            // não rodou, a home tem que dizer isso em vez de fingir que está tudo calmo.
            'total_pontuados' => count($comPrioridade),
            'sem_pontuacao' => count($itens) - count($comPrioridade),
            'por_banda' => $contagem,
            'topo' => $topo,
        ];
    }
);

Http::json([
    'ok' => true,
    'data' => $envelope['data'],
    'freshness' => $envelope['freshness'],
    'generated_at' => $envelope['generated_at'],
]);
