<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\DataPlatform\Contracts\ReadModelResponse;
use BIomie\DataPlatform\Contracts\SourceContractRegistry;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\Repository\GeoReadRepository;
use BIomie\Support\DocumentMask;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\GeoMapSupport;

final class GeoReadModel
{
    public function __construct(private readonly GeoReadRepository $repo) {}

    /** @return array{query_count:int,sql_time_ms:float} */
    public function performanceMetrics(): array
    {
        return $this->repo->metrics();
    }

    public function resetPerformanceMetrics(): void
    {
        $this->repo->resetMetrics();
    }

    public function summary(GeoQuery $query): ReadModelResponse
    {
        $bundle = $this->repo->summaryBundle($query);
        $summary = $bundle['summary'];
        $segments = $bundle['segments'];
        $buyers = $bundle['top_buyers'];
        $cnaes = $bundle['top_cnaes'];

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get('geo.sales_by_territory.v1'),
            data: [
                'summary' => $summary,
                'segments' => $segments,
                'top_buyers' => $buyers,
                'top_cnaes' => $cnaes,
            ],
            meta: [
                'period' => $query->periodMeta(),
                'filters' => $query->filtersMeta(),
                'freshness' => $bundle['freshness'],
                'read_model' => 'GeoReadModel',
            ],
            warnings: $this->summaryWarnings($summary)
        );
    }

    public function compositeMap(GeoQuery $query): ReadModelResponse
    {
        $payload = $this->repo->compositeMap($query);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get('geo.map_payload.v1'),
            data: $payload,
            meta: [
                'period' => $query->periodMeta(),
                'filters' => $query->filtersMeta(),
                'freshness' => $this->repo->freshness(),
                'read_model' => 'GeoReadModel',
            ],
            warnings: []
        );
    }

    public function mapPayload(GeoQuery $query): ReadModelResponse
    {
        $composite = $this->repo->compositeMap($query);
        $summary = $this->repo->summary($query);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get('geo.map_payload.v1'),
            data: [
                'summary' => $summary,
                'territories' => $composite['territories'] ?? [],
                'ranking' => $composite['ranking'] ?? [],
                'scales' => $composite['scales'] ?? [],
                'segments' => [],
                'top_buyers' => [],
                'top_cnaes' => [],
                'quality' => [
                    'rows_without_city' => (int) ($summary['rows_without_city'] ?? 0),
                    'rows_without_neighborhood' => (int) ($summary['rows_without_neighborhood'] ?? 0),
                    'rows_without_cnae' => (int) ($summary['rows_without_cnae'] ?? 0),
                ],
            ],
            meta: [
                'period' => $query->periodMeta(),
                'filters' => $query->filtersMeta(),
                'freshness' => $this->repo->freshness(),
                'read_model' => 'GeoReadModel',
                'payload_mode' => 'single_payload',
            ],
            warnings: $this->summaryWarnings($summary)
        );
    }

    /**
     * Compatibility shape for api/geo_composite_sales.php when the endpoint is
     * migrated to this read model.
     *
     * @return array<string,mixed>
     */
    public function legacyCompositePayload(GeoQuery $query): array
    {
        $response = $this->compositeMap($query)->toArray();
        $data = (array) ($response['data'] ?? []);

        return [
            'ok' => true,
            'layer' => 'mapa_composto_ceara',
            'date_from' => $query->dateFrom,
            'date_to' => $query->dateTo,
            'summary' => $data['summary'] ?? [],
            'territories' => $data['territories'] ?? [],
            'scales' => $data['scales'] ?? [],
            'ranking' => $data['ranking'] ?? [],
            'meta' => $response['meta'] ?? [],
            'source' => 'gold',
            'source_contract' => 'geo.map_payload.v1',
        ];
    }

    /**
     * Compatibility shape for api/geo_summary.php while the JS is still using
     * the legacy endpoint contract.
     *
     * @return array<string,mixed>
     */
    public function legacySummaryPayload(GeoQuery $query, bool $canViewFullDocuments = false): array
    {
        $response = $this->summary($query)->toArray();
        $data = (array) ($response['data'] ?? []);
        $summary = (array) ($data['summary'] ?? []);
        $topCnaes = array_values(array_filter(
            (array) ($data['top_cnaes'] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && !in_array((string) ($row['cnae_codigo'] ?? ''), ['', 'SEM_CNAE'], true)
        ));

        $noNeighborhoodRevenue = 0.0;
        $noNeighborhoodClients = 0;
        $noNeighborhoodInvoices = 0;
        foreach ((array) ($data['segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $name = strtolower(trim((string) ($segment['name'] ?? '')));
            if (in_array($name, ['', 'nao informado', 'nao_informado', 'sem_bairro', 'sem bairro'], true)) {
                $noNeighborhoodRevenue += (float) ($segment['value_raw'] ?? 0);
                $noNeighborhoodClients += (int) ($segment['clients'] ?? 0);
                $noNeighborhoodInvoices += (int) ($segment['notes'] ?? 0);
            }
        }

        $buyers = [];
        foreach ((array) ($data['top_buyers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $document = (string) ($row['documento'] ?? '');
            $buyers[] = [
                'nome' => (string) ($row['cliente_nome'] ?? ''),
                'documento' => $canViewFullDocuments ? $document : '',
                'cliente_nome' => (string) ($row['cliente_nome'] ?? ''),
                'cliente_documento' => $canViewFullDocuments
                    ? $document
                    : (DocumentMask::maskDocument($document, 'Nao informado') ?? 'Nao informado'),
                'documento_norm' => $canViewFullDocuments ? (preg_replace('/\D+/', '', $document) ?? '') : '',
                'codigo_cliente_omie' => (string) ($row['codigo_cliente_omie'] ?? ''),
                'bairro' => (string) ($row['bairro'] ?? ''),
                'cidade' => (string) ($row['cidade'] ?? ''),
                'cnae_codigo' => ($row['cnae_codigo'] ?? null) ?: null,
                'notas' => (int) ($row['notas'] ?? 0),
                'faturamento' => (float) ($row['faturamento'] ?? 0),
                'ultima_compra' => ($row['ultima_compra'] ?? null) ?: null,
            ];
        }

        $revenue = (float) ($summary['revenue'] ?? 0);
        $customers = (int) ($summary['customers'] ?? 0);
        $rowsWithoutNeighborhood = (int) ($summary['rows_without_neighborhood'] ?? 0);

        return [
            'ok' => true,
            'filters' => [
                'date_from' => $query->dateFrom,
                'date_to' => $query->dateTo,
                'city' => $query->received['city'] ?? $query->city,
                'neighborhood' => $query->received['neighborhood'] ?? $query->neighborhood,
                'cnae' => $query->cnae,
                'seller_id' => $query->sellerId,
                'layer' => $query->layer,
            ],
            'summary' => [
                'revenue' => $revenue,
                'total_value' => $revenue,
                'total_revenue' => $revenue,
                'pj_value_raw' => (float) ($summary['pj_value_raw'] ?? 0),
                'pf_value_raw' => (float) ($summary['pf_value_raw'] ?? 0),
                'customers' => $customers,
                'invoices' => (int) ($summary['invoices'] ?? 0),
                'average_ticket' => (float) ($summary['average_ticket'] ?? 0),
                'neighborhoods_with_sales' => (int) ($summary['neighborhoods_with_sales'] ?? 0),
                'rows_without_neighborhood' => $rowsWithoutNeighborhood,
                'last_materialized_at' => $summary['last_materialized_at'] ?? null,
                'bairros_total' => null,
                'bairros_com_venda' => (int) ($summary['neighborhoods_with_sales'] ?? 0),
                'bairros_sem_venda' => null,
                'cnaes_com_venda' => count($topCnaes),
                'melhor_bairro' => (string) (((array) ($data['segments'][0] ?? []))['name'] ?? ''),
                'melhor_bairro_valor' => (float) (((array) ($data['segments'][0] ?? []))['value_raw'] ?? 0),
                'sem_bairro' => [
                    'faturamento' => $noNeighborhoodRevenue,
                    'clientes' => $noNeighborhoodClients,
                    'notas' => $noNeighborhoodInvoices,
                ],
            ],
            'segments' => array_values((array) ($data['segments'] ?? [])),
            'top_buyers' => $buyers,
            'top_cnaes' => $topCnaes,
            'no_neighborhood' => [
                'faturamento' => $noNeighborhoodRevenue,
                'clientes' => $noNeighborhoodClients,
                'notas' => $noNeighborhoodInvoices,
                'pct_faturamento' => $revenue > 0 ? round($noNeighborhoodRevenue / $revenue * 100, 1) : 0.0,
                'reason' => 'Endereco ausente ou nao propagado no mart geo.',
                'action' => 'Rodar biomie geo:coverage e biomie geo:repair para priorizar correcao.',
            ],
            'data_quality' => [
                'missing_city_count' => (int) ($summary['rows_without_city'] ?? 0),
                'missing_neighborhood_count' => $rowsWithoutNeighborhood,
                'missing_neighborhood_revenue' => round($noNeighborhoodRevenue, 2),
                'missing_cnae_count' => (int) ($summary['rows_without_cnae'] ?? 0),
                'pct_revenue' => $revenue > 0 ? round($noNeighborhoodRevenue / $revenue * 100, 1) : 0.0,
            ],
            'cnae_status' => [
                'status' => count($topCnaes) > 0 ? 'ok' : 'sem_venda_com_cnae',
                'motivo' => count($topCnaes) > 0 ? null : 'Nenhum CNAE com venda encontrado no recorte pelo ReadModel.',
                'has_cnae_in_dim' => count($topCnaes) > 0,
            ],
            'diagnostics' => [
                'source_table' => 'mart_compradores_por_geo',
                'source_contract' => 'geo.sales_by_territory.v1',
                'read_model' => 'GeoReadModel',
                'gold_rows_in_period' => (int) ($summary['invoices'] ?? 0),
                'rows_without_city' => (int) ($summary['rows_without_city'] ?? 0),
                'rows_without_neighborhood' => $rowsWithoutNeighborhood,
                'rows_without_cnae' => (int) ($summary['rows_without_cnae'] ?? 0),
                'last_materialized_at' => $summary['last_materialized_at'] ?? null,
                'freshness' => $response['meta']['freshness'] ?? [],
            ],
            'coverage' => [
                'status' => $rowsWithoutNeighborhood > 0 ? 'partial' : 'ok',
                'missing_percent' => $customers > 0 ? round(($rowsWithoutNeighborhood / max(1, $customers)) * 100, 2) : 100.0,
                'enriched_clients' => $customers,
                'queued_clients' => 0,
            ],
            'lifecycle' => [
                'status' => 'not_calculated',
                'recurrent' => 0.0,
                'new' => 0.0,
                'rev_recurrent' => 0.0,
            ],
            'meta' => $response['meta'] ?? [],
            'warnings' => $response['warnings'] ?? [],
            'source' => 'gold',
            'source_contract' => 'geo.sales_by_territory.v1',
        ];
    }

    /**
     * Compatibility shape for api/sales_by_neighborhood.php.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function legacyNeighborhoodPayload(GeoQuery $query, array $request): array
    {
        $resolvedBairroKey = $this->resolveBairroKey($query, $request);
        $payload = $this->repo->neighborhoodSales($query, $resolvedBairroKey);
        $data = (array) ($payload['data'] ?? []);
        $totalRevenue = (float) (($payload['summary']['total_revenue'] ?? 0));

        $out = [
            'ok' => true,
            'filters' => [
                'date_from' => $query->dateFrom,
                'date_to' => $query->dateTo,
                'city' => $query->received['city'] ?? $query->city ?? 'Fortaleza',
                'neighborhood' => $query->received['neighborhood'] ?? $query->neighborhood,
                'cnae' => $query->cnae,
                'seller_id' => $query->sellerId,
                'layer' => $query->layer,
            ],
            'data' => $data,
            'scale' => $payload['scale'] ?? GeoMapSupport::revenueScale($data),
            'summary' => $payload['summary'] ?? [],
            'bairros' => $payload['bairros'] ?? [],
            'meta' => [
                'source_table' => 'mart_compradores_por_geo',
                'source_mode' => $payload['source_mode'] ?? 'gold_mart',
                'source_contract' => 'geo.sales_by_neighborhood.v1',
                'read_model' => 'GeoReadModel',
                'count' => count($data),
                'materialization' => [
                    'source_table' => 'mart_compradores_por_geo',
                    'competencia_start' => $query->startComp,
                    'competencia_end' => $query->endComp,
                    'rows' => count($data),
                    'status' => count($data) > 0 ? 'ok' : 'empty',
                ],
            ],
            'source' => 'gold',
            'source_contract' => 'geo.sales_by_neighborhood.v1',
            'read_model' => 'GeoReadModel',
        ];

        if ($totalRevenue <= 0.0) {
            $out['empty_state'] = GeoMapSupport::emptyState();
            $out['diagnostics'] = [
                'motivo_zero' => count($data) === 0 ? 'sem_linhas_no_mart' : 'periodo_sem_vendas',
                'filters_applied' => 'GeoQuery + territory layer',
            ];
        }

        return $out;
    }

    /**
     * Compatibility shape for api/geo_territory_invoices.php.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function legacyTerritoryInvoicesPayload(GeoQuery $query, array $request, bool $canViewFullDocuments): array
    {
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $payload = $this->repo->territoryInvoices($query, $this->territoryFromRequest($request), $page, $perPage);

        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $doc = (string) ($row['cliente_documento'] ?? '');
            $items[] = [
                'id' => $row['id'] ?? null,
                'numero_nf' => $row['numero_nf'] ?? null,
                'serie' => ($row['serie_nf'] ?? '') !== '' ? $row['serie_nf'] : null,
                'data_emissao' => $row['data_emissao'] ?? null,
                'cliente_nome' => $row['cliente_nome'] ?? '',
                'cliente_documento' => $canViewFullDocuments ? $doc : (DocumentMask::maskDocument($doc, 'Nao informado') ?? 'Nao informado'),
                'cidade' => $row['cidade'] ?? null,
                'bairro' => $row['bairro'] ?? null,
                'vendedor_nome' => ($row['vendedor_nome'] ?? '') !== '' ? $row['vendedor_nome'] : null,
                'valor' => (float) ($row['valor'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'summary' => $payload['summary'] ?? ['notas' => 0, 'valor_total' => 0, 'clientes' => 0, 'ticket_medio' => 0],
            'items' => $items,
            'pagination' => $payload['pagination'] ?? ['page' => $page, 'per_page' => $perPage, 'total' => 0],
            'meta' => [
                'source_mode' => $payload['source_mode'] ?? 'readmodel',
                'source_contract' => 'geo.territory_invoices.v1',
                'read_model' => 'GeoReadModel',
            ],
            'source' => 'canonical',
            'source_contract' => 'geo.territory_invoices.v1',
            'read_model' => 'GeoReadModel',
        ];
    }

    /**
     * Compatibility shape for api/geo_territory_buyers.php.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function legacyTerritoryBuyersPayload(GeoQuery $query, array $request, bool $canViewFullDocuments): array
    {
        $limit = min(50, max(1, (int) ($request['limit'] ?? 10)));
        $documentType = $this->normalizeDocumentType((string) ($request['document_type'] ?? 'all'));
        $payload = $this->repo->territoryBuyers($query, $this->territoryFromRequest($request), $limit, $documentType);

        $data = [];
        foreach ((array) ($payload['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data[] = $this->buyerRow((array) $row, $canViewFullDocuments);
        }

        return [
            'ok' => true,
            'data' => $data,
            'meta' => [
                'source_mode' => (string) (($payload['meta']['source_mode'] ?? 'mart_compradores_por_geo')),
                'count' => count($data),
                'territory_type' => (string) ($request['territory_type'] ?? ''),
                'document_type_filter' => $documentType,
                'summary' => $this->buyerSummary($data),
                'cnae_filter' => [
                    'code' => (string) ($query->cnae ?? ''),
                    'description' => '',
                ],
                'source_contract' => 'geo.territory_buyers.v1',
                'read_model' => 'GeoReadModel',
            ],
            'source' => 'gold',
            'source_contract' => 'geo.territory_buyers.v1',
            'read_model' => 'GeoReadModel',
        ];
    }

    /**
     * Compatibility shape for api/geo_territory_cnaes.php.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function legacyTerritoryCnaesPayload(GeoQuery $query, array $request): array
    {
        $payload = $this->repo->territoryCnaes($query, $this->territoryFromRequest($request));

        return [
            'ok' => true,
            'items' => $payload['items'] ?? [],
            'source' => $payload['source'] ?? 'mart_compradores_por_geo',
            'meta' => [
                'source_contract' => 'geo.territory_cnaes.v1',
                'read_model' => 'GeoReadModel',
                'territory_type' => (string) ($request['territory_type'] ?? ''),
                'count' => count((array) ($payload['items'] ?? [])),
            ],
            'source_contract' => 'geo.territory_cnaes.v1',
            'read_model' => 'GeoReadModel',
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @return list<string>
     */
    private function summaryWarnings(array $summary): array
    {
        $warnings = [];
        if ((int) ($summary['rows_without_city'] ?? 0) > 0) {
            $warnings[] = 'Ha linhas sem cidade no mart geo para o recorte.';
        }
        if ((int) ($summary['rows_without_neighborhood'] ?? 0) > 0) {
            $warnings[] = 'Ha linhas sem bairro no mart geo para o recorte.';
        }
        if ((int) ($summary['rows_without_cnae'] ?? 0) > 0) {
            $warnings[] = 'Ha linhas sem CNAE no mart geo para o recorte.';
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $request
     */
    private function resolveBairroKey(GeoQuery $query, array $request): ?string
    {
        $rawBairroKey = trim((string) ($request['bairro_key'] ?? ''));
        $rawBairroNorm = trim((string) ($request['bairro_norm'] ?? ''));

        if ($rawBairroKey !== '') {
            return GeoKeyNormalizer::makeNeighborhoodKey($rawBairroKey);
        }
        if ($rawBairroNorm !== '') {
            return GeoKeyNormalizer::makeNeighborhoodKey($rawBairroNorm);
        }
        if ($query->neighborhood !== null) {
            return GeoKeyNormalizer::normToKey($query->neighborhood);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function territoryFromRequest(array $request): array
    {
        return [
            'territory_type' => trim((string) ($request['territory_type'] ?? '')),
            'cidade_key' => strtolower(trim((string) ($request['cidade_key'] ?? ''))),
            'bairro_key' => strtolower(trim((string) ($request['bairro_key'] ?? ''))),
            'filter_city' => strtolower(trim((string) ($request['filter_city'] ?? ''))),
        ];
    }

    private function normalizeDocumentType(string $type): string
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'pj' => 'cnpj',
            'pf' => 'cpf',
            'cnpj', 'cpf' => $type,
            default => 'all',
        };
    }

    private function documentDigits(?string $document): string
    {
        return preg_replace('/\D+/', '', (string) ($document ?? '')) ?? '';
    }

    private function documentType(?string $document): string
    {
        return match (strlen($this->documentDigits($document))) {
            14 => 'cnpj',
            11 => 'cpf',
            default => 'unknown',
        };
    }

    private function documentLabel(string $type): string
    {
        return match ($type) {
            'cnpj' => 'CNPJ',
            'cpf' => 'CPF',
            default => 'Documento',
        };
    }

    private function maskDocument(?string $document, bool $canViewFullDocuments): string
    {
        $digits = $this->documentDigits($document);
        if ($digits === '') {
            return 'Nao informado';
        }
        if ($canViewFullDocuments) {
            return (string) $document;
        }

        return DocumentMask::maskDocument($document, 'Nao informado') ?? 'Nao informado';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buyerRow(array $row, bool $canViewFullDocuments): array
    {
        $doc = (string) ($row['document_raw'] ?? '');
        $type = $this->documentType($doc);
        $revenue = (float) ($row['revenue'] ?? 0);
        $invoices = (int) ($row['invoices'] ?? 0);
        $documentMasked = $this->maskDocument($doc, $canViewFullDocuments);

        return [
            'customer_name' => $row['customer_name'] ?? '',
            'document_raw' => $documentMasked,
            'document_masked' => $documentMasked,
            'document_type' => $type,
            'document_label' => $this->documentLabel($type),
            'buyer_kind' => $type === 'cnpj' ? 'b2b' : ($type === 'cpf' ? 'cpf' : 'unknown'),
            'is_b2b' => $type === 'cnpj',
            'revenue' => $revenue,
            'invoices' => $invoices,
            'average_ticket' => $invoices > 0 ? round($revenue / $invoices, 2) : 0.0,
            'last_purchase' => $row['last_purchase'] ?? null,
            'cnae' => $row['cnae'] ?? null,
            'segment' => null,
            'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
            'cidade' => $row['cidade_norm'] ?? '',
            'cidade_norm' => $row['cidade_norm'] ?? '',
            'bairro' => $row['bairro_norm'] ?? '',
            'bairro_norm' => $row['bairro_norm'] ?? '',
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,float|int>>
     */
    private function buyerSummary(array $rows): array
    {
        $summary = [
            'total' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'cnpj' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'cpf' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'unknown' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
        ];

        foreach ($rows as $row) {
            $type = (string) ($row['document_type'] ?? 'unknown');
            if (!isset($summary[$type])) {
                $type = 'unknown';
            }
            $revenue = (float) ($row['revenue'] ?? 0);
            $invoices = (int) ($row['invoices'] ?? 0);
            foreach (['total', $type] as $bucket) {
                $summary[$bucket]['count']++;
                $summary[$bucket]['revenue'] += $revenue;
                $summary[$bucket]['invoices'] += $invoices;
            }
        }

        return $summary;
    }
}
