<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

use BIomie\Logger;
use BIomie\Telemetry;
use PDO;
use Throwable;

final class ShadowValidationRepository
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ShadowValidationFormatter $formatter,
        private readonly ?PDO $pdo = null,
        private readonly string $fallbackLogFile = '',
    ) {
    }

    /**
     * @param array<string,mixed> $record
     */
    public function persistRecord(array $record): void
    {
        $telemetry = Telemetry::global();
        $telemetry?->recordMetric('shadow_validation_total', 1, 'counter', [
            'endpoint' => (string) ($record['endpoint_name'] ?? 'unknown'),
            'diff_type' => (string) ($record['diff_type'] ?? 'match'),
            'severity' => (string) ($record['severity'] ?? 'none'),
        ], 'shadow', 'shadow_validation');

        if ((string) ($record['diff_type'] ?? 'match') !== 'match') {
            $telemetry?->recordEvent(
                (string) (($record['severity'] ?? 'none') === 'critical' ? 'ERROR' : 'WARNING'),
                'shadow.diff',
                (string) ($record['summary'] ?? 'Diferenca detectada em shadow validation.'),
                [
                    'endpoint' => $record['endpoint_name'] ?? null,
                    'diff_type' => $record['diff_type'] ?? null,
                    'severity' => $record['severity'] ?? null,
                    'request_id' => $record['request_id'] ?? null,
                ],
                'shadow',
                'shadow_validation'
            );
        }

        if ($this->pdo instanceof PDO) {
            try {
                $this->ensureSchema();
                $stmt = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO biomie_shadow_validations (
                        endpoint_name, query_signature, query_context_json,
                        legacy_payload_hash, candidate_payload_hash,
                        diff_type, severity, summary, diff_json,
                        sample_rate, sample_bucket, request_id,
                        legacy_source, candidate_source, executed_at
                    ) VALUES (
                        :endpoint_name, :query_signature, :query_context_json,
                        :legacy_payload_hash, :candidate_payload_hash,
                        :diff_type, :severity, :summary, :diff_json,
                        :sample_rate, :sample_bucket, :request_id,
                        :legacy_source, :candidate_source, NOW()
                    )
                SQL);
                $stmt->execute([
                    'endpoint_name' => $record['endpoint_name'],
                    'query_signature' => $record['query_signature'],
                    'query_context_json' => $record['query_context_json'],
                    'legacy_payload_hash' => $record['legacy_payload_hash'],
                    'candidate_payload_hash' => $record['candidate_payload_hash'],
                    'diff_type' => $record['diff_type'],
                    'severity' => $record['severity'],
                    'summary' => $this->formatter->truncate((string) $record['summary'], 250),
                    'diff_json' => $record['diff_json'],
                    'sample_rate' => $record['sample_rate'],
                    'sample_bucket' => $record['sample_bucket'],
                    'request_id' => $record['request_id'],
                    'legacy_source' => $record['legacy_source'],
                    'candidate_source' => $record['candidate_source'],
                ]);
                return;
            } catch (Throwable $exception) {
                $this->logger->warning('Persistencia do shadow em banco falhou; usando log.', [
                    'message' => $exception->getMessage(),
                    'endpoint' => $record['endpoint_name'] ?? null,
                ]);
            }
        }

        $dir = dirname($this->fallbackLogFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $this->fallbackLogFile,
            $this->formatter->encodeJson($record) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function ensureSchema(): void
    {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_shadow_validations (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              endpoint_name VARCHAR(64) NOT NULL,
              query_signature VARCHAR(64) NOT NULL,
              query_context_json LONGTEXT NULL,
              legacy_payload_hash VARCHAR(64) NULL,
              candidate_payload_hash VARCHAR(64) NULL,
              diff_type VARCHAR(64) NOT NULL DEFAULT 'match',
              severity ENUM('none','low','medium','high','critical') NOT NULL DEFAULT 'none',
              summary VARCHAR(255) NOT NULL DEFAULT '',
              diff_json LONGTEXT NULL,
              sample_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
              sample_bucket TINYINT UNSIGNED NOT NULL DEFAULT 0,
              request_id VARCHAR(64) NULL,
              legacy_source VARCHAR(64) NULL,
              candidate_source VARCHAR(64) NULL,
              executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_shadow_endpoint_executed (endpoint_name, executed_at),
              KEY idx_shadow_signature (query_signature),
              KEY idx_shadow_diff (diff_type, severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
}
