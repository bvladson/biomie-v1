<?php

declare(strict_types=1);

namespace BIomie\Commercial;

/**
 * ESCOPO COMERCIAL EFETIVO — o que a query REALMENTE pode ler, já autorizado.
 *
 * Saída do {@see CommercialScopeResolver}: os vendedores e canais já cruzados
 * com a autorização do usuário. É ESTE objeto que alimenta card, gráfico,
 * tabela, calendário e exportação — a fonte única que impede divergência.
 *
 * sellerIds: null = todos autorizados (sem restrição de vendedor no WHERE)
 *            []   = nenhum ⇒ a query DEVE devolver zero (1=0)
 *            [..] = exatamente esses códigos
 * channels:  SEMPRE lista concreta (>=1 se o usuário tem algum canal). Vazia só
 *            se o usuário não tem canal algum ⇒ também zero.
 *
 * filterHash: identifica de forma estável o estado (empresa+usuário+escopo+
 * filtros). A UI só pinta resultados cujo filterHash bate com o pedido atual —
 * evita que uma resposta atrasada sobrescreva a seleção nova.
 */
final class EffectiveCommercialScope
{
    /**
     * @param list<string>|null $sellerIds
     * @param list<string>      $channels
     */
    public function __construct(
        public readonly ?array $sellerIds,
        public readonly array $channels,
        public readonly CommercialFilterSet $filters,
        public readonly int $companyId,
    ) {
    }

    /** O escopo garante zero linhas? (nenhum vendedor OU nenhum canal). */
    public function isEmpty(): bool
    {
        return $this->sellerIds === [] || $this->channels === [];
    }

    /**
     * Hash estável do estado — para o meta.filter_hash da resposta e a chave de
     * cache. Ordena tudo: a mesma seleção sempre gera o mesmo hash,
     * independentemente da ordem em que veio da UI.
     */
    public function filterHash(): string
    {
        $sellers = $this->sellerIds;
        if (is_array($sellers)) {
            sort($sellers, SORT_STRING);
        }
        $channels = $this->channels;
        sort($channels, SORT_STRING);
        $statuses = $this->filters->statuses;
        sort($statuses, SORT_STRING);
        $stages = $this->filters->omieStages;
        sort($stages, SORT_STRING);

        $material = [
            'company' => $this->companyId,
            'sellers' => $sellers,            // null preservado (≠ [])
            'channels' => $channels,
            'start' => $this->filters->start,
            'end' => $this->filters->end,
            'client' => $this->filters->client,
            'product' => $this->filters->product,
            'statuses' => $statuses,
            'stages' => $stages,
            'order' => $this->filters->orderNumber,
            'invoice' => $this->filters->invoiceNumber,
            'min' => $this->filters->minimumValue,
            'max' => $this->filters->maximumValue,
            'with_freight' => $this->filters->withFreight,
            'without_order' => $this->filters->withoutOrder,
            'critical_only' => $this->filters->criticalOnly,
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> bloco meta.seller_scope da resposta da API */
    public function sellerScopeMeta(): array
    {
        return [
            'restricted' => $this->sellerIds !== null,
            'count' => $this->sellerIds === null ? null : count($this->sellerIds),
        ];
    }
}
