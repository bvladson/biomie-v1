<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

/**
 * Allowlists and helpers for the finance title search endpoint.
 * Used by api/finance_search.php — never maps raw input to SQL directly.
 */
final class FinanceFilters
{
    /**
     * Allowed status values → SQL condition fragment.
     * :ref must be bound in the query as today's date (Y-m-d).
     *
     * @return array<string,string>
     */
    public static function statusConditions(): array
    {
        return [
            'recebido'   => "status_titulo IN ('liquidado','recebido','pago')",
            'aberto'     => "status_titulo NOT IN ('liquidado','recebido','pago') AND COALESCE(valor_aberto,0) > 0 AND data_vencimento >= :ref",
            'vencido'    => "status_titulo NOT IN ('liquidado','recebido','pago') AND COALESCE(valor_aberto,0) > 0 AND data_vencimento < :ref",
            'vence_hoje' => "status_titulo NOT IN ('liquidado','recebido','pago') AND data_vencimento = :ref",
            'a_vencer'   => "status_titulo NOT IN ('liquidado','recebido','pago') AND COALESCE(valor_aberto,0) > 0 AND data_vencimento > :ref",
        ];
    }

    /**
     * Allowed aging bucket values → SQL WHERE fragment.
     * :ref must be bound as today's date; requires status NOT IN received set.
     *
     * @return array<string,string>
     */
    public static function agingConditions(): array
    {
        return [
            'sem_atraso' => "data_vencimento >= :ref",
            '1_7'        => "DATEDIFF(:ref, data_vencimento) BETWEEN 1 AND 7",
            '8_15'       => "DATEDIFF(:ref, data_vencimento) BETWEEN 8 AND 15",
            '16_30'      => "DATEDIFF(:ref, data_vencimento) BETWEEN 16 AND 30",
            '31_60'      => "DATEDIFF(:ref, data_vencimento) BETWEEN 31 AND 60",
            '60_plus'    => "DATEDIFF(:ref, data_vencimento) > 60",
        ];
    }

    /**
     * Allowed date_field values → column name used for period filtering.
     * Default: data_vencimento (most operationally relevant for AR).
     *
     * @return array<string,string>
     */
    public static function dateFieldMap(): array
    {
        return [
            'vencimento'  => 'data_vencimento',
            'emissao'     => 'data_emissao',
            'pagamento'   => 'data_liquidacao',
            'competencia' => 'data_competencia',
        ];
    }

    public static function resolveDateField(string $field): string
    {
        return self::dateFieldMap()[$field] ?? 'data_vencimento';
    }

    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'vencimento'  => 'data_vencimento',
                'emissao'     => 'data_emissao',
                'pagamento'   => 'data_liquidacao',
                'valor'       => 'COALESCE(valor_liquido, valor_pago, 0)',
                'aberto'      => 'COALESCE(valor_aberto, 0)',
                'cliente'     => 'nome_cliente',
                'vendedor'    => 'nome_vendedor',
                'status'      => 'status_titulo',
                'atraso'      => 'data_vencimento',
            ],
            default: 'data_vencimento'
        );
    }
}
