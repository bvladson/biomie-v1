<?php

declare(strict_types=1);

namespace BIomie\Omie;

use BIomie\Cache;
use BIomie\Company\CompanyPackageLoader;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Database;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\Support\SecretCipher;
use Closure;
use PDO;
use RuntimeException;

/**
 * Creates and pools OmieClient instances keyed by company_id.
 * Credentials are read exclusively from biomie_company_integrations.
 *
 * Usage:
 *   $factory = new OmieClientFactory($pdo, $logger, $config, $cache);
 *   $client  = $factory->forCompany(TenantContext::companyId());
 */
final class OmieClientFactory
{
    /** @var array<int, OmieClient> Per-request pool keyed by company_id. */
    private array $pool = [];
    private int $lastConnectionActivityAt;

    public function __construct(
        private PDO              $pdo,
        private readonly Logger  $logger,
        private readonly Config  $config,
        private readonly ?Cache  $cache = null,
        private readonly ?Closure $reconnect = null,
    ) {
        $this->lastConnectionActivityAt = time();
    }

    public static function boot(Config $config, Logger $logger, ?Cache $cache = null): self
    {
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        return new self(Database::connect($config, $connection), $logger, $config, $cache);
    }

    /** Get (or create) an OmieClient for the given company. */
    public function forCompany(int $companyId): OmieClient
    {
        if (isset($this->pool[$companyId])) {
            return $this->pool[$companyId];
        }

        [$appKey, $appSecret, $slug] = $this->loadCredentials($companyId);

        // Use an isolated per-company cache when we have the slug.
        // Falls back to the shared global cache if slug is unavailable.
        $companyCache = $slug !== '' ? Cache::forCompanySlug($slug) : $this->cache;

        $this->pool[$companyId] = new OmieClient(
            $appKey,
            $appSecret,
            (int) $this->config->get('omie.request_timeout', 20),
            $this->logger,
            new OmieMethodPolicyRegistry($this->config),
            $companyCache,
            "company-{$companyId}",
        );

        return $this->pool[$companyId];
    }

