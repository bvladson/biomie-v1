<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use PDO;

/**
 * AGENDA DE ORÇAMENTOS — resolve o(s) vendedor(es) de um usuário (Fase 10).
 *
 * Traduz a conta BIomie logada nos `seller_id` (códigos Omie) que ela pode ver.
 * É a fronteira de segurança da visão do vendedor: quem NÃO tem vínculo não vê
 * NADA (lista vazia), nunca a carteira inteira. "Sem vínculo" jamais degrada
 * para "vê tudo" — seria o pior modo de falhar.
 *
 * @see biomie_quote_seller_binding
 */
final class SellerBindingResolver
{
    private const TABLE = 'biomie_quote_seller_binding';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Códigos Omie que o usuário representa NUMA empresa.
     *
     * @return list<string> vazio = usuário não é vendedor mapeado nessa empresa
     */
    public function sellerIdsFor(int $userId, int $companyId): array
    {
        if ($userId <= 0 || $companyId <= 0) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT seller_id FROM ' . self::TABLE . '
              WHERE user_id = :u AND company_id = :c
              ORDER BY seller_id'
        );
        $st->execute(['u' => $userId, 'c' => $companyId]);

        // seller_id = '0' (pedido sem vendedor no Omie) NUNCA é vinculável: não
        // representa uma pessoa. Filtrado para não virar uma "carteira fantasma".
        return array_values(array_filter(
            array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $s): bool => $s !== '' && $s !== '0'
        ));
    }

    /**
     * O usuário é um vendedor mapeado nesta empresa?
     */
    public function isBoundSeller(int $userId, int $companyId): bool
    {
        return $this->sellerIdsFor($userId, $companyId) !== [];
    }

    /**
     * Todos os vínculos do usuário, por empresa. Para telas/diagnóstico.
     *
     * @return array<int,list<string>> company_id => [seller_id,...]
     */
    public function bindingsFor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT company_id, seller_id FROM ' . self::TABLE . '
              WHERE user_id = :u ORDER BY company_id, seller_id'
        );
        $st->execute(['u' => $userId]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (string) $r['seller_id'];
            if ($sid === '' || $sid === '0') {
                continue;
            }
            $out[(int) $r['company_id']][] = $sid;
        }

        return $out;
    }
}
