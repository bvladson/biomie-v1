<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Auth;
use BIomie\Logger;
use PDO;
use Throwable;

final class AdminLogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * @param array<string,mixed>|null $details
     */
    public function log(string $eventType, ?int $targetUserId = null, ?array $details = null, ?int $actorUserId = null): void
    {
        $actor = $actorUserId ?? (int) (Auth::currentUser()['id'] ?? 0) ?: null;
        $details = $this->sanitizeDetails($details ?? []);

        try {
            if ($this->adminLogTableExists()) {
                $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO biomie_admin_logs (actor_user_id, target_user_id, event_type, route, ip_address, user_agent, details_json)
VALUES (:actor_user_id, :target_user_id, :event_type, :route, :ip_address, :user_agent, :details_json)
SQL);
                $stmt->execute([
                    ':actor_user_id' => $actor,
                    ':target_user_id' => $targetUserId,
                    ':event_type' => mb_substr($eventType, 0, 60),
                    ':route' => mb_substr($this->route(), 0, 255),
                    ':ip_address' => mb_substr($this->clientIp(), 0, 45),
                    ':user_agent' => mb_substr($this->userAgent(), 0, 255),
                    ':details_json' => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]);
                return;
            }

            Auth::repository()->recordLog(
                mb_substr($eventType, 0, 40),
                $actor,
                Auth::currentUser()['username'] ?? null,
                $this->route(),
                $this->clientIp(),
                $this->userAgent(),
                $details
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('Falha ao gravar log administrativo.', [
                'event_type' => $eventType,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 100): array
    {
        try {
            if (!$this->adminLogTableExists()) {
                return [];
            }

            $stmt = $this->pdo->prepare(<<<'SQL'
SELECT l.id, l.event_type, l.route, l.ip_address, l.user_agent, l.details_json, l.created_at,
       actor.username AS actor_username, actor.full_name AS actor_full_name,
       target.username AS target_username, target.full_name AS target_full_name
FROM biomie_admin_logs l
LEFT JOIN biomie_users actor ON actor.id = l.actor_user_id
LEFT JOIN biomie_users target ON target.id = l.target_user_id
ORDER BY l.id DESC
LIMIT :limit
SQL);
            $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $this->logger?->warning('Falha ao listar logs administrativos.', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    private function adminLogTableExists(): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute([':table' => 'biomie_admin_logs']);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function sanitizeDetails(array $details): array
    {
        $safe = [];
        foreach ($details as $key => $value) {
            $safeKey = (string) $key;
            if (preg_match('/password|senha|token|secret|app_key|app_secret|csrf/i', $safeKey) === 1) {
                $safe[$safeKey] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                /** @var array<string,mixed> $nested */
                $nested = $value;
                $safe[$safeKey] = $this->sanitizeDetails($nested);
                continue;
            }

            if (is_string($value)) {
                $safe[$safeKey] = mb_substr($value, 0, 500);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$safeKey] = $value;
            }
        }

        return $safe;
    }

    private function route(): string
    {
        return trim((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?: '/';
    }

    private function clientIp(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
    }

    private function userAgent(): string
    {
        return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')) ?: 'unknown';
    }
}
