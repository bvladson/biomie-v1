<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\DataGovernance\KpiDateContract;

/**
 * COM.R3 — Data canônica para métricas comerciais.
 *
 * Thin-wrapper semântico sobre KpiDateContract::fiscal().
 * Garante que todos os endpoints comerciais usem exatamente a mesma
 * expressão de data: COALESCE(data_competencia, data_emissao).
 *
 * Uso:
 *   CommercialMetricDate::metricDateSql('nf')
 *   → "COALESCE(nf.data_competencia, nf.data_emissao)"
 *
 *   CommercialMetricDate::competenciaSql('nf')
 *   → "DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')"
 *
 *   CommercialMetricDate::wherePeriod('nf', ':start', ':end')
 *   → "COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end"
 */
final class CommercialMetricDate
{
    /**
     * SQL expression for the canonical commercial metric date.
     *
     * @param string $alias Table alias (e.g. 'nf', 'n', '') — pass '' for no prefix.
     */
    public static function metricDateSql(string $alias = 'nf'): string
    {
        return KpiDateContract::fiscal($alias);
    }

    /**
     * DATE_FORMAT expression for GROUP BY competencia (YYYY-MM).
     */
    public static function competenciaSql(string $alias = 'nf'): string
    {
        return KpiDateContract::competencia('fiscal', $alias);
    }

    /**
     * WHERE clause fragment for period filtering.
     *
     * @param string $startParam PDO placeholder name (default ':start')
     * @param string $endParam   PDO placeholder name (default ':end')
     */
    public static function wherePeriod(
        string $alias      = 'nf',
        string $startParam = ':start',
        string $endParam   = ':end'
    ): string {
        return KpiDateContract::wherePeriod('fiscal', $alias, $startParam, $endParam);
    }

    /**
     * Returns the base PDO params for a period (no-alias version).
     *
     * @return array{':start': string, ':end': string}
     */
    public static function periodParams(string $start, string $end): array
    {
        return [':start' => $start, ':end' => $end];
    }
}
