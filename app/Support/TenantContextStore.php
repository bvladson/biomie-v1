<?php

declare(strict_types=1);

namespace BIomie\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Persistência server-side dos identificadores opacos de contexto.
 *
 * Nenhuma informação de autorização é confiada ao token enviado pelo cliente.
 * O token referencia uma linha vinculada ao usuário e com validade/revogação.
 */
final class TenantContextStore
{
    public const DEFAULT_TTL_SECONDS = 43200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findOwned(string $contextId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT context_id, user_id, context_type, company_id,
                    allowed_company_ids_json, company_scope_map_json,
                    business_scope, role_name,
                    seller_scope_hash, permissions_version,
                    created_at, expires_at, last_used_at, revoked_at
               FROM biomie_user_contexts
              WHERE context_id = :context_id AND user_id = :user_id
              LIMIT 1'
        );
        $stmt->execute([
            'context_id' => $contextId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $allowedCompanyIds
     * @param array<int,list<string>> $allowedBusinessScopes
     */
    public function create(
        int $userId,
        string $type,
        ?int $companyId,
        array $allowedCompanyIds,
        array $allowedBusinessScopes,
        string $businessScope,
        string $role,
        string $sellerScopeHash,
        int $permissionsVersion,
        ?int $ttlSeconds = null,
    ): string {
        if (!in_array($type, ['company', 'group'], true)) {
            throw new InvalidArgumentException('Tipo de contexto inválido.');
        }
        if (($type === 'company' && ($companyId === null || $companyId <= 0))
            || ($type === 'group' && $companyId !== null)
        ) {
            throw new InvalidArgumentException('Relação entre tipo de contexto e empresa inválida.');
        }

        $ttl = $ttlSeconds ?? self::ttlSeconds();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->add(new DateInterval('PT' . $ttl . 'S'));
        $allowedJson = json_encode(
            array_values($allowedCompanyIds),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $scopeMapJson = json_encode(
            $allowedBusinessScopes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO biomie_user_contexts (
                context_id, user_id, context_type, company_id,
                allowed_company_ids_json, company_scope_map_json,
                business_scope, role_name,
                seller_scope_hash, permissions_version,
                created_at, expires_at, last_used_at, revoked_at
             ) VALUES (
                :context_id, :user_id, :context_type, :company_id,
                :allowed_ids, :scope_map, :business_scope, :role_name,
                :seller_scope_hash, :permissions_version,
                :created_at, :expires_at, :last_used_at, NULL
             )'
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $contextId = bin2hex(random_bytes(16));
            try {
                $stmt->execute([
                    'context_id' => $contextId,
                    'user_id' => $userId,
                    'context_type' => $type,
                    'company_id' => $companyId,
                    'allowed_ids' => $allowedJson,
                    'scope_map' => $scopeMapJson,
                    'business_scope' => $businessScope,
                    'role_name' => $role,
                    'seller_scope_hash' => $sellerScopeHash,
                    'permissions_version' => $permissionsVersion,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                    'last_used_at' => $now->format('Y-m-d H:i:s'),
                ]);
                return $contextId;
            } catch (PDOException $exception) {
                if (!self::isDuplicateKey($exception) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Não foi possível gerar um identificador de contexto.');
    }

    public function touch(string $contextId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biomie_user_contexts
                SET last_used_at = :last_used_at
              WHERE context_id = :context_id
                AND user_id = :user_id
                AND revoked_at IS NULL
                AND last_used_at < :touch_before'
        );
        $now = time();
        $stmt->execute([
            'last_used_at' => gmdate('Y-m-d H:i:s', $now),
            'touch_before' => gmdate('Y-m-d H:i:s', $now - 300),
            'context_id' => $contextId,
            'user_id' => $userId,
        ]);
    }

    public function revoke(string $contextId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biomie_user_contexts
                SET revoked_at = COALESCE(revoked_at, :revoked_at)
              WHERE context_id = :context_id AND user_id = :user_id'
        );
        $stmt->execute([
            'revoked_at' => gmdate('Y-m-d H:i:s'),
            'context_id' => $contextId,
            'user_id' => $userId,
        ]);
    }

    public function revokeAllForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biomie_user_contexts
                SET revoked_at = COALESCE(revoked_at, :revoked_at)
              WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('Y-m-d H:i:s'),
            'user_id' => $userId,
        ]);

        return $stmt->rowCount();
    }

    public function pruneExpired(int $retentionDays = 7): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * 86400);
        $stmt = $this->pdo->prepare(
            'DELETE FROM biomie_user_contexts
              WHERE expires_at < :cutoff
                 OR (revoked_at IS NOT NULL AND revoked_at < :cutoff)'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    public static function ttlSeconds(): int
    {
        $configured = filter_var(
            getenv('BIOMIE_CONTEXT_TTL_SECONDS') ?: self::DEFAULT_TTL_SECONDS,
            FILTER_VALIDATE_INT,
        );
        if (!is_int($configured)) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return max(900, min(86400, $configured));
    }

    private static function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19;
    }
}
