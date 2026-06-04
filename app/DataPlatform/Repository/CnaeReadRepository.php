<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Repository;

use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\Support\CnaeStatusResolver;
use PDO;
use Throwable;

final class CnaeReadRepository
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
        return [
            'query_count' => $this->queryCount,
            'sql_time_ms' => round($this->sqlTimeMs, 2),
        ];
    }

    public function resetMetrics(): void
    {
        $this->queryCount = 0;
        $this->sqlTimeMs = 0.0;
    }

    /** @return array<string,mixed> */
    public function coverage(CnaeQuery $query): array
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            return $this->emptyCoverage();
        }

        [$whereSql, $params] = $this->baseWhere($query, applyCnae: false, applyStatus: false);
        $statusExpr = $this->statusExpr('m');
        $customerExpr = $this->customerExpr('m');

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(DISTINCT {$customerExpr}) AS total_customers,
                SUM(m.qtd_notas) AS total_invoices,
                SUM(m.faturamento) AS total_revenue
            FROM mart_vendas_por_cnae_cnpj m
            WHERE {$whereSql}
        ");
        $this->execute($stmt, $params);
        $total = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->pdo->prepare("
            SELECT
                {$statusExpr} AS cnae_status,
                COUNT(DISTINCT {$customerExpr}) AS customers,
                SUM(m.qtd_notas) AS invoices,
                SUM(m.faturamento) AS revenue
            FROM mart_vendas_por_cnae_cnpj m
            WHERE {$whereSql}
            GROUP BY {$statusExpr}
        ");
        $this->execute($stmt, $params);

        $byStatus = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string) ($row['cnae_status'] ?? CnaeStatusResolver::UNKNOWN);
            $byStatus[$status] = [
                'status' => $status,
                'label' => CnaeStatusResolver::label($status),
                'customers' => (int) ($row['customers'] ?? 0),
                'invoices' => (int) ($row['invoices'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
            ];
        }

        foreach (CnaeStatusResolver::statuses() as $status) {
            $byStatus[$status] ??= [
                'status' => $status,
                'label' => CnaeStatusResolver::label($status),
                'customers' => 0,
                'invoices' => 0,
                'revenue' => 0.0,
            ];
        }

        $totalCustomers = (int) ($total['total_customers'] ?? 0);
        $cnpjCustomers = (int) $byStatus[CnaeStatusResolver::OK]['customers']
            + (int) $byStatus[CnaeStatusResolver::MISSING_CNPJ]['customers'];
        $cnpjWithCnae = (int) $byStatus[CnaeStatusResolver::OK]['customers'];
        $cnpjMissing = (int) $byStatus[CnaeStatusResolver::MISSING_CNPJ]['customers'];

        return [
            'total_customers' => $totalCustomers,
            'total_invoices' => (int) ($total['total_invoices'] ?? 0),
            'total_revenue' => (float) ($total['total_revenue'] ?? 0),
            'cnpj_customers' => $cnpjCustomers,
            'cpf_customers' => (int) $byStatus[CnaeStatusResolver::NOT_APPLICABLE_CPF]['customers'],
            'cnpj_with_cnae' => $cnpjWithCnae,
            'cnpj_missing_cnae' => $cnpjMissing,
            'cpf_not_applicable' => (int) $byStatus[CnaeStatusResolver::NOT_APPLICABLE_CPF]['customers'],
            'invalid_document_customers' => (int) $byStatus[CnaeStatusResolver::INVALID_DOCUMENT]['customers'],
            'unknown_document_customers' => (int) $byStatus[CnaeStatusResolver::UNKNOWN]['customers'],
            'cnpj_coverage_pct' => $this->pct($cnpjWithCnae, $cnpjCustomers),
            'general_coverage_pct' => $this->pct($cnpjWithCnae, $totalCustomers),
            'by_status' => array_values($byStatus),
            'impact' => [
                'missing_cnpj_revenue' => (float) $byStatus[CnaeStatusResolver::MISSING_CNPJ]['revenue'],
                'missing_cnpj_customers' => $cnpjMissing,
                'missing_cnpj_invoices' => (int) $byStatus[CnaeStatusResolver::MISSING_CNPJ]['invoices'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function ranking(CnaeQuery $query): array
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            return [];
        }

        [$whereSql, $params] = $this->baseWhere($query);
        [$join, $descExpr] = $this->cnaeDimJoinAndDescription('m');
        $statusExpr = $this->statusExpr('m');
        $customerExpr = $this->customerExpr('m');

        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(m.cnae_codigo), ''), 'SEM_CNAE') AS cnae_codigo,
                {$descExpr} AS cnae_descricao,
                COALESCE(NULLIF(MAX(TRIM(m.segmento_macro)), ''), 'Nao classificado') AS segmento_macro,
                {$statusExpr} AS cnae_status,
                COUNT(DISTINCT {$customerExpr}) AS customers,
                SUM(m.qtd_notas) AS invoices,
                SUM(m.faturamento) AS revenue,
                MAX(m.ultima_compra) AS last_purchase
            FROM mart_vendas_por_cnae_cnpj m
            {$join}
            WHERE {$whereSql}
            GROUP BY COALESCE(NULLIF(TRIM(m.cnae_codigo), ''), 'SEM_CNAE'), {$statusExpr}
            ORDER BY {$this->orderBy($query)}
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $query->limit]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string) ($row['cnae_status'] ?? CnaeStatusResolver::UNKNOWN);
            $rows[] = [
                'cnae_codigo' => (string) ($row['cnae_codigo'] ?? 'SEM_CNAE'),
                'cnae_descricao' => (string) ($row['cnae_descricao'] ?? 'CNAE nao informado'),
                'segmento_macro' => (string) ($row['segmento_macro'] ?? 'Nao classificado'),
                'cnae_status' => $status,
                'cnae_status_label' => CnaeStatusResolver::label($status),
                'customers' => (int) ($row['customers'] ?? 0),
                'invoices' => (int) ($row['invoices'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
                'last_purchase' => $row['last_purchase'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function companies(CnaeQuery $query): array
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            return [];
        }

        [$whereSql, $params] = $this->baseWhere($query);
        [$join, $descExpr] = $this->cnaeDimJoinAndDescription('m');
        $statusExpr = $this->statusExpr('m');
        $documentExpr = $this->documentExpr('m');
        $documentTypeExpr = $this->documentTypeExpr('m');
        $customerExpr = $this->customerExpr('m');

        $stmt = $this->pdo->prepare("
            SELECT
                MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                {$customerExpr} AS customer_key,
                COALESCE(NULLIF(MAX(m.cliente_nome), ''), 'Sem nome') AS cliente_nome,
                {$documentExpr} AS documento,
                {$documentTypeExpr} AS document_type,
                COALESCE(NULLIF(TRIM(m.cnae_codigo), ''), 'SEM_CNAE') AS cnae_codigo,
                {$descExpr} AS cnae_descricao,
                COALESCE(NULLIF(MAX(TRIM(m.segmento_macro)), ''), 'Nao classificado') AS segmento_macro,
                {$statusExpr} AS cnae_status,
                MAX(m.cidade_norm) AS cidade_key,
                MAX(m.bairro_norm) AS bairro_key,
                MAX(m.uf) AS uf,
                SUM(m.qtd_notas) AS invoices,
                SUM(m.faturamento) AS revenue,
                MAX(m.ultima_compra) AS last_purchase
            FROM mart_vendas_por_cnae_cnpj m
            {$join}
            WHERE {$whereSql}
            GROUP BY {$customerExpr}, {$documentExpr}, {$documentTypeExpr}, COALESCE(NULLIF(TRIM(m.cnae_codigo), ''), 'SEM_CNAE'), {$statusExpr}
            ORDER BY {$this->orderBy($query)}
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $query->limit]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string) ($row['cnae_status'] ?? CnaeStatusResolver::UNKNOWN);
            $rows[] = [
                'codigo_cliente_omie' => (string) ($row['codigo_cliente_omie'] ?? ''),
                'customer_key' => (string) ($row['customer_key'] ?? ''),
                'cliente_nome' => (string) ($row['cliente_nome'] ?? 'Sem nome'),
                'documento' => (string) ($row['documento'] ?? ''),
                'document_type' => (string) ($row['document_type'] ?? 'unknown'),
                'buyer_kind' => (string) ($row['document_type'] ?? '') === 'cnpj' ? 'b2b' : ((string) ($row['document_type'] ?? '') === 'cpf' ? 'cpf' : 'unknown'),
                'is_b2b' => (string) ($row['document_type'] ?? '') === 'cnpj',
                'cnae_codigo' => (string) ($row['cnae_codigo'] ?? 'SEM_CNAE'),
                'cnae_descricao' => (string) ($row['cnae_descricao'] ?? 'CNAE nao informado'),
                'segmento_macro' => (string) ($row['segmento_macro'] ?? 'Nao classificado'),
                'cnae_status' => $status,
                'cnae_status_label' => CnaeStatusResolver::label($status),
                'cidade_key' => (string) ($row['cidade_key'] ?? ''),
                'bairro_key' => (string) ($row['bairro_key'] ?? ''),
                'uf' => (string) ($row['uf'] ?? ''),
                'invoices' => (int) ($row['invoices'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
                'last_purchase' => $row['last_purchase'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function opportunities(CnaeQuery $query): array
    {
        $request = new CnaeQuery(
            dateFrom: $query->dateFrom,
            dateTo: $query->dateTo,
            startComp: $query->startComp,
            endComp: $query->endComp,
            city: $query->city,
            neighborhood: $query->neighborhood,
            cnae: null,
            sellerId: $query->sellerId,
            documentType: 'cnpj',
            status: CnaeStatusResolver::MISSING_CNPJ,
            limit: $query->limit,
            sort: $query->sort,
            direction: $query->direction,
            mode: $query->mode,
            received: $query->received,
            normalized: $query->normalized,
        );

        return $this->companies($request);
    }

    /** @return list<array<string,mixed>> */
    public function geoDistribution(CnaeQuery $query): array
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            return [];
        }

        [$whereSql, $params] = $this->baseWhere($query);
        $customerExpr = $this->customerExpr('m');

        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(NULLIF(MAX(m.cidade_norm), ''), 'sem_cidade') AS cidade_key,
                COALESCE(NULLIF(MAX(m.bairro_norm), ''), 'sem_bairro') AS bairro_key,
                COUNT(DISTINCT {$customerExpr}) AS customers,
                SUM(m.qtd_notas) AS invoices,
                SUM(m.faturamento) AS revenue
            FROM mart_vendas_por_cnae_cnpj m
            WHERE {$whereSql}
            GROUP BY COALESCE(NULLIF(m.cidade_norm, ''), 'sem_cidade'), COALESCE(NULLIF(m.bairro_norm, ''), 'sem_bairro')
            ORDER BY revenue DESC
            LIMIT :limit
        ");
        $this->execute($stmt, $params, [':limit' => $query->limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    public function freshness(): array
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            return ['available' => false, 'table' => 'mart_vendas_por_cnae_cnpj'];
        }

        $stmt = $this->pdo->prepare('SELECT MAX(refreshed_at) AS refreshed_at, COUNT(*) AS rows_total FROM mart_vendas_por_cnae_cnpj');
        $this->execute($stmt, []);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'available' => true,
            'table' => 'mart_vendas_por_cnae_cnpj',
            'refreshed_at' => $row['refreshed_at'] ?? null,
            'rows_total' => (int) ($row['rows_total'] ?? 0),
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function baseWhere(CnaeQuery $query, bool $applyCnae = true, bool $applyStatus = true): array
    {
        $where = ['m.competencia BETWEEN :start_comp AND :end_comp'];
        $params = [':start_comp' => $query->startComp, ':end_comp' => $query->endComp];

        if ($query->city !== null && $query->city !== '') {
            $cityExpr = $this->columnExists('mart_vendas_por_cnae_cnpj', 'cidade_key')
                ? "LOWER(TRIM(COALESCE(NULLIF(m.cidade_key, ''), m.cidade_norm)))"
                : 'LOWER(TRIM(m.cidade_norm))';
            $where[] = "{$cityExpr} = :city";
            $params[':city'] = strtolower(trim($query->city));
        }

        if ($query->neighborhood !== null && $query->neighborhood !== '') {
            $bairroExpr = $this->columnExists('mart_vendas_por_cnae_cnpj', 'bairro_key')
                ? "LOWER(TRIM(COALESCE(NULLIF(m.bairro_key, ''), m.bairro_norm)))"
                : 'LOWER(TRIM(m.bairro_norm))';
            $where[] = "{$bairroExpr} = :neighborhood";
            $params[':neighborhood'] = strtolower(trim($query->neighborhood));
        }

        if ($query->sellerId !== null && $query->sellerId !== '' && $this->columnExists('mart_vendas_por_cnae_cnpj', 'nome_vendedor')) {
            $where[] = 'LOWER(m.nome_vendedor) LIKE :seller_id';
            $params[':seller_id'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
        }

        if ($applyCnae && $query->cnae !== null && $query->cnae !== '') {
            $cnaeNorm = preg_replace('/\D+/', '', $query->cnae) ?? '';
            $parts = ['LOWER(COALESCE(m.cnae_descricao, \'\')) LIKE :cnae_like'];
            $params[':cnae_like'] = '%' . mb_strtolower($query->cnae) . '%';
            if (strtoupper($query->cnae) === 'SEM_CNAE') {
                $parts[] = "m.cnae_codigo IS NULL OR TRIM(m.cnae_codigo) = '' OR m.cnae_codigo = 'SEM_CNAE'";
            } elseif ($cnaeNorm !== '') {
                $parts[] = "REGEXP_REPLACE(COALESCE(m.cnae_codigo, ''), '[^0-9]', '') = :cnae_norm";
                $params[':cnae_norm'] = $cnaeNorm;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        if ($query->documentType !== 'all') {
            $docTypeExpr = $this->documentTypeExpr('m');
            $where[] = "({$docTypeExpr}) COLLATE utf8mb4_unicode_ci = :document_type";
            $params[':document_type'] = $query->documentType;
        }

        if ($applyStatus && $query->status !== null) {
            $where[] = '(' . $this->statusExpr('m') . ') COLLATE utf8mb4_unicode_ci = :cnae_status';
            $params[':cnae_status'] = $query->status;
        }

        return [implode(' AND ', $where), $params];
    }

    private function documentExpr(string $alias): string
    {
        if ($this->columnExists('mart_vendas_por_cnae_cnpj', 'documento_normalizado')) {
            return "REGEXP_REPLACE(COALESCE({$alias}.documento_normalizado, ''), '[^0-9]', '')";
        }

        return "''";
    }

    private function cnaeDigitsExpr(string $alias): string
    {
        return "REGEXP_REPLACE(COALESCE(NULLIF({$alias}.cnae_codigo, 'SEM_CNAE'), ''), '[^0-9]', '')";
    }

    private function documentTypeExpr(string $alias): string
    {
        $doc = $this->documentExpr($alias);
        return "CASE
            WHEN CHAR_LENGTH({$doc}) = 14 THEN 'cnpj'
            WHEN CHAR_LENGTH({$doc}) = 11 THEN 'cpf'
            WHEN CHAR_LENGTH({$doc}) = 0 THEN 'unknown'
            ELSE 'invalid'
        END";
    }

    private function statusExpr(string $alias): string
    {
        $doc = $this->documentExpr($alias);
        $cnae = $this->cnaeDigitsExpr($alias);
        return "CASE
            WHEN CHAR_LENGTH({$doc}) = 14 AND {$cnae} REGEXP '^[0-9]{7}$' THEN 'ok'
            WHEN CHAR_LENGTH({$doc}) = 14 THEN 'missing_cnpj'
            WHEN CHAR_LENGTH({$doc}) = 11 THEN 'not_applicable_cpf'
            WHEN CHAR_LENGTH({$doc}) = 0 THEN 'unknown'
            ELSE 'invalid_document'
        END";
    }

    private function customerExpr(string $alias): string
    {
        return "COALESCE(CAST({$alias}.codigo_cliente_omie AS CHAR), {$this->documentExpr($alias)}, {$alias}.cliente_nome)";
    }

    /** @return array{0:string,1:string} */
    private function cnaeDimJoinAndDescription(string $alias): array
    {
        $hasDim = $this->tableExists('biomie_cnae_dim')
            && $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado');
        $join = $hasDim
            ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE({$alias}.cnae_codigo, ''), '[^0-9]', '')"
            : '';
        $description = $hasDim
            ? "COALESCE(NULLIF(MAX(cdim.cnae_descricao), ''), NULLIF(MAX({$alias}.cnae_descricao), ''), 'CNAE nao informado')"
            : "COALESCE(NULLIF(MAX({$alias}.cnae_descricao), ''), 'CNAE nao informado')";

        return [$join, $description];
    }

    private function orderBy(CnaeQuery $query): string
    {
        $map = [
            'revenue' => 'revenue',
            'customers' => 'customers',
            'invoices' => 'invoices',
            'last_purchase' => 'last_purchase',
        ];
        $column = $map[$query->sort] ?? 'revenue';
        $direction = $query->direction === 'asc' ? 'ASC' : 'DESC';
        return "{$column} {$direction}";
    }

    /**
     * @param array<int|string,mixed> $params
     * @param array<int|string,mixed> $extra
     */
    private function execute(\PDOStatement $stmt, array $params, array $extra = []): void
    {
        $started = microtime(true);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value);
        }
        foreach ($extra as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $this->queryCount++;
        $this->sqlTimeMs += (microtime(true) - $started) * 1000;
    }

    private function tableExists(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $this->execute($stmt, [$table]);
            return $this->tableCache[$table] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $this->execute($stmt, [$table, $column]);
            return $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return $this->columnCache[$key] = false;
        }
    }

    /** @return array<string,mixed> */
    private function emptyCoverage(): array
    {
        return [
            'total_customers' => 0,
            'total_invoices' => 0,
            'total_revenue' => 0.0,
            'cnpj_customers' => 0,
            'cpf_customers' => 0,
            'cnpj_with_cnae' => 0,
            'cnpj_missing_cnae' => 0,
            'cpf_not_applicable' => 0,
            'invalid_document_customers' => 0,
            'unknown_document_customers' => 0,
            'cnpj_coverage_pct' => 0.0,
            'general_coverage_pct' => 0.0,
            'by_status' => [],
            'impact' => ['missing_cnpj_revenue' => 0.0, 'missing_cnpj_customers' => 0, 'missing_cnpj_invoices' => 0],
        ];
    }

    private function pct(int $num, int $den): float
    {
        return $den > 0 ? round(($num / $den) * 100, 2) : 0.0;
    }
}
