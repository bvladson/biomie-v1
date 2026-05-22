<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use BIomie\Http;
use PDO;
use Throwable;

/**
 * Reads product mix data from canonical NF + item tables.
 *
 * Summary is split into two separate queries to avoid the LEFT JOIN inflation
 * bug: SUM(n.valor_total) via a LEFT JOIN on items multiplies each NF's total
 * by the number of items on that NF, producing a grossly inflated revenue figure.
 *
 * All queries filter by operation_scope = 'commercial' when the column exists,
 * so remittance and unknown NFs never contaminate the Mix Faturado.
 */
final class ProductMixReadRepository
{
    private ?bool $hasScope = null;

    public function __construct(private readonly PDO $pdo) {}

    public function isAvailable(): bool
    {
        return Http::tableExists($this->pdo, 'biomie_notas_fiscais_canonicas')
            && Http::tableExists($this->pdo, 'biomie_notas_fiscais_itens_canonicos');
    }

    /**
     * Returns true when operation_scope column is present on the canonical NF table.
     * Result is cached for the lifetime of the object (one HTTP request).
     */
    private function hasOperationScope(): bool
    {
        if ($this->hasScope === null) {
            try {
                $this->hasScope = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'biomie_notas_fiscais_canonicas'
                       AND COLUMN_NAME  = 'operation_scope'"
                )->fetchColumn() > 0;
            } catch (Throwable) {
                $this->hasScope = false;
            }
        }
        return $this->hasScope;
    }

    /** Scope fragment for queries that alias the NF table as `n`. */
    private function nScopeFilter(): string
    {
        return $this->hasOperationScope() ? "\n              AND n.operation_scope = 'commercial'" : '';
    }

    /** Scope fragment for queries with no alias (bare table name). */
    private function bareScope(): string
    {
        return $this->hasOperationScope() ? "\n              AND operation_scope = 'commercial'" : '';
    }

    /** @return array<string,mixed> */
    public function summary(string $start, string $end): array
    {
        $bareScope = $this->bareScope();
        $nScope    = $this->nScopeFilter();

        // ── NF-level aggregates (no join — avoids multiplication by item count) ──
        $nfSql = <<<SQL
            SELECT
              COUNT(*)                                          AS invoices_count,
              COALESCE(SUM(valor_total),       0)              AS revenue_total,
              COALESCE(SUM(valor_mercadorias), 0)              AS merchandise_total,
              COALESCE(SUM(valor_descontos),   0)              AS discount_total,
              COUNT(DISTINCT codigo_vendedor_omie)             AS vendors_count
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal  = 'autorizada'
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end{$bareScope}
        SQL;
        $nfStmt = $this->pdo->prepare($nfSql);
        $nfStmt->execute(['start' => $start, 'end' => $end]);
        $nfRow = $nfStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // ── Item-level aggregates (join safe — only item fields are aggregated) ──
        $itemSql = <<<SQL
            SELECT
              COUNT(i.id)                                      AS items_count,
              COUNT(DISTINCT i.codigo_produto)                 AS products_count,
              COALESCE(SUM(i.valor_total),     0)             AS mix_total
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE n.status_fiscal = 'autorizada'
              AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end{$nScope}
        SQL;
        $itemStmt = $this->pdo->prepare($itemSql);
        $itemStmt->execute(['start' => $start, 'end' => $end]);
        $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_merge($nfRow, $itemRow);
    }

    /** @return list<array<string,mixed>> */
    public function products(string $start, string $end, int $limit = 100): array
    {
        $nScope = $this->nScopeFilter();
        $sql = <<<SQL
            SELECT
              NULLIF(TRIM(COALESCE(i.codigo_produto, '')), '') AS product_code,
              COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao') AS description,
              COALESCE(SUM(i.quantidade),    0)               AS quantity,
              COALESCE(SUM(i.valor_total),   0)               AS revenue,
              COALESCE(SUM(i.valor_desconto),0)               AS discount_total,
              COUNT(DISTINCT n.nota_key)                      AS orders,
              COUNT(DISTINCT n.nota_key)                      AS invoices_count,
              COUNT(DISTINCT n.codigo_cliente_omie)           AS customers_count,
              COUNT(DISTINCT n.codigo_vendedor_omie)          AS vendors_count,
              MIN(COALESCE(n.data_competencia, n.data_emissao)) AS first_sale_at,
              MAX(COALESCE(n.data_competencia, n.data_emissao)) AS last_sale_at
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE n.status_fiscal = 'autorizada'
              AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end{$nScope}
            GROUP BY product_code, description
            ORDER BY revenue DESC, quantity DESC, description ASC
            LIMIT :limit
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function breakdown(string $start, string $end, string $productCode, int $limit = 200): array
    {
        if ($productCode === '') {
            return [];
        }
        $nScope = $this->nScopeFilter();
        $sql = <<<SQL
            SELECT
              n.nota_key,
              n.chave_nfe,
              n.numero_nf,
              n.serie_nf,
              n.codigo_pedido_origem       AS origin_order_code,
              n.pedido_id_omie,
              COALESCE(n.data_competencia, n.data_emissao) AS data_competencia,
              n.codigo_cliente_omie        AS customer_id,
              n.nome_cliente               AS customer_name,
              n.documento_cliente          AS customer_document,
              n.codigo_vendedor_omie       AS vendor_id,
              n.nome_vendedor              AS vendor_name,
              i.codigo_produto             AS product_code,
              i.descricao_produto          AS description,
              i.quantidade,
              i.valor_total                AS revenue,
              i.valor_desconto             AS discount_total
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE n.status_fiscal = 'autorizada'
              AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
              AND i.codigo_produto = :product_code{$nScope}
            ORDER BY COALESCE(n.data_competencia, n.data_emissao) DESC, n.numero_nf DESC
            LIMIT :limit
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('product_code', $productCode);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
