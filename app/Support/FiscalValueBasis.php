<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * Fase O — cálculo unificado da base comercial de NF.
 *
 * Faturamento Orçamento-PD = valor_total_nf − frete − outras despesas acessórias − seguro.
 * IPI permanece na base porque os vendedores recebem comissão sobre o valor faturado com IPI.
 *
 * Fórmula: valor_base_comercial = valor_total − valor_frete − valor_outras_despesas − valor_seguro
 */
final class FiscalValueBasis
{
    /**
     * Retorna expressão SQL que calcula valor_base_comercial_nf inline.
     *
     * Usa COALESCE para ser seguro mesmo quando colunas são NULL (NFs históricas
     * sincronizadas antes da Fase O). Nesse caso a expressão equivale a valor_total.
     *
     * @param string $alias alias da tabela sem ponto (ex: 'nf', 'n') ou '' para sem alias
     */
    public static function commercialBaseSql(string $alias = ''): string
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return "({$p}valor_total"
            . " - COALESCE({$p}valor_frete, 0)"
            . " - COALESCE({$p}valor_outras_despesas, 0)"
            . " - COALESCE({$p}valor_seguro, 0))";
    }

    /**
     * Retorna expressão SQL usando a coluna pré-computada quando disponível (Fase O.1).
     *
     * Prefere valor_base_comercial_nf se não-null, senão cai na fórmula inline.
     *
     * @param string $alias alias de tabela sem ponto ('nf', 'n', '')
     */
    public static function preComputedBaseSql(string $alias = ''): string
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return 'COALESCE('
            . "{$p}valor_base_comercial_nf, "
            . self::commercialBaseSql($alias)
            . ')';
    }

    /**
     * Calcula valor_base_comercial a partir de uma linha PHP.
     *
     * Fase O.1: prefere valor_base_comercial_nf pré-computado quando presente.
     *
     * @param array<string,mixed> $row
     */
    public static function commercialBaseFromRow(array $row): float
    {
        if (isset($row['valor_base_comercial_nf']) && $row['valor_base_comercial_nf'] !== null) {
            return max(0.0, (float) $row['valor_base_comercial_nf']);
        }

        return max(0.0,
            (float) ($row['valor_total'] ?? 0)
            - (float) ($row['valor_frete'] ?? 0)
            - (float) ($row['valor_outras_despesas'] ?? 0)
            - (float) ($row['valor_seguro'] ?? 0)
        );
    }

    /** @param array<string,mixed> $row */
    public static function hasFreight(array $row): bool
    {
        return (bool) ($row['has_frete'] ?? false)
            || (float) ($row['valor_frete'] ?? 0) > 0.009;
    }

    /** @param array<string,mixed> $row */
    public static function hasOtherExpenses(array $row): bool
    {
        return (bool) ($row['has_outras_despesas'] ?? false)
            || (float) ($row['valor_outras_despesas'] ?? 0) > 0.009;
    }

    /** @param array<string,mixed> $row */
    public static function hasInsurance(array $row): bool
    {
        return (bool) ($row['has_seguro'] ?? false)
            || (float) ($row['valor_seguro'] ?? 0) > 0.009;
    }

    /**
     * Verdadeiro se a NF tem pelo menos um componente não comissionável
     * (frete, outras despesas acessórias ou seguro).
     *
     * @param array<string,mixed> $row
     */
    public static function hasNonCommissionableAddons(array $row): bool
    {
        return (bool) ($row['has_componentes_nao_comissionaveis'] ?? false)
            || self::hasFreight($row)
            || self::hasOtherExpenses($row)
            || self::hasInsurance($row);
    }

    /**
     * Retorna todos os componentes fiscais relevantes de uma linha, incluindo
     * o valor_base_comercial já calculado.
     *
     * Fase O.1: usa valor_base_comercial_nf pré-computado quando disponível.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function fiscalComponentsFromRow(array $row): array
    {
        $total  = (float) ($row['valor_total'] ?? 0);
        $merch  = (float) ($row['valor_mercadorias'] ?? 0);
        $disc   = (float) ($row['valor_descontos'] ?? 0);
        $ipi    = (float) ($row['valor_ipi'] ?? 0);
        $frete  = (float) ($row['valor_frete'] ?? 0);
        $outras = (float) ($row['valor_outras_despesas'] ?? 0);
        $seguro = (float) ($row['valor_seguro'] ?? 0);

        return [
            'valor_total'                        => $total,
            'valor_mercadorias'                  => $merch,
            'valor_descontos'                    => $disc,
            'valor_ipi'                          => $ipi,
            'valor_frete'                        => $frete,
            'valor_outras_despesas'              => $outras,
            'valor_seguro'                       => $seguro,
            'valor_base_comercial'               => self::commercialBaseFromRow($row),
            'has_frete'                          => self::hasFreight($row),
            'has_outras_despesas'                => self::hasOtherExpenses($row),
            'has_seguro'                         => self::hasInsurance($row),
            'has_componentes_nao_comissionaveis' => self::hasNonCommissionableAddons($row),
            'fiscal_basis_source'                => (string) ($row['fiscal_basis_source'] ?? ''),
        ];
    }
}
