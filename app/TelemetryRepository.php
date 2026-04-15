<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

final class TelemetryRepository
{
    private const TABLE_EVENTS = 'biomie_operational_events';
    private const TABLE_METRICS = 'biomie_operational_metrics';
    private const TABLE_SPANS = 'biomie_operational_spans';

    private bool $schemaReady = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    public static function boot(Config $config, Logger $logger): ?self
    {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($config, $connection)) {
            return null;
        }

        try {
            $repository = new self(Database::connect($config, $connection), $logger);
            $repository->ensureSchema();
            return $repository;
        } catch (Throwable $exception) {
            $logger->warning('Observabilidade persistente indisponivel; seguindo com telemetria em memoria/log.', [
                'message' => $exception->getMessage(),
                'connection' => $connection,
            ]);

            return null;
        }
    }

    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_operational_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                level_name VARCHAR(16) NOT NULL DEFAULT 'INFO',
                event_name VARCHAR(96) NOT NULL,
                domain_name VARCHAR(32) NULL,
                source_name VARCHAR(64) NULL,
                request_id VARCHAR(64) NULL,
                correlation_id VARCHAR(64) NULL,
                trace_id CHAR(32) NULL,
                span_id CHAR(16) NULL,
                summary VARCHAR(255) NOT NULL DEFAULT '',
                context_json LONGTEXT NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_operational_events_occurred (occurred_at),
                KEY idx_operational_events_event (event_name, occurred_at),
                KEY idx_operational_events_correlation (correlation_id, occurred_at),
                KEY idx_operational_events_domain (domain_name, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_operational_metrics (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                metric_name VARCHAR(96) NOT NULL,
                metric_kind ENUM('counter','gauge','histogram') NOT NULL DEFAULT 'gauge',
                domain_name VARCHAR(32) NULL,
                source_name VARCHAR(64) NULL,
                request_id VARCHAR(64) NULL,
                correlation_id VARCHAR(64) NULL,
                trace_id CHAR(32) NULL,
                labels_json LONGTEXT NULL,
                numeric_value DECIMAL(18,6) NOT NULL DEFAULT 0,
                observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_operational_metrics_name_time (metric_name, observed_at),
                KEY idx_operational_metrics_domain_time (domain_name, observed_at),
                KEY idx_operational_metrics_correlation (correlation_id, observed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_operational_spans (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                trace_id CHAR(32) NOT NULL,
                span_id CHAR(16) NOT NULL,
                parent_span_id CHAR(16) NULL,
                request_id VARCHAR(64) NULL,
                correlation_id VARCHAR(64) NULL,
                span_name VARCHAR(128) NOT NULL,
                domain_name VARCHAR(32) NULL,
                source_name VARCHAR(64) NULL,
                route_name VARCHAR(255) NULL,
                status_name VARCHAR(32) NOT NULL DEFAULT 'ok',
                attributes_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NOT NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_operational_trace_span (trace_id, span_id),
                KEY idx_operational_spans_trace (trace_id, started_at),
                KEY idx_operational_spans_correlation (correlation_id, started_at),
                KEY idx_operational_spans_name (span_name, started_at),
                KEY idx_operational_spans_domain (domain_name, started_at),
                KEY idx_operational_spans_status (status_name, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->schemaReady = true;
    }

    /**
     * @param array<string,mixed> $record
     */
    public function recordEvent(array $record): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_operational_events (
                level_name, event_name, domain_name, source_name,
                request_id, correlation_id, trace_id, span_id,
                summary, context_json, occurred_at
            ) VALUES (
                :level_name, :event_name, :domain_name, :source_name,
                :request_id, :correlation_id, :trace_id, :span_id,
                :summary, :context_json, :occurred_at
            )
        SQL);

        $stmt->execute([
            'level_name' => strtoupper((string) ($record['level_name'] ?? 'INFO')),
            'event_name' => (string) ($record['event_name'] ?? 'event'),
            'domain_name' => $this->nullableString($record['domain_name'] ?? null),
            'source_name' => $this->nullableString($record['source_name'] ?? null),
            'request_id' => $this->nullableString($record['request_id'] ?? null),
            'correlation_id' => $this->nullableString($record['correlation_id'] ?? null),
            'trace_id' => $this->nullableString($record['trace_id'] ?? null),
            'span_id' => $this->nullableString($record['span_id'] ?? null),
            'summary' => $this->truncate((string) ($record['summary'] ?? ''), 255),
            'context_json' => $this->encodeJson($record['context'] ?? null),
            'occurred_at' => $this->normalizeTimestamp($record['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $record
     */
    public function recordMetric(array $record): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_operational_metrics (
                metric_name, metric_kind, domain_name, source_name,
                request_id, correlation_id, trace_id,
                labels_json, numeric_value, observed_at
            ) VALUES (
                :metric_name, :metric_kind, :domain_name, :source_name,
                :request_id, :correlation_id, :trace_id,
                :labels_json, :numeric_value, :observed_at
            )
        SQL);

        $stmt->execute([
            'metric_name' => (string) ($record['metric_name'] ?? 'metric'),
            'metric_kind' => $this->metricKind((string) ($record['metric_kind'] ?? 'gauge')),
            'domain_name' => $this->nullableString($record['domain_name'] ?? null),
            'source_name' => $this->nullableString($record['source_name'] ?? null),
            'request_id' => $this->nullableString($record['request_id'] ?? null),
            'correlation_id' => $this->nullableString($record['correlation_id'] ?? null),
            'trace_id' => $this->nullableString($record['trace_id'] ?? null),
            'labels_json' => $this->encodeJson($record['labels'] ?? null),
            'numeric_value' => round((float) ($record['numeric_value'] ?? 0), 6),
            'observed_at' => $this->normalizeTimestamp($record['observed_at'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $record
     */
    public function recordSpan(array $record): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_operational_spans (
                trace_id, span_id, parent_span_id,
                request_id, correlation_id,
                span_name, domain_name, source_name, route_name,
                status_name, attributes_json, result_json,
                started_at, ended_at, duration_ms
            ) VALUES (
                :trace_id, :span_id, :parent_span_id,
                :request_id, :correlation_id,
                :span_name, :domain_name, :source_name, :route_name,
                :status_name, :attributes_json, :result_json,
                :started_at, :ended_at, :duration_ms
            )
            ON DUPLICATE KEY UPDATE
                parent_span_id = VALUES(parent_span_id),
                request_id = VALUES(request_id),
                correlation_id = VALUES(correlation_id),
                span_name = VALUES(span_name),
                domain_name = VALUES(domain_name),
                source_name = VALUES(source_name),
                route_name = VALUES(route_name),
                status_name = VALUES(status_name),
                attributes_json = VALUES(attributes_json),
                result_json = VALUES(result_json),
                started_at = VALUES(started_at),
                ended_at = VALUES(ended_at),
                duration_ms = VALUES(duration_ms)
        SQL);

        $stmt->execute([
            'trace_id' => (string) ($record['trace_id'] ?? ''),
            'span_id' => (string) ($record['span_id'] ?? ''),
            'parent_span_id' => $this->nullableString($record['parent_span_id'] ?? null),
            'request_id' => $this->nullableString($record['request_id'] ?? null),
            'correlation_id' => $this->nullableString($record['correlation_id'] ?? null),
            'span_name' => (string) ($record['span_name'] ?? 'span'),
            'domain_name' => $this->nullableString($record['domain_name'] ?? null),
            'source_name' => $this->nullableString($record['source_name'] ?? null),
            'route_name' => $this->nullableString($record['route_name'] ?? null),
            'status_name' => $this->nullableString($record['status_name'] ?? 'ok') ?? 'ok',
            'attributes_json' => $this->encodeJson($record['attributes'] ?? null),
            'result_json' => $this->encodeJson($record['result'] ?? null),
            'started_at' => $this->normalizeTimestamp($record['started_at'] ?? null),
            'ended_at' => $this->normalizeTimestamp($record['ended_at'] ?? null),
            'duration_ms' => max(0, (int) ($record['duration_ms'] ?? 0)),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentEvents(int $hours = 24, int $limit = 50): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE_EVENTS . '
             WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY occurred_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':hours', max(1, min($hours, 720)), PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min($limit, 500)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentSpans(int $hours = 24, int $limit = 200): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE_SPANS . '
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY started_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':hours', max(1, min($hours, 720)), PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min($limit, 1000)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function metricRows(string $metricName, int $hours = 24, int $limit = 1000): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE_METRICS . '
             WHERE metric_name = :metric_name
               AND observed_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY observed_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':metric_name', $metricName);
        $stmt->bindValue(':hours', max(1, min($hours, 720)), PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min($limit, 5000)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeShadowDiffs(int $hours = 24): array
    {
        $this->ensureSchema();
        try {
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT endpoint_name, severity, diff_type, COUNT(*) AS total
                FROM biomie_shadow_validations
                WHERE executed_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                GROUP BY endpoint_name, severity, diff_type
                ORDER BY COUNT(*) DESC, endpoint_name ASC
            SQL);
            $stmt->bindValue(':hours', max(1, min($hours, 720)), PDO::PARAM_INT);
            $stmt->execute();

            $byEndpoint = [];
            $criticalOpen = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $endpoint = (string) ($row['endpoint_name'] ?? 'unknown');
                $severity = (string) ($row['severity'] ?? 'none');
                $diffType = (string) ($row['diff_type'] ?? 'match');
                $total = (int) ($row['total'] ?? 0);

                $byEndpoint[$endpoint]['endpoint'] = $endpoint;
                $byEndpoint[$endpoint]['total'] = (int) (($byEndpoint[$endpoint]['total'] ?? 0) + $total);
                $byEndpoint[$endpoint]['by_severity'][$severity] = (int) (($byEndpoint[$endpoint]['by_severity'][$severity] ?? 0) + $total);
                $byEndpoint[$endpoint]['by_diff_type'][$diffType] = (int) (($byEndpoint[$endpoint]['by_diff_type'][$diffType] ?? 0) + $total);

                if (in_array($severity, ['high', 'critical'], true) && $diffType !== 'match') {
                    $criticalOpen += $total;
                }
            }

            return [
                'critical_open_total' => $criticalOpen,
                'by_endpoint' => array_values($byEndpoint),
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('Shadow validation ainda nao possui tabela consultavel para observabilidade.', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'critical_open_total' => 0,
                'by_endpoint' => [],
            ];
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, max(0, $maxLength - 3)) . '...';
    }

    private function encodeJson(mixed $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? null : $json;
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function metricKind(string $kind): string
    {
        $normalized = strtolower(trim($kind));
        if (in_array($normalized, ['counter', 'gauge', 'histogram'], true)) {
            return $normalized;
        }

        return 'gauge';
    }
}
