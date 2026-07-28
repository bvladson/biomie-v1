<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\Support\SellerVisibilityScope;
use PDO;

/**
 * RESOLVEDOR DE ESCOPO COMERCIAL — a fronteira única de autorização dos filtros.
 *
 * Recebe a INTENÇÃO ({@see CommercialFilterSet}) e devolve o EFETIVO
 * ({@see EffectiveCommercialScope}), cruzando com a autorização do usuário nos
 * dois eixos que exigem permissão:
 *   · vendedores — via {@see SellerVisibilityScope} (null/[]/lista já existente)
 *   · canais     — via {@see CommercialChannelScope} (allowlist varejo/licitacao)
 *
 * O front só descreve o pedido; QUEM DECIDE é aqui. Pedido fora do autorizado
 * (vendedor ou canal) ⇒ CommercialScopeDeniedException ⇒ 403. Nunca "reduz em
 * silêncio" um pedido proibido — a diferença entre "não é seu" e "vazio" é
 * informação que a UI precisa tratar diferente.
 *
 * REGRA DE VENDEDOR (idêntica à semântica já adotada):
 *   autorizado []   ⇒ []            (nenhum, mesmo se pediu algo)
 *   pedido    null  ⇒ autorizado    (todos os autorizados)
 *   autorizado null ⇒ pedido        (usuário sem restrição usa o filtro puro)
 *   pedido fora do autorizado ⇒ 403 (não interseção silenciosa)
 *   senão ⇒ interseção
 */
final class CommercialScopeResolver
{
    public function __construct(
        private readonly SellerVisibilityScope $sellers,
        private readonly CommercialChannelScope $channels,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new SellerVisibilityScope($pdo), new CommercialChannelScope($pdo));
    }

    public function resolve(int $companyId, int $userId, CommercialFilterSet $filters): EffectiveCommercialScope
    {
        return new EffectiveCommercialScope(
            sellerIds: $this->resolveSellers($companyId, $userId, $filters->sellerIds),
            channels: $this->channels->resolve($companyId, $userId, $filters->channels),
            filters: $filters,
            companyId: $companyId,
        );
    }

    /**
     * @param  list<string>|null $requested
     * @return list<string>|null
     * @throws CommercialScopeDeniedException vendedor pedido fora do autorizado
     */
    private function resolveSellers(int $companyId, int $userId, ?array $requested): ?array
    {
        $allowed = $this->sellers->allowedSellerIds($companyId, $userId);

        if ($allowed === []) {
            return [];                                  // nenhum autorizado ⇒ nada
        }
        if ($requested === null) {
            return $allowed;                            // sem filtro ⇒ todos autorizados
        }

        $requested = array_values(array_unique(array_filter(
            array_map(static fn ($s): string => trim((string) $s), $requested),
            static fn (string $s): bool => $s !== '' && $s !== '0'
        )));

        if ($allowed === null) {
            return $requested;                          // usuário sem restrição usa o filtro
        }

        // Pedir vendedor fora do escopo é 403 — jamais interseção muda.
        $forbidden = array_values(array_diff($requested, $allowed));
        if ($forbidden !== []) {
            throw new CommercialScopeDeniedException('Um ou mais vendedores não são autorizados.');
        }

        return array_values(array_intersect($allowed, $requested));
    }
}
