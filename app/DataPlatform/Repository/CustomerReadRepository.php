<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Repository;

use BIomie\DataPlatform\Query\CustomerQuery;
use BIomie\DataPlatform\Support\CnaeStatusResolver;
use BIomie\DataPlatform\Support\CustomerDocumentClassifier;
use BIomie\DataPlatform\Support\CustomerIdentityResolver;
use BIomie\Support\BiVisibilityScope;
use PDO;
use PDOStatement;
use Throwable;

final class CustomerReadRepository
{
    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,bool> */
    private array $columnCache = [];

    private int $queryCount = 0;

    private float $sqlTimeMs = 0.0;

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{query_count:int,sql_time_ms:float} */
    public function metrics(): array
    {
        return ['query_count' => $this->queryCount, 'sql_time_ms' => round($this->sqlTimeMs, 2)];
    }

    public function resetMetrics(): void
    {
        $this->queryCount = 0;
        $this->sqlTimeMs = 0.0;
    }

    /** @return array<string,mixed> */
    public function profile(CustomerQuery $query): array
    {
        $dim = $this->findDimProfile($query);
        if ($dim !== []) {
            $commercial = $this->commercialSummary(
                (string) ($dim['codigo_cliente_omie'] ?? ''),
                (string) ($dim['documento_normalizado'] ?? $dim['cpf_cnpj'] ?? ''),
                (string) ($dim['nome_cliente'] ?? ''),
                $query
            );

            return [
                'source' => 'clientes_dim',
                'has_dim_profile' => true,
                'identity' => $dim,
                'geo' => $this->geoFromDimRow($dim),
                'cnae' => $this->cnaeFromRow($dim),
                'commercial' => $commercial,
                'ownership' => $this->ownershipFromDimOrNf($dim, $query),
            ];
        }

        $fallback = $this->fallbackProfile($query);
        $commercial = $this->commercialSummary(
            (string) ($fallback['codigo_cliente_omie'] ?? $query->customer ?? ''),
            (string) ($fallback['documento_normalizado'] ?? $fallback['documento'] ?? $query->document ?? ''),
            (string) ($fallback['nome_cliente'] ?? ''),
            $query
        );

        return [
            'source' => (string) ($fallback['source'] ?? 'pending_omie'),
            'has_dim_profile' => false,
            'identity' => $fallback,
            'geo' => $this->geoFromFallback($fallback),
            'cnae' => $this->cnaeFromRow($fallback),
            'commercial' => $commercial,
            'ownership' => $this->ownershipFromDimOrNf($fallback, $query),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function search(CustomerQuery $query): array
    {
        if (!$this->tableExists('biomie_clientes_dim')) {
            return [];
        }

        [$whereSql, $params] = $this->customerWhere($query, requireIdentity: false, includeSearch: true);
        $orderBy = $this->orderBy($query);

        $sql = "
            SELECT
                d.codigo_cliente_omie,
                d.nome_cliente,
                d.nome_fantasia,
                d.cpf_cnpj,
                d.documento_normalizado,
                d.tipo_documento,
                d.cnae_codigo,
                d.cnae_descricao,
                d.cidade_key,
                d.bairro_key,
                d.cidade_normalizada,
                d.bairro_normalizado,
                d.uf,
                d.vendedor_responsavel_codigo_omie,
                d.vendedor_responsavel_nome,
                d.vendedor_responsavel_origem,
                COALESCE(SUM(m.valor_nfe), 0) AS revenue_period,
                COALESCE(SUM(m.nfes_emitidas), 0) AS invoices_period,
                MAX(d.ultima_compra_em) AS last_purchase_date
            FROM biomie_clientes_dim d
            LEFT JOIN mart_cliente_periodo m
              ON m.codigo_cliente_omie = d.codigo_cliente_omie
             AND m.competencia BETWEEN :start_comp AND :end_comp
            WHERE {$whereSql}
            GROUP BY
                d.codigo_cliente_omie, d.nome_cliente, d.nome_fantasia, d.cpf_cnpj,
                d.documento_normalizado, d.tipo_documento, d.cnae_codigo, d.cnae_descricao,
                d.cidade_key, d.bairro_key, d.cidade_normalizada, d.bairro_normalizado, d.uf,
                d.vendedor_responsavel_codigo_omie, d.vendedor_responsavel_nome, d.vendedor_responsavel_origem
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        $this->execute(
            $stmt,
            $params + [':start_comp' => $query->startComp, ':end_comp' => $query->endComp],
            [':limit' => $query->limit, ':offset' => $query->offset]
        );

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $this->normalizeSearchRow($row);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function quality(CustomerQuery $query): array
    {
        $dim = $this->dimQuality();
        $period = $this->periodQuality($query);

        return [
            'customers' => $dim,
            'period' => $period,
            'impact' => [
                'incomplete_revenue' => (float) ($period['incomplete_revenue'] ?? 0),
                'missing_geo_revenue' => (float) ($period['missing_geo_revenue'] ?? 0),
                'missing_cnae_cnpj_revenue' => (float) ($period['missing_cnae_cnpj_revenue'] ?? 0),
                'orphan_revenue' => (float) ($period['orphan_revenue'] ?? 0),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function opportunities(CustomerQuery $query): array
    {
        if (!$this->tableExists('mart_cliente_periodo')) {
            return [];
        }

        $sql = "
            SELECT
                m.codigo_cliente_omie,
                MAX(m.nome_cliente) AS nome_cliente,
                MAX(m.documento_cliente) AS documento_cliente,
                MAX(d.documento_normalizado) AS documento_normalizado,
                MAX(d.cpf_cnpj) AS cpf_cnpj,
                MAX(d.cnae_codigo) AS cnae_codigo,
                MAX(d.cnae_descricao) AS cnae_descricao,
                MAX(d.cidade_key) AS cidade_key,
                MAX(d.bairro_key) AS bairro_key,
                MAX(d.cidade_normalizada) AS cidade_normalizada,
                MAX(d.bairro_normalizado) AS bairro_normalizado,
                MAX(d.uf) AS uf,
                MAX(d.vendedor_responsavel_nome) AS vendedor_responsavel_nome,
                MAX(d.vendedor_responsavel_codigo_omie) AS vendedor_responsavel_codigo_omie,
                MAX(d.vendedor_responsavel_origem) AS vendedor_responsavel_origem,
                COALESCE(SUM(m.valor_nfe), 0) AS revenue_period,
                COALESCE(SUM(m.nfes_emitidas), 0) AS invoices_period,
                MAX(d.ultima_compra_em) AS last_purchase_date,
                MAX(CASE WHEN d.codigo_cliente_omie IS NULL THEN 1 ELSE 0 END) AS is_orphan
            FROM mart_cliente_periodo m
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
            WHERE m.competencia BETWEEN :start_comp AND :end_comp
            GROUP BY m.codigo_cliente_omie
            HAVING revenue_period > 0 OR invoices_period > 0
            ORDER BY {$this->orderBy($query)}
            LIMIT :scan_limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $this->execute($stmt, [':start_comp' => $query->startComp, ':end_comp' => $query->endComp], [':scan_limit' => max($query->limit * 8, 100)]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $normalized = $this->normalizeOpportunityRow($row);
            if ($query->qualityStatus !== null && !in_array($query->qualityStatus, (array) ($normalized['quality']['missing'] ?? []), true) && $normalized['quality']['status'] !== $query->qualityStatus) {
                continue;
            }
            if ((array) ($normalized['quality']['missing'] ?? []) === ['not_applicable_cpf']) {
                continue;
            }
            if ($normalized['quality']['status'] === 'complete') {
                continue;
            }
            $items[] = $normalized;
            if (count($items) >= $query->limit) {
                break;
            }
        }

        return $items;
    }

    /** @return array<string,mixed> */
    public function commercialHistory(CustomerQuery $query): array
    {
        $profile = $this->profile($query);
        $identity = (array) ($profile['identity'] ?? []);
        $codigo = (string) ($identity['codigo_cliente_omie'] ?? $query->customer ?? '');
        $document = (string) ($identity['documento_normalizado'] ?? $identity['cpf_cnpj'] ?? $query->document ?? '');

        return [
            'summary' => $this->commercialSummary($codigo, $document, (string) ($identity['nome_cliente'] ?? ''), $query),
            'months' => $this->commercialMonths($codigo, $document, $query),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function sampleCustomers(int $limit = 10): array
    {
        if (!$this->tableExists('mart_cliente_periodo')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT codigo_cliente_omie, COALESCE(SUM(valor_nfe), 0) AS revenue
             FROM mart_cliente_periodo
             GROUP BY codigo_cliente_omie
             ORDER BY revenue DESC
             LIMIT :limit'
        );
        $this->execute($stmt, [], [':limit' => max(1, min(50, $limit))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    public function freshness(): array
    {
        $out = [];
        foreach ([
            'biomie_clientes_dim' => 'updated_at',
            'biomie_clientes_geo_dim' => null,
            'mart_cliente_periodo' => 'refreshed_at',
        ] as $table => $column) {
            if (!$this->tableExists($table)) {
                $out[$table] = ['available' => false];
                continue;
            }
            $dateExpr = $column !== null && $this->columnExists($table, $column) ? "MAX(`{$column}`)" : 'NULL';
            $stmt = $this->pdo->prepare("SELECT {$dateExpr} AS refreshed_at, COUNT(*) AS rows_total FROM `{$table}`");
            $this->execute($stmt, []);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out[$table] = [
                'available' => true,
                'refreshed_at' => $row['refreshed_at'] ?? null,
                'rows_total' => (int) ($row['rows_total'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function findDimProfile(CustomerQuery $query): array
    {
        if (!$this->tableExists('biomie_clientes_dim')) {
            return [];
        }

        [$whereSql, $params] = $this->customerWhere($query, requireIdentity: true, includeSearch: true);
        if ($whereSql === '1 = 0') {
            return [];
        }

        $geoJoin = $this->tableExists('biomie_clientes_geo_dim')
            ? 'LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = d.codigo_cliente_omie'
            : '';

        $sql = "
            SELECT
                d.codigo_cliente_omie,
                d.cpf_cnpj,
                d.documento_normalizado,
                d.documento_mascarado,
                d.tipo_documento,
                d.tipo_pessoa,
                d.nome_cliente,
                d.nome_fantasia,
                d.cliente_nome_exibicao,
                d.natureza_cliente,
                d.cnae_codigo,
                d.cnae_descricao,
                d.cnae_source,
                d.cnae_confidence,
                d.cnae_captured_at,
                d.cnae_unknown_reason,
                d.segmento_macro,
                d.primeira_compra_em,
                d.ultima_compra_em,
                d.notas_12m,
                d.faturado_12m,
                d.is_recorrente,
                d.recorrencia_faixa,
                d.cidade,
                d.uf,
                d.bairro,
                d.cep,
                d.cidade_normalizada,
                d.bairro_normalizado,
                d.geo_status,
                d.cidade_key,
                d.bairro_key,
                d.vendedor_responsavel_codigo_omie,
                d.vendedor_responsavel_nome,
                d.vendedor_responsavel_origem,
                d.vendedor_responsavel_confidence,
                d.enrichment_status,
                d.enrichment_source,
                d.updated_at,
                g.cidade_norm AS geo_cidade_norm,
                g.bairro_norm AS geo_bairro_norm,
                g.cidade_key AS geo_cidade_key,
                g.bairro_key AS geo_bairro_key,
                g.uf AS geo_uf,
                g.cep AS geo_cep,
                g.match_source AS geo_match_source,
                g.match_confidence AS geo_match_confidence,
                g.geo_status AS geo_dim_status
            FROM biomie_clientes_dim d
            {$geoJoin}
            WHERE {$whereSql}
            ORDER BY d.codigo_cliente_omie DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $this->execute($stmt, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function customerWhere(CustomerQuery $query, bool $requireIdentity, bool $includeSearch): array
    {
        $where = [];
        $params = [];
        $identityParts = [];

        if ($query->customer !== null && ctype_digit($query->customer)) {
            $identityParts[] = 'd.codigo_cliente_omie = :customer_id';
            $params[':customer_id'] = $query->customer;
        }
        if ($query->document !== null) {
            $identityParts[] = '(d.documento_normalizado = :document_dim OR REGEXP_REPLACE(COALESCE(d.cpf_cnpj, \'\'), \'[^0-9]\', \'\') = :document_raw)';
            $params[':document_dim'] = $query->document;
            $params[':document_raw'] = $query->document;
        }
        if ($includeSearch && $query->q !== null) {
            $identityParts[] = '(LOWER(d.nome_cliente) LIKE :q_name OR LOWER(COALESCE(d.nome_fantasia, \'\')) LIKE :q_fantasy OR LOWER(COALESCE(d.cliente_nome_exibicao, \'\')) LIKE :q_display)';
            $params[':q_name'] = '%' . mb_strtolower($query->q) . '%';
            $params[':q_fantasy'] = '%' . mb_strtolower($query->q) . '%';
            $params[':q_display'] = '%' . mb_strtolower($query->q) . '%';
        }

        if ($identityParts !== []) {
            $where[] = '(' . implode(' OR ', $identityParts) . ')';
        } elseif ($requireIdentity) {
            return ['1 = 0', []];
        }

        if ($query->documentType !== 'all') {
            $where[] = match ($query->documentType) {
                'cnpj' => "CHAR_LENGTH(REGEXP_REPLACE(COALESCE(d.cpf_cnpj, d.documento_normalizado, ''), '[^0-9]', '')) = 14",
                'cpf' => "CHAR_LENGTH(REGEXP_REPLACE(COALESCE(d.cpf_cnpj, d.documento_normalizado, ''), '[^0-9]', '')) = 11",
                'invalid' => "CHAR_LENGTH(REGEXP_REPLACE(COALESCE(d.cpf_cnpj, d.documento_normalizado, ''), '[^0-9]', '')) NOT IN (0, 11, 14)",
                'unknown' => "CHAR_LENGTH(REGEXP_REPLACE(COALESCE(d.cpf_cnpj, d.documento_normalizado, ''), '[^0-9]', '')) = 0",
                default => '1=1',
            };
        }
        if ($query->city !== null) {
            $where[] = '(LOWER(COALESCE(d.cidade_key, d.cidade_normalizada, d.cidade, \'\')) = :city)';
            $params[':city'] = mb_strtolower($query->city);
        }
        if ($query->neighborhood !== null) {
            $where[] = '(LOWER(COALESCE(d.bairro_key, d.bairro_normalizado, d.bairro, \'\')) = :neighborhood)';
            $params[':neighborhood'] = mb_strtolower($query->neighborhood);
        }
        if ($query->cnae !== null) {
            if (strtoupper($query->cnae) === 'SEM_CNAE') {
                $where[] = "(d.cnae_codigo IS NULL OR TRIM(d.cnae_codigo) = '' OR d.cnae_codigo = 'SEM_CNAE')";
            } else {
                $where[] = "REGEXP_REPLACE(COALESCE(d.cnae_codigo, ''), '[^0-9]', '') = :cnae";
                $params[':cnae'] = preg_replace('/\D+/', '', $query->cnae) ?? $query->cnae;
            }
        }
        if ($query->sellerId !== null) {
            $where[] = '(d.vendedor_responsavel_codigo_omie = :seller_code OR LOWER(COALESCE(d.vendedor_responsavel_nome, \'\')) LIKE :seller_like)';
            $params[':seller_code'] = $query->sellerId;
            $params[':seller_like'] = '%' . mb_strtolower($query->sellerId) . '%';
        }

        return [$where !== [] ? implode(' AND ', $where) : '1=1', $params];
    }

    /** @return array<string,mixed> */
    private function fallbackProfile(CustomerQuery $query): array
    {
        $mart = $this->fallbackFromGeoMart($query);
        if ($mart !== []) {
            return $mart + ['source' => 'mart'];
        }

        $nf = $this->fallbackFromNf($query);
        if ($nf !== []) {
            return $nf + ['source' => 'nf_canonica'];
        }

        return [
            'codigo_cliente_omie' => $query->customer,
            'documento_normalizado' => $query->document,
            'nome_cliente' => $query->q ?: 'Cliente pendente de enriquecimento',
            'source' => 'pending_omie',
        ];
    }

    /** @return array<string,mixed> */
    private function fallbackFromGeoMart(CustomerQuery $query): array
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            return [];
        }

        [$where, $params] = $this->fallbackIdentityWhere($query, 'm', 'documento', 'cliente_nome');
        if ($where === '1 = 0') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(m.cliente_nome) AS nome_cliente,
                MAX(m.documento) AS cpf_cnpj,
                MAX(REGEXP_REPLACE(COALESCE(m.documento, ''), '[^0-9]', '')) AS documento_normalizado,
                MAX(m.uf) AS uf,
                MAX(m.cidade_norm) AS cidade_normalizada,
                MAX(m.bairro_norm) AS bairro_normalizado,
                MAX(m.cidade_key) AS cidade_key,
                MAX(m.bairro_key) AS bairro_key,
                MAX(m.cnae_codigo) AS cnae_codigo,
                MAX(m.cnae_descricao) AS cnae_descricao,
                MAX(m.segmento_macro) AS segmento_macro
            FROM mart_compradores_por_geo m
            WHERE {$where}
        ");
        $this->execute($stmt, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return !empty($row['codigo_cliente_omie']) || !empty($row['cpf_cnpj']) || !empty($row['nome_cliente']) ? $row : [];
    }

    /** @return array<string,mixed> */
    private function fallbackFromNf(CustomerQuery $query): array
    {
        if (!$this->tableExists('biomie_notas_fiscais_canonicas')) {
            return [];
        }

        [$where, $params] = $this->fallbackIdentityWhere($query, 'nf', 'documento_cliente', 'nome_cliente');
        if ($where === '1 = 0') {
            return [];
        }
        $visibility = BiVisibilityScope::getCondition($this->pdo, 'biomie_notas_fiscais_canonicas', 'nf');

        $stmt = $this->pdo->prepare("
            SELECT
                MAX(nf.codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(nf.nome_cliente) AS nome_cliente,
                MAX(nf.documento_cliente) AS cpf_cnpj,
                MAX(REGEXP_REPLACE(COALESCE(nf.documento_cliente, ''), '[^0-9]', '')) AS documento_normalizado
            FROM biomie_notas_fiscais_canonicas nf
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.operation_scope = 'commercial'
              AND {$visibility}
              AND {$where}
        ");
        $this->execute($stmt, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return !empty($row['codigo_cliente_omie']) || !empty($row['cpf_cnpj']) || !empty($row['nome_cliente']) ? $row : [];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function fallbackIdentityWhere(CustomerQuery $query, string $alias, string $docCol, string $nameCol): array
    {
        $where = [];
        $params = [];
        if ($query->customer !== null && ctype_digit($query->customer)) {
            $where[] = "{$alias}.codigo_cliente_omie = :customer";
            $params[':customer'] = $query->customer;
        }
        if ($query->document !== null) {
            $where[] = "REGEXP_REPLACE(COALESCE({$alias}.{$docCol}, ''), '[^0-9]', '') = :document";
            $params[':document'] = $query->document;
        }
        if ($query->q !== null) {
            $where[] = "LOWER(COALESCE({$alias}.{$nameCol}, '')) LIKE :q";
            $params[':q'] = '%' . mb_strtolower($query->q) . '%';
        }

        return [$where !== [] ? '(' . implode(' OR ', $where) . ')' : '1 = 0', $params];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function geoFromDimRow(array $row): array
    {
        return [
            'cidade_key' => (string) (($row['cidade_key'] ?? '') ?: ($row['geo_cidade_key'] ?? '') ?: ($row['cidade_normalizada'] ?? '') ?: ($row['geo_cidade_norm'] ?? '')),
            'cidade_label' => (string) (($row['cidade_normalizada'] ?? '') ?: ($row['geo_cidade_norm'] ?? '') ?: ($row['cidade'] ?? '')),
            'bairro_key' => (string) (($row['bairro_key'] ?? '') ?: ($row['geo_bairro_key'] ?? '') ?: ($row['bairro_normalizado'] ?? '') ?: ($row['geo_bairro_norm'] ?? '')),
            'bairro_label' => (string) (($row['bairro_normalizado'] ?? '') ?: ($row['geo_bairro_norm'] ?? '') ?: ($row['bairro'] ?? '')),
            'uf' => (string) (($row['uf'] ?? '') ?: ($row['geo_uf'] ?? '')),
            'cep' => (string) (($row['cep'] ?? '') ?: ($row['geo_cep'] ?? '')),
            'quality' => (string) (($row['geo_dim_status'] ?? '') ?: ($row['geo_status'] ?? '') ?: 'unknown'),
            'source' => (string) ($row['geo_match_source'] ?? 'clientes_dim'),
            'confidence' => isset($row['geo_match_confidence']) ? (float) $row['geo_match_confidence'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function geoFromFallback(array $row): array
    {
        return [
            'cidade_key' => (string) ($row['cidade_key'] ?? $row['cidade_normalizada'] ?? ''),
            'cidade_label' => (string) ($row['cidade_normalizada'] ?? $row['cidade_key'] ?? ''),
            'bairro_key' => (string) ($row['bairro_key'] ?? $row['bairro_normalizado'] ?? ''),
            'bairro_label' => (string) ($row['bairro_normalizado'] ?? $row['bairro_key'] ?? ''),
            'uf' => (string) ($row['uf'] ?? ''),
            'cep' => (string) ($row['cep'] ?? ''),
            'quality' => 'pending_omie',
            'source' => (string) ($row['source'] ?? 'fallback'),
            'confidence' => null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cnaeFromRow(array $row): array
    {
        $document = (string) (($row['cpf_cnpj'] ?? '') ?: ($row['documento_normalizado'] ?? '') ?: ($row['documento_cliente'] ?? ''));
        $cnae = (string) ($row['cnae_codigo'] ?? '');
        $status = CnaeStatusResolver::resolve($document, $cnae);

        return [
            'status' => $status,
            'status_label' => CnaeStatusResolver::label($status),
            'codigo' => $status === CnaeStatusResolver::OK ? $cnae : null,
            'descricao' => $status === CnaeStatusResolver::OK ? (string) ($row['cnae_descricao'] ?? '') : null,
            'source' => (string) ($row['cnae_source'] ?? 'clientes_dim'),
            'confidence' => isset($row['cnae_confidence']) ? (int) $row['cnae_confidence'] : null,
            'unknown_reason' => (string) ($row['cnae_unknown_reason'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function ownershipFromDimOrNf(array $row, CustomerQuery $query): array
    {
        $sellerName = trim((string) ($row['vendedor_responsavel_nome'] ?? ''));
        if ($sellerName !== '') {
            return [
                'seller_id' => (string) ($row['vendedor_responsavel_codigo_omie'] ?? ''),
                'seller_name' => $sellerName,
                'owner_source' => (string) ($row['vendedor_responsavel_origem'] ?? 'clientes_dim'),
                'confidence' => isset($row['vendedor_responsavel_confidence']) ? (int) $row['vendedor_responsavel_confidence'] : null,
            ];
        }

        $latest = $this->latestSeller(
            (string) ($row['codigo_cliente_omie'] ?? $query->customer ?? ''),
            (string) ($row['documento_normalizado'] ?? $row['cpf_cnpj'] ?? $query->document ?? ''),
            $query
        );

        return [
            'seller_id' => (string) ($latest['codigo_vendedor_omie'] ?? ''),
            'seller_name' => (string) ($latest['nome_vendedor'] ?? ''),
            'owner_source' => $latest !== [] ? 'nf_latest' : 'missing',
            'confidence' => $latest !== [] ? 60 : 0,
        ];
    }

    /** @return array<string,mixed> */
    private function commercialSummary(string $codigo, string $document, string $name, CustomerQuery $query): array
    {
        $summary = [
            'first_purchase_date' => null,
            'last_purchase_date' => null,
            'revenue_period' => 0.0,
            'invoices_period' => 0,
            'orders_period' => 0,
            'active_months' => 0,
            'recurrence_status' => 'sem_historico',
            'open_amount' => 0.0,
            'source' => 'mart_cliente_periodo',
        ];

        if ($this->tableExists('mart_cliente_periodo')) {
            [$where, $params] = $this->martCustomerWhere($codigo, $document, $name);
            if ($where !== '1 = 0') {
                $stmt = $this->pdo->prepare("
                    SELECT
                        COALESCE(SUM(valor_nfe), 0) AS revenue_period,
                        COALESCE(SUM(nfes_emitidas), 0) AS invoices_period,
                        COALESCE(SUM(pedidos_faturados), 0) AS orders_period,
                        COALESCE(SUM(valor_aberto), 0) AS open_amount,
                        COUNT(DISTINCT CASE WHEN valor_nfe > 0 OR nfes_emitidas > 0 THEN competencia END) AS active_months
                    FROM mart_cliente_periodo
                    WHERE competencia BETWEEN :start_comp AND :end_comp
                      AND {$where}
                ");
                $this->execute($stmt, $params + [':start_comp' => $query->startComp, ':end_comp' => $query->endComp]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $summary['revenue_period'] = (float) ($row['revenue_period'] ?? 0);
                $summary['invoices_period'] = (int) ($row['invoices_period'] ?? 0);
                $summary['orders_period'] = (int) ($row['orders_period'] ?? 0);
                $summary['active_months'] = (int) ($row['active_months'] ?? 0);
                $summary['open_amount'] = (float) ($row['open_amount'] ?? 0);
            }
        }

        $nfDates = $this->nfPurchaseDates($codigo, $document, $name);
        $summary['first_purchase_date'] = $nfDates['first_purchase_date'] ?? null;
        $summary['last_purchase_date'] = $nfDates['last_purchase_date'] ?? null;
        $summary['recurrence_status'] = ((int) $summary['active_months']) >= 2
            ? 'recorrente'
            : (((int) $summary['invoices_period']) > 0 ? 'ativo_periodo' : 'sem_compra_periodo');

        return $summary;
    }

    /** @return list<array<string,mixed>> */
    private function commercialMonths(string $codigo, string $document, CustomerQuery $query): array
    {
        if (!$this->tableExists('mart_cliente_periodo')) {
            return [];
        }

        [$where, $params] = $this->martCustomerWhere($codigo, $document, '');
        if ($where === '1 = 0') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT competencia, SUM(valor_nfe) AS revenue, SUM(nfes_emitidas) AS invoices,
                   SUM(pedidos_faturados) AS orders, SUM(valor_aberto) AS open_amount
            FROM mart_cliente_periodo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND {$where}
            GROUP BY competencia
            ORDER BY competencia ASC
        ");
        $this->execute($stmt, $params + [':start_comp' => $query->startComp, ':end_comp' => $query->endComp]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function martCustomerWhere(string $codigo, string $document, string $name): array
    {
        $where = [];
        $params = [];
        if ($codigo !== '' && ctype_digit($codigo)) {
            $where[] = 'codigo_cliente_omie = :codigo';
            $params[':codigo'] = $codigo;
        }
        $digits = preg_replace('/\D+/', '', $document) ?? '';
        if ($digits !== '') {
            $where[] = "REGEXP_REPLACE(COALESCE(documento_cliente, ''), '[^0-9]', '') = :document";
            $params[':document'] = $digits;
        }
        if ($where === [] && trim($name) !== '') {
            $where[] = 'LOWER(nome_cliente) = :name';
            $params[':name'] = mb_strtolower(trim($name));
        }

        return [$where !== [] ? '(' . implode(' OR ', $where) . ')' : '1 = 0', $params];
    }

    /** @return array<string,mixed> */
    private function nfPurchaseDates(string $codigo, string $document, string $name): array
    {
        if (!$this->tableExists('biomie_notas_fiscais_canonicas')) {
            return [];
        }

        [$where, $params] = $this->nfCustomerWhere($codigo, $document, $name);
        if ($where === '1 = 0') {
            return [];
        }
        $visibility = BiVisibilityScope::getCondition($this->pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $stmt = $this->pdo->prepare("
            SELECT
                MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS first_purchase_date,
                MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS last_purchase_date
            FROM biomie_notas_fiscais_canonicas nf
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.operation_scope = 'commercial'
              AND {$visibility}
              AND {$where}
        ");
        $this->execute($stmt, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function latestSeller(string $codigo, string $document, CustomerQuery $query): array
    {
        if (!$this->tableExists('biomie_notas_fiscais_canonicas')) {
            return [];
        }

        [$where, $params] = $this->nfCustomerWhere($codigo, $document, '');
        if ($where === '1 = 0') {
            return [];
        }
        $visibility = BiVisibilityScope::getCondition($this->pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $stmt = $this->pdo->prepare("
            SELECT nf.codigo_vendedor_omie, nf.nome_vendedor
            FROM biomie_notas_fiscais_canonicas nf
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.operation_scope = 'commercial'
              AND {$visibility}
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :date_from AND :date_to
              AND {$where}
            ORDER BY COALESCE(nf.data_competencia, nf.data_emissao) DESC, nf.id DESC
            LIMIT 1
        ");
        $this->execute($stmt, $params + [':date_from' => $query->dateFrom, ':date_to' => $query->dateTo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function nfCustomerWhere(string $codigo, string $document, string $name): array
    {
        $where = [];
        $params = [];
        if ($codigo !== '' && ctype_digit($codigo)) {
            $where[] = 'nf.codigo_cliente_omie = :codigo';
            $params[':codigo'] = $codigo;
        }
        $digits = preg_replace('/\D+/', '', $document) ?? '';
        if ($digits !== '') {
            $where[] = "REGEXP_REPLACE(COALESCE(nf.documento_cliente, ''), '[^0-9]', '') = :document";
            $params[':document'] = $digits;
        }
        if ($where === [] && trim($name) !== '') {
            $where[] = 'LOWER(nf.nome_cliente) = :name';
            $params[':name'] = mb_strtolower(trim($name));
        }

        return [$where !== [] ? '(' . implode(' OR ', $where) . ')' : '1 = 0', $params];
    }

    /** @return array<string,mixed> */
    private function dimQuality(): array
    {
        if (!$this->tableExists('biomie_clientes_dim')) {
            return $this->emptyQualityCounts();
        }

        $docExpr = "REGEXP_REPLACE(COALESCE(cpf_cnpj, documento_normalizado, ''), '[^0-9]', '')";
        $cnaeExpr = "REGEXP_REPLACE(COALESCE(cnae_codigo, ''), '[^0-9]', '')";
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total_customers,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) > 0 THEN 1 ELSE 0 END) AS with_document,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 0 THEN 1 ELSE 0 END) AS missing_document,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 11 THEN 1 ELSE 0 END) AS cpf_customers,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 14 THEN 1 ELSE 0 END) AS cnpj_customers,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) NOT IN (0, 11, 14) THEN 1 ELSE 0 END) AS invalid_document,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 14 AND CHAR_LENGTH({$cnaeExpr}) = 7 THEN 1 ELSE 0 END) AS cnpj_with_cnae,
                SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 14 AND CHAR_LENGTH({$cnaeExpr}) <> 7 THEN 1 ELSE 0 END) AS cnpj_missing_cnae,
                SUM(CASE WHEN COALESCE(cidade_key, cidade_normalizada, cidade, '') <> '' THEN 1 ELSE 0 END) AS with_city,
                SUM(CASE WHEN COALESCE(cidade_key, cidade_normalizada, cidade, '') = '' THEN 1 ELSE 0 END) AS missing_city,
                SUM(CASE WHEN COALESCE(bairro_key, bairro_normalizado, bairro, '') <> '' THEN 1 ELSE 0 END) AS with_neighborhood,
                SUM(CASE WHEN COALESCE(bairro_key, bairro_normalizado, bairro, '') = '' THEN 1 ELSE 0 END) AS missing_neighborhood,
                SUM(CASE WHEN COALESCE(vendedor_responsavel_nome, '') <> '' THEN 1 ELSE 0 END) AS with_owner,
                SUM(CASE WHEN COALESCE(vendedor_responsavel_nome, '') = '' THEN 1 ELSE 0 END) AS missing_owner
            FROM biomie_clientes_dim
        ");
        $this->execute($stmt, []);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn ($value) => is_numeric($value) ? (int) $value : $value, $row);
    }

    /** @return array<string,mixed> */
    private function periodQuality(CustomerQuery $query): array
    {
        $out = [
            'customers_with_purchase' => 0,
            'revenue_period' => 0.0,
            'invoices_period' => 0,
            'incomplete_revenue' => 0.0,
            'missing_geo_revenue' => 0.0,
            'missing_cnae_cnpj_revenue' => 0.0,
            'orphan_customers' => 0,
            'orphan_revenue' => 0.0,
        ];

        if (!$this->tableExists('mart_cliente_periodo')) {
            return $out;
        }

        $docExpr = "REGEXP_REPLACE(COALESCE(d.cpf_cnpj, d.documento_normalizado, m.documento_cliente, ''), '[^0-9]', '')";
        $cnaeExpr = "REGEXP_REPLACE(COALESCE(d.cnae_codigo, ''), '[^0-9]', '')";
        $geoMissing = "COALESCE(d.cidade_key, d.cidade_normalizada, d.cidade, '') = '' OR COALESCE(d.bairro_key, d.bairro_normalizado, d.bairro, '') = ''";
        $incomplete = "d.codigo_cliente_omie IS NULL OR CHAR_LENGTH({$docExpr}) = 0 OR CHAR_LENGTH({$docExpr}) NOT IN (11,14) OR {$geoMissing} OR COALESCE(d.vendedor_responsavel_nome, '') = '' OR (CHAR_LENGTH({$docExpr}) = 14 AND CHAR_LENGTH({$cnaeExpr}) <> 7)";
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT m.codigo_cliente_omie) AS customers_with_purchase,
                COALESCE(SUM(m.valor_nfe), 0) AS revenue_period,
                COALESCE(SUM(m.nfes_emitidas), 0) AS invoices_period,
                COALESCE(SUM(CASE WHEN {$incomplete} THEN m.valor_nfe ELSE 0 END), 0) AS incomplete_revenue,
                COALESCE(SUM(CASE WHEN {$geoMissing} THEN m.valor_nfe ELSE 0 END), 0) AS missing_geo_revenue,
                COALESCE(SUM(CASE WHEN CHAR_LENGTH({$docExpr}) = 14 AND CHAR_LENGTH({$cnaeExpr}) <> 7 THEN m.valor_nfe ELSE 0 END), 0) AS missing_cnae_cnpj_revenue,
                COUNT(DISTINCT CASE WHEN d.codigo_cliente_omie IS NULL THEN m.codigo_cliente_omie END) AS orphan_customers,
                COALESCE(SUM(CASE WHEN d.codigo_cliente_omie IS NULL THEN m.valor_nfe ELSE 0 END), 0) AS orphan_revenue
            FROM mart_cliente_periodo m
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
            WHERE m.competencia BETWEEN :start_comp AND :end_comp
        ");
        $this->execute($stmt, [':start_comp' => $query->startComp, ':end_comp' => $query->endComp]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($row as $key => $value) {
            $out[$key] = str_contains((string) $key, 'revenue') ? (float) $value : (int) $value;
        }

        if ($this->tableExists('mart_vendas_por_cnae_cnpj')) {
            $statusExpr = "CASE
                WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE(documento_normalizado, ''), '[^0-9]', '')) = 14
                 AND CHAR_LENGTH(REGEXP_REPLACE(COALESCE(cnae_codigo, ''), '[^0-9]', '')) <> 7
                THEN 1 ELSE 0 END";
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN {$statusExpr} = 1 THEN faturamento ELSE 0 END), 0)
                FROM mart_vendas_por_cnae_cnpj
                WHERE competencia BETWEEN :start_comp AND :end_comp
            ");
            $this->execute($stmt, [':start_comp' => $query->startComp, ':end_comp' => $query->endComp]);
            $out['missing_cnae_cnpj_revenue'] = (float) $stmt->fetchColumn();
        }

        return $out;
    }

    /** @return array<string,int> */
    private function emptyQualityCounts(): array
    {
        return [
            'total_customers' => 0,
            'with_document' => 0,
            'missing_document' => 0,
            'cpf_customers' => 0,
            'cnpj_customers' => 0,
            'invalid_document' => 0,
            'cnpj_with_cnae' => 0,
            'cnpj_missing_cnae' => 0,
            'with_city' => 0,
            'missing_city' => 0,
            'with_neighborhood' => 0,
            'missing_neighborhood' => 0,
            'with_owner' => 0,
            'missing_owner' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeSearchRow(array $row): array
    {
        $document = CustomerDocumentClassifier::classify((string) (($row['cpf_cnpj'] ?? '') ?: ($row['documento_normalizado'] ?? '')), false);
        $cnaeStatus = CnaeStatusResolver::resolve($document['digits'], (string) ($row['cnae_codigo'] ?? ''));

        return [
            'customer_key' => CustomerIdentityResolver::customerKey((string) ($row['codigo_cliente_omie'] ?? ''), $document['digits'], (string) ($row['nome_cliente'] ?? '')),
            'codigo_cliente_omie' => (string) ($row['codigo_cliente_omie'] ?? ''),
            'nome' => (string) ($row['nome_cliente'] ?? ''),
            'nome_fantasia' => (string) ($row['nome_fantasia'] ?? ''),
            'document' => [
                'type' => $document['type'],
                'masked' => $document['masked'],
                'status' => $document['status'],
            ],
            'geo' => [
                'cidade_key' => (string) ($row['cidade_key'] ?? ''),
                'cidade_label' => (string) ($row['cidade_normalizada'] ?? ''),
                'bairro_key' => (string) ($row['bairro_key'] ?? ''),
                'bairro_label' => (string) ($row['bairro_normalizado'] ?? ''),
                'uf' => (string) ($row['uf'] ?? ''),
            ],
            'cnae' => [
                'status' => $cnaeStatus,
                'codigo' => $cnaeStatus === CnaeStatusResolver::OK ? (string) ($row['cnae_codigo'] ?? '') : null,
                'descricao' => $cnaeStatus === CnaeStatusResolver::OK ? (string) ($row['cnae_descricao'] ?? '') : null,
            ],
            'commercial' => [
                'revenue_period' => (float) ($row['revenue_period'] ?? 0),
                'invoices_period' => (int) ($row['invoices_period'] ?? 0),
                'last_purchase_date' => $row['last_purchase_date'] ?? null,
            ],
            'ownership' => [
                'seller_id' => (string) ($row['vendedor_responsavel_codigo_omie'] ?? ''),
                'seller_name' => (string) ($row['vendedor_responsavel_nome'] ?? ''),
                'owner_source' => (string) ($row['vendedor_responsavel_origem'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeOpportunityRow(array $row): array
    {
        $documentRaw = (string) (($row['cpf_cnpj'] ?? '') ?: ($row['documento_normalizado'] ?? '') ?: ($row['documento_cliente'] ?? ''));
        $document = CustomerDocumentClassifier::classify($documentRaw, false);
        $geo = [
            'cidade_key' => (string) ($row['cidade_key'] ?? ''),
            'cidade_label' => (string) ($row['cidade_normalizada'] ?? ''),
            'bairro_key' => (string) ($row['bairro_key'] ?? ''),
            'bairro_label' => (string) ($row['bairro_normalizado'] ?? ''),
            'uf' => (string) ($row['uf'] ?? ''),
        ];
        $cnaeStatus = CnaeStatusResolver::resolve($document['digits'], (string) ($row['cnae_codigo'] ?? ''));
        $cnae = [
            'status' => $cnaeStatus,
            'codigo' => $cnaeStatus === CnaeStatusResolver::OK ? (string) ($row['cnae_codigo'] ?? '') : null,
            'descricao' => $cnaeStatus === CnaeStatusResolver::OK ? (string) ($row['cnae_descricao'] ?? '') : null,
        ];
        $commercial = [
            'revenue_period' => (float) ($row['revenue_period'] ?? 0),
            'invoices_period' => (int) ($row['invoices_period'] ?? 0),
            'last_purchase_date' => $row['last_purchase_date'] ?? null,
        ];
        $ownership = [
            'seller_id' => (string) ($row['vendedor_responsavel_codigo_omie'] ?? ''),
            'seller_name' => (string) ($row['vendedor_responsavel_nome'] ?? ''),
            'owner_source' => (string) ($row['vendedor_responsavel_origem'] ?? ''),
        ];
        $quality = \BIomie\DataPlatform\Support\CustomerQualityResolver::resolve(
            ['document_type' => $document['type'], 'document_status' => $document['status']],
            $geo,
            $cnae,
            $commercial,
            $ownership,
            ((int) ($row['is_orphan'] ?? 0)) === 0,
        );

        return [
            'customer_key' => CustomerIdentityResolver::customerKey((string) ($row['codigo_cliente_omie'] ?? ''), $document['digits'], (string) ($row['nome_cliente'] ?? '')),
            'codigo_cliente_omie' => (string) ($row['codigo_cliente_omie'] ?? ''),
            'nome' => (string) ($row['nome_cliente'] ?? ''),
            'document' => ['type' => $document['type'], 'masked' => $document['masked'], 'status' => $document['status']],
            'geo' => $geo,
            'cnae' => $cnae,
            'commercial' => $commercial,
            'ownership' => $ownership,
            'quality' => $quality,
        ];
    }

    private function orderBy(CustomerQuery $query): string
    {
        $direction = $query->direction === 'asc' ? 'ASC' : 'DESC';
        $map = [
            'revenue' => 'revenue_period',
            'last_purchase' => 'last_purchase_date',
            'name' => 'nome_cliente',
            'invoices' => 'invoices_period',
            'quality' => 'revenue_period',
        ];

        return ($map[$query->sort] ?? 'revenue_period') . ' ' . $direction;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $this->execute($stmt, [':table' => $table]);
            return $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
            $this->execute($stmt, [':table' => $table, ':column' => $column]);
            return $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->columnCache[$key] = false;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,int|string|float> $extra
     */
    private function execute(PDOStatement $stmt, array $params, array $extra = []): void
    {
        $started = microtime(true);
        foreach ($params as $key => $value) {
            $stmt->bindValue((string) $key, $value);
        }
        foreach ($extra as $key => $value) {
            $stmt->bindValue((string) $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $this->queryCount++;
        $this->sqlTimeMs += (microtime(true) - $started) * 1000;
    }
}
