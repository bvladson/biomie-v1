<?php

declare(strict_types=1);

namespace BIomie;

use PDO;

final class AuthRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    password_changed_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_biomie_users_username (username),
    UNIQUE KEY uniq_biomie_users_email (email),
    KEY idx_biomie_users_active (is_active),
    KEY idx_biomie_users_active_role (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS biomie_access_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(40) NOT NULL,
    username_attempted VARCHAR(160) DEFAULT NULL,
    route VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    details_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_biomie_access_logs_user (user_id),
    KEY idx_biomie_access_logs_event (event_type),
    KEY idx_biomie_access_logs_created (created_at),
    KEY idx_biomie_access_logs_event_username_created (event_type, username_attempted, created_at),
    KEY idx_biomie_access_logs_event_ip_created (event_type, ip_address, created_at),
    CONSTRAINT fk_biomie_access_logs_user FOREIGN KEY (user_id) REFERENCES biomie_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function countUsers(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM biomie_users');
        return (int) $stmt->fetchColumn();
    }

    public function listUsers(): array
    {
        $stmt = $this->pdo->query(<<<'SQL'
SELECT id, username, full_name, email, role, is_active, last_login_at, created_at, updated_at
FROM biomie_users
ORDER BY is_active DESC, role DESC, full_name ASC, username ASC
SQL);

        return $stmt->fetchAll() ?: [];
    }

    public function listLogs(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT l.id, l.event_type, l.username_attempted, l.route, l.ip_address, l.user_agent, l.details_json, l.created_at,
       u.username, u.full_name
FROM biomie_access_logs l
LEFT JOIN biomie_users u ON u.id = l.user_id
ORDER BY l.id DESC
LIMIT :limit
SQL);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function findUserForLogin(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT id, username, full_name, email, password_hash, role, is_active
FROM biomie_users
WHERE username = :ident1 OR email = :ident2
LIMIT 1
SQL);
        $stmt->execute([':ident1' => $identifier, ':ident2' => $identifier]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findActiveUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT id, username, full_name, email, role, is_active, last_login_at
FROM biomie_users
WHERE id = :id AND is_active = 1
LIMIT 1
SQL);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT id, username, full_name, email, role, is_active, last_login_at, password_changed_at, created_at, updated_at
FROM biomie_users
WHERE id = :id
LIMIT 1
SQL);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function createFirstAdmin(string $username, string $fullName, ?string $email, string $password): int
    {
        return $this->createUser($username, $fullName, $email, $password, 'admin', true, null);
    }

    public function createUser(
        string $username,
        string $fullName,
        ?string $email,
        string $password,
        string $role = 'user',
        bool $isActive = true,
        ?int $actorId = null,
    ): int {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO biomie_users (username, full_name, email, password_hash, role, is_active, password_changed_at, created_by, updated_by)
VALUES (:username, :full_name, :email, :password_hash, :role, :is_active, NOW(), :created_by, :updated_by)
SQL);
        $stmt->execute([
            ':username' => $username,
            ':full_name' => $fullName,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':is_active' => $isActive ? 1 : 0,
            ':created_by' => $actorId,
            ':updated_by' => $actorId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateUser(int $userId, string $fullName, ?string $email, string $role, bool $isActive, ?int $actorId = null): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE biomie_users
SET full_name = :full_name,
    email = :email,
    role = :role,
    is_active = :is_active,
    updated_by = :updated_by
WHERE id = :id
SQL);
        $stmt->execute([
            ':id' => $userId,
            ':full_name' => $fullName,
            ':email' => $email,
            ':role' => $role,
            ':is_active' => $isActive ? 1 : 0,
            ':updated_by' => $actorId,
        ]);
    }

    public function updatePassword(int $userId, string $password, ?int $actorId = null): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE biomie_users
SET password_hash = :password_hash,
    password_changed_at = NOW(),
    updated_by = :updated_by
WHERE id = :id
SQL);
        $stmt->execute([
            ':id' => $userId,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_by' => $actorId,
        ]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE biomie_users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':id' => $userId,
            ':password_hash' => $passwordHash,
        ]);
    }

    public function touchLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE biomie_users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    public function countRecentFailedLogins(string $identifier, string $ipAddress, int $minutes = 15): int
    {
        $since = date('Y-m-d H:i:s', time() - (max(1, $minutes) * 60));
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM (
    SELECT id
    FROM biomie_access_logs
    WHERE event_type IN ('login_failed', 'login_blocked')
      AND username_attempted = :identifier
      AND created_at >= :since_identifier
    UNION
    SELECT id
    FROM biomie_access_logs
    WHERE event_type IN ('login_failed', 'login_blocked')
      AND ip_address = :ip_address
      AND ip_address <> ''
      AND created_at >= :since_ip
) recent_attempts
SQL);
        $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
        $stmt->bindValue(':since_identifier', $since, PDO::PARAM_STR);
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':since_ip', $since, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function recordLog(
        string $eventType,
        ?int $userId,
        ?string $usernameAttempted,
        ?string $route,
        ?string $ipAddress,
        ?string $userAgent,
        ?array $details = null,
    ): void {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO biomie_access_logs (user_id, event_type, username_attempted, route, ip_address, user_agent, details_json)
VALUES (:user_id, :event_type, :username_attempted, :route, :ip_address, :user_agent, :details_json)
SQL);
        $stmt->execute([
            ':user_id' => $userId,
            ':event_type' => $eventType,
            ':username_attempted' => $usernameAttempted,
            ':route' => $route,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ':details_json' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}
