<?php

declare(strict_types=1);

namespace BIomie\Commercial;

/**
 * CONJUNTO DE FILTROS COMERCIAIS — a INTENÇÃO do usuário, ainda não autorizada.
 *
 * É um contrato ÚNICO para que card, gráfico, tabela, calendário e exportação
 * NÃO interpretem os filtros cada um à sua maneira (foi assim que card e
 * miniagenda divergiram, 56×55). Descreve o que foi PEDIDO; quem decide o que é
 * permitido é o {@see CommercialScopeResolver}, no servidor.
 *
 * Dois eixos são de AUTORIZAÇÃO (vendedores, canais) — passam pelo resolver e
 * podem virar 403. Os demais são REFINO (cliente, produto, valor…) — restringem
 * a leitura, nunca ampliam, então não precisam de checagem de permissão.
 *
 * sellerIds segue a semântica do BIomie: null = todos autorizados · [] = nenhum.
 */
final class CommercialFilterSet
{
    /**
     * @param list<string>|null $sellerIds  null = todos autorizados · [] = nenhum · [...] = pedidos
     * @param list<string>      $channels   vazio = todos os canais autorizados
     * @param list<string>      $statuses   situações comerciais pedidas (refino)
     * @param list<string>      $omieStages etapas Omie pedidas (refino)
     */
    public function __construct(
        public readonly ?array $sellerIds,
        public readonly array $channels,
        public readonly string $start,
        public readonly string $end,
        public readonly ?string $client = null,
        public readonly ?string $product = null,
        public readonly array $statuses = [],
        public readonly array $omieStages = [],
        public readonly ?string $orderNumber = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?float $minimumValue = null,
        public readonly ?float $maximumValue = null,
        public readonly bool $withFreight = false,
        public readonly bool $withoutOrder = false,
        public readonly bool $criticalOnly = false,
    ) {
    }

    /**
     * Constrói a partir dos parâmetros crus da request (query/body), sem decidir
     * autorização — só normaliza tipos e formatos.
     *
     * @param array<string,mixed> $q
     */
    public static function fromRequest(array $q, string $start, string $end): self
    {
        $list = static function (mixed $v): array {
            if (is_array($v)) {
                return array_values(array_filter(array_map(
                    static fn ($x): string => trim((string) $x),
                    $v
                ), static fn (string $s): bool => $s !== ''));
            }
            $s = trim((string) $v);
            return $s !== '' ? [$s] : [];
        };
        $str = static function (mixed $v): ?string {
            $s = trim((string) $v);
            return $s !== '' ? $s : null;
        };
        $num = static function (mixed $v): ?float {
            $s = trim((string) $v);
            return $s !== '' && is_numeric($s) ? (float) $s : null;
        };

        // Ausência de 'seller' = todos autorizados (null). Presença define a lista.
        $sellers = array_key_exists('seller', $q) ? $list($q['seller']) : null;

        return new self(
            sellerIds: $sellers,
            channels: $list($q['channel'] ?? null),
            start: $start,
            end: $end,
            client: $str($q['client'] ?? null),
            product: $str($q['product'] ?? null),
            statuses: $list($q['status'] ?? null),
            omieStages: $list($q['stage'] ?? null),
            orderNumber: $str($q['order'] ?? null),
            invoiceNumber: $str($q['invoice'] ?? null),
            minimumValue: $num($q['min_value'] ?? null),
            maximumValue: $num($q['max_value'] ?? null),
            withFreight: (bool) ($q['with_freight'] ?? false),
            withoutOrder: (bool) ($q['without_order'] ?? false),
            criticalOnly: (bool) ($q['critical_only'] ?? false),
        );
    }
}
