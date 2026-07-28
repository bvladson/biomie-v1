<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
use Closure;
use PDO;

final class BronzeRepository
{
    private const TABLE_RAW = 'biomie_omie_bronze';

    private bool $schemaReady = false;
    private int $lastConnectionActivityAt;

    public function __construct(
        private PDO $pdo,
        private readonly Logger $logger,
        private readonly ?int $tenantCompanyId = null,
        private readonly ?Closure $reconnect = null,
    ) {
        $this->lastConnectionActivityAt = time();
    }

    public static function boot(Config $config, Logger $logger): ?self
    {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($config, $connection)) {
            return null;
        }

        try {
            $repository = new self(
                Database::connect($config, $connection),
                $logger,
                null,
                static fn (): PDO => Database::reconnect($config, $connection),
            );
            $repository->ensureSchema();
            return $repository;
        } catch (\Throwable $exception) {
            $logger->warning('Camada bronze indisponivel; seguindo sem persistencia bruta.', [
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

        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_omie_bronze (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                domain_name VARCHAR(32) NOT NULL,
                entity_name VARCHAR(64) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                omie_method VARCHAR(64) NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'runtime',
                request_id VARCHAR(64) NULL,
                natural_key VARCHAR(191) NULL,
                provider_key VARCHAR(191) NULL,
                request_hash CHAR(64) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                idempotency_key CHAR(64) NOT NULL,
                request_params_json LONGTEXT NOT NULL,
                payload_json LONGTEXT NOT NULL,
                metadata_json LONGTEXT NULL,
                ingested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bronze_idempotency (idempotency_key),
                KEY idx_bronze_domain_entity_ingested (domain_name, entity_name, ingested_at),
                KEY idx_bronze_natural (natural_key),
                KEY idx_bronze_provider (provider_key),
                KEY idx_bronze_payload_hash (payload_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $this->withReconnect(static function (PDO $pdo) use ($sql): void {
            $pdo->exec($sql);
        });
        $this->schemaReady = true;
    }

    /**
     * @param array<string,mixed> $requestParams
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    public function captureRecord(
        string $domain,
        string $entityName,
        string $endpoint,
        string $omieMethod,
        array $requestParams,
        array $payload,
        ?string $naturalKey = null,
        ?string $providerKey = null,
        array $metadata = [],
        string $source = 'runtime',
    ): bool {
        try {
            $this->ensureSchema();
            $companyId = $this->companyId();
            $metadata['company_id'] = $companyId;

            $fingerprint = OperationalPayloadFingerprint::bronzeRecord(
                $domain,
                $entityName,
                $endpoint,
                $omieMethod,
                $requestParams,
                $payload,
                $naturalKey,
                $providerKey,
                $metadata,
                $source
            );

            $sql = <<<'SQL'
                INSERT IGNORE INTO biomie_omie_bronze (
                    company_id, domain_name, entity_name, endpoint, omie_method, source, request_id,
                    natural_key, provider_key, request_hash, payload_hash, idempotency_key,
                    request_params_json, payload_json, metadata_json, ingested_at
                ) VALUES (
                    :company_id, :domain_name, :entity_name, :endpoint, :omie_method, :source, :request_id,
                    :natural_key, :provider_key, :request_hash, :payload_hash, :idempotency_key,
                    :request_params_json, :payload_json, :metadata_json, NOW()
                )
            SQL;

            $this->withReconnect(static function (PDO $pdo) use (
                $sql,
                $companyId,
                $domain,
                $entityName,
                $endpoint,
                $omieMethod,
                $source,
                $naturalKey,
                $providerKey,
                $fingerprint,
            ): void {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'company_id' => $companyId,
                    'domain_name' => $domain,
                    'entity_name' => $entityName,
                    'endpoint' => $endpoint,
                    'omie_method' => $omieMethod,
                    'source' => $source,
                    'request_id' => RequestContext::id(),
                    'natural_key' => $naturalKey !== null && $naturalKey !== '' ? $naturalKey : null,
                    'provider_key' => $providerKey !== null && $providerKey !== '' ? $providerKey : null,
                    'request_hash' => $fingerprint['request_hash'],
                    'payload_hash' => $fingerprint['payload_hash'],
                    'idempotency_key' => $fingerprint['idempotency_key'],
                    'request_params_json' => $fingerprint['request_params_json'],
                    'payload_json' => $fingerprint['payload_json'],
                    'metadata_json' => $fingerprint['metadata_json'],
                ]);
            });

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao persistir payload bruto na camada bronze.', [
                'domain' => $domain,
                'entity' => $entityName,
                'method' => $omieMethod,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param list<string> $domains
     * @return list<array<string,mixed>>
     */
    public function findRetroactiveChanges(string $start, string $end, array $domains = [], int $limit = 50): array
    {
        $this->ensureSchema();

        $sql = <<<'SQL'
            SELECT
                domain_name,
                entity_name,
                natural_key,
                provider_key,
                COUNT(*) AS records_count,
                COUNT(DISTINCT payload_hash) AS distinct_payloads,
                MIN(ingested_at) AS first_ingested_at,
                MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE company_id = :company_id
              AND natural_key IS NOT NULL
              AND natural_key <> ''
              AND DATE(ingested_at) BETWEEN :start AND :end
        SQL;

        $params = [
            'company_id' => $this->companyId(),
            'start' => $start,
            'end' => $end,
        ];

        if ($domains !== []) {
            $placeholders = [];
            foreach (array_values($domains) as $index => $domain) {
                $key = 'domain_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $domain;
            }
            $sql .= ' AND domain_name IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= <<<'SQL'
            GROUP BY domain_name, entity_name, natural_key, provider_key
            HAVING COUNT(DISTINCT payload_hash) > 1
            ORDER BY MAX(ingested_at) DESC
            LIMIT :lim
        SQL;

        return $this->withReconnect(static function (PDO $pdo) use ($sql, $params, $limit): array {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':lim', max(1, min($limit, 500)), PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    private function companyId(): int
    {
        return $this->tenantCompanyId ?? TenantContext::companyId();
    }

    /**
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    private function withReconnect(callable $callback): mixed
    {
        $this->ensureLiveConnection();

        try {
            $result = $callback($this->pdo);
            $this->lastConnectionActivityAt = time();
            return $result;
        } catch (\Throwable $exception) {
            if (!$this->shouldReconnect($exception) || !$this->reconnect instanceof Closure) {
                throw $exception;
            }

            $pdo = ($this->reconnect)();
            if (!$pdo instanceof PDO) {
                throw $exception;
            }

            $this->pdo = $pdo;
            $this->lastConnectionActivityAt = time();
            $this->logger->warning('Camada bronze reconectada apos perda de conexao MariaDB.');

            $result = $callback($this->pdo);
            $this->lastConnectionActivityAt = time();
            return $result;
        }
    }

    private function ensureLiveConnection(): void
    {
        if (time() - $this->lastConnectionActivityAt < 15) {
            return;
        }

        try {
            @$this->pdo->query('SELECT 1');
            $this->lastConnectionActivityAt = time();
        } catch (\Throwable $exception) {
            if (!$this->shouldReconnect($exception) || !$this->reconnect instanceof Closure) {
                throw $exception;
            }

            $pdo = ($this->reconnect)();
            if (!$pdo instanceof PDO) {
                throw $exception;
            }

            $this->pdo = $pdo;
            $this->lastConnectionActivityAt = time();
            $this->logger->warning('Camada bronze reconectada apos probe de conexao MariaDB.');
        }
    }

    private function shouldReconnect(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'error while sending query')
            || str_contains($message, 'sqlstate[hy000]')
            || str_contains($message, 'general error: 2006')
            || str_contains($message, 'general error: 2013');
    }
}
