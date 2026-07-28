<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\Support\SellerVisibilityScope;
use PDO;

/**
 * CÉREBRO do Filtro Comercial Inteligente — decide o recorte efetivo.
 *
 * PRECEDÊNCIA (a mesma para os dois eixos, canal e vendedor):
 *   1. URL explícita  — o usuário PEDIU agora. Pedido fora do autorizado ⇒ 403
 *      ({@see CommercialScopeDeniedException}). Nunca reduz em silêncio: forjar um
 *      canal/vendedor tem de falhar visível, não virar "vazio".
 *   2. Preferência salva — sanitizada contra a autorização ATUAL: remove só os
 *      itens que o admin tirou, sinaliza a sanitização e NUNCA amplia para todos.
 *      (O usuário não está pedindo agora; a permissão mudou sob ele.)
 *   3. Default inteligente — todos os autorizados (canais) / sem restrição de
 *      vendedor (null). É o comportamento pré-filtro.
 *
 * O CONTEXTO (TenantContext) define o universo máximo; este resolvedor só REFINA
 * dentro dele. A autorização é sempre recalculada aqui, no servidor.
 *
 * Ele NÃO grava nada: a persistência é explícita e só ocorre após validação (ver
 * api/commercial_filter_pref.php). A leitura é livre de efeito colateral — uma
 * preferência sanitizada é corrigida no resultado, não reescrita no banco (se o
 * acesso voltar, a intenção original volta com ele).
 */
