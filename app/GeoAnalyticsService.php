<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class GeoAnalyticsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function summarize(string $start, string $end, string $scope = 'uf_brasil'): array
    {
        [$rows, $sourceMode] = $this->loadSummaryRows($start, $end, $scope);
        $totals = $this->loadTotals($start, $end, $scope);
        $queueStats = $this->loadQueueStats();

        $totalValue = Support::toFloat($totals['total_value'] ?? 0);
        $pfValue = 0.0;
        $pjValue = 0.0;
        $recurrentValue = 0.0;
        $segments = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = Support::toFloat($row['valor'] ?? 0);
            $segment = trim((string) ($row['segmento_macro'] ?? 'OUTROS'));
            $documentType = $this->normalizeDocumentType((string) ($row['tipo_documento'] ?? 'OUTRO'));
            $isRecurrent = (int) ($row['is_recorrente'] ?? 0) === 1;

            if ($documentType === 'PF') {
                $pfValue += $value;
            } elseif ($documentType === 'PJ') {
                $pjValue += $value;
            }

            if ($isRecurrent) {
                $recurrentValue += $value;
            }

            if (!isset($segments[$segment])) {
                $segments[$segment] = [
                    'name' => $this->formatRegionName($segment),
                    'value' => 0.0,
                    'clients' => 0,
                ];
            }

            $segments[$segment]['value'] += $value;
            $segments[$segment]['clients'] += (int) ($row['clientes'] ?? 0);
        }

        usort($segments, static fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        $geoCovered = (int) ($totals['geo_clients'] ?? 0);
        $activeClients = (int) ($totals['active_clients'] ?? 0);
        $missingPercent = $activeClients > 0
            ? round(max(0, 100 - (($geoCovered / $activeClients) * 100)), 1)
            : 100.0;

        return [
            'summary' => [
                'revenue' => Support::money($totalValue),
                'revenue_raw' => $totalValue,
                'clients_unique' => $activeClients,
                'pf_share' => $totalValue > 0 ? round(($pfValue / $totalValue) * 100, 1) : 0.0,
                'pj_share' => $totalValue > 0 ? round(($pjValue / $totalValue) * 100, 1) : 0.0,
                'pf_value' => Support::money($pfValue),
                'pj_value' => Support::money($pjValue),
                'pf_value_raw' => $pfValue,
                'pj_value_raw' => $pjValue,
            ],
            'segments' => array_values(array_map(
                static fn (array $segment) => [
                    'name' => $segment['name'],
                    'revenue' => Support::money($segment['value']),
                    'value_raw' => $segment['value'],
                    'clients' => $segment['clients'],
                    'share' => $totalValue > 0 ? round(($segment['value'] / $totalValue) * 100, 1) : 0.0,
                ],
                $segments
            )),
            'coverage' => [
                'status' => $activeClients > 0 ? ($geoCovered > 0 ? 'ready' : 'pending') : 'empty',
                'missing_percent' => $missingPercent,
                'enriched_clients' => $geoCovered,
                'queued_clients' => (int) ($queueStats['queued_clients'] ?? 0),
            ],
            'lifecycle' => [
                'status' => $totalValue > 0 ? 'ready' : 'empty',
                'recurrent' => $totalValue > 0 ? round(($recurrentValue / $totalValue) * 100, 1) : 0.0,
                'new' => $totalValue > 0 ? round((($totalValue - $recurrentValue) / $totalValue) * 100, 1) : 0.0,
                'rev_recurrent' => $recurrentValue,
            ],
            'meta' => [
                'source_mode' => $sourceMode,
                'pipeline_ready' => $sourceMode === 'aggregate',
                'active_clients' => $activeClients,
            ],
        ];
    }

    /**
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function salesByCity(string $start, string $end, string $uf = 'CE', string $level = 'city'): array
    {
        $uf = strtoupper(trim($uf));
        $level = strtolower(trim($level));

        if ($uf === 'ALL' && $level === 'uf') {
            [$rows, $sourceMode] = $this->loadUfRows($start, $end);
            return [
                'data' => $rows,
                'meta' => [
                    'scope' => 'ufs',
                    'source_mode' => $sourceMode,
                    'pipeline_ready' => $sourceMode === 'aggregate',
                ],
            ];
        }

        if ($uf === 'ALL') {
            [$rows, $sourceMode] = $this->loadBrazilCityRows($start, $end);
            return [
                'data' => $rows,
                'meta' => [
                    'scope' => 'cidade_brasil',
                    'source_mode' => $sourceMode,
                    'pipeline_ready' => $sourceMode === 'aggregate',
                ],
            ];
        }

        [$rows, $sourceMode] = $this->loadCityRowsByUf($start, $end, $uf);
        return [
            'data' => $rows,
            'meta' => [
                'scope' => 'cidade_' . strtolower($uf),
                'source_mode' => $sourceMode,
                'pipeline_ready' => $sourceMode === 'aggregate',
            ],
        ];
    }

    /**
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function salesByNeighborhood(string $start, string $end, string $cityIbge = '2304400'): array
    {
        [$rows, $sourceMode] = $this->loadNeighborhoodRows($start, $end, $cityIbge);

        return [
            'data' => $rows,
            'meta' => [
                'scope' => 'bairro_fortaleza',
                'source_mode' => $sourceMode,
                'pipeline_ready' => $sourceMode === 'aggregate',
                'city_ibge' => $cityIbge,
            ],
        ];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function loadSummaryRows(string $start, string $end, string $scope): array
    {
        $rows = $this->queryAggregateSummaryRows($start, $end, $scope);
        if ($rows !== []) {
            return [$rows, 'aggregate'];
        }

        $rows = $this->queryFactSummaryRows($start, $end, $scope);
        if ($rows !== []) {
            return [$rows, 'fact_fallback'];
        }

        return [$this->queryCanonicalSummaryRows($start, $end, $scope), 'canonical_fallback'];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function loadUfRows(string $start, string $end): array
    {
        $rows = $this->queryAggregateRankingRows(
            $start,
            $end,
            'uf_brasil',
            'COALESCE(NULLIF(uf, \'\'), \'NA\')'
        );

        if ($rows !== []) {
            return [$rows, 'aggregate'];
        }

        $rows = $this->queryFactRegionRows($start, $end, 'uf');
        if ($rows !== []) {
            return [$rows, 'fact_fallback'];
        }

        return [$this->queryCanonicalRegionRows($start, $end, 'uf'), 'canonical_fallback'];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function loadBrazilCityRows(string $start, string $end): array
    {
        $rows = $this->queryFactRegionRows($start, $end, 'city');
        if ($rows !== []) {
            return [$rows, 'fact_fallback'];
        }

        return [$this->queryCanonicalRegionRows($start, $end, 'city'), 'canonical_fallback'];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function loadCityRowsByUf(string $start, string $end, string $uf): array
    {
        $rows = [];
        if ($uf === 'CE') {
            $rows = $this->queryAggregateRankingRows(
                $start,
                $end,
                'cidade_ce',
                'COALESCE(NULLIF(cidade_norm, \'\'), \'NA\')'
            );
        }

        if ($rows !== []) {
            return [$rows, 'aggregate'];
        }

        $rows = $this->queryFactRegionRows($start, $end, 'city', $uf);
        if ($rows !== []) {
            return [$rows, 'fact_fallback'];
        }

        return [$this->queryCanonicalRegionRows($start, $end, 'city', $uf), 'canonical_fallback'];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function loadNeighborhoodRows(string $start, string $end, string $cityIbge): array
    {
        $rows = $this->queryAggregateRankingRows(
            $start,
            $end,
            'bairro_fortaleza',
            'COALESCE(NULLIF(bairro_norm, \'\'), \'NA\')'
        );

        if ($rows !== []) {
            return [$rows, 'aggregate'];
        }

        $rows = $this->queryFactRegionRows($start, $end, 'neighborhood', 'CE', $cityIbge);
        if ($rows !== []) {
            return [$rows, 'fact_fallback'];
        }

        return [$this->queryCanonicalRegionRows($start, $end, 'neighborhood', 'CE', $cityIbge), 'canonical_fallback'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryAggregateSummaryRows(string $start, string $end, string $scope): array
    {
        if (!$this->hasTable('biomie_geo_vendas_diaria')) {
            return [];
        }

        try {
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    segmento_macro,
                    tipo_documento,
                    is_recorrente,
                    SUM(vendas_qtd)      AS qtd,
                    SUM(valor_total)     AS valor,
                    SUM(clientes_unicos) AS clientes
                FROM biomie_geo_vendas_diaria
                WHERE {$tenantFilter}
                  AND data_referencia BETWEEN :start AND :end
                  AND escopo_geo = :scope
                GROUP BY segmento_macro, tipo_documento, is_recorrente
            SQL);
            $stmt->execute([
                'start' => $start,
                'end' => $end,
                'scope' => $scope,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: resumo agregado indisponivel.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryCanonicalSummaryRows(string $start, string $end, string $scope): array
    {
        $hasClientDim = $this->hasTable('biomie_clientes_dim');
        $hasGeoDim = $this->hasTable('biomie_clientes_geo_dim');
        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = $this->canonicalScopeWhere($scope, $params, $hasGeoDim);
        $segmentSql = $hasClientDim ? "COALESCE(dim.segmento_macro, 'OUTROS')" : "'OUTROS'";
        $documentSql = $hasClientDim
            ? $this->documentTypeSql('dim.tipo_documento')
            : $this->documentTypeFromInvoiceSql('nf.documento_cliente');
        $recurrenceSql = $hasClientDim ? 'COALESCE(dim.is_recorrente, 0)' : '0';
        $dimJoin = $hasClientDim
            ? "LEFT JOIN biomie_clientes_dim dim\n                ON dim.codigo_cliente_omie = nf.codigo_cliente_omie\n               AND dim.company_id = nf.company_id"
            : '';
        $geoJoin = $hasGeoDim
            ? "LEFT JOIN biomie_clientes_geo_dim geo\n                ON geo.codigo_cliente_omie = nf.codigo_cliente_omie\n               AND geo.company_id = nf.company_id"
            : '';
        $tenantFilter = TenantContext::sqlFilter('nf');

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    {$segmentSql} AS segmento_macro,
                    {$documentSql} AS tipo_documento,
                    {$recurrenceSql} AS is_recorrente,
                    COUNT(DISTINCT nf.id) AS qtd,
                    SUM(nf.valor_total) AS valor,
                    COUNT(DISTINCT CONCAT(nf.company_id, ':', nf.codigo_cliente_omie)) AS clientes
                FROM biomie_notas_fiscais_canonicas nf
                {$dimJoin}
                {$geoJoin}
                WHERE nf.status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND nf.data_emissao BETWEEN :start AND :end
                  {$where}
                GROUP BY segmento_macro, tipo_documento, is_recorrente
            SQL);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: resumo canonico indisponivel.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadTotals(string $start, string $end, string $scope): array
    {
        $factTotals = $this->loadFactTotals($start, $end, $scope);
        if ($factTotals !== []) {
            return $factTotals;
        }

        $hasGeoDim = $this->hasTable('biomie_clientes_geo_dim');
        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = $this->canonicalScopeWhere($scope, $params, $hasGeoDim);
        $geoJoin = $hasGeoDim
            ? "LEFT JOIN biomie_clientes_geo_dim geo\n                ON geo.codigo_cliente_omie = nf.codigo_cliente_omie\n               AND geo.company_id = nf.company_id"
            : '';
        $geoCoverageSql = $hasGeoDim
            ? "COUNT(DISTINCT CASE
                    WHEN COALESCE(geo.uf, '') <> ''
                     AND COALESCE(geo.cidade_norm, '') <> ''
                    THEN CONCAT(nf.company_id, ':', nf.codigo_cliente_omie)
                    ELSE NULL
                END)"
            : '0';
        $tenantFilter = TenantContext::sqlFilter('nf');

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    SUM(nf.valor_total) AS total_value,
                    COUNT(DISTINCT CONCAT(nf.company_id, ':', nf.codigo_cliente_omie)) AS active_clients,
                    {$geoCoverageSql} AS geo_clients
                FROM biomie_notas_fiscais_canonicas nf
                {$geoJoin}
                WHERE nf.status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND nf.data_emissao BETWEEN :start AND :end
                  {$where}
            SQL);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: totais canonicos indisponiveis.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<string,int>
     */
    private function loadQueueStats(): array
    {
        if (!$this->hasTable('biomie_clientes_para_enriquecer')) {
            return ['queued_clients' => 0];
        }

        try {
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT COUNT(*)
                 FROM biomie_clientes_para_enriquecer
                 WHERE {$tenantFilter}
                   AND status IN ('pendente', 'processando')"
            );
            return ['queued_clients' => (int) ($stmt->fetchColumn() ?: 0)];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: fila de enriquecimento indisponivel.', [
                'message' => $exception->getMessage(),
            ]);
            return ['queued_clients' => 0];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryAggregateRankingRows(
        string $start,
        string $end,
        string $scope,
        string $groupExpression,
    ): array {
        if (!$this->hasTable('biomie_geo_vendas_diaria')) {
            return [];
        }

        try {
            $tenantFilter = TenantContext::sqlFilter();
            $sql = <<<SQL
                SELECT
                    {$groupExpression} AS region_name,
                    SUM(valor_total) AS valor,
                    SUM(clientes_unicos) AS clientes,
                    SUM(vendas_qtd) AS notes
                FROM biomie_geo_vendas_diaria
                WHERE {$tenantFilter}
                  AND data_referencia BETWEEN :start AND :end
                  AND escopo_geo = :scope
                GROUP BY region_name
                ORDER BY valor DESC
            SQL;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'start' => $start,
                'end' => $end,
                'scope' => $scope,
            ]);

            return $this->formatRankingRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: ranking agregado indisponivel.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryFactSummaryRows(string $start, string $end, string $scope): array
    {
        if (!$this->hasTable('biomie_vendas_geo_cliente_fact')) {
            return [];
        }

        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = $this->factScopeWhere($scope, $params);
        $tenantFilter = TenantContext::sqlFilter();

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    COALESCE(segmento_macro, 'OUTROS') AS segmento_macro,
                    {$this->documentTypeSql('tipo_documento')} AS tipo_documento,
                    COALESCE(is_recorrente, 0) AS is_recorrente,
                    COUNT(DISTINCT nf_id) AS qtd,
                    SUM(valor_total) AS valor,
                    COUNT(DISTINCT CONCAT(company_id, ':', codigo_cliente_omie)) AS clientes
                FROM biomie_vendas_geo_cliente_fact
                WHERE status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND data_referencia BETWEEN :start AND :end
                  {$where}
                GROUP BY segmento_macro, tipo_documento, is_recorrente
            SQL);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: resumo fact indisponivel.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFactTotals(string $start, string $end, string $scope): array
    {
        if (!$this->hasTable('biomie_vendas_geo_cliente_fact')) {
            return [];
        }

        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = $this->factScopeWhere($scope, $params);
        $tenantFilter = TenantContext::sqlFilter();

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    SUM(valor_total) AS total_value,
                    COUNT(DISTINCT CONCAT(company_id, ':', codigo_cliente_omie)) AS active_clients,
                    COUNT(DISTINCT CASE
                        WHEN COALESCE(uf, '') <> ''
                         AND COALESCE(cidade, '') <> ''
                        THEN CONCAT(company_id, ':', codigo_cliente_omie)
                        ELSE NULL
                    END) AS geo_clients
                FROM biomie_vendas_geo_cliente_fact
                WHERE status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND data_referencia BETWEEN :start AND :end
                  {$where}
            SQL);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: totais fact indisponiveis.', [
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryFactRegionRows(
        string $start,
        string $end,
        string $dimension,
        ?string $uf = null,
        ?string $cityIbge = null,
    ): array {
        if (!$this->hasTable('biomie_vendas_geo_cliente_fact')) {
            return [];
        }

        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = [];
        $groupSql = '';

        if ($dimension === 'uf') {
            $groupSql = "COALESCE(NULLIF(uf, ''), 'NA')";
        } elseif ($dimension === 'city') {
            $groupSql = "COALESCE(NULLIF(cidade, ''), 'NA')";
            if ($uf !== null && $uf !== '' && strtoupper($uf) !== 'ALL') {
                $where[] = 'COALESCE(uf, \'\') = :uf';
                $params['uf'] = strtoupper($uf);
            }
        } else {
            $groupSql = "COALESCE(NULLIF(bairro, ''), 'NA')";
            $where[] = "COALESCE(uf, '') = 'CE'";
            $where[] = "COALESCE(cidade, '') = 'fortaleza'";
        }

        $whereSql = $where !== [] ? ' AND ' . implode(' AND ', $where) : '';
        $tenantFilter = TenantContext::sqlFilter();

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    {$groupSql} AS region_name,
                    SUM(valor_total) AS valor,
                    COUNT(DISTINCT CONCAT(company_id, ':', codigo_cliente_omie)) AS clientes,
                    COUNT(DISTINCT nf_id) AS notes
                FROM biomie_vendas_geo_cliente_fact
                WHERE status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND data_referencia BETWEEN :start AND :end
                  {$whereSql}
                GROUP BY region_name
                ORDER BY valor DESC
            SQL);
            $stmt->execute($params);

            return $this->formatRankingRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: ranking fact indisponivel.', [
                'dimension' => $dimension,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queryCanonicalRegionRows(
        string $start,
        string $end,
        string $dimension,
        ?string $uf = null,
        ?string $cityIbge = null,
    ): array {
        if (!$this->hasTable('biomie_clientes_geo_dim')) {
            return [];
        }

        $params = [
            'start' => $start,
            'end' => $end,
        ];
        $where = [];
        $groupSql = '';

        if ($dimension === 'uf') {
            $groupSql = "COALESCE(NULLIF(geo.uf, ''), 'NA')";
        } elseif ($dimension === 'city') {
            $groupSql = "COALESCE(NULLIF(geo.cidade_norm, ''), 'NA')";
            if ($uf !== null && $uf !== '' && strtoupper($uf) !== 'ALL') {
                $where[] = 'COALESCE(geo.uf, \'\') = :uf';
                $params['uf'] = strtoupper($uf);
            }
        } else {
            $groupSql = "COALESCE(NULLIF(geo.bairro_norm, ''), 'NA')";
            $where[] = "COALESCE(geo.uf, '') = 'CE'";
            $where[] = "(
                COALESCE(geo.municipio_ibge, '') = :city_ibge
                OR (
                    COALESCE(geo.municipio_ibge, '') = ''
                    AND COALESCE(geo.cidade_norm, '') = 'fortaleza'
                )
            )";
            $params['city_ibge'] = $cityIbge ?? '2304400';
        }

        $whereSql = $where !== [] ? ' AND ' . implode(' AND ', $where) : '';
        $tenantFilter = TenantContext::sqlFilter('nf');

        try {
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    {$groupSql} AS region_name,
                    SUM(nf.valor_total) AS valor,
                    COUNT(DISTINCT CONCAT(nf.company_id, ':', nf.codigo_cliente_omie)) AS clientes,
                    COUNT(DISTINCT nf.id) AS notes
                FROM biomie_notas_fiscais_canonicas nf
                LEFT JOIN biomie_clientes_geo_dim geo
                    ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
                   AND geo.company_id = nf.company_id
                WHERE nf.status_fiscal = 'autorizada'
                  AND {$tenantFilter}
                  AND nf.data_emissao BETWEEN :start AND :end
                  {$whereSql}
                GROUP BY region_name
                ORDER BY valor DESC
            SQL);
            $stmt->execute($params);

            return $this->formatRankingRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $exception) {
            $this->logger->warning('GeoAnalyticsService: ranking canonico indisponivel.', [
                'dimension' => $dimension,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    private function canonicalScopeWhere(string $scope, array &$params, bool $hasGeoDim = true): string
    {
        if (!$hasGeoDim && in_array($scope, ['cidade_ce', 'bairro_fortaleza'], true)) {
            return ' AND 1 = 0';
        }

        return match ($scope) {
            'cidade_ce' => " AND COALESCE(geo.uf, '') = 'CE'",
            'bairro_fortaleza' => " AND COALESCE(geo.uf, '') = 'CE' AND COALESCE(geo.cidade_norm, '') = 'fortaleza'",
            default => '',
        };
    }

    private function factScopeWhere(string $scope, array &$params): string
    {
        return match ($scope) {
            'cidade_ce' => " AND COALESCE(uf, '') = 'CE'",
            'bairro_fortaleza' => " AND COALESCE(uf, '') = 'CE' AND COALESCE(cidade, '') = 'fortaleza'",
            default => '',
        };
    }

    private function documentTypeSql(string $column): string
    {
        return sprintf(
            "CASE
                WHEN UPPER(COALESCE(%s, '')) IN ('PF', 'CPF') THEN 'PF'
                WHEN UPPER(COALESCE(%s, '')) IN ('PJ', 'CNPJ') THEN 'PJ'
                ELSE 'OUTRO'
            END",
            $column,
            $column
        );
    }

    private function documentTypeFromInvoiceSql(string $column): string
    {
        $normalized = sprintf(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(%s, ''), '.', ''), '-', ''), '/', ''), ' ', ''), '(', ''), ')', '')",
            $column
        );

        return sprintf(
            "CASE
                WHEN LENGTH(%s) = 11 THEN 'PF'
                WHEN LENGTH(%s) = 14 THEN 'PJ'
                ELSE 'OUTRO'
            END",
            $normalized,
            $normalized
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function formatRankingRows(array $rows): array
    {
        $results = [];
        $totalValue = 0.0;

        foreach ($rows as $row) {
            $totalValue += Support::toFloat($row['valor'] ?? 0);
        }

        foreach ($rows as $row) {
            $value = Support::toFloat($row['valor'] ?? 0);
            $name = trim((string) ($row['region_name'] ?? ''));
            $results[] = [
                'name' => $this->formatRegionName($name),
                'revenue' => Support::money($value),
                'value_raw' => $value,
                'clients' => (int) ($row['clientes'] ?? 0),
                'notes' => (int) ($row['notes'] ?? 0),
                'share' => $totalValue > 0 ? round(($value / $totalValue) * 100, 1) : 0.0,
            ];
        }

        return $results;
    }

    private function normalizeDocumentType(string $value): string
    {
        $normalized = strtoupper(trim($value));
        return match ($normalized) {
            'CPF', 'PF' => 'PF',
            'CNPJ', 'PJ' => 'PJ',
            default => 'OUTRO',
        };
    }

    private function formatRegionName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || strtoupper($trimmed) === 'NA') {
            return 'Nao Informado';
        }

        if (preg_match('/^[A-Z]{2}$/', strtoupper($trimmed)) === 1) {
            return strtoupper($trimmed);
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
    }

    private function hasTable(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table
                LIMIT 1
            SQL);
            $stmt->execute(['table' => $table]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
