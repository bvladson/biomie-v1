<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Canonical\Support\CanonicalJsonCodec;
use Closure;
use PDO;

final class CanonicalReconciliationRepository
{
    private const TABLE_RECONCILIATION_RUNS = 'biomie_reconciliacoes_runs';
    private const TABLE_RECONCILIATION_VENDOR_ROWS = 'biomie_reconciliacoes_vendedores';
    private const TABLE_RECONCILIATION_DIFF_ROWS = 'biomie_reconciliacoes_diferencas';

    public function __construct(
        private readonly Closure $pdoResolver,
        private readonly Closure $ensureSchema,
        private readonly Closure $beginTransactionSafely,
        private readonly CanonicalJsonCodec $jsonCodec,
    ) {
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $actor
     */
    public function saveSnapshot(array $report, ?array $actor = null): int
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $source = is_array($report['source'] ?? null) ? $report['source'] : [];
        $period = is_array($source['period'] ?? null) ? $source['period'] : [];
        $periodStart = (string) ($period['start'] ?? '');
        $periodEnd = (string) ($period['end'] ?? '');
        if ($periodStart === '' || $periodEnd === '') {
            throw new \InvalidArgumentException('Periodo da conciliacao nao informado.');
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $vendorRows = is_array($report['vendor_comparison'] ?? null) ? $report['vendor_comparison'] : [];
        $differences = is_array($report['differences'] ?? null) ? $report['differences'] : [];
        $sourceType = trim((string) ($source['source_type'] ?? 'omie_csv')) ?: 'omie_csv';
        $sourceFile = trim((string) ($source['source_file'] ?? 'consolidado.csv'));
        $createdUserId = isset($actor['id']) ? (int) $actor['id'] : null;
        $createdUserName = trim((string) ($actor['username'] ?? $actor['full_name'] ?? ''));

        $runId = 0;
        try {
            ($this->beginTransactionSafely)();
            $pdo = $this->pdo();

            $stmt = $pdo->prepare(
                'UPDATE ' . self::TABLE_RECONCILIATION_RUNS . '
                 SET latest_for_period = 0
                 WHERE source_type = :source_type
                   AND period_start = :period_start
                   AND period_end = :period_end'
            );
            $stmt->execute([
                'source_type' => $sourceType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE_RECONCILIATION_RUNS . ' (
                    source_type, source_file, period_start, period_end,
                    notes_csv, notes_system, differences_count, matches_count,
                    created_user_id, created_user_name, latest_for_period, report_json, created_at
                 ) VALUES (
                    :source_type, :source_file, :period_start, :period_end,
                    :notes_csv, :notes_system, :differences_count, :matches_count,
                    :created_user_id, :created_user_name, 1, :report_json, NOW()
                 )'
            );
            $stmt->execute([
                'source_type' => $sourceType,
                'source_file' => $sourceFile,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes_csv' => (int) ($metrics['notes_csv'] ?? 0),
                'notes_system' => (int) ($metrics['notes_system'] ?? 0),
                'differences_count' => (int) ($metrics['differences'] ?? 0),
                'matches_count' => (int) ($metrics['matches'] ?? 0),
                'created_user_id' => $createdUserId,
                'created_user_name' => $createdUserName !== '' ? $createdUserName : null,
                'report_json' => $this->jsonCodec->encode($report),
            ]);

            $runId = (int) $pdo->lastInsertId();

            if ($vendorRows !== []) {
                $stmtVendor = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE_RECONCILIATION_VENDOR_ROWS . ' (
                        run_id, vendor_key, vendor_name, has_difference, payload_json
                     ) VALUES (
                        :run_id, :vendor_key, :vendor_name, :has_difference, :payload_json
                     )'
                );

                foreach ($vendorRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stmtVendor->execute([
                        'run_id' => $runId,
                        'vendor_key' => (string) ($row['vendor_key'] ?? ''),
                        'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                        'has_difference' => !empty($row['has_difference']) ? 1 : 0,
                        'payload_json' => $this->jsonCodec->encode($row),
                    ]);
                }
            }

            if ($differences !== []) {
                $stmtDiff = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE_RECONCILIATION_DIFF_ROWS . ' (
                        run_id, note_key, note_ref, difference_type, payload_json
                     ) VALUES (
                        :run_id, :note_key, :note_ref, :difference_type, :payload_json
                     )'
                );