final class CommercialFilterResolver
{
    public function __construct(
        private readonly SellerVisibilityScope $sellers,
        private readonly CommercialChannelScope $channels,
        private readonly CommercialFilterPreferenceStore $store,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            new SellerVisibilityScope($pdo),
            new CommercialChannelScope($pdo),
            new CommercialFilterPreferenceStore($pdo),
        );
    }

    /**
     * @param array{
     *   channel_present?: bool, channels?: list<string>,
     *   seller_present?: bool, seller_ids?: list<string>
     * } $request
     *
     * @throws CommercialScopeDeniedException  pedido explícito (URL) fora do autorizado
     */
    public function resolve(
        int $companyId,
        int $userId,
        string $page,
        string $businessScope,
        array $request
    ): ResolvedCommercialFilter {
        $allowedChannels = $this->channels->allowedChannels($companyId, $userId);
        $allowedSellers = $this->sellers->allowedSellerIds($companyId, $userId);

        $channelPresent = (bool) ($request['channel_present'] ?? false);
        $sellerPresent = (bool) ($request['seller_present'] ?? false);
        $urlChannels = self::cleanList($request['channels'] ?? []);
        $urlSellers = self::cleanList($request['seller_ids'] ?? []);

        // A preferência só é consultada quando NÃO há pedido explícito no eixo.
        $pref = ($channelPresent && $sellerPresent)
            ? null
            : $this->store->load($companyId, $userId, $page, $businessScope);

        $source = ($channelPresent || $sellerPresent)
            ? 'url'
            : ($pref !== null ? 'preference' : 'default');

        // ── Canais ────────────────────────────────────────────────────────────
        $droppedChannels = [];
        $channelsSanitized = false;
        if ($channelPresent) {
            // URL explícita: resolve() faz allowlist + autorização ⇒ 403 se forjado.
            $effectiveChannels = $this->channels->resolve($companyId, $userId, $urlChannels);
        } else {
            $prefChannels = $pref['channels'] ?? null;
            if ($prefChannels === null) {
                $effectiveChannels = $allowedChannels; // todos os autorizados
            } else {
                $kept = array_values(array_intersect($prefChannels, $allowedChannels));
                $droppedChannels = array_values(array_diff($prefChannels, $allowedChannels));
                $channelsSanitized = $droppedChannels !== [];
                // Sanitizou tudo? Volta ao default (todos autorizados) — jamais
                // "nenhum canal" silencioso. Com o invariante do contexto mixed
                // (2 canais sempre autorizados) este ramo praticamente não ocorre.
                $effectiveChannels = $kept !== [] ? $kept : $allowedChannels;
            }
        }
        $requestedChannels = self::channelIntent($effectiveChannels, $allowedChannels);

        // ── Vendedores ──────────────────────────────────────────────────────────
        $droppedSellers = [];
        $sellersSanitized = false;
        if ($sellerPresent) {
            if ($urlSellers === []) {
                // Presente mas vazio = "limpar filtro" = todos os autorizados.
                $effectiveSellers = $allowedSellers;
                $requestedSellers = null;
            } else {
                $effectiveSellers = $this->authorizeSellersOrDeny($urlSellers, $allowedSellers);
                $requestedSellers = self::sellerIntent($effectiveSellers, $allowedSellers);
            }
        } else {
            $prefSellers = $pref['seller_ids'] ?? null;
            if ($prefSellers === null) {
                $effectiveSellers = $allowedSellers; // null=todos · []=nenhum · list
                $requestedSellers = null;
            } elseif ($prefSellers === []) {
                $effectiveSellers = [];              // vazio explícito = nenhum
                $requestedSellers = [];
            } else {
                [$effectiveSellers, $droppedSellers] = self::sanitizeSellers($prefSellers, $allowedSellers);
                $sellersSanitized = $droppedSellers !== [];
                $requestedSellers = self::sellerIntent($effectiveSellers, $allowedSellers);
            }
        }

        return new ResolvedCommercialFilter(
            effectiveChannels: $effectiveChannels,
            effectiveSellerIds: $effectiveSellers,
            requestedChannels: $requestedChannels,
            requestedSellerIds: $requestedSellers,
            allowedChannels: $allowedChannels,
            source: $source,
            channelsSanitized: $channelsSanitized,
            sellersSanitized: $sellersSanitized,
            droppedChannels: $droppedChannels,
            droppedSellers: $droppedSellers,
        );
    }

    /**
     * Autoriza uma lista pedida por URL. Item fora do autorizado ⇒ 403 (não
     * interseção silenciosa) — a UI precisa distinguir "não é seu" de "vazio".
     *
     * @param  list<string>      $requested
     * @param  list<string>|null $allowed
     * @return list<string>
     * @throws CommercialScopeDeniedException
     */
    private function authorizeSellersOrDeny(array $requested, ?array $allowed): array
    {
        if ($allowed === []) {
            return []; // nenhum autorizado ⇒ nada, mesmo pedindo
        }
        if ($allowed === null) {
            return $requested; // usuário sem restrição usa o filtro puro
        }
        $forbidden = array_values(array_diff($requested, $allowed));
        if ($forbidden !== []) {
            throw new CommercialScopeDeniedException('Um ou mais vendedores não são autorizados.');
        }

        return array_values(array_intersect($allowed, $requested));
    }

    /**
     * Sanitiza uma preferência de vendedores: remove só os proibidos, sem 403 e
     * sem ampliar. Devolve [efetivo, removidos].
     *
     * @param  list<string>      $prefSellers
     * @param  list<string>|null $allowed
     * @return array{0: list<string>|null, 1: list<string>}
     */
    private static function sanitizeSellers(array $prefSellers, ?array $allowed): array
    {
        if ($allowed === null) {
            return [$prefSellers, []];       // sem restrição ⇒ tudo que pediu vale
        }
        if ($allowed === []) {
            return [[], $prefSellers];       // nada autorizado ⇒ tudo foi removido
        }
        $kept = array_values(array_intersect($allowed, $prefSellers));
        $dropped = array_values(array_diff($prefSellers, $allowed));

        return [$kept, $dropped];
    }

    /**
     * Intenção de canal a persistir: "todas" (== conjunto autorizado) vira null,
     * para não congelar a lista atual de canais.
     *
     * @param  list<string> $effective
     * @param  list<string> $allowed
     * @return list<string>|null
     */
    private static function channelIntent(array $effective, array $allowed): ?array
    {
        $e = $effective;
        $a = $allowed;
        sort($e, SORT_STRING);
        sort($a, SORT_STRING);

        return $e === $a ? null : array_values($effective);
    }

    /**
     * Intenção de vendedor a persistir. null=todos · []=nenhum · list=específico.
     * Se a seleção efetiva cobre exatamente o autorizado, persiste null (todos).
     *
     * @param  list<string>|null $effective
     * @param  list<string>|null $allowed
     * @return list<string>|null
     */
    private static function sellerIntent(?array $effective, ?array $allowed): ?array
    {
        if ($effective === null) {
            return null;
        }
        if ($effective === []) {
            return [];
        }
        if (is_array($allowed)) {
            $e = $effective;
            $a = $allowed;
            sort($e, SORT_STRING);
            sort($a, SORT_STRING);
            if ($e === $a) {
                return null; // cobre todo o autorizado ⇒ "todos"
            }
        }

        return array_values($effective);
    }

    /**
     * @param  mixed $raw
     * @return list<string>
     */
    private static function cleanList(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw === null ? [] : [$raw];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $raw),
            static fn (string $v): bool => $v !== ''
        )));
    }
}
