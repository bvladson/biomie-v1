<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use BIomie\DataPlatform\Silver\Readers\CanonicalDimensionEnricher;
use BIomie\DataPlatform\Silver\Readers\CanonicalDisplayMapper;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Modules\Fiscal\Infrastructure\FiscalReadRepository;
use BIomie\Support;
use Closure;
use PDO;
use RuntimeException;

final class SalesReadRepository
{
    private ?CanonicalDisplayMapper $displayMapper = null;
    private ?CanonicalDimensionEnricher $dimensionEnricher = null;
    private ?FiscalReadRepository $fiscalReadRepository = null;
    private ?CanonicalReadSupport $readSupport = null;

    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return array<string, array{codigo_omie:string,nome:string,email:?string,comissao:mixed}>
     */
    public function getVendorMap(): array
    {
        return $this->readSupport()->getVendorMap();
    }

    public function listCommercialVendorRoster(): array
    {
        return $this->readSupport()->listCommercialVendorRoster();
    }

    /**
     * @return array{orders_count:int,merchandise_total:float,order_total:float}
     */
    public function summarizeOrderPipelineCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS orders_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS order_total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'autorizado'
              AND data_emissao BETWEEN :start AND :end
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'orders_count'      => (int) ($row['orders_count'] ?? 0),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'order_total'       => Support::money((float) ($row['order_total'] ?? 0)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateOrderPipelineByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS orders_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS order_total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial = 'autorizado'
              AND data_emissao BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersCommercial(string $start, string $end, ?string $status = null): array
    {
        if ($status === 'faturado') {
            return $this->fiscalReadRepository()->listInvoicesCommercial($start, $end);
        }
        if ($status === 'cancelado') {
            return $this->fiscalReadRepository()->listCancelledInvoicesCommercial($start, $end);
        }
        if ($status === 'autorizado' || $status === 'outro') {
            return array_values(array_filter(
                $this->listPendingOrdersCommercial($start, $end),
                static fn (array $row): bool => (string) ($row['status_comercial'] ?? '') === $status,
            ));
        }

        return array_merge(
            $this->fiscalReadRepository()->listInvoicesCommercial($start, $end),
            $this->listPendingOrdersCommercial($start, $end),
            $this->fiscalReadRepository()->listCancelledInvoicesCommercial($start, $end),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrderRowsForReconciliation(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT *
            FROM biomie_pedidos_canonicos
            WHERE data_emissao BETWEEN :start AND :end
               OR (data_faturamento IS NOT NULL AND data_faturamento BETWEEN :start AND :end)
               OR (data_cancelamento IS NOT NULL AND data_cancelamento BETWEEN :start AND :end)
            ORDER BY data_emissao DESC, pedido_id_omie DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendorCommercial(string $start, string $end): array
    {
        $rows = array_merge(
            $this->fiscalReadRepository()->aggregateInvoicesByVendorCommercial($start, $end),
            $this->aggregatePendingByVendorCommercial($start, $end),
            $this->fiscalReadRepository()->aggregateCancelledVendorStatusCommercial($start, $end),
        );

        usort($rows, static function (array $l, array $r): int {
            $nameCompare = strcmp(
                mb_strtolower((string) ($l['nome_vendedor'] ?? '')),
                mb_strtolower((string) ($r['nome_vendedor'] ?? '')),
            );
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return strcmp((string) ($l['status_comercial'] ?? ''), (string) ($r['status_comercial'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeCommercial(string $start, string $end): array
    {
        $inv = $this->fiscalReadRepository()->summarizeInvoicesCommercial($start, $end);
        $pen = $this->summarizePendingCommercial($start, $end);

        return [
            'billed_value'       => (float) ($inv['billed_value'] ?? 0),
            'billed_orders'      => (int) ($inv['billed_orders'] ?? 0),
            'authorized_value'   => (float) ($pen['authorized_value'] ?? 0),
            'authorized_orders'  => (int) ($pen['authorized_orders'] ?? 0),
            'cancelled_value'    => (float) ($pen['cancelled_value'] ?? 0),
            'cancelled_orders'   => (int) ($pen['cancelled_orders'] ?? 0),
            'quote_value'        => (float) ($pen['quote_value'] ?? 0),
            'quote_orders'       => (int) ($pen['quote_orders'] ?? 0),
            'merchandise_total'  => (float) ($inv['merchandise_total'] ?? 0),
            'discounts_total'    => (float) ($inv['discounts_total'] ?? 0),
            'active_vendors'     => (int) ($inv['active_vendors'] ?? 0),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyByStatusCommercial(string $start, string $end): array
    {
        $rows = array_merge(
            $this->fiscalReadRepository()->dailyInvoiceStatusRowsCommercial($start, $end),
            $this->dailyPendingStatusRowsCommercial($start, $end),
            $this->fiscalReadRepository()->dailyCancelledStatusRowsCommercial($start, $end),
        );

        usort($rows, static function (array $l, array $r): int {
            $dayCompare = strcmp((string) ($l['dia'] ?? ''), (string) ($r['dia'] ?? ''));
            if ($dayCompare !== 0) {
                return $dayCompare;
            }

            return strcmp((string) ($l['status_comercial'] ?? ''), (string) ($r['status_comercial'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrdersForDisplayCommercial(string $start, string $end, ?string $status = null): array
    {
        $rows = [];

        if ($status === null || $status === '' || $status === 'faturado') {
            $rows = array_merge($rows, $this->fiscalReadRepository()->listInvoicesForDisplayCommercial($start, $end));
        }

        if ($status === null || $status === '' || $status === 'autorizado' || $status === 'outro') {
            $pendingRows = $this->listPendingOrdersForDisplayCommercial($start, $end);
            if ($status === 'autorizado' || $status === 'outro') {
                $pendingRows = array_values(array_filter(
                    $pendingRows,
                    static fn (array $row): bool => (string) ($row['status_group'] ?? '') === $status,
                ));
            }
            $rows = array_merge($rows, $pendingRows);
        }

        if ($status === null || $status === '' || $status === 'cancelado') {
            $rows = array_merge($rows, $this->fiscalReadRepository()->listCancelledInvoicesForDisplayCommercial($start, $end));
        }

        usort($rows, static function (array $l, array $r): int {
            $leftDate = (string) ($l['status_date'] ?? $l['issue_date'] ?? '');
            $rightDate = (string) ($r['status_date'] ?? $r['issue_date'] ?? '');
            $dateCompare = strcmp($rightDate, $leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($r['codigo'] ?? ''), (string) ($l['codigo'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPipelineOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        $sql = $this->readSupport()->pendingOrderSelectSql() . <<<'SQL'
            FROM biomie_pedidos_canonicos p
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = p.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE p.status_comercial = 'autorizado'
              AND p.data_emissao BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND p.codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' ORDER BY p.data_emissao DESC, p.codigo_pedido DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->mapDisplayRows($this->enrichCustomerSellerIdentityRows($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersCommercial(string $start, string $end): array
    {
        $sql = $this->readSupport()->pendingOrderSelectSql() . <<<'SQL'
            FROM biomie_pedidos_canonicos p
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = p.codigo_vendedor_omie
               AND v.ativo = 1
            WHERE p.status_comercial IN ('autorizado', 'outro')
              AND p.data_emissao BETWEEN :start AND :end
            ORDER BY p.data_emissao DESC, p.codigo_pedido DESC
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapDisplayRows($this->enrichCustomerSellerIdentityRows($this->listPendingOrdersCommercial($start, $end)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function productDocumentBreakdownCommercial(
        string $start,
        string $end,
        ?string $productCode = null,
        int $limit = 200,
    ): array {
        return $this->readSupport()->productDocumentBreakdownCommercial($start, $end, $productCode, $limit);
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizePendingCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        $result = [
            'authorized_value'  => 0.0,
            'authorized_orders' => 0,
            'cancelled_value'   => 0.0,
            'cancelled_orders'  => 0,
            'quote_value'       => 0.0,
            'quote_orders'      => 0,
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status_comercial'] ?? 'outro');
            $orders = (int) ($row['pedidos'] ?? 0);
            $total = (float) ($row['total'] ?? 0);
            if ($status === 'autorizado') {
                $result['authorized_value'] = $total;
                $result['authorized_orders'] = $orders;
            } else {
                $result['quote_value'] += $total;
                $result['quote_orders'] += $orders;
            }
        }

        $cancelledSummary = $this->fiscalReadRepository()->summarizeCancelledCommercial($start, $end);
        $result['cancelled_value'] = (float) ($cancelledSummary['invoice_total'] ?? 0);
        $result['cancelled_orders'] = (int) ($cancelledSummary['notes_count'] ?? 0);

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->readSupport()->topProductsCommercial($start, $end, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topClientsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->readSupport()->topClientsCommercial($start, $end, $limit);
    }

    private function aggregatePendingByVendorCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor, status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY codigo_vendedor_omie, nome_vendedor, status_comercial
            ORDER BY nome_vendedor, status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dailyPendingStatusRowsCommercial(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                data_emissao AS dia, status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM(valor_total), 0) AS total
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND data_emissao BETWEEN :start AND :end
            GROUP BY data_emissao, status_comercial
            ORDER BY data_emissao, status_comercial
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fiscalReadRepository(): FiscalReadRepository
    {
        return $this->fiscalReadRepository ??= new FiscalReadRepository($this->pdoResolver);
    }

    private function readSupport(): CanonicalReadSupport
    {
        return $this->readSupport ??= new CanonicalReadSupport($this->pdoResolver);
    }

    private function enrichCustomerSellerIdentityRows(array $rows): array
    {
        return $this->dimensionEnricher()->enrichCustomerSellerIdentityRows($rows);
    }

    private function mapDisplayRows(array $rows, string $defaultClient = 'Cliente nao informado'): array
    {
        return $this->displayMapper()->mapOrderRows($rows, $defaultClient);
    }

    private function displayMapper(): CanonicalDisplayMapper
    {
        return $this->displayMapper ??= new CanonicalDisplayMapper();
    }

    private function dimensionEnricher(): CanonicalDimensionEnricher
    {
        return $this->dimensionEnricher ??= new CanonicalDimensionEnricher($this->pdoResolver);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }
}
