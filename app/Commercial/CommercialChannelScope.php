<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use PDO;

/**
 * AUTORIZAÇÃO DE CANAL (varejo/licitação) por usuário × empresa.
 *
 * Espelha o SellerVisibilityScope, do outro eixo: enquanto aquele responde
 * "quais vendedores", este responde "quais operações comerciais". A fonte é
 * `biomie_user_companies.can_varejo/can_licitacao` — a MESMA que o TenantContext
 * usa para montar allowedBusinessScopes, então não há segunda verdade.
 *
 * ─── SEMÂNTICA (allowlist rígida) ───────────────────────────────────────────
 *   allowedChannels(): SEMPRE uma lista concreta dos canais autorizados
 *                      (['varejo'], ['licitacao'] ou ['varejo','licitacao']).
 *                      Diferente do escopo de vendedor, aqui NÃO existe "null =
 *                      todos": canal é conjunto fechado e pequeno; "todos
 *                      autorizados" = a lista inteira, explícita.
 *   resolve(requested): interseção com o autorizado. Pedido fora do autorizado
 *                       ⇒ CommercialScopeDeniedException (o endpoint responde
 *                       403). Pedido vazio ⇒ todos os autorizados.
 *
 * Um `?channel=licitacao` adulterado por quem só tem varejo NÃO libera nada:
 * a autorização é recalculada aqui, no servidor, a cada requisição.
 */
final class CommercialChannelScope
{
    /** Conjunto fechado de canais válidos. Valide por allowlist antes de usar em SQL. */
    public const CHANNELS = ['varejo', 'licitacao'];

    /** @var array<string,list<string>> cache por request: "company:user" => canais */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Canais que o usuário pode ver nesta empresa. Lista concreta (nunca null).
     * Vazia ⇒ o usuário não tem NENHUM canal (não vê dado comercial).
     *
     * @return list<string>
     */
    public function allowedChannels(int $companyId, int $userId): array
    {
        if ($companyId <= 0 || $userId <= 0) {
            return [];
        }
        $key = $companyId . ':' . $userId;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $st = $this->pdo->prepare(
            'SELECT can_varejo, can_licitacao FROM biomie_user_companies
              WHERE company_id = :c AND user_id = :u'
        );
        $st->execute(['c' => $companyId, 'u' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $allowed = [];
        if ($row !== false) {
            if ((int) ($row['can_varejo'] ?? 0) === 1) {
                $allowed[] = 'varejo';
            }
            if ((int) ($row['can_licitacao'] ?? 0) === 1) {
                $allowed[] = 'licitacao';
            }
        }

        return $this->cache[$key] = $allowed;
    }

    /**
     * Canais EFETIVOS: interseção entre o autorizado e o pedido pelo usuário.
     *
     * @param  list<string> $requested vazio = "todos os autorizados"
     * @return list<string>
     * @throws CommercialScopeDeniedException canal fora do allowlist ou não autorizado
     */
    public function resolve(int $companyId, int $userId, array $requested): array
    {
        $allowed = $this->allowedChannels($companyId, $userId);

        if ($requested === []) {
            return $allowed;                       // "Todos autorizados"
        }

        // Allowlist ANTES de qualquer uso: um valor fora do conjunto fechado é
        // adulteração, não filtro.
        $requested = array_values(array_unique(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $requested
        )));
        foreach ($requested as $c) {
            if (!in_array($c, self::CHANNELS, true)) {
                throw new CommercialScopeDeniedException('Operação comercial inválida.');
            }
        }

        // Pedir um canal que não se tem autorização é 403 — não silenciosamente
        // reduzido, para a UI não mascarar uma tentativa indevida como "vazio".
        $forbidden = array_values(array_diff($requested, $allowed));
        if ($forbidden !== []) {
            throw new CommercialScopeDeniedException('Operação comercial não autorizada.');
        }

        return array_values(array_intersect($allowed, $requested));
    }

    public function canSee(int $companyId, int $userId, string $channel): bool
    {
        return in_array(strtolower(trim($channel)), $this->allowedChannels($companyId, $userId), true);
    }
}
