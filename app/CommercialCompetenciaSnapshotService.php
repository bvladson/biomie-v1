<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Competencia\CompetenciaPayloadBuilder;
use BIomie\Competencia\CompetenciaSupport;
use BIomie\GoldMartRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final class CommercialCompetenciaSnapshotService
{
    private const SNAPSHOT_TABLE = 'biomie_competencia_snapshots';
    private const CATALOG_TABLE = 'biomie_competencia_sync_catalog';
    private const SNAPSHOT_TENANT_UNIQUE = 'uq_company_scope_competencia_snap';
    private const CATALOG_TENANT_UNIQUE = 'uq_company_scope_competencia_cat';
    private const REVENUE_TOLERANCE = 0.01;
    /**
     * Version 3 adds strict freshness plus company/channel/visibility
     * isolation. Earlier snapshots cannot prove that complete contract.
     */
    private const SCHEMA_VERSION = 3;
    private ?CompetenciaSupport $competenciaSupport = null;
    private ?CompetenciaPayloadBuilder $payloadBuilder = null;
    /** @var array<string,array{fingerprint:string,valid:bool}> */
    private array $snapshotValidationCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly CanonicalRepository $canonical,
        private readonly ?TargetsRepository $targetsRepository,
        private readonly float $saturdayWeight = 0.33,
        private readonly ?GoldMartRepository $goldMart = null,
    ) {
    }

    /**
     * Empresa ativa (tenant). Snapshots são isolados por (company_id, competencia) —
     * sem isto, o snapshot de uma empresa vazaria para as outras (mesma competência).
     */
    private function companyId(): int
    {
        return \BIomie\Support\TenantContext::companyId();
    }

    private function businessChannel(): string
    {
        $channel = strtolower(trim(\BIomie\Support\TenantContext::canal()));
        // mixed ("Todas as operações") tem snapshot próprio: a chave única da
        // tabela inclui business_channel, então nunca se mistura com varejo.
        if (!in_array($channel, ['varejo', 'licitacao', 'mixed', 'consolidado'], true)) {
            throw new \LogicException('Canal ausente ou invalido para snapshot comercial.');
        }
        return $channel;
    }

    /** Stable fingerprint for the company set visible in this context. */
    private function visibilityScope(): string
    {
        $companyIds = \BIomie\Support\TenantContext::allowedCompanyIds();
        sort($companyIds, SORT_NUMERIC);

        return substr(hash('sha256', implode('|', [
            (string) $this->companyId(),
            $this->businessChannel(),
            implode(',', $companyIds),
        ])), 0, 32);
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_competencia_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                business_channel VARCHAR(16) NOT NULL DEFAULT 'varejo',
                visibility_scope VARCHAR(32) NOT NULL DEFAULT 'legacy',
                competencia CHAR(7) NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'canonical',
                schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                overview_payload LONGTEXT NULL,
                vendors_payload LONGTEXT NULL,
                flight_plan_payload LONGTEXT NULL,
                targets_payload LONGTEXT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_sync DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_company_scope_competencia_snap (company_id, business_channel, visibility_scope, competencia),
                KEY idx_biomie_competencia_snapshot_last_sync (last_sync),
                KEY idx_biomie_competencia_snapshot_generated (generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_competencia_sync_catalog (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                business_channel VARCHAR(16) NOT NULL DEFAULT 'varejo',
                visibility_scope VARCHAR(32) NOT NULL DEFAULT 'legacy',
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
                UNIQUE KEY uq_company_scope_competencia_cat (company_id, business_channel, visibility_scope, competencia),
                KEY idx_biomie_competencia_catalog_status (status),
                KEY idx_biomie_competencia_catalog_generated (snapshot_generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Compatibility path for installations created before the multitenant
        // foundation migration. This only evolves columns/indexes; no row is
        // deleted or rewritten.
        $this->ensureScopedCompetenciaKey(self::SNAPSHOT_TABLE, self::SNAPSHOT_TENANT_UNIQUE);
        $this->ensureScopedCompetenciaKey(self::CATALOG_TABLE, self::CATALOG_TENANT_UNIQUE);
    }

    private function ensureScopedCompetenciaKey(string $table, string $targetIndex): void
    {
        if (!$this->schemaColumnExists($table, 'company_id')) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE `%s` ADD COLUMN company_id TINYINT UNSIGNED NOT NULL DEFAULT 1',
                $table,
            ));
        }

        if (!$this->schemaColumnExists($table, 'business_channel')) {
            $this->pdo->exec(sprintf(
                "ALTER TABLE `%s` ADD COLUMN business_channel VARCHAR(16) NOT NULL DEFAULT 'varejo' AFTER company_id",
                $table,
            ));
        }

        if (!$this->schemaColumnExists($table, 'visibility_scope')) {
            // Existing rows stay under `legacy`: they were generated without
            // an explicit visibility contract and therefore fail closed.
            $this->pdo->exec(sprintf(
                "ALTER TABLE `%s` ADD COLUMN visibility_scope VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER business_channel",
                $table,
            ));
        }

        $uniqueIndexes = $this->uniqueIndexColumns($table);
        $hasScopedUnique = false;
        $legacyIndexes = [];
        foreach ($uniqueIndexes as $indexName => $columns) {
            if ($columns === ['company_id', 'business_channel', 'visibility_scope', 'competencia']) {
                $hasScopedUnique = true;
            } elseif ($columns === ['competencia'] || $columns === ['company_id', 'competencia']) {
                $legacyIndexes[] = $indexName;
            }
        }

        if ($hasScopedUnique && $legacyIndexes === []) {
            return;
        }

        $alterClauses = [];
        foreach ($legacyIndexes as $legacyIndex) {
            $alterClauses[] = sprintf('DROP INDEX `%s`', $legacyIndex);
        }
        if (!$hasScopedUnique) {
            $alterClauses[] = sprintf(
                'ADD UNIQUE KEY `%s` (`company_id`, `business_channel`, `visibility_scope`, `competencia`)',
                $targetIndex,
            );
        }

        if ($alterClauses !== []) {
            // One atomic ALTER mirrors Version20260619000000 and avoids an
            // intermediate state without a uniqueness constraint.
            $this->pdo->exec(sprintf(
                'ALTER TABLE `%s` %s',
                $table,
                implode(', ', $alterClauses),
            ));
        }
    }

    private function schemaColumnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string,list<string>> */
    private function uniqueIndexColumns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND NON_UNIQUE = 0
                AND INDEX_NAME <> \'PRIMARY\'
              ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute(['table_name' => $table]);

        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexName = (string) ($row['INDEX_NAME'] ?? '');
            $columnName = (string) ($row['COLUMN_NAME'] ?? '');
            if ($indexName === '' || $columnName === '') {
                continue;
            }
            $indexes[$indexName] ??= [];
            $indexes[$indexName][] = $columnName;
        }

        return $indexes;
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
        // Web reads must never run blocking DDL. Until the scoped migration is
        // present, fail closed and let the caller use the canonical live path.
        foreach ([self::SNAPSHOT_TABLE, self::CATALOG_TABLE] as $table) {
            if (!$this->schemaColumnExists($table, 'business_channel')
                || !$this->schemaColumnExists($table, 'visibility_scope')
            ) {
                return null;
            }
        }

        $column = $this->screenColumn($screen);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT s.%s AS payload,
                    s.overview_payload AS overview_validation_payload,
                    s.period_start,
                    s.period_end,
                    s.generated_at,
                    s.last_sync,
                    s.source,
                    s.schema_version,
                    c.status AS catalog_status
               FROM %s s
               LEFT JOIN %s c
                 ON c.company_id = s.company_id
                AND c.business_channel = s.business_channel
                AND c.visibility_scope = s.visibility_scope
                AND c.competencia = s.competencia
              WHERE s.company_id = :company_id
                AND s.business_channel = :business_channel
                AND s.visibility_scope = :visibility_scope
                AND s.competencia = :competencia
              LIMIT 1',
            $column,
            self::SNAPSHOT_TABLE,
            self::CATALOG_TABLE,
        ));
        $stmt->execute([
            'company_id' => $this->companyId(),
            'business_channel' => $this->businessChannel(),
            'visibility_scope' => $this->visibilityScope(),
            'competencia' => $competencia,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (!$this->isSnapshotMetadataValid($row, $competencia)) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return null;
        }

        if (!$this->isSnapshotCanonicalConsistent($row, $competencia)) {
            return null;
        }

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['snapshot_competencia'] = $competencia;
        $payload['meta']['snapshot_generated_at'] = (string) ($row['generated_at'] ?? '');
        $payload['meta']['snapshot_last_sync'] = isset($row['last_sync']) ? (string) $row['last_sync'] : null;
        $payload['meta']['snapshot_source'] = (string) ($row['source'] ?? 'canonical');
        $payload['meta']['snapshot_schema_version'] = (int) ($row['schema_version'] ?? self::SCHEMA_VERSION);
        $payload['meta']['snapshot_business_channel'] = $this->businessChannel();
        $payload['meta']['snapshot_visibility_scope'] = $this->visibilityScope();
        $payload['meta']['served_from'] = 'historical-read-model';
        $payload['source'] = 'historical_snapshot';
        $payload['mode'] = (string) ($payload['mode'] ?? 'historical_snapshot');

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isSnapshotMetadataValid(array $row, string $competencia): bool
    {
        if ((int) ($row['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return false;
        }

        $expectedStart = $competencia . '-01';
        $expectedEnd = date('Y-m-t', strtotime($expectedStart));
        if ((string) ($row['period_start'] ?? '') !== $expectedStart
            || (string) ($row['period_end'] ?? '') !== $expectedEnd
        ) {
            return false;
        }

        $catalogStatus = strtolower(trim((string) ($row['catalog_status'] ?? '')));
        return $catalogStatus === 'ready';
    }

    /**
     * Rejects snapshots that are older than Silver/canonical or whose overview
     * totals diverge from the current canonical fiscal totals. Validation is
     * fail-closed: inability to prove consistency must not serve stale history.
     *
     * @param array<string,mixed> $row
     */
    private function isSnapshotCanonicalConsistent(array $row, string $competencia): bool
    {
        $periodStart = (string) ($row['period_start'] ?? ($competencia . '-01'));
        $periodEnd = (string) ($row['period_end'] ?? date('Y-m-t', strtotime($periodStart)));
        $cacheKey = implode(':', [
            (string) $this->companyId(),
            $this->businessChannel(),
            $this->visibilityScope(),
            $competencia,
        ]);
        $fingerprint = hash('sha256', implode('|', [
            (string) ($row['schema_version'] ?? ''),
            (string) ($row['generated_at'] ?? ''),
            (string) ($row['last_sync'] ?? ''),
            hash('sha256', (string) ($row['overview_validation_payload'] ?? '')),
        ]));
        $cached = $this->snapshotValidationCache[$cacheKey] ?? null;
        if (is_array($cached) && $cached['fingerprint'] === $fingerprint) {
            return $cached['valid'];
        }

        try {
            $canonicalLastSync = $this->canonical->lastSyncAt($periodStart, $periodEnd);
            $snapshotLastSync = trim((string) ($row['last_sync'] ?? ''));

            if ($canonicalLastSync !== null && trim($canonicalLastSync) !== '') {
                if ($snapshotLastSync === '') {
                    return $this->rememberSnapshotValidation($cacheKey, $fingerprint, false);
                }

                $canonicalTimestamp = strtotime($canonicalLastSync);
                $snapshotTimestamp = strtotime($snapshotLastSync);
                if ($canonicalTimestamp === false
                    || $snapshotTimestamp === false
                    || $canonicalTimestamp > $snapshotTimestamp
                ) {
                    return $this->rememberSnapshotValidation($cacheKey, $fingerprint, false);
                }
            }

            $canonicalSummary = $this->canonical->summarizeInvoicesCommercial($periodStart, $periodEnd);
            $canonicalOrders = (int) ($canonicalSummary['billed_orders'] ?? 0);
            $canonicalRevenue = Support::toFloat($canonicalSummary['billed_value'] ?? 0);

            $overview = json_decode((string) ($row['overview_validation_payload'] ?? ''), true);
            if (!is_array($overview)) {
                return $this->rememberSnapshotValidation($cacheKey, $fingerprint, false);
            }
            $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
            $snapshotOrders = (int) ($summary['orders'] ?? $summary['billed_orders'] ?? 0);
            $snapshotRevenue = Support::toFloat(
                $summary['revenue']
                    ?? $summary['gross_revenue']
                    ?? $summary['billed_value']
                    ?? 0
            );

            $ordersMatch = $canonicalOrders === $snapshotOrders;
            $revenueDelta = abs($canonicalRevenue - $snapshotRevenue);
            $revenueMatches = $revenueDelta <= self::REVENUE_TOLERANCE + 0.000001;
            $dailyMatches = $this->snapshotDailyMatchesCanonical($overview, $periodStart, $periodEnd);
            $vendorMatches = $this->snapshotVendorsMatchCanonical($overview, $periodStart, $periodEnd);
            $valid = $ordersMatch && $revenueMatches && $dailyMatches && $vendorMatches;

            if (!$valid) {
                $this->logger->warning('Snapshot historico diverge do resumo canonical.', [
                    'competencia' => $competencia,
                    'canonical_orders' => $canonicalOrders,
                    'snapshot_orders' => $snapshotOrders,
                    'canonical_revenue' => $canonicalRevenue,
                    'snapshot_revenue' => $snapshotRevenue,
                    'revenue_delta' => $revenueDelta,
                    'daily_matches' => $dailyMatches,
                    'vendor_matches' => $vendorMatches,
                    'request_id' => RequestContext::id(),
                ]);
            }

            return $this->rememberSnapshotValidation($cacheKey, $fingerprint, $valid);
        } catch (Throwable $exception) {
            $this->logger->warning('Nao foi possivel validar a atualidade do snapshot historico.', [
                'competencia' => $competencia,
                'message' => $exception->getMessage(),
                'request_id' => RequestContext::id(),
            ]);

            return $this->rememberSnapshotValidation($cacheKey, $fingerprint, false);
        }
    }

    private function rememberSnapshotValidation(
        string $cacheKey,
        string $fingerprint,
        bool $valid,
    ): bool {
        $this->snapshotValidationCache[$cacheKey] = [
            'fingerprint' => $fingerprint,
            'valid' => $valid,
        ];

        return $valid;
    }

    /** @param array<string,mixed> $overview */
    private function snapshotDailyMatchesCanonical(array $overview, string $start, string $end): bool
    {
        $expected = [];
        foreach ($this->canonical->dailyByStatusCommercial($start, $end) as $row) {
            if ((string) ($row['status_comercial'] ?? '') !== 'faturado') {
                continue;
            }
            $date = (string) ($row['dia'] ?? '');
            if ($date === '') {
                continue;
            }
            $expected[$date] ??= ['value' => 0.0, 'orders' => 0];
            $expected[$date]['value'] += Support::toFloat($row['total'] ?? 0);
            $expected[$date]['orders'] += (int) ($row['pedidos'] ?? 0);
        }

        $actual = [];
        foreach ((array) ($overview['daily_sales'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string) ($row['date'] ?? $row['dia'] ?? '');
            if ($date === '') {
                continue;
            }
            $value = Support::toFloat(
                $row['gross_revenue'] ?? $row['revenue'] ?? $row['billed_value'] ?? 0
            );
            $orders = (int) ($row['orders'] ?? $row['billed_orders'] ?? 0);
            if (abs($value) <= self::REVENUE_TOLERANCE && $orders === 0) {
                continue;
            }
            $actual[$date] ??= ['value' => 0.0, 'orders' => 0];
            $actual[$date]['value'] += $value;
            $actual[$date]['orders'] += $orders;
        }

        return $this->sameGroupedSnapshotValues($expected, $actual);
    }

    /** @param array<string,mixed> $overview */
    private function snapshotVendorsMatchCanonical(array $overview, string $start, string $end): bool
    {
        $expected = [];
        foreach ($this->canonical->aggregateInvoicesByVendorCommercial($start, $end) as $row) {
            $key = $this->snapshotVendorKey(
                (string) ($row['codigo_vendedor_omie'] ?? ''),
                (string) ($row['nome_vendedor'] ?? ''),
            );
            if ($key === '') {
                continue;
            }
            $expected[$key] ??= ['value' => 0.0, 'orders' => 0];
            $expected[$key]['value'] += Support::toFloat($row['total'] ?? 0);
            $expected[$key]['orders'] += (int) ($row['pedidos'] ?? 0);
        }

        $actual = [];
        foreach ((array) ($overview['vendor_ranking'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->snapshotVendorKey(
                (string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''),
                (string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? ''),
            );
            if ($key === '') {
                continue;
            }
            $actual[$key] ??= ['value' => 0.0, 'orders' => 0];
            $actual[$key]['value'] += Support::toFloat(
                $row['gross_revenue'] ?? $row['revenue'] ?? $row['total'] ?? 0
            );
            $actual[$key]['orders'] += (int) ($row['orders'] ?? $row['pedidos'] ?? 0);
        }

        return $this->sameGroupedSnapshotValues($expected, $actual);
    }

    private function snapshotVendorKey(string $vendorId, string $vendorName): string
    {
        $vendorId = trim($vendorId);
        if ($vendorId !== '') {
            return 'id:' . $vendorId;
        }
        $vendorName = trim($vendorName);
        return $vendorName !== '' ? 'name:' . $this->normalizeVendorKey($vendorName) : '';
    }

    /**
     * @param array<string,array{value:float,orders:int}> $expected
     * @param array<string,array{value:float,orders:int}> $actual
     */
    private function sameGroupedSnapshotValues(array $expected, array $actual): bool
    {
        ksort($expected);
        ksort($actual);
        if (array_keys($expected) !== array_keys($actual)) {
            return false;
        }
        foreach ($expected as $key => $row) {
            if ($row['orders'] !== $actual[$key]['orders']
                || abs($row['value'] - $actual[$key]['value']) > self::REVENUE_TOLERANCE
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{overview:array<string,mixed>,vendors:array<string,mixed>,flight_plan:array<string,mixed>,targets:array<string,mixed>,meta:array<string,mixed>}
     */
    public function materializeCompetencia(string $competencia, bool $force = false): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $competencia) !== 1) {
            throw new \InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
        }

        $lockSet = \BIomie\Support\CommercialDataLockSet::acquire(
            $this->pdo,
            $this->companyId(),
            $this->logger,
        );
        if ($lockSet === null) {
            throw new \RuntimeException(
                'Sincronizacao fiscal ou materializacao Gold em execucao; tente o snapshot novamente.'
            );
        }

        try {
            return $this->materializeCompetenciaWhileLocked($competencia, $force);
        } finally {
            $lockSet->release();
        }
    }

    /**
     * @return array{overview:array<string,mixed>,vendors:array<string,mixed>,flight_plan:array<string,mixed>,targets:array<string,mixed>,meta:array<string,mixed>}
     */
    private function materializeCompetenciaWhileLocked(string $competencia, bool $force): array
    {

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
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::CATALOG_TABLE . '
              WHERE company_id = :company_id
                AND business_channel = :business_channel
                AND visibility_scope = :visibility_scope
                AND competencia = :competencia
              LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $this->companyId(),
            'business_channel' => $this->businessChannel(),
            'visibility_scope' => $this->visibilityScope(),
            'competencia' => $competencia,
        ]);
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
                    company_id, business_channel, visibility_scope,
                    competencia, period_start, period_end, source, schema_version,
                    overview_payload, vendors_payload, flight_plan_payload, targets_payload,
                    generated_at, last_sync
                ) VALUES (
                    :company_id, :business_channel, :visibility_scope,
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
            'company_id' => $this->companyId(),
            'business_channel' => $this->businessChannel(),
            'visibility_scope' => $this->visibilityScope(),
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
        $payload = [
            'company_id' => $this->companyId(),
            'business_channel' => $this->businessChannel(),
            'visibility_scope' => $this->visibilityScope(),
        ] + $values + $defaults;

        $sql = 'INSERT INTO ' . self::CATALOG_TABLE . ' (
                    company_id, business_channel, visibility_scope,
                    competencia, period_start, period_end, status, source,
                    duration_ms, orders_count, notes_count, vendors_count,
                    last_reconciliation_at, snapshot_generated_at, last_error, request_id
                ) VALUES (
                    :company_id, :business_channel, :visibility_scope,
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
            $this->competenciaSupport(),
            $this->goldMart,
        );
    }
}
