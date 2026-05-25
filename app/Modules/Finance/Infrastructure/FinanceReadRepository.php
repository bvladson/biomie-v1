<?php

declare(strict_types=1);

namespace BIomie\Modules\Finance\Infrastructure;

use Closure;
use PDO;

final class FinanceReadRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialTitlesByPeriod(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT titulo_key, titulo_id_omie, numero_documento, status_titulo, especie_documento,
                    forma_pagamento, codigo_cliente_omie, nome_cliente, documento_cliente,
                    codigo_vendedor_omie, nome_vendedor, data_emissao, data_vencimento,
                    data_liquidacao, data_competencia, valor_pago, valor_liquido, valor_aberto,
                    codigo_conta_corrente_omie, documento_origem_tipo, documento_origem_ref
             FROM biomie_titulos_financeiros_canonicos
             WHERE data_competencia BETWEEN :start AND :end
             ORDER BY data_competencia DESC, data_liquidacao DESC, data_vencimento DESC, titulo_key DESC'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Single aggregated query: totals for received, open, overdue within the period.
     *
     * @return array{received:float,open:float,overdue:float,received_titles:int,open_titles:int,overdue_titles:int,overdue_customers:int}
     */
    public function summarizeReceivables(string $start, string $end, ?string $referenceDate = null): array
    {
        $ref = $referenceDate ?? date('Y-m-d');

        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COALESCE(SUM(
                    CASE WHEN status_titulo IN ('liquidado','recebido','pago')
                    THEN COALESCE(valor_liquido, valor_pago, 0) ELSE 0 END
                ), 0) AS received,

                COALESCE(SUM(
                    CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                         AND COALESCE(valor_aberto, 0) > 0
                    THEN COALESCE(valor_aberto, 0) ELSE 0 END
                ), 0) AS open,

                COALESCE(SUM(
                    CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                         AND COALESCE(valor_aberto, 0) > 0
                         AND data_vencimento < :ref_date
                    THEN COALESCE(valor_aberto, 0) ELSE 0 END
                ), 0) AS overdue,

                COUNT(CASE WHEN status_titulo IN ('liquidado','recebido','pago')
                      THEN 1 ELSE NULL END)                         AS received_titles,

                COUNT(CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                           AND COALESCE(valor_aberto, 0) > 0
                      THEN 1 ELSE NULL END)                         AS open_titles,

                COUNT(CASE WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                           AND COALESCE(valor_aberto, 0) > 0
                           AND data_vencimento < :ref_date2
                      THEN 1 ELSE NULL END)                         AS overdue_titles,

                COUNT(DISTINCT CASE
                    WHEN status_titulo NOT IN ('liquidado','recebido','pago')
                         AND COALESCE(valor_aberto, 0) > 0
                         AND data_vencimento < :ref_date3
                    THEN codigo_cliente_omie ELSE NULL END)          AS overdue_customers

            FROM biomie_titulos_financeiros_canonicos
            WHERE data_competencia BETWEEN :start AND :end
        SQL);

        $stmt->execute([
            'start'      => $start,
            'end'        => $end,
            'ref_date'   => $ref,
            'ref_date2'  => $ref,
            'ref_date3'  => $ref,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? [
            'received'          => (float) ($row['received'] ?? 0),
            'open'              => (float) ($row['open'] ?? 0),
            'overdue'           => (float) ($row['overdue'] ?? 0),
            'received_titles'   => (int) ($row['received_titles'] ?? 0),
            'open_titles'       => (int) ($row['open_titles'] ?? 0),
            'overdue_titles'    => (int) ($row['overdue_titles'] ?? 0),
            'overdue_customers' => (int) ($row['overdue_customers'] ?? 0),
        ] : [
            'received' => 0.0, 'open' => 0.0, 'overdue' => 0.0,
            'received_titles' => 0, 'open_titles' => 0, 'overdue_titles' => 0, 'overdue_customers' => 0,
        ];
    }

    /**
     * Returns top overdue customers ordered by overdue_amount DESC.
     *
     * @return list<array<string,mixed>>
     */
    public function listOverdueCustomers(
        string $start,
        string $end,
        ?string $referenceDate = null,
        int $limit = 10
    ): array {
        $ref   = $referenceDate ?? date('Y-m-d');
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COALESCE(codigo_cliente_omie, 0)         AS customer_id,
                COALESCE(nome_cliente, 'Cliente nao informado') AS customer_name,
                COALESCE(documento_cliente, '')          AS document,
                COALESCE(SUM(valor_aberto), 0)           AS overdue_amount,
                COUNT(*)                                 AS titles_count,
                MAX(DATEDIFF(:ref_date, data_vencimento)) AS max_days_overdue,
                MAX(data_vencimento)                     AS last_due_date
            FROM biomie_titulos_financeiros_canonicos
            WHERE data_competencia BETWEEN :start AND :end
              AND status_titulo NOT IN ('liquidado','recebido','pago')
              AND COALESCE(valor_aberto, 0) > 0
              AND data_vencimento < :ref_date2
            GROUP BY codigo_cliente_omie, nome_cliente, documento_cliente
            ORDER BY overdue_amount DESC
        SQL);

        $stmt->bindValue(':ref_date',  $ref);
        $stmt->bindValue(':ref_date2', $ref);
        $stmt->bindValue(':start',     $start);
        $stmt->bindValue(':end',       $end);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_slice(array_map(static fn (array $r): array => [
            'customer_id'      => (string) ($r['customer_id'] ?? ''),
            'customer_name'    => (string) ($r['customer_name'] ?? 'Cliente nao informado'),
            'document'         => (string) ($r['document'] ?? ''),
            'overdue_amount'   => (float) ($r['overdue_amount'] ?? 0),
            'titles_count'     => (int) ($r['titles_count'] ?? 0),
            'max_days_overdue' => (int) ($r['max_days_overdue'] ?? 0),
            'last_due_date'    => (string) ($r['last_due_date'] ?? ''),
        ], $rows), 0, $limit);
    }

    /**
     * Top open titles ordered by due date ASC (most urgent first).
     *
     * @return list<array<string,mixed>>
     */
    public function listTopOpenTitles(
        string $start,
        string $end,
        ?string $referenceDate = null,
        int $limit = 10
    ): array {
        $ref   = $referenceDate ?? date('Y-m-d');
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo()->prepare(<<<'SQL'
            SELECT
                COALESCE(titulo_key, '')              AS title_id,
                COALESCE(nome_cliente, 'Cliente nao informado') AS customer_name,
                data_vencimento                       AS due_date,
                COALESCE(valor_aberto, 0)             AS amount,
                COALESCE(status_titulo, '')           AS status
            FROM biomie_titulos_financeiros_canonicos
            WHERE data_competencia BETWEEN :start AND :end
              AND status_titulo NOT IN ('liquidado','recebido','pago')
              AND COALESCE(valor_aberto, 0) > 0
            ORDER BY data_vencimento ASC
        SQL);

        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_slice(array_map(static fn (array $r): array => [
            'title_id'      => (string) ($r['title_id'] ?? ''),
            'customer_name' => (string) ($r['customer_name'] ?? ''),
            'due_date'      => (string) ($r['due_date'] ?? ''),
            'amount'        => (float) ($r['amount'] ?? 0),
            'status'        => (string) ($r['status'] ?? ''),
            'is_overdue'    => $r['due_date'] !== null && $r['due_date'] < $ref,
        ], $rows), 0, $limit);
    }

    /**
     * Detects which fields are present in the table (for diagnostics).
     *
     * @return array<string,string>
     */
    public function detectFinanceFields(): array
    {
        return [
            'amount_field'            => 'valor_liquido (received) / valor_aberto (open)',
            'status_field'            => 'status_titulo',
            'due_date_field'          => 'data_vencimento',
            'liquidation_date_field'  => 'data_liquidacao',
            'customer_field'          => 'codigo_cliente_omie / nome_cliente',
            'period_filter_field'     => 'data_competencia',
            'received_statuses'       => 'liquidado, recebido, pago',
        ];
    }

    /**
     * Checks if the table exists and returns the total row count.
     */
    public function tableInfo(): array
    {
        try {
            $exists = (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'biomie_titulos_financeiros_canonicos'"
            )->fetchColumn() > 0;

            if (!$exists) {
                return ['exists' => false, 'total_rows' => 0];
            }

            $total = (int) $this->pdo()->query(
                'SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos'
            )->fetchColumn();

            return ['exists' => true, 'total_rows' => $total];
        } catch (\Throwable) {
            return ['exists' => false, 'total_rows' => 0, 'error' => 'query_failed'];
        }
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }
}
