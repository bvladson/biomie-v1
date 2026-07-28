<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

/**
 * Contrato oficial de datas por domínio e KPI.
 *
 * Problema resolvido:
 *   O mesmo número aparece diferente em páginas diferentes quando cada
 *   endpoint escolhe arbitrariamente qual coluna de data usar para filtrar.
 *
 * Regras canônicas:
 *
 *   DOMÍNIO COMERCIAL (pedidos):
 *     data_referencia = data_faturamento ?? data_emissao
 *     Razão: data_faturamento é o momento em que a venda foi confirmada;
 *            data_emissao é o fallback quando o faturamento não foi preenchido.
 *
 *   DOMÍNIO FISCAL (NF-e):
 *     data_referencia = data_competencia ?? data_emissao
 *     Razão: a competência fiscal é o período oficial para apuração de impostos;
 *            data_emissao é o fallback (especialmente em notas retroativas).
 *
 *   DOMÍNIO FINANCEIRO (títulos/recebimentos):
 *     filtro por período  = data_competencia ?? data_vencimento
 *     KPI recebimento     = data_liquidacao   (sem fallback — nulo = não recebido)
 *     KPI inadimplência   = data_vencimento   (quando < HOJE e valor_aberto > 0)
 *
 *   COMPETÊNCIA (agregação mensal):
 *     = DATE_FORMAT(data_referencia_do_domínio, '%Y-%m')
 *     A string de competência é sempre derivada da data_referencia acima.
 *
 * Todos os métodos retornam expressões SQL prontas para uso em queries.
 * Nenhum outro arquivo deve codificar essas regras de data diretamente.
 */
final class KpiDateContract
{
    // ── Constantes de nome de KPI ────────────────────────────────────────────

    public const KPI_RECEITA_COMERCIAL = 'receita_comercial';
    public const KPI_RECEITA_FISCAL    = 'receita_fiscal';
    public const KPI_COMPETENCIA       = 'competencia';
    public const KPI_RECEBIMENTO       = 'recebimento';
    public const KPI_INADIMPLENCIA     = 'inadimplencia';
    public const KPI_VENCIMENTO        = 'vencimento';

    // ── Expressões SQL por domínio ───────────────────────────────────────────

    /**
     * Data de referência do domínio COMERCIAL (pedidos).
     *
     * data_faturamento ?? data_emissao
     *
     * @param string $alias Prefixo de tabela, ex.: 'p' para "p.data_faturamento"
     */
    public static function commercial(string $alias = ''): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return "COALESCE({$p}data_faturamento, {$p}data_emissao)";
    }

    /**
     * Data de referência do domínio FISCAL (NF-e).
     *
     * data_competencia ?? data_emissao
     */
    public static function fiscal(string $alias = ''): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return "COALESCE({$p}data_competencia, {$p}data_emissao)";
    }

    /**
     * Data de referência do domínio FINANCEIRO (títulos) para filtro de período.
     *
     * data_competencia ?? data_vencimento
     */
    public static function financial(string $alias = ''): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return "COALESCE({$p}data_competencia, {$p}data_vencimento)";
    }

    /**
     * Data de recebimento efetivo (financeiro).
     *
     * data_liquidacao — sem fallback; NULL significa não recebido.
     */
    public static function received(string $alias = ''): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return "{$p}data_liquidacao";
    }

    /**
     * Data de vencimento (para KPI de inadimplência).
     *
     * data_vencimento — filtrável por < CURDATE() AND valor_aberto > 0.
     */
    public static function due(string $alias = ''): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return "{$p}data_vencimento";
    }

    /**
     * Expressão de competência (YYYY-MM) derivada da data de referência
     * do domínio informado.
     *
     * @param string $domain 'commercial' | 'fiscal' | 'financial'
     */
    public static function competencia(string $domain, string $alias = ''): string
    {
        $dateExpr = match ($domain) {
            'fiscal'     => self::fiscal($alias),
            'financial'  => self::financial($alias),
            default      => self::commercial($alias),
        };

        return "DATE_FORMAT({$dateExpr}, '%Y-%m')";
    }

    /**
     * Cláusula WHERE completa para filtro de período por domínio.
     *
     * Ex.: KpiDateContract::wherePeriod('commercial', 'p', ':start', ':end')
     *   → "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end"
     *
     * @param string $domain    'commercial' | 'fiscal' | 'financial'
     * @param string $alias     Prefixo de tabela
     * @param string $startParam Nome do parâmetro PDO para início (padrão: ':start')
     * @param string $endParam  Nome do parâmetro PDO para fim (padrão: ':end')
     */
    public static function wherePeriod(
        string $domain,
        string $alias = '',
        string $startParam = ':start',
        string $endParam = ':end',
    ): string {
        $dateExpr = match ($domain) {
            'fiscal'    => self::fiscal($alias),
            'financial' => self::financial($alias),
            default     => self::commercial($alias),
        };

        return "{$dateExpr} BETWEEN {$startParam} AND {$endParam}";
    }

    /**
     * Cláusula para calcular dias de atraso de um título.
     *
     * CASE WHEN data_vencimento < :today AND valor_aberto > 0
     *      THEN DATEDIFF(:today, data_vencimento)
     *      ELSE 0 END
     *
     * @param string $alias      Prefixo de tabela para data_vencimento e valor_aberto
     * @param string $todayParam Nome do parâmetro PDO com a data de hoje (padrão: ':today')
     */
    public static function diasAtraso(string $alias = '', string $todayParam = ':today'): string
    {
        $p = $alias !== '' ? "{$alias}." : '';
        return <<<SQL
            CASE
                WHEN {$p}data_vencimento IS NOT NULL
                     AND {$p}data_vencimento < {$todayParam}
                     AND {$p}valor_aberto > 0
                THEN DATEDIFF({$todayParam}, {$p}data_vencimento)
                ELSE 0
            END
        SQL;
    }

    /**
     * Retorna a documentação completa do contrato como array associativo.
     * Útil para endpoints de status / observabilidade.
     *
     * @return array<string,string>
     */
    public static function documentation(): array
    {
        return [
            'receita_comercial_date' => 'data_faturamento ?? data_emissao',
            'receita_fiscal_date'    => 'data_competencia ?? data_emissao',
            'financeiro_periodo'     => 'data_competencia ?? data_vencimento',
            'recebimento_date'       => 'data_liquidacao (NULL = não recebido)',
            'inadimplencia_date'     => 'data_vencimento < HOJE AND valor_aberto > 0',
            'competencia_format'     => 'DATE_FORMAT(data_referencia, \'%Y-%m\')',
            'fonte'                  => 'BIomie\\DataGovernance\\KpiDateContract',
        ];
    }
}
