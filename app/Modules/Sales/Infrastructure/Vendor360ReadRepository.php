<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use BIomie\Support;
use PDO;
use Throwable;

/**
 * Consultas locais para o drawer Vendor 360.
 * Não chama Omie — lê apenas mart_bridge_pedido_nfe_titulo e biomie_notas_fiscais_*
 */
final class Vendor360ReadRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Pedidos que geraram NFs, via bridge pedido → NF.
     * bridge_status != 'pedido_sem_nf' AND nota_key IS NOT NULL
     *
     * @return list<array<string,mixed>>
     */
    public function linkedOrdersByVendor(string $start, string $end, string $vendorId, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    b.codigo_pedido,
                    b.pedido_id_omie,
                    b.codigo_vendedor_omie,
                    b.nome_vendedor,
                    b.codigo_cliente_omie,
                    b.nome_cliente,
                    b.documento_cliente,
                    b.status_comercial,
                    b.data_emissao,
                    b.data_faturamento,
                    b.valor_pedido,
                    b.valor_mercadorias_pedido,
                    b.nota_key,
                    b.status_fiscal,
                    b.data_competencia_nfe,
                    b.valor_nfe,
                    b.titulo_key,
                    b.status_titulo,
                    b.valor_aberto,
                    b.valor_recebido,
                    b.bridge_status
                FROM mart_bridge_pedido_nfe_titulo b
                WHERE b.codigo_vendedor_omie = :vendor_id
                  AND b.bridge_status NOT IN ('pedido_sem_nf', 'cancelado')
                  AND b.nota_key IS NOT NULL
                  AND (
                      b.data_competencia_nfe BETWEEN :start AND :end
                      OR b.data_faturamento   BETWEEN :start AND :end
                      OR b.data_emissao       BETWEEN :start AND :end
                  )
                ORDER BY COALESCE(b.data_competencia_nfe, b.data_faturamento, b.data_emissao) DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('vendor_id', $vendorId);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();

            return array_map($this->mapLinkedOrderRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pedidos autorizados sem NF vinculada (carteira).
     * bridge_status = 'pedido_sem_nf' AND status_comercial = 'autorizado'
     *
     * @return list<array<string,mixed>>
     */
    public function walletOrdersByVendor(string $start, string $end, string $vendorId, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    b.codigo_pedido,
                    b.pedido_id_omie,
                    b.codigo_vendedor_omie,
                    b.nome_vendedor,
                    b.codigo_cliente_omie,
                    b.nome_cliente,
                    b.documento_cliente,
                    b.status_comercial,
                    b.data_emissao,
                    b.data_faturamento,
                    b.valor_pedido,
                    b.valor_mercadorias_pedido,
                    b.bridge_status
                FROM mart_bridge_pedido_nfe_titulo b
                WHERE b.codigo_vendedor_omie = :vendor_id
                  AND b.status_comercial = 'autorizado'
                  AND b.bridge_status = 'pedido_sem_nf'
                  AND b.data_emissao BETWEEN :start AND :end
                ORDER BY b.data_emissao DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('vendor_id', $vendorId);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();

            return array_map($this->mapWalletOrderRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Top produtos do vendedor a partir das NFs autorizadas.
     *
     * @return list<array<string,mixed>>
     */
    public function topProductsByVendorCommercial(string $start, string $end, string $vendorId, int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    i.codigo_produto AS product_code,
                    COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao') AS description,
                    SUM(i.quantidade)                      AS quantity,
                    SUM(i.valor_total)                     AS revenue,
                    SUM(COALESCE(i.valor_desconto, 0))     AS discount,
                    COUNT(DISTINCT n.nota_key)             AS invoices_count,
                    COUNT(DISTINCT n.codigo_cliente_omie)  AS customers_count,
                    MIN(n.data_competencia)                AS first_sale,
                    MAX(n.data_competencia)                AS last_sale
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                WHERE n.status_fiscal = 'autorizada'
                  AND n.codigo_vendedor_omie = :vendor_id
                  AND n.data_competencia BETWEEN :start AND :end
                GROUP BY i.codigo_produto, description
                ORDER BY revenue DESC, quantity DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue('vendor_id', $vendorId);
            $stmt->bindValue('start', $start);
            $stmt->bindValue('end', $end);
            $stmt->bindValue('limit', max($limit, 1), PDO::PARAM_INT);
            $stmt->execute();

            return array_map($this->mapProductRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Verifica se mart_bridge_pedido_nfe_titulo tem registros para o período,
     * usando as mesmas colunas de data da query principal (linkedOrdersByVendor).
     *
     * A condição OR é necessária porque pedido e NF podem estar em competências
     * diferentes: data_emissao do pedido pode ser abril, data_competencia_nfe maio.
     * Verificar só data_emissao causaria false-negative e forçaria o NF fallback
     * mesmo quando a bridge está populada.
     */
    public function isBridgeAvailable(string $start, string $end): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM mart_bridge_pedido_nfe_titulo
                 WHERE (
                     data_competencia_nfe BETWEEN :start AND :end
                     OR data_faturamento   BETWEEN :start AND :end
                     OR data_emissao       BETWEEN :start AND :end
                 )
                 LIMIT 1'
            );
            $stmt->execute(['start' => $start, 'end' => $end]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function mapLinkedOrderRow(array $row): array
    {
        return [
            'order_code'        => (string) ($row['codigo_pedido'] ?? ''),
            'pedido_id_omie'    => (string) ($row['pedido_id_omie'] ?? ''),
            'client_id'         => (string) ($row['codigo_cliente_omie'] ?? ''),
            'client_name'       => (string) ($row['nome_cliente'] ?? ''),
            'client_document'   => (string) ($row['documento_cliente'] ?? ''),
            'order_value'       => Support::money((float) ($row['valor_pedido'] ?? 0)),
            'merchandise_value' => Support::money((float) ($row['valor_mercadorias_pedido'] ?? 0)),
            'invoice_key'       => (string) ($row['nota_key'] ?? ''),
            'invoice_value'     => Support::money((float) ($row['valor_nfe'] ?? 0)),
            'bridge_status'     => (string) ($row['bridge_status'] ?? ''),
            'status_comercial'  => (string) ($row['status_comercial'] ?? ''),
            'status_fiscal'     => (string) ($row['status_fiscal'] ?? ''),
            'issue_date'        => (string) ($row['data_emissao'] ?? ''),
            'billing_date'      => (string) ($row['data_faturamento'] ?? ''),
            'invoice_date'      => (string) ($row['data_competencia_nfe'] ?? ''),
            // aliases para o front
            'codigo'            => (string) ($row['codigo_pedido'] ?? ''),
            'cliente'           => (string) ($row['nome_cliente'] ?? ''),
            'total'             => Support::money((float) ($row['valor_nfe'] ?? $row['valor_pedido'] ?? 0)),
            'gross_total'       => Support::money((float) ($row['valor_nfe'] ?? $row['valor_pedido'] ?? 0)),
        ];
    }

    private function mapWalletOrderRow(array $row): array
    {
        return [
            'order_code'        => (string) ($row['codigo_pedido'] ?? ''),
            'pedido_id_omie'    => (string) ($row['pedido_id_omie'] ?? ''),
            'client_id'         => (string) ($row['codigo_cliente_omie'] ?? ''),
            'client_name'       => (string) ($row['nome_cliente'] ?? ''),
            'client_document'   => (string) ($row['documento_cliente'] ?? ''),
            'order_value'       => Support::money((float) ($row['valor_pedido'] ?? 0)),
            'merchandise_value' => Support::money((float) ($row['valor_mercadorias_pedido'] ?? 0)),
            'bridge_status'     => (string) ($row['bridge_status'] ?? 'pedido_sem_nf'),
            'status_comercial'  => (string) ($row['status_comercial'] ?? 'autorizado'),
            'issue_date'        => (string) ($row['data_emissao'] ?? ''),
            'billing_date'      => (string) ($row['data_faturamento'] ?? ''),
            // aliases para o front
            'codigo'            => (string) ($row['codigo_pedido'] ?? ''),
            'cliente'           => (string) ($row['nome_cliente'] ?? ''),
            'total'             => Support::money((float) ($row['valor_pedido'] ?? 0)),
            'gross_total'       => Support::money((float) ($row['valor_pedido'] ?? 0)),
            'status_group'      => 'autorizado',
        ];
    }

    private function mapProductRow(array $row): array
    {
        $revenue = (float) ($row['revenue'] ?? 0);
        $qty     = (float) ($row['quantity'] ?? 0);
        return [
            'product_code'    => (string) ($row['product_code'] ?? ''),
            'description'     => (string) ($row['description'] ?? 'Produto sem descricao'),
            'quantity'        => Support::money($qty),
            'revenue'         => Support::money($revenue),
            'value'           => Support::money($revenue),
            'discount'        => Support::money((float) ($row['discount'] ?? 0)),
            'invoices_count'  => (int) ($row['invoices_count'] ?? 0),
            'orders'          => (int) ($row['invoices_count'] ?? 0),
            'customers_count' => (int) ($row['customers_count'] ?? 0),
            'first_sale'      => (string) ($row['first_sale'] ?? ''),
            'last_sale'       => (string) ($row['last_sale'] ?? ''),
        ];
    }
}
