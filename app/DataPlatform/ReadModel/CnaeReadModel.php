<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\DataPlatform\Contracts\CnaeSourceContracts;
use BIomie\DataPlatform\Contracts\ReadModelResponse;
use BIomie\DataPlatform\Contracts\SourceContractRegistry;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\Repository\CnaeReadRepository;
use BIomie\Support\DocumentMask;

final class CnaeReadModel
{
    public function __construct(private readonly CnaeReadRepository $repo) {}

    /** @return array{query_count:int,sql_time_ms:float} */
    public function performanceMetrics(): array
    {
        return $this->repo->metrics();
    }

    public function resetPerformanceMetrics(): void
    {
        $this->repo->resetMetrics();
    }

    public function coverage(CnaeQuery $query): ReadModelResponse
    {
        $coverage = $this->repo->coverage($query);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CnaeSourceContracts::COVERAGE),
            data: ['coverage' => $coverage],
            meta: $this->meta($query, 'CnaeReadModel'),
            insights: $this->coverageInsights($coverage),
            warnings: $this->coverageWarnings($coverage),
        );
    }

    public function ranking(CnaeQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CnaeSourceContracts::RANKING),
            data: ['items' => $this->repo->ranking($query)],
            meta: $this->meta($query, 'CnaeReadModel'),
        );
    }

    public function opportunities(CnaeQuery $query): ReadModelResponse
    {
        $effectiveQuery = $this->opportunityQuery($query);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CnaeSourceContracts::OPPORTUNITIES),
            data: ['items' => $this->repo->companies($effectiveQuery)],
            meta: $this->meta($effectiveQuery, 'CnaeReadModel'),
            insights: ['CNPJ sem CNAE deve ser priorizado por faturamento e recorrencia.'],
        );
    }

    public function buyers(CnaeQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CnaeSourceContracts::CUSTOMER_STATUS),
            data: ['items' => $this->repo->companies($query)],
            meta: $this->meta($query, 'CnaeReadModel'),
        );
    }

    public function geoDistribution(CnaeQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CnaeSourceContracts::GEO_DISTRIBUTION),
            data: ['items' => $this->repo->geoDistribution($query)],
            meta: $this->meta($query, 'CnaeReadModel'),
        );
    }

    /** @return array<string,mixed> */
    public function legacyCompanyRankingPayload(CnaeQuery $query, bool $canViewFullDocuments = false): array
    {
        $items = $query->mode === 'top_cnaes'
            ? $this->repo->ranking($query)
            : $this->repo->companies($query);
        $summary = $this->summaryFromItems($items);

        $normalizedItems = [];
        foreach ($items as $row) {
            $doc = (string) ($row['documento'] ?? '');
            $masked = $canViewFullDocuments ? $doc : DocumentMask::maskDocument($doc, 'Nao informado');
            $normalizedItems[] = [
                'cnae_codigo' => (string) ($row['cnae_codigo'] ?? 'SEM_CNAE'),
                'cnae_descricao' => (string) ($row['cnae_descricao'] ?? 'CNAE nao informado'),
                'segmento_macro' => (string) ($row['segmento_macro'] ?? 'Nao classificado'),
                'cliente_nome' => (string) ($row['cliente_nome'] ?? ''),
                'documento' => $masked,
                'documento_mascarado' => $masked,
                'document_type' => (string) ($row['document_type'] ?? ''),
                'document_label' => ((string) ($row['document_type'] ?? '')) === 'cnpj' ? 'CNPJ' : (((string) ($row['document_type'] ?? '')) === 'cpf' ? 'CPF' : 'Documento'),
                'buyer_kind' => (string) ($row['buyer_kind'] ?? ''),
                'is_b2b' => (bool) ($row['is_b2b'] ?? false),
                'cnae_status' => (string) ($row['cnae_status'] ?? ''),
                'cnae_status_label' => (string) ($row['cnae_status_label'] ?? ''),
                'cidade_key' => (string) ($row['cidade_key'] ?? ''),
                'bairro_key' => (string) ($row['bairro_key'] ?? ''),
                'faturamento' => (float) ($row['revenue'] ?? 0),
                'notas' => (int) ($row['invoices'] ?? 0),
                'empresas' => (int) ($row['customers'] ?? 0),
                'ultima_compra' => $row['last_purchase'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'mode' => $query->mode,
            'document_type_filter' => $query->documentType,
            'source_contract' => CnaeSourceContracts::CUSTOMER_STATUS,
            'summary' => $summary,
            'items' => $normalizedItems,
            'meta' => $this->meta($query, 'CnaeReadModel'),
        ];
    }

    /** @return array<string,mixed> */
    public function legacySalesByCnaePayload(CnaeQuery $query): array
    {
        $items = $this->repo->ranking($query);
        $data = array_map(static fn (array $row): array => [
            'cnae' => $row['cnae_codigo'] ?? 'SEM_CNAE',
            'cnae_description' => $row['cnae_descricao'] ?? 'CNAE nao informado',
            'segment' => $row['segmento_macro'] ?? null,
            'revenue' => (float) ($row['revenue'] ?? 0),
            'customers' => (int) ($row['customers'] ?? 0),
            'invoices' => (int) ($row['invoices'] ?? 0),
            'cnae_codigo' => $row['cnae_codigo'] ?? 'SEM_CNAE',
            'cnae_descricao' => $row['cnae_descricao'] ?? 'CNAE nao informado',
            'segmento_macro' => $row['segmento_macro'] ?? null,
            'cnae_status' => $row['cnae_status'] ?? null,
            'faturamento' => (float) ($row['revenue'] ?? 0),
            'qtd_clientes' => (int) ($row['customers'] ?? 0),
            'qtd_notas' => (int) ($row['invoices'] ?? 0),
        ], $items);

        return [
            'ok' => true,
            'filters' => $query->filtersMeta(),
            'data' => $data,
            'meta' => [
                'source_contract' => CnaeSourceContracts::RANKING,
                'source_table' => 'mart_vendas_por_cnae_cnpj',
                'source_mode' => 'cnae_readmodel',
                'count' => count($data),
                'read_model' => 'CnaeReadModel',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function meta(CnaeQuery $query, string $readModel): array
    {
        return [
            'period' => $query->periodMeta(),
            'filters' => $query->filtersMeta(),
            'freshness' => $this->repo->freshness(),
            'read_model' => $readModel,
        ];
    }

    /**
     * @param array<string,mixed> $coverage
     * @return list<string>
     */
    private function coverageInsights(array $coverage): array
    {
        $missingRevenue = (float) ($coverage['impact']['missing_cnpj_revenue'] ?? 0);
        if ($missingRevenue <= 0) {
            return ['CPF fica fora da regua de erro; cobertura B2B mede apenas CNPJ.'];
        }

        return ['CNPJ sem CNAE tem impacto financeiro e deve alimentar fila de enriquecimento B2B.'];
    }

    /**
     * @param array<string,mixed> $coverage
     * @return list<string>
     */
    private function coverageWarnings(array $coverage): array
    {
        return ((int) ($coverage['cnpj_missing_cnae'] ?? 0)) > 0
            ? ['Existem CNPJs com faturamento sem CNAE; isso e oportunidade real de governanca.']
            : [];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function summaryFromItems(array $items): array
    {
        $cnaes = [];
        $companies = [];
        $revenue = 0.0;
        $customersSum = 0;
        foreach ($items as $row) {
            $cnaes[(string) ($row['cnae_codigo'] ?? 'SEM_CNAE')] = true;
            $customerKey = (string) ($row['customer_key'] ?? $row['cliente_nome'] ?? '');
            if ($customerKey !== '') {
                $companies[$customerKey] = true;
            }
            $revenue += (float) ($row['revenue'] ?? 0);
            $customersSum += (int) ($row['customers'] ?? 0);
        }

        $empresas = count(array_filter(array_keys($companies)));
        if ($empresas === 0 && $customersSum > 0) {
            $empresas = $customersSum;
        }

        return [
            'cnaes' => count($cnaes),
            'empresas' => $empresas,
            'faturamento' => $revenue,
        ];
    }

    private function opportunityQuery(CnaeQuery $query): CnaeQuery
    {
        return new CnaeQuery(
            dateFrom: $query->dateFrom,
            dateTo: $query->dateTo,
            startComp: $query->startComp,
            endComp: $query->endComp,
            city: $query->city,
            neighborhood: $query->neighborhood,
            cnae: null,
            sellerId: $query->sellerId,
            documentType: 'cnpj',
            status: 'missing_cnpj',
            limit: $query->limit,
            sort: $query->sort,
            direction: $query->direction,
            mode: $query->mode,
            received: $query->received,
            normalized: $query->normalized,
        );
    }
}
