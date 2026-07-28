<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use Closure;
use PDO;

/**
 * Consultas de cobertura e diagnóstico da camada canônica.
 *
 * Responde perguntas do tipo: qual período está coberto? qual o último sync?
 * Não lê dados de negócio — lê metadados de cobertura temporal.
 */
final class CanonicalCoverageRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    // ── Última sincronização ─────────────────────────────────────────────────

    /**
     * Retorna o timestamp do último registro sincronizado dentro do período.
     */
    public function lastSyncAt(string $start, string $end): ?string
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $sql = <<<SQL
            SELECT MAX(last_sync) AS last_sync
            FROM (
                SELECT MAX(sincronizado_em) AS last_sync
                FROM biomie_notas_fiscais_canonicas
                WHERE {$tenantFilter}
                  AND data_competencia BETWEEN :start_invoice AND :end_invoice

                UNION ALL

                SELECT MAX(sincronizado_em) AS last_sync
                FROM biomie_pedidos_canonicos
                WHERE {$tenantFilter}
                  AND data_emissao BETWEEN :start_order AND :end_order
            ) syncs
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'start_invoice' => $start,
            'end_invoice'   => $end,
            'start_order'   => $start,
            'end_order'     => $end,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row['last_sync'] ?? null) ?: null;
    }

    // ── Diagnósticos de cobertura ────────────────────────────────────────────

    /**
     * Diagnóstico de cobertura das notas fiscais no período.
     *
     * @return array<string,mixed>
     */
    public function invoiceCoverageDiagnostics(string $start, string $end): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $sql = <<<SQL
            SELECT
                MIN(data_competencia) AS invoice_min_date,
                MAX(data_competencia) AS invoice_max_date,
                COUNT(*) AS invoice_notes_count,
                COUNT(DISTINCT data_competencia) AS invoice_days_with_data,
                MIN(CASE WHEN status_fiscal = 'autorizada' THEN data_competencia END) AS authorized_min_date,
                MAX(CASE WHEN status_fiscal = 'autorizada' THEN data_competencia END) AS authorized_max_date,
                SUM(CASE WHEN status_fiscal = 'autorizada' THEN 1 ELSE 0 END) AS authorized_notes_count,
                COUNT(DISTINCT CASE WHEN status_fiscal = 'autorizada' THEN data_competencia END) AS authorized_days_with_data,
                MIN(CASE WHEN status_fiscal = 'cancelada' THEN data_competencia END) AS cancelled_min_date,
                MAX(CASE WHEN status_fiscal = 'cancelada' THEN data_competencia END) AS cancelled_max_date,
                SUM(CASE WHEN status_fiscal = 'cancelada' THEN 1 ELSE 0 END) AS cancelled_notes_count,
                COUNT(DISTINCT CASE WHEN status_fiscal = 'cancelada' THEN data_competencia END) AS cancelled_days_with_data,
                MIN(CASE WHEN status_fiscal = 'nao_autorizada' THEN data_competencia END) AS not_authorized_min_date,
                MAX(CASE WHEN status_fiscal = 'nao_autorizada' THEN data_competencia END) AS not_authorized_max_date,
                SUM(CASE WHEN status_fiscal = 'nao_autorizada' THEN 1 ELSE 0 END) AS not_authorized_notes_count,
                COUNT(DISTINCT CASE WHEN status_fiscal = 'nao_autorizada' THEN data_competencia END) AS not_authorized_days_with_data,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal IN ('autorizada', 'cancelada', 'nao_autorizada')
              AND {$tenantFilter}
              AND data_competencia BETWEEN :start AND :end
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'requested_start'           => $start,
            'requested_end'             => $end,
            'invoice_min_date'          => $row['invoice_min_date'] ?? null,
            'invoice_max_date'          => $row['invoice_max_date'] ?? null,
            'invoice_days_with_data'    => (int) ($row['invoice_days_with_data'] ?? 0),
            'invoice_notes_count'       => (int) ($row['invoice_notes_count'] ?? 0),
            'authorized_min_date'       => $row['authorized_min_date'] ?? null,
            'authorized_max_date'       => $row['authorized_max_date'] ?? null,
            'authorized_days_with_data' => (int) ($row['authorized_days_with_data'] ?? 0),
            'authorized_notes_count'    => (int) ($row['authorized_notes_count'] ?? 0),
            'cancelled_min_date'        => $row['cancelled_min_date'] ?? null,
            'cancelled_max_date'        => $row['cancelled_max_date'] ?? null,
            'cancelled_days_with_data'  => (int) ($row['cancelled_days_with_data'] ?? 0),
            'cancelled_notes_count'     => (int) ($row['cancelled_notes_count'] ?? 0),
            'not_authorized_min_date'   => $row['not_authorized_min_date'] ?? null,
            'not_authorized_max_date'   => $row['not_authorized_max_date'] ?? null,
            'not_authorized_days_with_data' => (int) ($row['not_authorized_days_with_data'] ?? 0),
            'not_authorized_notes_count' => (int) ($row['not_authorized_notes_count'] ?? 0),
            'last_sync_at'              => $row['last_sync_at'] ?? null,
            'has_any_rows'              => (int) ($row['invoice_notes_count'] ?? 0) > 0,
        ];
    }

    /**
     * Diagnóstico de cobertura dos pedidos em aberto no período.
     *
     * @return array<string,mixed>
     */
    public function pendingCoverageDiagnostics(string $start, string $end): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $sql = <<<SQL
            SELECT
                MIN(data_emissao) AS pending_min_date,
                MAX(data_emissao) AS pending_max_date,
                COUNT(*) AS pending_orders_count,
                COUNT(DISTINCT data_emissao) AS pending_days_with_data,
                MIN(CASE WHEN status_comercial = 'autorizado' THEN data_emissao END) AS authorized_min_date,
                MAX(CASE WHEN status_comercial = 'autorizado' THEN data_emissao END) AS authorized_max_date,
                SUM(CASE WHEN status_comercial = 'autorizado' THEN 1 ELSE 0 END) AS authorized_orders_count,
                COUNT(DISTINCT CASE WHEN status_comercial = 'autorizado' THEN data_emissao END) AS authorized_days_with_data,
                MIN(CASE WHEN status_comercial = 'outro' THEN data_emissao END) AS quote_min_date,
                MAX(CASE WHEN status_comercial = 'outro' THEN data_emissao END) AS quote_max_date,
                SUM(CASE WHEN status_comercial = 'outro' THEN 1 ELSE 0 END) AS quote_orders_count,
                COUNT(DISTINCT CASE WHEN status_comercial = 'outro' THEN data_emissao END) AS quote_days_with_data,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_pedidos_canonicos
            WHERE status_comercial IN ('autorizado', 'outro')
              AND {$tenantFilter}
              AND data_emissao BETWEEN :start AND :end
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'requested_start'          => $start,
            'requested_end'            => $end,
            'pending_min_date'         => $row['pending_min_date'] ?? null,
            'pending_max_date'         => $row['pending_max_date'] ?? null,
            'pending_days_with_data'   => (int) ($row['pending_days_with_data'] ?? 0),
            'pending_orders_count'     => (int) ($row['pending_orders_count'] ?? 0),
            'authorized_min_date'      => $row['authorized_min_date'] ?? null,
            'authorized_max_date'      => $row['authorized_max_date'] ?? null,
            'authorized_orders_count'  => (int) ($row['authorized_orders_count'] ?? 0),
            'authorized_days_with_data'=> (int) ($row['authorized_days_with_data'] ?? 0),
            'quote_min_date'           => $row['quote_min_date'] ?? null,
            'quote_max_date'           => $row['quote_max_date'] ?? null,
            'quote_orders_count'       => (int) ($row['quote_orders_count'] ?? 0),
            'quote_days_with_data'     => (int) ($row['quote_days_with_data'] ?? 0),
            'last_sync_at'             => $row['last_sync_at'] ?? null,
            'has_any_rows'             => (int) ($row['pending_orders_count'] ?? 0) > 0,
        ];
    }

    /**
     * Diagnóstico combinado: cobertura comercial = fiscal + pedidos em aberto.
     *
     * @return array<string,mixed>
     */
    public function commercialCoverageDiagnostics(string $start, string $end): array
    {
        $invoice = $this->invoiceCoverageDiagnostics($start, $end);
        $pending = $this->pendingCoverageDiagnostics($start, $end);

        $actualStart = $this->firstCoverageDate(
            $invoice['invoice_min_date'] ?? null,
            $pending['pending_min_date'] ?? null,
        );
        $actualEnd = $this->lastCoverageDate(
            $invoice['invoice_max_date'] ?? null,
            $pending['pending_max_date'] ?? null,
        );
        $lastSync = $this->lastCoverageDate(
            $invoice['last_sync_at'] ?? null,
            $pending['last_sync_at'] ?? null,
        );
        $hasAnyRows = (bool) ($invoice['has_any_rows'] ?? false)
            || (bool) ($pending['has_any_rows'] ?? false);

        return [
            'requested_start'        => $start,
            'requested_end'          => $end,
            'actual_start'           => $actualStart,
            'actual_end'             => $actualEnd,
            'invoice_min_date'       => $invoice['invoice_min_date'] ?? null,
            'invoice_max_date'       => $invoice['invoice_max_date'] ?? null,
            'invoice_days_with_data' => (int) ($invoice['invoice_days_with_data'] ?? 0),
            'invoice_notes_count'    => (int) ($invoice['invoice_notes_count'] ?? 0),
            'pending_min_date'       => $pending['pending_min_date'] ?? null,
            'pending_max_date'       => $pending['pending_max_date'] ?? null,
            'pending_days_with_data' => (int) ($pending['pending_days_with_data'] ?? 0),
            'pending_orders_count'   => (int) ($pending['pending_orders_count'] ?? 0),
            'last_sync_at'           => $lastSync,
            'has_any_rows'           => $hasAnyRows,
            'invoice'                => $invoice,
            'pending'                => $pending,
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private function pdo(): PDO
    {
        return ($this->pdoResolver)();
    }

    private function firstCoverageDate(?string ...$dates): ?string
    {
        $values = array_values(array_filter(
            $dates,
            static fn (?string $v): bool => is_string($v) && trim($v) !== '',
        ));
        if ($values === []) {
            return null;
        }
        sort($values, SORT_STRING);
        return $values[0];
    }

    private function lastCoverageDate(?string ...$dates): ?string
    {
        $values = array_values(array_filter(
            $dates,
            static fn (?string $v): bool => is_string($v) && trim($v) !== '',
        ));
        if ($values === []) {
            return null;
        }
        rsort($values, SORT_STRING);
        return $values[0];
    }
}
