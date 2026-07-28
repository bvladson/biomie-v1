<?php

declare(strict_types=1);

namespace BIomie\Observability;

use PDO;
use RuntimeException;

final class TelemetryRetentionService
{
    /**
     * @param array<string,int> $retentionDays
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $backupDirectory,
        private readonly array $retentionDays = [],
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function audit(): array
    {
        $result = [];
        foreach ($this->policies() as $policy) {
            $table = $policy['table'];
            $column = $policy['date_column'];
            $days = $policy['days'];
            $stmt = $this->pdo->query(sprintf(
                'SELECT COUNT(*) AS total_rows,
                        SUM(`%1$s` < DATE_SUB(NOW(), INTERVAL %2$d DAY)) AS eligible_rows,
                        MIN(`%1$s`) AS oldest_at,
                        MAX(`%1$s`) AS newest_at
                 FROM `%3$s`',
                $column,
                $days,
                $table,
            ));
            $row = $stmt !== false ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            $result[] = $policy + [
                'total_rows' => (int) ($row['total_rows'] ?? 0),
                'eligible_rows' => (int) ($row['eligible_rows'] ?? 0),
                'oldest_at' => $row['oldest_at'] ?? null,
                'newest_at' => $row['newest_at'] ?? null,
            ];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function run(bool $apply, int $batchSize = 1000, int $maxBatches = 100): array
    {
        $batchSize = max(100, min(5000, $batchSize));
        $maxBatches = max(1, min(1000, $maxBatches));
        $startedAt = microtime(true);
        $tables = [];

        foreach ($this->policies() as $policy) {
            $tables[] = $this->processTable($policy, $apply, $batchSize, $maxBatches);
        }

        return [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'dry-run',
            'generated_at' => date(DATE_ATOM),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'tables' => $tables,
        ];
    }

    /**
     * @param array{table:string,date_column:string,days:int} $policy
     * @return array<string,mixed>
     */
    private function processTable(array $policy, bool $apply, int $batchSize, int $maxBatches): array
    {
        $table = $policy['table'];
        $column = $policy['date_column'];
        $days = $policy['days'];
        $eligibleBefore = $this->eligibleCount($table, $column, $days);
        if (!$apply || $eligibleBefore === 0) {
            return $policy + [
                'eligible_before' => $eligibleBefore,
                'archived_rows' => 0,
                'deleted_rows' => 0,
                'eligible_after' => $eligibleBefore,
                'backup_file' => null,
            ];
        }

        $this->ensureBackupDirectory();
        $backupFile = $this->backupDirectory . DIRECTORY_SEPARATOR
            . sprintf('%s_before_%dd_%s.jsonl.gz', $table, $days, date('Ymd_His'));
        $handle = gzopen($backupFile, 'wb9');
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel criar backup de retencao: ' . $backupFile);
        }

        $archived = 0;
        $deleted = 0;
        $batches = 0;
        try {
            while ($batches < $maxBatches) {
                $rows = $this->eligibleBatch($table, $column, $days, $batchSize);
                if ($rows === []) {
                    break;
                }

                $ids = [];
                foreach ($rows as $row) {
                    $ids[] = (int) ($row['id'] ?? 0);
                    $json = json_encode(
                        $row,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                    if (!is_string($json) || gzwrite($handle, $json . "\n") === false) {
                        throw new RuntimeException('Falha ao gravar backup de retencao para ' . $table);
                    }
                    $archived++;
                }
                gzclose($handle);
                $handle = gzopen($backupFile, 'ab9');
                if ($handle === false) {
                    throw new RuntimeException('Falha ao reabrir backup de retencao para ' . $table);
                }
                $deleted += $this->deleteIds($table, array_values(array_filter($ids)));
                $batches++;
            }
        } finally {
            if (is_resource($handle)) {
                gzclose($handle);
            }
        }

        $eligibleAfter = $this->eligibleCount($table, $column, $days);
        $manifest = [
            'table' => $table,
            'date_column' => $column,
            'retention_days' => $days,
            'eligible_before' => $eligibleBefore,
            'archived_rows' => $archived,
            'deleted_rows' => $deleted,
            'eligible_after' => $eligibleAfter,
            'backup_file' => basename($backupFile),
            'backup_sha256' => hash_file('sha256', $backupFile) ?: null,
            'completed_at' => date(DATE_ATOM),
        ];
        file_put_contents(
            $backupFile . '.manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );

        return $policy + $manifest + ['batches' => $batches];
    }

    /** @return list<array<string,mixed>> */
    private function eligibleBatch(string $table, string $column, int $days, int $limit): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM `%s`
             WHERE `%s` < DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY id ASC
             LIMIT :limit',
            $table,
            $column,
            $days,
        ));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<int> $ids */
    private function deleteIds(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE id IN (%s)', $table, $placeholders));
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function eligibleCount(string $table, string $column, int $days): int
    {
        $stmt = $this->pdo->query(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE `%s` < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $table,
            $column,
            $days,
        ));
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    private function ensureBackupDirectory(): void
    {
        if (is_dir($this->backupDirectory)) {
            return;
        }
        if (!mkdir($this->backupDirectory, 0775, true) && !is_dir($this->backupDirectory)) {
            throw new RuntimeException('Nao foi possivel criar diretorio de backup: ' . $this->backupDirectory);
        }
    }

    /** @return list<array{table:string,date_column:string,days:int}> */
    private function policies(): array
    {
        return [
            [
                'table' => 'biomie_operational_spans',
                'date_column' => 'created_at',
                'days' => max(7, (int) ($this->retentionDays['spans'] ?? 30)),
            ],
            [
                'table' => 'biomie_operational_metrics',
                'date_column' => 'observed_at',
                'days' => max(30, (int) ($this->retentionDays['metrics'] ?? 90)),
            ],
            [
                'table' => 'biomie_operational_events',
                'date_column' => 'occurred_at',
                'days' => max(30, (int) ($this->retentionDays['events'] ?? 90)),
            ],
        ];
    }
}
