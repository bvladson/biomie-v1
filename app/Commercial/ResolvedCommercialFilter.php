<?php

declare(strict_types=1);

namespace BIomie\Commercial;

/**
 * RESULTADO da resolução de filtros comerciais — o que a leitura vai aplicar.
 *
 * Saída do {@see CommercialFilterResolver}: separa dois planos que a UI precisa
 * distinguir:
 *   · EFETIVO   — canais/vendedores já cruzados com a autorização (alimenta SQL,
 *                 breakdown e filter_hash). É o que a query realmente lê.
 *   · INTENÇÃO  — o que persistir como preferência (o pedido do usuário, não o
 *                 efetivo): "todas" vira null para não congelar a lista atual.
 *
 * A distinção null ≠ [] é preservada em ambos os planos (ver
 * {@see CommercialFilterPreferenceStore}).
 */
final class ResolvedCommercialFilter
{
    /**
     * @param list<string>      $effectiveChannels  canais autorizados após refino (>=1 se há canal)
     * @param list<string>|null $effectiveSellerIds null = todos autorizados · [] = nenhum
     * @param list<string>|null $requestedChannels  intenção a persistir · null = todos
     * @param list<string>|null $requestedSellerIds intenção a persistir · null = todos · [] = nenhum
     * @param list<string>      $allowedChannels    universo de canais autorizados
     * @param list<string>      $droppedChannels    canais removidos por sanitização
     * @param list<string>      $droppedSellers     vendedores removidos por sanitização
     */
    public function __construct(
        public readonly array $effectiveChannels,
        public readonly ?array $effectiveSellerIds,
        public readonly ?array $requestedChannels,
        public readonly ?array $requestedSellerIds,
        public readonly array $allowedChannels,
        public readonly string $source,           // 'url' | 'preference' | 'default'
        public readonly bool $channelsSanitized,
        public readonly bool $sellersSanitized,
        public readonly array $droppedChannels,
        public readonly array $droppedSellers,
    ) {
    }

    public function sanitized(): bool
    {
        return $this->channelsSanitized || $this->sellersSanitized;
    }

    /** O recorte garante zero linhas (nenhum vendedor OU nenhum canal)? */
    public function isEmpty(): bool
    {
        return $this->effectiveSellerIds === [] || $this->effectiveChannels === [];
    }

    /**
     * Constrói o escopo efetivo (para filter_hash e metadados), combinando os
     * eixos autorizados com o refino não-sensível (cliente/produto/valores…).
     *
     * @param array<string,mixed> $refine
     */
    public function toEffectiveScope(
        int $companyId,
        string $start,
        string $end,
        array $refine = []
    ): EffectiveCommercialScope {
        $filters = new CommercialFilterSet(
            sellerIds: $this->effectiveSellerIds,
            channels: $this->effectiveChannels,
            start: $start,
            end: $end,
            client: $refine['client'] ?? null,
            product: $refine['product'] ?? null,
            statuses: $refine['statuses'] ?? [],
            omieStages: $refine['stages'] ?? [],
            orderNumber: $refine['order'] ?? null,
            invoiceNumber: $refine['invoice'] ?? null,
            minimumValue: $refine['min_value'] ?? null,
            maximumValue: $refine['max_value'] ?? null,
            withFreight: (bool) ($refine['with_freight'] ?? false),
            withoutOrder: (bool) ($refine['without_order'] ?? false),
            criticalOnly: (bool) ($refine['critical_only'] ?? false),
        );

        return new EffectiveCommercialScope(
            sellerIds: $this->effectiveSellerIds,
            channels: $this->effectiveChannels,
            filters: $filters,
            companyId: $companyId,
        );
    }

    /**
     * Bloco meta.channel_scope da resposta — o que a UI usa para pintar o toggle,
     * saber o que está efetivo e avisar sanitização.
     *
     * @return array<string,mixed>
     */
    public function channelScopeMeta(): array
    {
        return [
            'allowed' => $this->allowedChannels,
            'effective' => $this->effectiveChannels,
            'requested' => $this->requestedChannels,          // null = todas
            'all_selected' => $this->requestedChannels === null,
            'sanitized' => $this->channelsSanitized,
            'dropped' => $this->droppedChannels,
        ];
    }
}
