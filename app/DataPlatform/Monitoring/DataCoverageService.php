<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Monitoring;

final class DataCoverageService
{
    public function __construct(
        private readonly DataCoverageRepository $repository
    ) {
    }

    public function getDatabaseInfo(): array
    {
        return $this->repository->getDatabaseInfo();
    }

    public function analyzeMonth(string $month): array
    {
        $tables = [
            'biomie_omie_bronze',
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
            'mart_fiscal_nfe',
            'mart_cliente_periodo',
            'mart_compradores_por_geo',
            'mart_vendedor_dia',
            'mart_produto_periodo',
            'mart_vendas_por_cnae_cnpj'
        ];

        $layers = [];
        foreach ($tables as $table) {
            $layers[$table] = $this->repository->checkTableCoverage($table, $month);
        }

        $canonicalNfs = $layers['biomie_notas_fiscais_canonicas']['rows_in_period'] ?? 0;
        $canonicalOrders = $layers['biomie_pedidos_canonicos']['rows_in_period'] ?? 0;
        $canonicalItems = $layers['biomie_notas_fiscais_itens_canonicos']['rows_in_period'] ?? 0;
        $martFiscal = $layers['mart_fiscal_nfe']['rows_in_period'] ?? 0;
        $martGeo = $layers['mart_compradores_por_geo']['rows_in_period'] ?? 0;
        $martProduto = $layers['mart_produto_periodo']['rows_in_period'] ?? 0;
        $martVendedor = $layers['mart_vendedor_dia']['rows_in_period'] ?? 0;
        $bronzePayloads = $layers['biomie_omie_bronze']['rows_in_period'] ?? 0;

        $status = 'ok';
        $firstBrokenLayer = null;
        $recommendedAction = null;
        $reason = null;

        // Reason logic backwards to find the ROOT cause.
        // If bronze is missing, that's the first broken layer.
        if ($bronzePayloads === 0 && $canonicalNfs === 0) {
            $status = 'critical';
            $reason = 'fiscal_sync_missing';
            $firstBrokenLayer = 'biomie_omie_bronze';
            $recommendedAction = 'Executar sync fiscal Omie para o periodo';
        } elseif ($bronzePayloads > 0 && $canonicalNfs === 0) {
            $status = 'critical';
            $reason = 'bronze_not_promoted_to_canonical';
            $firstBrokenLayer = 'biomie_notas_fiscais_canonicas';
            $recommendedAction = 'Executar promoção canonical fiscal';
        } elseif ($canonicalOrders > 0 && $canonicalNfs === 0) {
            $status = 'warning';
            $reason = 'orders_without_invoices';
            $firstBrokenLayer = 'biomie_notas_fiscais_canonicas';
            $recommendedAction = 'Verificar se NFs foram emitidas para os pedidos no periodo';
        } elseif ($canonicalNfs > 0 && $martFiscal === 0) {
            $status = 'critical';
            $reason = 'fiscal_mart_missing';
            $firstBrokenLayer = 'mart_fiscal_nfe';
            $recommendedAction = 'Executar rebuild fiscal/gold';
        } elseif ($canonicalNfs > 0 && $martGeo === 0) {
            $status = 'critical'; // The instruction said warning/critical, geo is important so critical
            $reason = 'geo_mart_missing';
            $firstBrokenLayer = 'mart_compradores_por_geo';
            $recommendedAction = 'Executar geo:rebuild-period';
        } elseif ($canonicalItems > 0 && $martProduto === 0) {
            $status = 'warning';
            $reason = 'product_mart_missing';
            $firstBrokenLayer = 'mart_produto_periodo';
            $recommendedAction = 'Executar materialização de produtos';
        } elseif ($canonicalNfs > 0 && $martVendedor === 0) {
            $status = 'warning';
            $reason = 'vendor_mart_missing';
            $firstBrokenLayer = 'mart_vendedor_dia';
            $recommendedAction = 'Executar rebuild vendedor dia';
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'first_broken_layer' => $firstBrokenLayer,
            'recommended_action' => $recommendedAction,
            'layers' => $layers,
        ];
    }
}
