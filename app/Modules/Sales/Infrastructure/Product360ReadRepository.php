<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\ProductTemporalBucketResolver;
use PDO;
use Throwable;

/**
 * Consultas locais para o Product 360.
 * Não chama Omie — lê apenas dados canonicos e gold já sincronizados.
 * Fonte de realizado: biomie_notas_fiscais_itens_canonicos + biomie_notas_fiscais_canonicas.
 * Filtro temporal: n.data_competencia (nunca data_emissao para realizado fiscal).
 */
final class Product360ReadRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Resumo agregado do produto no período.
     * Usa TRIM no código e COALESCE(data_competencia, data_emissao) para datas.
     *
     * @return array<string,mixed>|null
     */
    public function summary(string $productCode, string $start, string $end): ?array
    {
        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    i.codigo_produto                                                     AS product_code,
                    COALESCE(NULLIF(TRIM(MAX(i.descricao_produto)), ''), '')             AS product_description,
                    SUM(i.quantidade)                                                   AS quantity_total,
                    SUM(i.valor_total)                                                  AS realized_total,
                    SUM(i.valor_desconto)                                               AS discount_total,
                    COALESCE(SUM(i.valor_mercadorias), SUM(i.valor_total))              AS merchandise_total,
                    COUNT(DISTINCT n.nota_key)                                          AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                               AS customers_count,
                    COUNT(DISTINCT n.codigo_vendedor_omie)                              AS vendors_count,
                    MIN(COALESCE(n.data_competencia, n.data_emissao))                   AS first_sale_at,
                    MAX(COALESCE(n.data_competencia, n.data_emissao))                   AS last_sale_at,
                    SUM(CASE WHEN i.valor_mercadorias IS NULL THEN 1 ELSE 0 END)        AS items_without_merchandise
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$nfScope}
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
                GROUP BY i.codigo_produto
            SQL);
            $stmt->bindValue('product_code', trim($productCode));
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * NFs autorizadas que contêm o produto.
     *
     * @return list<array<string,mixed>>
     */
    public function invoices(string $productCode, string $start, string $end, int $limit = 100): array
    {
        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    n.nota_key,
                    n.chave_nfe,
                    n.numero_nf,
                    n.serie_nf,
                    n.data_competencia,
                    n.codigo_cliente_omie                                               AS customer_id,
                    n.nome_cliente                                                      AS customer_name,
                    n.documento_cliente                                                 AS customer_document,
                    n.codigo_vendedor_omie                                              AS vendor_id,
                    n.nome_vendedor                                                     AS vendor_name,
                    i.quantidade                                                        AS quantity,
                    i.valor_total                                                       AS product_total,
                    n.valor_total                                                       AS invoice_total,
                    n.codigo_pedido_origem                                              AS origin_order_code,
                    n.pedido_id_omie,
                    n.status_fiscal
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$nfScope}
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
                ORDER BY COALESCE(n.data_competencia, n.data_emissao) DESC, n.numero_nf DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('product_code', $productCode);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Clientes que compraram o produto no período.
     *
     * @return list<array<string,mixed>>
     */
    public function customers(string $productCode, string $start, string $end, int $limit = 50): array
    {
        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    n.codigo_cliente_omie                                           AS customer_id,
                    n.nome_cliente                                                  AS customer_name,
                    n.documento_cliente                                             AS customer_document,
                    SUM(i.valor_total)                                              AS revenue,
                    SUM(i.quantidade)                                               AS quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS invoices_count,
                    CASE WHEN COUNT(DISTINCT n.nota_key) > 0
                         THEN SUM(i.valor_total) / COUNT(DISTINCT n.nota_key)
                         ELSE 0 END                                                AS ticket_avg,
                    MIN(COALESCE(n.data_competencia, n.data_emissao))               AS first_purchase_at,
                    MAX(COALESCE(n.data_competencia, n.data_emissao))               AS last_purchase_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$nfScope}
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
                GROUP BY n.codigo_cliente_omie, n.nome_cliente, n.documento_cliente
                ORDER BY revenue DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('product_code', $productCode);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Vendedores que venderam o produto no período.
     *
     * @return list<array<string,mixed>>
     */
    public function vendors(string $productCode, string $start, string $end, int $limit = 50): array
    {
        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    n.codigo_vendedor_omie                                          AS vendor_id,
                    n.nome_vendedor                                                 AS vendor_name,
                    SUM(i.valor_total)                                              AS revenue,
                    SUM(i.quantidade)                                               AS quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                           AS customers_count,
                    MIN(COALESCE(n.data_competencia, n.data_emissao))               AS first_sale_at,
                    MAX(COALESCE(n.data_competencia, n.data_emissao))               AS last_sale_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$nfScope}
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
                GROUP BY n.codigo_vendedor_omie, n.nome_vendedor
                ORDER BY revenue DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('product_code', $productCode);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pedidos vinculados via bridge pedido → NF onde o produto apareceu.
     * Pedidos vinculados ≠ carteira. São pedidos que já geraram NFs autorizadas.
     *
     * @return list<array<string,mixed>>
     */
    public function linkedOrders(string $productCode, string $start, string $end, int $limit = 50): array
    {
        try {
            $nfScope = $this->nfScope('n');
            $bridgeScope = \BIomie\Support\TenantContext::sqlFilter('b');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT DISTINCT
                    b.codigo_pedido                                                 AS order_code,
                    b.pedido_id_omie,
                    b.nota_key,
                    b.codigo_cliente_omie                                           AS customer_id,
                    b.nome_cliente                                                  AS customer_name,
                    b.codigo_vendedor_omie                                          AS vendor_id,
                    b.nome_vendedor                                                 AS vendor_name,
                    b.data_emissao,
                    b.data_faturamento,
                    b.data_competencia_nfe,
                    b.valor_pedido,
                    b.valor_nfe,
                    b.bridge_status
                FROM mart_bridge_pedido_nfe_titulo b
                INNER JOIN biomie_notas_fiscais_itens_canonicos i
                   ON i.company_id = b.company_id
                  AND i.nota_key = b.nota_key
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$bridgeScope}
                  AND {$nfScope}
                  AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
                ORDER BY COALESCE(b.data_competencia_nfe, b.data_faturamento, b.data_emissao) DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('product_code', $productCode);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Evolução temporal com bucket adaptativo:
     *   ≤ 45 dias → diário | 46–180 dias → semanal | > 180 dias → mensal
     *
     * Usa COALESCE(data_competencia, data_emissao) para não perder NFs sem competência.
     * Buckets sem venda preenchidos com zero para o gráfico não quebrar.
     *
     * @return array{bucket_mode:string,bucket_title:string,rows:list<array<string,mixed>>}
     */
    public function timeline(string $productCode, string $start, string $end, ?string $granularity = null): array
    {
        $mode       = ProductTemporalBucketResolver::resolveMode($start, $end, $granularity);
        $dateExpr   = 'COALESCE(n.data_competencia, n.data_emissao)';
        $bucketExpr = ProductTemporalBucketResolver::bucketSql('n', $mode);

        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(
                "SELECT
                    {$bucketExpr}                                                    AS bucket,
                    SUM(i.valor_total)                                              AS revenue,
                    SUM(i.quantidade)                                               AS quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                           AS customers_count
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas n
                    ON n.company_id = i.company_id
                   AND n.nota_key = i.nota_key
                 WHERE n.status_fiscal = 'autorizada'
                   AND {$nfScope}
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND TRIM(i.codigo_produto) = TRIM(:product_code)
                 GROUP BY {$bucketExpr}
                 ORDER BY bucket ASC"
            );
            $stmt->bindValue(':product_code', trim($productCode));
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        // Add revenue_raw and label to each row
        $rows = array_map(static function (array $r): array {
            $r['revenue_raw'] = (float) ($r['revenue'] ?? 0);
            $r['label']       = (string) ($r['bucket'] ?? '');
            return $r;
        }, $rows);

        // Fill empty buckets and merge
        $empty  = ProductTemporalBucketResolver::generateEmptyBuckets($start, $end, $mode);
        $filled = ProductTemporalBucketResolver::mergeIntoBuckets($empty, $rows, $mode);

        return [
            'bucket_mode'  => $mode,
            'bucket_title' => ProductTemporalBucketResolver::bucketTitle($mode),
            'rows'         => $filled,
        ];
    }

    /**
     * LTV observado: histórico completo do produto em todo o sync local.
     *
     * @return array<string,mixed>
     */
    public function ltv(string $productCode): array
    {
        try {
            $nfScope = $this->nfScope('n');
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    SUM(i.valor_total)                                              AS lifetime_revenue,
                    SUM(i.quantidade)                                               AS lifetime_quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS lifetime_invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                           AS lifetime_customers_count,
                    MIN(n.data_competencia)                                         AS lifetime_first_sale_at,
                    MAX(n.data_competencia)                                         AS lifetime_last_sale_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                   ON n.company_id = i.company_id
                  AND n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND {$nfScope}
                  AND TRIM(i.codigo_produto) = TRIM(:product_code)
            SQL);
            $stmt->bindValue('product_code', trim($productCode));
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return [];
            }

            // Repeat customers: clients with >= 2 distinct NFs for this product
            $repeatStmt = $this->pdo->prepare(<<<SQL
                SELECT COUNT(*) AS repeat_customers_count
                FROM (
                    SELECT n.codigo_cliente_omie
                    FROM biomie_notas_fiscais_itens_canonicos i
                    INNER JOIN biomie_notas_fiscais_canonicas n
                       ON n.company_id = i.company_id
                      AND n.nota_key = i.nota_key
                    WHERE n.status_fiscal = 'autorizada'
                      AND {$nfScope}
                      AND TRIM(i.codigo_produto) = TRIM(:product_code)
                    GROUP BY n.codigo_cliente_omie
                    HAVING COUNT(DISTINCT n.nota_key) >= 2
                ) AS repeat_sub
            SQL);
            $repeatStmt->bindValue('product_code', trim($productCode));
            $repeatStmt->execute();
            $repeatRow = $repeatStmt->fetch(PDO::FETCH_ASSOC);

            $row['repeat_customers_count'] = (int) ($repeatRow['repeat_customers_count'] ?? 0);
            return $row;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Contagem de linhas em mart_produto_periodo para o produto.
     */
    public function martRowCount(string $productCode): int
    {
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM mart_produto_periodo
                  WHERE {$tenantFilter}
                    AND TRIM(codigo_produto) = TRIM(:product_code)"
            );
            $stmt->bindValue('product_code', trim($productCode));
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Last canonical NF data_competencia for freshness estimation.
     */
    public function lastCanonicalNfAt(): ?string
    {
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT MAX(updated_at) FROM biomie_notas_fiscais_canonicas WHERE {$tenantFilter} AND status_fiscal = 'autorizada'"
            );
            $val = $stmt ? $stmt->fetchColumn() : null;
            return ($val && $val !== '0000-00-00 00:00:00') ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Last gold mart materialization for mart_produto_periodo.
     */
    public function lastGoldMaterializedAt(): ?string
    {
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT MAX(refreshed_at) FROM mart_produto_periodo WHERE {$tenantFilter}"
            );
            $val = $stmt ? $stmt->fetchColumn() : null;
            return ($val && $val !== '0000-00-00 00:00:00') ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ── EST.R1 — Stock methods ────────────────────────────────────────────────

    /**
     * Saldo de estoque por local para um produto.
     *
     * @return array{available:bool,data:list<array<string,mixed>>,empty_state:string|null,total_atual:float,total_minimo:float,has_low_stock:bool}
     */
    public function stockByLocation(string $productCode): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_saldos')) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Estoque ainda não sincronizado. Execute EST.R1 sync.', 'total_atual' => 0.0, 'total_minimo' => 0.0, 'has_low_stock' => false];
        }
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(
                "SELECT codigo_produto, codigo_local, descricao_produto, nome_local,
                        estoque_atual, estoque_minimo, estoque_inicial, unidade, custo_medio, atualizado_em
                 FROM biomie_produtos_estoque_saldos
                 WHERE codigo_produto = :code
                   AND {$tenantFilter}
                 ORDER BY nome_local ASC"
            );
            $stmt->execute([':code' => $productCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Erro ao carregar estoque', 'total_atual' => 0.0, 'total_minimo' => 0.0, 'has_low_stock' => false];
        }

        if (empty($rows)) {
            return ['available' => true, 'data' => [], 'empty_state' => 'Produto sem saldo de estoque — sincronizar via Omie', 'total_atual' => 0.0, 'total_minimo' => 0.0, 'has_low_stock' => false];
        }

        $totalAtual  = (float) array_sum(array_column($rows, 'estoque_atual'));
        $totalMinimo = (float) array_sum(array_column($rows, 'estoque_minimo'));
        $hasLow      = !empty(array_filter($rows, fn($r) => (float)($r['estoque_minimo'] ?? 0) > 0 && (float)($r['estoque_atual'] ?? 0) <= (float)($r['estoque_minimo'] ?? 0)));

        $data = array_map(function (array $r): array {
            $atual  = (float)($r['estoque_atual']  ?? 0);
            $minimo = (float)($r['estoque_minimo'] ?? 0);
            return [
                'codigo_local'    => (string)($r['codigo_local']   ?? ''),
                'nome_local'      => (string)($r['nome_local']      ?? ''),
                'estoque_atual'   => round($atual, 4),
                'estoque_minimo'  => round($minimo, 4),
                'estoque_inicial' => round((float)($r['estoque_inicial'] ?? 0), 4),
                'unidade'         => (string)($r['unidade']         ?? ''),
                'custo_medio'     => round((float)($r['custo_medio'] ?? 0), 2),
                'atualizado_em'   => substr((string)($r['atualizado_em'] ?? ''), 0, 19),
                'status'          => $atual <= 0 ? 'sem_estoque' : ($minimo > 0 && $atual <= $minimo ? 'baixo' : 'ok'),
            ];
        }, $rows);

        return ['available' => true, 'data' => $data, 'empty_state' => null, 'total_atual' => round($totalAtual, 4), 'total_minimo' => round($totalMinimo, 4), 'has_low_stock' => $hasLow];
    }

    /**
     * Movimentos de estoque de um produto em um período (paginado).
     *
     * @return array{available:bool,data:list<array<string,mixed>>,empty_state:string|null}
     */
    public function stockMovements(string $productCode, string $start, string $end, int $limit = 50): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_movimentos')) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Histórico de movimentos não sincronizado — disponível após EST.R1 sync.'];
        }
        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(
                "SELECT data_movimento, codigo_local, tipo_movimento, quantidade,
                        valor, documento, nota_key, pedido_id_omie, cliente_fornecedor
                 FROM biomie_produtos_estoque_movimentos
                 WHERE codigo_produto = :code
                   AND {$tenantFilter}
                   AND data_movimento BETWEEN :start AND :end
                 ORDER BY data_movimento DESC, id DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':code',  $productCode);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Erro ao carregar movimentos'];
        }

        return ['available' => true, 'data' => $rows, 'empty_state' => empty($rows) ? 'Sem movimentos no período' : null];
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $st->execute([':t' => $table]);
            return ((int) $st->fetchColumn()) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function nfScope(string $alias): string
    {
        return \BIomie\Support\TenantContext::sqlFilter($alias)
            . " AND {$alias}.operation_scope = 'commercial'"
            . ' AND ' . BiVisibilityScope::getCondition(
                $this->pdo,
                'biomie_notas_fiscais_canonicas',
                $alias,
            );
    }
}
