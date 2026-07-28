<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * UNIVERSO COMERCIAL DE NFs — a definição ÚNICA de "quais NFs contam".
 *
 * Antes vivia copiada em 4 lugares do daily_sales_integrity_check e, com regra
 * DIFERENTE, dentro de VendorDayMartBuilder::visibilityFragment. Foi essa
 * divergência que produziu o falso D11 de 2026-07-23 (licitação contada no mart,
 * excluída no baseline). Agora o CHECK tem uma fonte só.
 *
 * ─── A REGRA DE NEGÓCIO (Fase L) ────────────────────────────────────────────
 * Uma NF pertence ao universo comercial quando:
 *   1. status_fiscal = 'autorizada'   — cancelada/denegada não conta;
 *   2. operation_scope = 'commercial' — remessa/uso interno/transferência fora;
 *   3. É VISÍVEL no comercial OU é LICITAÇÃO:
 *        business_channel = 'licitacao'         → SEMPRE preservada (contexto próprio),
 *        OU is_biomie_commercial_visible = 1    → varejo visível.
 *      Varejo explicitamente invisível (remessa marcada, etc.) NÃO entra.
 *
 * ─── DUAS CAMADAS, DE PROPÓSITO ─────────────────────────────────────────────
 * O daily check usa esta SQL nos dois lados que compara → PARIDADE numérica.
 * MAS paridade sozinha é circular: se a regra estiver errada, os dois lados
 * erram juntos e "batem". Por isso existe também run_commercial_nf_universe.php,
 * que fixa o resultado esperado de fixtures conhecidas (varejo→inclui,
 * licitação→preserva, remessa→exclui, cancelada→exclui, outra empresa→isola) e
 * prova que ESTA regra classifica cada operação no contexto certo. Se a regra
 * mudar errado, a paridade continua verde mas o teste semântico QUEBRA.
 */
final class CommercialNfUniverse
{
    /**
     * Predicado SQL de pertencimento ao universo comercial (inclui licitação).
     *
     * @param string $alias alias da tabela de NF ('' para tabela sem alias)
     */
    public static function sql(string $alias = 'nf'): string
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return "{$p}status_fiscal = 'autorizada'"
            . " AND {$p}operation_scope = 'commercial'"
            . " AND ({$p}business_channel = 'licitacao'"
            . " OR COALESCE({$p}is_biomie_commercial_visible, 1) = 1)";
    }

    /**
     * Classifica UMA linha (para o teste semântico e diagnósticos). Espelha a
     * SQL acima em PHP — a duplicação é PROPOSITAL: se as duas discordarem, o
     * teste semântico acusa, o que é exatamente o ponto (não confiar cegamente
     * numa fonte só).
     *
     * @param array<string,mixed> $nf
     * @return 'varejo'|'licitacao'|'excluida' contexto ao qual a NF pertence
     */
    public static function classify(array $nf): string
    {
        $status = (string) ($nf['status_fiscal'] ?? '');
        $scope  = (string) ($nf['operation_scope'] ?? '');
        if ($status !== 'autorizada' || $scope !== 'commercial') {
            return 'excluida';
        }

        $channel = (string) ($nf['business_channel'] ?? '');
        if ($channel === 'licitacao') {
            return 'licitacao';
        }

        // Varejo: só entra se visível. NULL ⇒ visível (COALESCE 1).
        $visible = $nf['is_biomie_commercial_visible'];
        $isVisible = $visible === null || (int) $visible === 1;

        return $isVisible ? 'varejo' : 'excluida';
    }
}
