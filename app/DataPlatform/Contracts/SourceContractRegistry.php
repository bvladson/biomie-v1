<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Contracts;

use BIomie\DataPlatform\Semantic\DataSourceTruth;
use OutOfBoundsException;

final class SourceContractRegistry
{
    /** @var array<string,SourceContract>|null */
    private static ?array $cache = null;

    /** @return array<string,SourceContract> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            'geo.sales_by_territory.v1' => new SourceContract(
                id: 'geo.sales_by_territory.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_compradores_por_geo',
                periodColumn: 'competencia',
                grain: 'competencia + cidade_key + bairro_key + cliente + vendedor',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty']
            ),
            'geo.map_payload.v1' => new SourceContract(
                id: 'geo.map_payload.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_compradores_por_geo',
                periodColumn: 'competencia',
                grain: 'competencia + territorio',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty']
            ),
            'geo.sales_by_neighborhood.v1' => new SourceContract(
                id: 'geo.sales_by_neighborhood.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_compradores_por_geo',
                periodColumn: 'competencia',
                grain: 'competencia + cidade + bairro',
                requiredFilters: ['competencia_between', 'layer'],
                allowedFallbacks: ['empty']
            ),
            'geo.territory_buyers.v1' => new SourceContract(
                id: 'geo.territory_buyers.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_compradores_por_geo',
                periodColumn: 'competencia',
                grain: 'competencia + territorio + cliente',
                requiredFilters: ['competencia_between', 'territory_filter'],
                allowedFallbacks: ['empty']
            ),
            'geo.territory_cnaes.v1' => new SourceContract(
                id: 'geo.territory_cnaes.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_compradores_por_geo',
                periodColumn: 'competencia',
                grain: 'competencia + territorio + cnae',
                requiredFilters: ['competencia_between', 'territory_filter'],
                allowedFallbacks: ['empty', 'sem_cnae']
            ),
            'geo.territory_invoices.v1' => new SourceContract(
                id: 'geo.territory_invoices.v1',
                domain: 'geo',
                sourceTier: DataSourceTruth::CANONICAL,
                table: 'biomie_notas_fiscais_canonicas',
                periodColumn: 'data_competencia',
                grain: 'territory + nota_key',
                requiredFilters: ["status_fiscal = 'autorizada'", 'territory_filter', 'BiVisibilityScope'],
                allowedFallbacks: ['empty']
            ),
            'cnae.sales_by_cnpj.v1' => new SourceContract(
                id: 'cnae.sales_by_cnpj.v1',
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + cnae + documento',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty', 'not_applicable_cpf']
            ),
            'fiscal.invoice_drilldown.v1' => new SourceContract(
                id: 'fiscal.invoice_drilldown.v1',
                domain: 'fiscal',
                sourceTier: DataSourceTruth::CANONICAL,
                table: 'biomie_notas_fiscais_canonicas',
                periodColumn: 'data_competencia',
                grain: 'nota_key',
                requiredFilters: ["status_fiscal = 'autorizada'", 'BiVisibilityScope'],
                allowedFallbacks: []
            ),
            'customer.profile.v1' => new SourceContract(
                id: 'customer.profile.v1',
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_dim',
                periodColumn: 'updated_at',
                grain: 'codigo_cliente_omie',
                requiredFilters: ['customer_identity'],
                allowedFallbacks: ['canonical_nf_minimal_profile', 'mart_minimal_profile']
            ),
        ];

        return self::$cache;
    }

    public static function get(string $id): SourceContract
    {
        $all = self::all();
        if (!isset($all[$id])) {
            throw new OutOfBoundsException("Source contract '{$id}' not found.");
        }

        return $all[$id];
    }
}
