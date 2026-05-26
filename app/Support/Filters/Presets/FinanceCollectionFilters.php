<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

/**
 * Allowlists for the finance collection-by-vendor endpoint.
 * This preset is intentionally narrower than FinanceFilters: it only reads
 * open receivables and uses due date as the operational collection axis.
 */
final class FinanceCollectionFilters
{
    public const VENDOR_LABEL_MISSING = 'Sem vendedor definido';

    public static function openCondition(): string
    {
        return "status_titulo NOT IN ('liquidado','recebido','pago') AND COALESCE(valor_aberto, 0) > 0";
    }

    public static function vendorMissingCondition(): string
    {
        return "(NULLIF(TRIM(COALESCE(codigo_vendedor_omie, '')), '') IS NULL"
            . " AND (NULLIF(TRIM(COALESCE(nome_vendedor, '')), '') IS NULL"
            . " OR LOWER(TRIM(nome_vendedor)) IN ('sem vendedor', 'sem vendedor definido', 'nao informado', 'nao identificado')))";
    }

    public static function vendorNameExpression(): string
    {
        return "CASE WHEN " . self::vendorMissingCondition()
            . " THEN '" . self::VENDOR_LABEL_MISSING . "'"
            . " ELSE COALESCE(NULLIF(TRIM(nome_vendedor), ''), CONCAT('Vendedor ', TRIM(codigo_vendedor_omie))) END";
    }

    public static function vendorKeyExpression(): string
    {
        return "CASE WHEN " . self::vendorMissingCondition()
            . " THEN '__sem_vendedor__' ELSE COALESCE(NULLIF(TRIM(codigo_vendedor_omie), ''), TRIM(nome_vendedor)) END";
    }

    /**
     * @return array<string,string>
     */
    public static function statusConditions(): array
    {
        return [
            'aberto'     => self::openCondition(),
            'vencido'    => 'data_vencimento < :ref_status_vencido',
            'vence_hoje' => 'data_vencimento = :ref_status_hoje',
            'a_vencer'   => 'data_vencimento > :ref_status_a_vencer',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function agingConditions(): array
    {
        return [
            'a_vencer'   => 'data_vencimento > :ref_aging_a_vencer',
            'vence_hoje' => 'data_vencimento = :ref_aging_hoje',
            '1_7'        => 'DATEDIFF(:ref_aging_1_7, data_vencimento) BETWEEN 1 AND 7',
            '8_15'       => 'DATEDIFF(:ref_aging_8_15, data_vencimento) BETWEEN 8 AND 15',
            '16_30'      => 'DATEDIFF(:ref_aging_16_30, data_vencimento) BETWEEN 16 AND 30',
            '31_60'      => 'DATEDIFF(:ref_aging_31_60, data_vencimento) BETWEEN 31 AND 60',
            '60_plus'    => 'DATEDIFF(:ref_aging_60_plus, data_vencimento) > 60',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function bucketLabels(): array
    {
        return [
            'a_vencer'   => 'A vencer',
            'vence_hoje' => 'Vence hoje',
            '1_7'        => 'Vencido 1-7 dias',
            '8_15'       => 'Vencido 8-15 dias',
            '16_30'      => 'Vencido 16-30 dias',
            '31_60'      => 'Vencido 31-60 dias',
            '60_plus'    => 'Vencido 60+ dias',
        ];
    }

    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'vencimento'   => 'data_vencimento',
                'atraso'       => 'data_vencimento',
                'valor_aberto' => 'COALESCE(valor_aberto, 0)',
                'cliente'      => 'nome_cliente',
                'vendedor'     => self::vendorNameExpression(),
                'status'       => 'status_titulo',
                'documento'    => 'numero_documento',
            ],
            default: 'data_vencimento'
        );
    }
}