                foreach ($differences as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stmtDiff->execute([
                        'run_id' => $runId,
                        'note_key' => (string) ($row['note_key'] ?? ''),
                        'note_ref' => (string) ($row['note_ref'] ?? ''),
                        'difference_type' => (string) ($row['type'] ?? 'mismatch'),
                        'payload_json' => $this->jsonCodec->encode($row),
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $runId;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadSnapshot(?int $runId = null): ?array
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        if ($runId !== null && $runId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE_RECONCILIATION_RUNS . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $runId]);
        } else {
            $stmt = $pdo->query(
                'SELECT * FROM ' . self::TABLE_RECONCILIATION_RUNS . '
                 WHERE latest_for_period = 1
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $report = $this->jsonCodec->decodeObject((string) ($row['report_json'] ?? ''));
        if ($report === null) {
            return null;
        }

        $report['saved'] = [
            'run_id' => (int) ($row['id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_user_name' => (string) ($row['created_user_name'] ?? ''),
            'source_file' => (string) ($row['source_file'] ?? ''),
        ];

        return $report;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentSnapshots(int $limit = 12): array
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $stmt = $pdo->prepare(
            'SELECT id, source_type, source_file, period_start, period_end, notes_csv, notes_system,
                    differences_count, matches_count, created_user_name, latest_for_period, created_at
             FROM ' . self::TABLE_RECONCILIATION_RUNS . '
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function report(string $start, string $end): array
    {
        $pdo = $this->pdo();

        $sql = <<<'SQL'
            SELECT
                codigo_vendedor_omie,
                nome_vendedor,
                SUM(qtd_faturado) AS qtd_faturado,
                SUM(total_faturado) AS total_faturado,
                SUM(qtd_autorizado) AS qtd_autorizado,
                SUM(total_autorizado) AS total_autorizado,
                SUM(qtd_cancelado) AS qtd_cancelado,
                SUM(total_cancelado) AS total_cancelado,
                SUM(qtd_total) AS qtd_total,
                SUM(total_geral) AS total_geral,
                MAX(ultima_sync) AS ultima_sync
            FROM (
                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    COUNT(*) AS qtd_faturado,
                    COALESCE(SUM(valor_total), 0) AS total_faturado,
                    0 AS qtd_autorizado,
                    0 AS total_autorizado,
                    0 AS qtd_cancelado,
                    0 AS total_cancelado,
                    COUNT(*) AS qtd_total,
                    COALESCE(SUM(valor_total), 0) AS total_geral,
                    MAX(sincronizado_em) AS ultima_sync
                FROM biomie_notas_fiscais_canonicas
                WHERE status_fiscal = 'autorizada'
                  AND data_competencia BETWEEN :start_invoice AND :end_invoice
                GROUP BY codigo_vendedor_omie, nome_vendedor

                UNION ALL

                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    0 AS qtd_faturado,
                    0 AS total_faturado,
                    COUNT(CASE WHEN status_comercial = 'autorizado' THEN 1 END) AS qtd_autorizado,
                    COALESCE(SUM(CASE WHEN status_comercial = 'autorizado' THEN valor_total END), 0) AS total_autorizado,
                    0 AS qtd_cancelado,
                    0 AS total_cancelado,
                    COUNT(*) AS qtd_total,
                    COALESCE(SUM(valor_total), 0) AS total_geral,
                    MAX(sincronizado_em) AS ultima_sync
                FROM biomie_pedidos_canonicos
                WHERE status_comercial IN ('autorizado', 'outro')
                  AND data_emissao BETWEEN :start_order AND :end_order
                GROUP BY codigo_vendedor_omie, nome_vendedor

                UNION ALL

                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    0 AS qtd_faturado,
                    0 AS total_faturado,
                    0 AS qtd_autorizado,
                    0 AS total_autorizado,
                    COUNT(*) AS qtd_cancelado,
                    COALESCE(SUM(valor_total), 0) AS total_cancelado,
                    COUNT(*) AS qtd_total,
                    COALESCE(SUM(valor_total), 0) AS total_geral,
                    MAX(sincronizado_em) AS ultima_sync
                FROM biomie_notas_fiscais_canonicas
                WHERE status_fiscal = 'cancelada'
                  AND data_competencia BETWEEN :start_cancel AND :end_cancel
                GROUP BY codigo_vendedor_omie, nome_vendedor
            ) consolidado
            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY total_faturado DESC, total_autorizado DESC
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'start_invoice' => $start,
            'end_invoice' => $end,
            'start_order' => $start,
            'end_order' => $end,
            'start_cancel' => $start,
            'end_cancel' => $end,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('PDO canonico indisponivel.');
        }

        return $pdo;
    }
}
