<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use Closure;
use PDO;

final class CanonicalSyncStateRepository
{
    private const TABLE_SYNC_STATE = 'biomie_sync_state';

    public function __construct(
        private readonly Closure $pdoResolver,
        private readonly Closure $withReconnect,
        private readonly int $companyId,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSyncState(string $start, string $end, string $syncName = 'orders_sync'): ?array
    {
        $this->ensureStateTableExists();

        return $this->fetchSyncStateByPeriod($syncName, $start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function prepareSyncState(
        string $start,
        string $end,
        string $source,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): array {
        $this->ensureStateTableExists();

        $currentState = $this->fetchSyncStateByPeriod($syncName, $start, $end);
        $isSamePeriod = $currentState !== null
            && ($currentState['period_start'] ?? null) === $start
            && ($currentState['period_end'] ?? null) === $end;
        $canResume = $isSamePeriod && empty($currentState['completed_at']);

        $state = [
            'sync_name' => $syncName,
            'phase' => $phase,
            'source' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'checkpoint_date' => $canResume ? ($currentState['checkpoint_date'] ?? $start) : $start,
            'checkpoint_page' => $canResume ? (int) ($currentState['checkpoint_page'] ?? 1) : 1,
            'checkpoint_batch' => $canResume ? (int) ($currentState['checkpoint_batch'] ?? 0) : 0,
            'vendors_synced' => 0,
            'orders_inserted' => 0,
            'orders_updated' => 0,
            'orders_unchanged' => 0,
            'orders_skipped' => 0,
            'last_error' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => null,
            'next_allowed_at' => null,
            'last_sync' => null,
        ];

        $this->updateSyncState($state);

        return $this->fetchSyncStateByPeriod($syncName, $start, $end) ?? $state;
    }

    public function markVendorsSynced(
        string $start,
        string $end,
        int $vendorsSynced,
        string $source,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->updateSyncState([
            'sync_name' => $syncName,
            'phase' => $phase,
            'source' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'vendors_synced' => $vendorsSynced,
            'last_error' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $orderStats
     */
    public function advanceOrdersCheckpoint(
        string $start,
        string $end,
        string $checkpointDate,
        int $checkpointPage,
        int $checkpointBatch,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->updateSyncState([
            'sync_name' => $syncName,
            'phase' => $phase,
            'source' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'checkpoint_date' => $checkpointDate,
            'checkpoint_page' => $checkpointPage,
            'checkpoint_batch' => $checkpointBatch,
            'vendors_synced' => $vendorsSynced,
            'orders_inserted' => (int) ($orderStats['inserted'] ?? 0),
            'orders_updated' => (int) ($orderStats['updated'] ?? 0),
            'orders_unchanged' => (int) ($orderStats['unchanged'] ?? 0),
            'orders_skipped' => (int) ($orderStats['skipped'] ?? 0),
            'last_error' => null,
            'next_allowed_at' => null,
            'last_sync' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string,mixed> $orderStats
     */
    public function markSyncCompleted(
        string $start,
        string $end,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        ?string $checkpointDate = null,
        int $checkpointPage = 1,
        int $checkpointBatch = 0,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $now = date('Y-m-d H:i:s');

        $this->updateSyncState([
            'sync_name' => $syncName,
            'phase' => $phase,
            'source' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'checkpoint_date' => $checkpointDate,
            'checkpoint_page' => $checkpointPage,
            'checkpoint_batch' => $checkpointBatch,
            'vendors_synced' => $vendorsSynced,
            'orders_inserted' => (int) ($orderStats['inserted'] ?? 0),
            'orders_updated' => (int) ($orderStats['updated'] ?? 0),
            'orders_unchanged' => (int) ($orderStats['unchanged'] ?? 0),
            'orders_skipped' => (int) ($orderStats['skipped'] ?? 0),
            'last_error' => null,
            'completed_at' => $now,
            'next_allowed_at' => null,
            'last_sync' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $orderStats
     */
    public function markSyncFailed(
        string $start,
        string $end,
        string $message,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        ?string $checkpointDate = null,
        ?int $checkpointPage = null,
        ?int $checkpointBatch = null,
        ?string $nextAllowedAt = null,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->updateSyncState([
            'sync_name' => $syncName,
            'phase' => $phase,
            'source' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'checkpoint_date' => $checkpointDate,
            'checkpoint_page' => $checkpointPage,
            'checkpoint_batch' => $checkpointBatch,
            'vendors_synced' => $vendorsSynced,
            'orders_inserted' => (int) ($orderStats['inserted'] ?? 0),
            'orders_updated' => (int) ($orderStats['updated'] ?? 0),
            'orders_unchanged' => (int) ($orderStats['unchanged'] ?? 0),
            'orders_skipped' => (int) ($orderStats['skipped'] ?? 0),
            'last_error' => $message,
            'completed_at' => null,
            'next_allowed_at' => $nextAllowedAt,
        ]);
    }

    /**
     * @param array<string,mixed> $state
     */
    public function updateSyncState(array $state): void
    {
        ($this->withReconnect)(function (PDO $pdo) use ($state): void {
            $this->ensureStateTableExists();
            $currentState = $this->fetchSyncStateByPeriod(
                (string) $state['sync_name'],
                (string) $state['period_start'],
                (string) $state['period_end']
            );
            $record = $this->buildSyncStateRecord($state, $currentState);

            $sql = <<<'SQL'
                INSERT INTO biomie_sync_state (
                    company_id, sync_name, phase, source,
                    period_start, period_end,
                    checkpoint_date, checkpoint_page, checkpoint_batch,
                    vendors_synced,
                    orders_inserted, orders_updated, orders_unchanged, orders_skipped,
                    last_error, started_at, completed_at, next_allowed_at, last_sync, updated_at
                )
                VALUES (
                    :company_id, :sync_name, :phase, :source,
                    :period_start, :period_end,
                    :checkpoint_date, :checkpoint_page, :checkpoint_batch,
                    :vendors_synced,
                    :orders_inserted, :orders_updated, :orders_unchanged, :orders_skipped,
                    :last_error, :started_at, :completed_at, :next_allowed_at, :last_sync, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    source = VALUES(source),
                    period_start = VALUES(period_start),
                    period_end = VALUES(period_end),
                    checkpoint_date = VALUES(checkpoint_date),
                    checkpoint_page = VALUES(checkpoint_page),
                    checkpoint_batch = VALUES(checkpoint_batch),
                    vendors_synced = VALUES(vendors_synced),
                    orders_inserted = VALUES(orders_inserted),
                    orders_updated = VALUES(orders_updated),
                    orders_unchanged = VALUES(orders_unchanged),
                    orders_skipped = VALUES(orders_skipped),
                    last_error = VALUES(last_error),
                    started_at = VALUES(started_at),
                    completed_at = VALUES(completed_at),
                    next_allowed_at = VALUES(next_allowed_at),
                    last_sync = VALUES(last_sync),
                    updated_at = NOW()
            SQL;

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['company_id' => $this->companyId] + $record);
        });
    }

    private function ensureStateTableExists(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sync_state (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sync_name VARCHAR(64) NOT NULL DEFAULT 'canonical_orders',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                phase ENUM('idle','vendors_synced','orders_running','completed','failed','chunked') NOT NULL DEFAULT 'idle',
                source VARCHAR(32) NOT NULL DEFAULT 'cron',
                checkpoint_date DATE DEFAULT NULL,
                checkpoint_page INT UNSIGNED DEFAULT NULL,
                checkpoint_batch INT UNSIGNED DEFAULT NULL,
                vendors_synced INT UNSIGNED NOT NULL DEFAULT 0,
                orders_inserted INT UNSIGNED NOT NULL DEFAULT 0,
                orders_updated INT UNSIGNED NOT NULL DEFAULT 0,
                orders_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
                orders_skipped INT UNSIGNED NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                next_allowed_at DATETIME DEFAULT NULL,
                last_sync DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_biomie_sync_state_period (sync_name, period_start, period_end),
                KEY idx_biomie_sync_state_phase (phase),
                KEY idx_biomie_sync_state_checkpoint (checkpoint_date, checkpoint_page, checkpoint_batch),
                KEY idx_biomie_sync_state_last_sync (last_sync)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL;

        ($this->withReconnect)(static function (PDO $pdo) use ($sql): void {
            $pdo->exec($sql);
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchSyncStateByPeriod(string $syncName, string $start, string $end): ?array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                sync_name,
                phase,
                source,
                period_start,
                period_end,
                checkpoint_date,
                checkpoint_page,
                checkpoint_batch,
                vendors_synced,
                orders_inserted,
                orders_updated,
                orders_unchanged,
                orders_skipped,
                last_error,
                started_at,
                completed_at,
                next_allowed_at,
                last_sync,
                updated_at
            FROM biomie_sync_state
            WHERE company_id = :company_id
              AND sync_name = :name
              AND period_start = :start
              AND period_end = :end
            LIMIT 1
        SQL;

        return ($this->withReconnect)(function (PDO $pdo) use ($sql, $syncName, $start, $end): ?array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'company_id' => $this->companyId,
                'name' => $syncName,
                'start' => $start,
                'end' => $end,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        });
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $currentState
     * @return array<string,mixed>
     */
    private function buildSyncStateRecord(array $state, ?array $currentState): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'sync_name' => (string) $state['sync_name'],
            'phase' => (string) $state['phase'],
            'source' => $this->pickSyncStateValue($state, $currentState, 'source'),
            'period_start' => $this->pickSyncStateValue($state, $currentState, 'period_start'),
            'period_end' => $this->pickSyncStateValue($state, $currentState, 'period_end'),
            'checkpoint_date' => $this->pickSyncStateValue($state, $currentState, 'checkpoint_date'),
            'checkpoint_page' => (int) $this->pickSyncStateValue($state, $currentState, 'checkpoint_page', 1),
            'checkpoint_batch' => (int) $this->pickSyncStateValue($state, $currentState, 'checkpoint_batch', 0),
            'vendors_synced' => (int) $this->pickSyncStateValue($state, $currentState, 'vendors_synced', 0),
            'orders_inserted' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_inserted', 0),
            'orders_updated' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_updated', 0),
            'orders_unchanged' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_unchanged', 0),
            'orders_skipped' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_skipped', 0),
            'last_error' => $this->pickSyncStateValue($state, $currentState, 'last_error'),
            'started_at' => $this->pickSyncStateValue($state, $currentState, 'started_at', $now),
            'completed_at' => $this->pickSyncStateValue($state, $currentState, 'completed_at'),
            'next_allowed_at' => $this->pickSyncStateValue($state, $currentState, 'next_allowed_at'),
            'last_sync' => $this->pickSyncStateValue($state, $currentState, 'last_sync'),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $currentState
     */
    private function pickSyncStateValue(array $state, ?array $currentState, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $state)) {
            return $state[$key];
        }

        if ($currentState !== null && array_key_exists($key, $currentState)) {
            return $currentState[$key];
        }

        return $default;
    }
}