    /** @return array{string, string, string} [appKey, appSecret, slug] */
    private function loadCredentials(int $companyId): array
    {
        $row = $this->withReconnect(function (PDO $pdo) use ($companyId): array|false {
            $stmt = $pdo->prepare(<<<'SQL'
                SELECT i.app_key,
                       i.app_secret_encrypted,
                       c.slug
                  FROM biomie_companies c
                  JOIN biomie_company_integrations i
                    ON i.company_id = c.id
                   AND i.provider = 'omie'
                   AND i.active = 1
                 WHERE c.id = :id AND c.ativo = 1
                 LIMIT 1
            SQL);
            $stmt->execute(['id' => $companyId]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        });

        if (!$row) {
            throw new RuntimeException(
                "No active Omie integration for company_id={$companyId}."
            );
        }

        $appKey = trim((string) ($row['app_key'] ?? ''));
        $encrypted = trim((string) ($row['app_secret_encrypted'] ?? ''));
        $cipher = new SecretCipher((string) $this->config->get('app.secret_key', ''));
        $appSecret = $encrypted !== '' ? $cipher->decrypt($encrypted) : '';

        if ($appKey === '' || $appSecret === '') {
            throw new RuntimeException(
                "No active Omie credentials for company_id={$companyId}."
            );
        }

        $this->withReconnect(static function (PDO $pdo) use ($companyId): void {
            $pdo->prepare(
                "UPDATE biomie_company_integrations
                    SET last_used_at = NOW()
                  WHERE company_id = :id AND provider = 'omie'"
            )->execute(['id' => $companyId]);
        });

        return [$appKey, $appSecret, (string) ($row['slug'] ?? '')];
    }

    public function resolveWebhookCompanyId(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $id = $this->withReconnect(static function (PDO $pdo) use ($token): mixed {
            $stmt = $pdo->prepare(
                "SELECT company_id
                   FROM biomie_company_integrations
                  WHERE provider = 'omie'
                    AND webhook_token_hash = :token_hash
                    AND active = 1
                  LIMIT 1"
            );
            $stmt->execute(['token_hash' => hash('sha256', $token)]);

            return $stmt->fetchColumn();
        });

        return $id !== false ? (int) $id : null;
    }

    public function resolveWebhookCompanyIdForSlug(string $slug, string $token): ?int
    {
        $canonicalSlug = strtolower(trim($slug));
        $slug = strtoupper($canonicalSlug);
        $token = trim($token);
        if ($slug === '' || $token === '') {
            return null;
        }

        $id = $this->withReconnect(static function (PDO $pdo) use ($slug, $token): mixed {
            $stmt = $pdo->prepare(
                "SELECT i.company_id
                   FROM biomie_company_integrations i
                   JOIN biomie_companies c ON c.id = i.company_id AND c.ativo = 1
                  WHERE i.provider = 'omie'
                    AND i.webhook_token_hash = :token_hash
                    AND i.active = 1
                    AND c.slug = :slug
                  LIMIT 1"
            );
            $stmt->execute([
                'token_hash' => hash('sha256', $token),
                'slug' => $slug,
            ]);

            return $stmt->fetchColumn();
        });

        if ($id === false) {
            return null;
        }

        $companyId = (int) $id;
        $manifest = CompanyPackageLoader::load($canonicalSlug);
        return $manifest->matchesIdentity($canonicalSlug, $companyId)
            ? $companyId
            : null;
    }

    public function markWebhookReceived(int $companyId): void
    {
        $this->withReconnect(static function (PDO $pdo) use ($companyId): void {
            $pdo->prepare(
                "UPDATE biomie_company_integrations
                    SET webhook_last_received_at = NOW()
                  WHERE company_id = :id
                    AND provider = 'omie'
                    AND active = 1"
            )->execute(['id' => $companyId]);
        });
    }

    public function markSuccess(int $companyId): void
    {
        $this->withReconnect(static function (PDO $pdo) use ($companyId): void {
            $stmt = $pdo->prepare(
                "UPDATE biomie_company_integrations
                    SET last_success_at = NOW(),
                        last_error_at = NULL
                  WHERE company_id = :id
                    AND provider = 'omie'
                    AND active = 1"
            );
            $stmt->execute(['id' => $companyId]);
        });
    }

    public function markFailure(int $companyId): void
    {
        $this->withReconnect(static function (PDO $pdo) use ($companyId): void {
            $stmt = $pdo->prepare(
                "UPDATE biomie_company_integrations
                    SET last_error_at = NOW()
                  WHERE company_id = :id
                    AND provider = 'omie'"
            );
            $stmt->execute(['id' => $companyId]);
        });
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
            if (!$this->isConnectionLoss($exception)) {
                throw $exception;
            }

            $connection = Database::isConfigured($this->config, 'flight_plan_db')
                ? 'flight_plan_db'
                : 'db';
            $this->pdo = $this->reconnectPdo($connection);
            $this->lastConnectionActivityAt = time();
            $this->logger->warning('Fabrica Omie reconectada apos perda de conexao MariaDB.', [
                'connection' => $connection,
            ]);

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
            if (!$this->isConnectionLoss($exception)) {
                throw $exception;
            }

            $connection = Database::isConfigured($this->config, 'flight_plan_db')
                ? 'flight_plan_db'
                : 'db';
            $this->pdo = $this->reconnectPdo($connection);
            $this->lastConnectionActivityAt = time();
            $this->logger->warning('Fabrica Omie reconectada apos probe de conexao MariaDB.', [
                'connection' => $connection,
            ]);
        }
    }

    private function isConnectionLoss(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'error while sending query')
            || str_contains($message, 'sqlstate[hy000]')
            || str_contains($message, 'general error: 2006')
            || str_contains($message, 'general error: 2013');
    }

    private function reconnectPdo(string $connection): PDO
    {
        if ($this->reconnect instanceof Closure) {
            $pdo = ($this->reconnect)();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }

        return Database::reconnect($this->config, $connection);
    }
}
