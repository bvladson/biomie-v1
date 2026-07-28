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
            CnaeSourceContracts::COVERAGE => new SourceContract(
                id: CnaeSourceContracts::COVERAGE,
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + documento + cnae_status',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty', 'not_applicable_cpf', 'missing_cnpj']
            ),
            CnaeSourceContracts::RANKING => new SourceContract(
                id: CnaeSourceContracts::RANKING,
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + cnae',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty', 'sem_cnae']
            ),
            CnaeSourceContracts::OPPORTUNITIES => new SourceContract(
                id: CnaeSourceContracts::OPPORTUNITIES,
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + cnpj sem cnae',
                requiredFilters: ['competencia_between', 'cnae_status = missing_cnpj'],
                allowedFallbacks: ['empty']
            ),
            CnaeSourceContracts::CUSTOMER_STATUS => new SourceContract(
                id: CnaeSourceContracts::CUSTOMER_STATUS,
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + cliente',
                requiredFilters: ['competencia_between', 'customer_identity'],
                allowedFallbacks: ['empty', 'not_applicable_cpf']
            ),
            CnaeSourceContracts::GEO_DISTRIBUTION => new SourceContract(
                id: CnaeSourceContracts::GEO_DISTRIBUTION,
                domain: 'cnae',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'competencia + cnae + cidade + bairro',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty', 'sem_geo']
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
            CustomerSourceContracts::PROFILE => new SourceContract(
                id: CustomerSourceContracts::PROFILE,
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_dim',
                periodColumn: 'updated_at',
                grain: 'codigo_cliente_omie',
                requiredFilters: ['customer_identity'],
                allowedFallbacks: ['canonical_nf_minimal_profile', 'mart_minimal_profile']
            ),
            CustomerSourceContracts::IDENTITY => new SourceContract(
                id: CustomerSourceContracts::IDENTITY,
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_dim',
                periodColumn: 'updated_at',
                grain: 'codigo_cliente_omie + documento_normalizado',
                requiredFilters: ['customer_identity'],
                allowedFallbacks: ['unknown_document']
            ),
            CustomerSourceContracts::COMMERCIAL_HISTORY => new SourceContract(
                id: CustomerSourceContracts::COMMERCIAL_HISTORY,
                domain: 'customers',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_cliente_periodo',
                periodColumn: 'competencia',
                grain: 'competencia + codigo_cliente_omie',
                requiredFilters: ['competencia_between', 'customer_identity'],
                allowedFallbacks: ['empty']
            ),
            CustomerSourceContracts::GEO_STATUS => new SourceContract(
                id: CustomerSourceContracts::GEO_STATUS,
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_geo_dim',
                periodColumn: 'updated_at',
                grain: 'codigo_cliente_omie + cidade_key + bairro_key',
                requiredFilters: ['customer_identity'],
                allowedFallbacks: ['geo_sem_cidade', 'geo_sem_bairro']
            ),
            CustomerSourceContracts::CNAE_STATUS => new SourceContract(
                id: CustomerSourceContracts::CNAE_STATUS,
                domain: 'customers',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_vendas_por_cnae_cnpj',
                periodColumn: 'competencia',
                grain: 'codigo_cliente_omie + cnae_status',
                requiredFilters: ['customer_identity'],
                allowedFallbacks: ['not_applicable_cpf', 'missing_cnpj']
            ),
            CustomerSourceContracts::QUALITY => new SourceContract(
                id: CustomerSourceContracts::QUALITY,
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_dim',
                periodColumn: 'updated_at',
                grain: 'cliente + quality_status',
                requiredFilters: ['optional_period'],
                allowedFallbacks: ['empty']
            ),
            CustomerSourceContracts::SEARCH => new SourceContract(
                id: CustomerSourceContracts::SEARCH,
                domain: 'customers',
                sourceTier: DataSourceTruth::SILVER,
                table: 'biomie_clientes_dim',
                periodColumn: 'updated_at',
                grain: 'codigo_cliente_omie',
                requiredFilters: ['search_or_filters'],
                allowedFallbacks: ['empty']
            ),
            CustomerSourceContracts::OPPORTUNITIES => new SourceContract(
                id: CustomerSourceContracts::OPPORTUNITIES,
                domain: 'customers',
                sourceTier: DataSourceTruth::GOLD,
                table: 'mart_cliente_periodo',
                periodColumn: 'competencia',
                grain: 'competencia + cliente + quality_gap',
                requiredFilters: ['competencia_between'],
                allowedFallbacks: ['empty']
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
