<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Competencia\CompetenciaPayloadBuilder;
use BIomie\Competencia\CompetenciaSupport;
use DateTimeImmutable;
use PDO;
use Throwable;

final class CommercialCompetenciaSnapshotService
{
    private const SNAPSHOT_TABLE = 'biomie_competencia_snapshots';
    private const CATALOG_TABLE = 'biomie_competencia_sync_catalog';
    private const SCHEMA_VERSION = 1;
    private ?CompetenciaSupport $competenciaSupport = null;
    private ?CompetenciaPayloadBuilder $payloadBuilder = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly CanonicalRepository $canonical,
        private readonly ?TargetsRepository $targetsRepository,
        private readonly float $saturdayWeight = 0.33,
    ) {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_competencia_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                competencia CHAR(7) NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'canonical',
                schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                overview_payload LONGTEXT NULL,
                vendors_payload LONGTEXT NULL,
                flight_plan_payload LONGTEXT NULL,
                targets_payload LONGTEXT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_sync DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_biomie_competencia_snapshot (competencia),
                KEY idx_biomie_competencia_snapshot_last_sync (last_sync),
                KEY idx_biomie_competencia_snapshot_generated (generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_competencia_sync_catalog (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                competencia CHAR(7) NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                status ENUM('pending','running','ready','stale','error') NOT NULL DEFAULT 'pending',
                source VARCHAR(32) NOT NULL DEFAULT 'canonical',
                duration_ms INT UNSIGNED NULL,
                orders_count INT UNSIGNED NOT NULL DEFAULT 0,
                notes_count INT UNSIGNED NOT NULL DEFAULT 0,
                vendors_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_reconciliation_at DATETIME NULL,
                snapshot_generated_at DATETIME NULL,
                last_error TEXT NULL,
                request_id VARCHAR(48) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_biomie_competencia_catalog (competencia),
                KEY idx_biomie_competencia_catalog_status (status),
                KEY idx_biomie_competencia_catalog_generated (snapshot_generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function isClosedCompetencia(string $competencia): bool
    {
        return preg_match('/^\d{4}-\d{2}$/', $competencia) === 1
            && $competencia < (new DateTimeImmutable('today'))->format('Y-m');
    }

    public function shouldUseSnapshot(string $start, string $end, ?string $competencia = null): ?string
    {
        $yearMonth = $competencia;
        if ($yearMonth === null || $yearMonth === '') {
            $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
            $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);
            if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
                return null;
            }

            if ($startDate->format('Y-m-01') !== $start || $endDate->format('Y-m-t') !== $end) {
                return null;
            }

            if ($startDate->format('Y-m') !== $endDate->format('Y-m')) {
                return null;
            }

            $yearMonth = $startDate->format('Y-m');
        }

        return $this->isClosedCompetencia($yearMonth) ? $yearMonth : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadPayload(string $screen, string $competencia): ?array
    {
        $column = $this->screenColumn($screen);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT %s AS payload, generated_at, last_sync, source, schema_version FROM %s WHERE competencia = :competencia LIMIT 1',
            $column,
            self::SNAPSHOT_TABLE
        ));
        $stmt->execute(['competencia' => $competencia]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return null;
        }

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['snapshot_competencia'] = $competencia;
        $payload['meta']['snapshot_generated_at'] = (string) ($row['generated_at'] ?? '');
        $payload['meta']['snapshot_last_sync'] = isset($row['last_sync']) ? (string) $row['last_sync'] : null;
        $payload['meta']['snapshot_source'] = (string) ($row['source'] ?? 'canonical');
        $payload['meta']['snapshot_schema_version'] = (int) ($row['schema_version'] ?? self::SCHEMA_VERSION);
        $payload['meta']['served_from'] = 'historical-read-model';
        $payload['source'] = 'historical_snapshot';
        $payload['mode'] = (string) ($payload['mode'] ?? 'historical_snapshot');

        return $payload;
    }

    /**
     * @return array{overview:array<string,mixed>,vendors:array<string,mixed>,flight_plan:array<string,mixed>,targets:array<string,mixed>,meta:array<string,mixed>}
     */
    public function materializeCompetencia(string $competencia, bool $force = false): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $competencia) !== 1) {
            throw new \InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
        }

        $this->ensureSchema();
        $startedAt = microtime(true);
        $periodStart = $competencia . '-01';
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        if (!$force) {
            $overview = $this->loadPayload('overview', $competencia);
            $vendors = $this->loadPayload('vendors', $competencia);
            $flightPlan = $this->loadPayload('flight_plan', $competencia);
            $targets = $this->loadPayload('targets', $competencia);

            if ($overview !== null && $vendors !== null && $flightPlan !== null && $targets !== null) {
                return [
                    'overview' => $overview,
                    'vendors' => $vendors,
                    'flight_plan' => $flightPlan,
                    'targets' => $targets,
                    'meta' => [
                        'competencia' => $competencia,
                        'status' => 'ready',
                        'served_from' => 'historical-read-model',
                    ],
                ];
            }
        }

        $this->upsertCatalog([
            'competencia' => $competencia,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'running',
            'source' => 'canonical',
            'request_id' => RequestContext::id(),
            'last_error' => null,
        ]);

        try {
            $overview = $this->buildOverviewPayload($periodStart, $periodEnd);
            $vendors = $this->buildVendorsPayload($periodStart, $periodEnd);
            $flightPlan = $this->buildFlightPlanPayload($periodStart, $periodEnd);
            $targets = $this->buildTargetsPayload($competencia);
            $lastSync = $this->canonical->lastSyncAt($periodStart, $periodEnd);

            $this->persistSnapshot(
                $competencia,
                $periodStart,
                $periodEnd,
                $overview,
                $vendors,
                $flightPlan,
                $targets,
                $lastSync
            );

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->upsertCatalog([
                'competencia' => $competencia,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'ready',
                'source' => 'canonical',
                'duration_ms' => $durationMs,
                'orders_count' => (int) ($overview['summary']['orders'] ?? 0),
                'notes_count' => (int) ($overview['summary']['orders'] ?? 0),
                'vendors_count' => (int) ($overview['summary']['active_vendors'] ?? 0),
                'snapshot_generated_at' => date('Y-m-d H:i:s'),
                'last_reconciliation_at' => $lastSync !== null ? date('Y-m-d H:i:s', strtotime($lastSync)) : null,
                'request_id' => RequestContext::id(),
                'last_error' => null,
            ]);

            return [
                'overview' => $this->withHistoricalMeta($overview, $competencia, $lastSync),
                'vendors' => $this->withHistoricalMeta($vendors, $competencia, $lastSync),
                'flight_plan' => $this->withHistoricalMeta($flightPlan, $competencia, $lastSync),
                'targets' => $this->withHistoricalMeta($targets, $competencia, $lastSync),
                'meta' => [
                    'competencia' => $competencia,
                    'status' => 'ready',
                    'duration_ms' => $durationMs,
                ],
            ];
        } catch (Throwable $exception) {
            $this->upsertCatalog([
                'competencia' => $competencia,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'error',
                'source' => 'canonical',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_id' => RequestContext::id(),
                'last_error' => $exception->getMessage(),
            ]);

            $this->logger->error('Falha ao materializar snapshot historico por competencia.', [
                'competencia' => $competencia,
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCatalog(string $competencia): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::CATALOG_TABLE . ' WHERE competencia = :competencia LIMIT 1');
        $stmt->execute(['competencia' => $competencia]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function persistSnapshot(
        string $competencia,
        string $periodStart,
        string $periodEnd,
        array $overview,
        array $vendors,
        array $flightPlan,
        array $targets,
        ?string $lastSync
    ): void {
        $sql = 'INSERT INTO ' . self::SNAPSHOT_TABLE . ' (
                    competencia, period_start, period_end, source, schema_version,
                    overview_payload, vendors_payload, flight_plan_payload, targets_payload,
                    generated_at, last_sync
                ) VALUES (
                    :competencia, :period_start, :period_end, :source, :schema_version,
                    :overview_payload, :vendors_payload, :flight_plan_payload, :targets_payload,
                    NOW(), :last_sync
                )
                ON DUPLICATE KEY UPDATE
                    period_start = VALUES(period_start),
                    period_end = VALUES(period_end),
                    source = VALUES(source),
                    schema_version = VALUES(schema_version),
                    overview_payload = VALUES(overview_payload),
                    vendors_payload = VALUES(vendors_payload),
                    flight_plan_payload = VALUES(flight_plan_payload),
                    targets_payload = VALUES(targets_payload),
                    generated_at = NOW(),
                    last_sync = VALUES(last_sync)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'competencia' => $competencia,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'canonical',
            'schema_version' => self::SCHEMA_VERSION,
            'overview_payload' => $this->encodeJson($overview),
            'vendors_payload' => $this->encodeJson($vendors),
            'flight_plan_payload' => $this->encodeJson($flightPlan),
            'targets_payload' => $this->encodeJson($targets),
            'last_sync' => $lastSync !== null ? date('Y-m-d H:i:s', strtotime($lastSync)) : null,
        ]);
    }

    private function upsertCatalog(array $values): void
    {
        $defaults = [
            'duration_ms' => null,
            'orders_count' => 0,
            'notes_count' => 0,
            'vendors_count' => 0,
            'last_reconciliation_at' => null,
            'snapshot_generated_at' => null,
            'last_error' => null,
            'request_id' => null,
        ];
        $payload = $values + $defaults;

        $sql = 'INSERT INTO ' . self::CATALOG_TABLE . ' (
                    competencia, period_start, period_end, status, source,
                    duration_ms, orders_count, notes_count, vendors_count,
                    last_reconciliation_at, snapshot_generated_at, last_error, request_id
                ) VALUES (
                    :competencia, :period_start, :period_end, :status, :source,
                    :duration_ms, :orders_count, :notes_count, :vendors_count,
                    :last_reconciliation_at, :snapshot_generated_at, :last_error, :request_id
                )
                ON DUPLICATE KEY UPDATE
                    period_start = VALUES(period_start),
                    period_end = VALUES(period_end),
                    status = VALUES(status),
                    source = VALUES(source),
                    duration_ms = VALUES(duration_ms),
                    orders_count = VALUES(orders_count),
                    notes_count = VALUES(notes_count),
                    vendors_count = VALUES(vendors_count),
                    last_reconciliation_at = VALUES(last_reconciliation_at),
                    snapshot_generated_at = VALUES(snapshot_generated_at),
                    last_error = VALUES(last_error),
                    request_id = VALUES(request_id)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFlightPlanPayload(string $start, string $end): array
    {
        return $this->payloadBuilder()->buildFlightPlanPayload($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTargetsPayload(string $competencia): array
    {
        return $this->payloadBuilder()->buildTargetsPayload($competencia);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVendorsPayload(string $start, string $end): array
    {
        return $this->payloadBuilder()->buildVendorsPayload($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOverviewPayload(string $start, string $end): array
    {
        return $this->payloadBuilder()->buildOverviewPayload($start, $end);
    }

    private function withHistoricalMeta(array $payload, string $competencia, ?string $lastSync): array
    {
        return $this->competenciaSupport()->withHistoricalMeta($payload, $competencia, $lastSync);
    }

    private function normalizeVendorKey(string $name): string
    {
        return $this->competenciaSupport()->normalizeVendorKey($name);
    }

    private function periodLabel(string $start, string $end): string
    {
        return $this->competenciaSupport()->periodLabel($start, $end);
    }

    private function screenColumn(string $screen): string
    {
        return $this->competenciaSupport()->screenColumn($screen);
    }

    private function encodeJson(array $payload): string
    {
        return $this->competenciaSupport()->encodeJson($payload);
    }

    private function competenciaSupport(): CompetenciaSupport
    {
        return $this->competenciaSupport ??= new CompetenciaSupport();
    }

    private function payloadBuilder(): CompetenciaPayloadBuilder
    {
        return $this->payloadBuilder ??= new CompetenciaPayloadBuilder(
            $this->canonical,
            $this->targetsRepository,
            $this->saturdayWeight,
            $this->competenciaSupport()
        );
    }
}
