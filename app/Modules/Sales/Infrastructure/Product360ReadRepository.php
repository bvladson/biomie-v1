<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

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
     *
     * @return array<string,mixed>|null
     */
    public function summary(string $productCode, string $start, string $end): ?array
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    i.codigo_produto                                                     AS product_code,
                    COALESCE(NULLIF(TRIM(MAX(i.descricao_produto)), ''), 'Produto sem descricao') AS product_description,
                    SUM(i.quantidade)                                                   AS quantity_total,
                    SUM(i.valor_total)                                                  AS realized_total,
                    SUM(i.valor_desconto)                                               AS discount_total,
                    COALESCE(SUM(i.valor_mercadorias), SUM(i.valor_total))              AS merchandise_total,
                    COUNT(DISTINCT n.nota_key)                                          AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                               AS customers_count,
                    COUNT(DISTINCT n.codigo_vendedor_omie)                              AS vendors_count,
                    MIN(n.data_competencia)                                             AS first_sale_at,
                    MAX(n.data_competencia)                                             AS last_sale_at,
                    SUM(CASE WHEN i.valor_mercadorias IS NULL THEN 1 ELSE 0 END)        AS items_without_merchandise
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end
                  AND i.codigo_produto = :product_code
                GROUP BY i.codigo_produto
            SQL);
            $stmt->bindValue('product_code', $productCode);
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
            $stmt = $this->pdo->prepare(<<<'SQL'
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
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end
                  AND i.codigo_produto = :product_code
                ORDER BY n.data_competencia DESC, n.numero_nf DESC
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
            $stmt = $this->pdo->prepare(<<<'SQL'
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
                    MIN(n.data_competencia)                                         AS first_purchase_at,
                    MAX(n.data_competencia)                                         AS last_purchase_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end
                  AND i.codigo_produto = :product_code
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    n.codigo_vendedor_omie                                          AS vendor_id,
                    n.nome_vendedor                                                 AS vendor_name,
                    SUM(i.valor_total)                                              AS revenue,
                    SUM(i.quantidade)                                               AS quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                           AS customers_count,
                    MIN(n.data_competencia)                                         AS first_sale_at,
                    MAX(n.data_competencia)                                         AS last_sale_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end
                  AND i.codigo_produto = :product_code
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
            $stmt = $this->pdo->prepare(<<<'SQL'
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
                INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key = b.nota_key
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.data_competencia BETWEEN :start AND :end
                  AND i.codigo_produto = :product_code
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
     * Evolução temporal: por dia se período ≤ 45 dias, por mês se > 45 dias.
     *
     * @return list<array<string,mixed>>
     */
    public function timeline(string $productCode, string $start, string $end): array
    {
        $days = (int) round((strtotime($end) - strtotime($start)) / 86400);
        $byDay = $days <= 45;

        try {
            if ($byDay) {
                $stmt = $this->pdo->prepare(<<<'SQL'
                    SELECT
                        n.data_competencia                                          AS bucket,
                        n.data_competencia                                          AS label,
                        SUM(i.valor_total)                                          AS revenue,
                        SUM(i.quantidade)                                           AS quantity,
                        COUNT(DISTINCT n.nota_key)                                  AS invoices_count,
                        COUNT(DISTINCT n.codigo_cliente_omie)                       AS customers_count
                    FROM biomie_notas_fiscais_itens_canonicos i
                    INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                    WHERE n.status_fiscal = 'autorizada'
                      AND n.data_competencia BETWEEN :start AND :end
                      AND i.codigo_produto = :product_code
                    GROUP BY n.data_competencia
                    ORDER BY n.data_competencia ASC
                SQL);
            } else {
                $stmt = $this->pdo->prepare(<<<'SQL'
                    SELECT
                        DATE_FORMAT(n.data_competencia, '%Y-%m')                    AS bucket,
                        DATE_FORMAT(n.data_competencia, '%Y-%m')                    AS label,
                        SUM(i.valor_total)                                          AS revenue,
                        SUM(i.quantidade)                                           AS quantity,
                        COUNT(DISTINCT n.nota_key)                                  AS invoices_count,
                        COUNT(DISTINCT n.codigo_cliente_omie)                       AS customers_count
                    FROM biomie_notas_fiscais_itens_canonicos i
                    INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                    WHERE n.status_fiscal = 'autorizada'
                      AND n.data_competencia BETWEEN :start AND :end
                      AND i.codigo_produto = :product_code
                    GROUP BY DATE_FORMAT(n.data_competencia, '%Y-%m')
                    ORDER BY bucket ASC
                SQL);
            }
            $stmt->bindValue('product_code', $productCode);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * LTV observado: histórico completo do produto em todo o sync local.
     *
     * @return array<string,mixed>
     */
    public function ltv(string $productCode): array
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    SUM(i.valor_total)                                              AS lifetime_revenue,
                    SUM(i.quantidade)                                               AS lifetime_quantity,
                    COUNT(DISTINCT n.nota_key)                                      AS lifetime_invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)                           AS lifetime_customers_count,
                    MIN(n.data_competencia)                                         AS lifetime_first_sale_at,
                    MAX(n.data_competencia)                                         AS lifetime_last_sale_at
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND i.codigo_produto = :product_code
            SQL);
            $stmt->bindValue('product_code', $productCode);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return [];
            }

            // Repeat customers: clients with >= 2 distinct NFs for this product
            $repeatStmt = $this->pdo->prepare(<<<'SQL'
                SELECT COUNT(*) AS repeat_customers_count
                FROM (
                    SELECT n.codigo_cliente_omie
                    FROM biomie_notas_fiscais_itens_canonicos i
                    INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                    WHERE n.status_fiscal = 'autorizada'
                      AND i.codigo_produto = :product_code
                    GROUP BY n.codigo_cliente_omie
                    HAVING COUNT(DISTINCT n.nota_key) >= 2
                ) AS repeat_sub
            SQL);
            $repeatStmt->bindValue('product_code', $productCode);
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
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM mart_produto_periodo WHERE codigo_produto = :product_code'
            );
            $stmt->bindValue('product_code', $productCode);
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
            $stmt = $this->pdo->query(
                "SELECT MAX(updated_at) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal = 'autorizada'"
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
            $stmt = $this->pdo->query('SELECT MAX(refreshed_at) FROM mart_produto_periodo');
            $val = $stmt ? $stmt->fetchColumn() : null;
            return ($val && $val !== '0000-00-00 00:00:00') ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }
}
