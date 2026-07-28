<?php

declare(strict_types=1);

namespace BIomie\Notifications;

use PDO;
use Throwable;

/**
 * Barramento único de notificações (sininho in-app + painel admin + digests).
 *
 * Multi-empresa: company_id NULL = global; senão pertence a uma empresa. A
 * leitura é por usuário (biomie_notification_reads). A listagem SEMPRE filtra
 * pelas empresas que o usuário acessa — mesma garantia de isolamento do resto
 * do sistema.
 *
 * Tolerante: se as tabelas ainda não existirem (migration pendente), os métodos
 * degradam para no-op/vazio em vez de quebrar a página.
 */
final class NotificationService
{
    public const CATEGORIES = ['sync', 'webhook', 'business', 'security', 'system'];
    public const SEVERITIES = ['info', 'success', 'warning', 'critical'];
    /** high = pedidos/clientes/faturamento (núcleo comercial); low = ruído operacional. */
    public const PRIORITIES = ['high', 'normal', 'low'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Publica uma notificação. Com dedupe_key + janela, evita repetir a mesma
     * notificação em curto intervalo (ex.: "EXPERT sem webhook há 6h").
     *
     * @param array{
     *   category:string, title:string, severity?:string, priority?:string, icon?:?string, body?:?string,
     *   link?:?string, company_id?:?int, source?:?string,
     *   dedupe_key?:?string, dedupe_window_seconds?:int, expires_at?:?string
     * } $n
     * @return int|null id criado, ou null se deduplicado/erro
     */
    public function push(array $n): ?int
    {
        $category = in_array($n['category'], self::CATEGORIES, true)
            ? $n['category'] : 'system';
        $severity = in_array((string) ($n['severity'] ?? ''), self::SEVERITIES, true)
            ? (string) $n['severity'] : 'info';
        $priority = in_array((string) ($n['priority'] ?? ''), self::PRIORITIES, true)
            ? (string) $n['priority'] : 'normal';
        $icon = isset($n['icon']) ? substr(trim((string) $n['icon']), 0, 40) : null;
        $title = trim($n['title']);
        if ($title === '') {
            return null;
        }
        $companyId  = isset($n['company_id']) && (int) $n['company_id'] > 0 ? (int) $n['company_id'] : null;
        $dedupeKey  = isset($n['dedupe_key']) && trim((string) $n['dedupe_key']) !== ''
            ? substr(trim((string) $n['dedupe_key']), 0, 191) : null;
        $window     = max(0, (int) ($n['dedupe_window_seconds'] ?? 0));

        try {
            if ($dedupeKey !== null && $window > 0 && $this->recentlyPushed($dedupeKey, $companyId, $window)) {
                return null;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO biomie_notifications
                    (company_id, category, severity, priority, icon, title, body, link, source, dedupe_key, created_at, expires_at)
                 VALUES
                    (:company_id, :category, :severity, :priority, :icon, :title, :body, :link, :source, :dedupe_key, NOW(), :expires_at)'
            );
            $stmt->execute([
                ':company_id' => $companyId,
                ':category'   => $category,
                ':severity'   => $severity,
                ':priority'   => $priority,
                ':icon'       => $icon,
                ':title'      => substr($title, 0, 200),
                ':body'       => isset($n['body']) ? (string) $n['body'] : null,
                ':link'       => isset($n['link']) ? substr((string) $n['link'], 0, 255) : null,
                ':source'     => isset($n['source']) ? substr((string) $n['source'], 0, 64) : null,
                ':dedupe_key' => $dedupeKey,
                ':expires_at' => $n['expires_at'] ?? null,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<int> $allowedCompanyIds
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, array $allowedCompanyIds, int $limit = 30, bool $unreadOnly = false): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $scope = $this->companyScopeSql($allowedCompanyIds);
            $unread = $unreadOnly ? ' AND r.notification_id IS NULL ' : '';
            // Não-lidas de alta prioridade (pedido/cliente/NF) primeiro; depois
            // por recência. Notificação já lida não "sobe" só por ser importante.
            $sql = "SELECT n.id, n.company_id, n.category, n.severity, n.priority, n.icon, n.title, n.body, n.link,
                           n.source, n.created_at,
                           (r.notification_id IS NOT NULL) AS is_read
                      FROM biomie_notifications n
                 LEFT JOIN biomie_notification_reads r
                        ON r.notification_id = n.id AND r.user_id = :uid
                     WHERE {$scope}
                       AND (n.expires_at IS NULL OR n.expires_at > NOW())
                       {$unread}
                  ORDER BY (r.notification_id IS NULL AND n.priority = 'high') DESC,
                           n.created_at DESC, n.id DESC
                     LIMIT {$limit}";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':uid' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['company_id'] = $row['company_id'] !== null ? (int) $row['company_id'] : null;
                $row['is_read'] = (bool) $row['is_read'];
            }
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<int> $allowedCompanyIds */
    public function unreadCountForUser(int $userId, array $allowedCompanyIds): int
    {
        try {
            $scope = $this->companyScopeSql($allowedCompanyIds);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM biomie_notifications n
              LEFT JOIN biomie_notification_reads r
                     ON r.notification_id = n.id AND r.user_id = :uid
                  WHERE {$scope}
                    AND (n.expires_at IS NULL OR n.expires_at > NOW())
                    AND r.notification_id IS NULL"
            );
            $stmt->execute([':uid' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function markRead(int $userId, int $notificationId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO biomie_notification_reads (notification_id, user_id, read_at)
                 VALUES (:nid, :uid, NOW())'
            );
            $stmt->execute([':nid' => $notificationId, ':uid' => $userId]);
        } catch (Throwable) {
        }
    }

    /** @param list<int> $allowedCompanyIds */
    public function markAllRead(int $userId, array $allowedCompanyIds): int
    {
        try {
            $scope = $this->companyScopeSql($allowedCompanyIds);
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO biomie_notification_reads (notification_id, user_id, read_at)
                 SELECT n.id, :uid, NOW()
                   FROM biomie_notifications n
              LEFT JOIN biomie_notification_reads r
                     ON r.notification_id = n.id AND r.user_id = :uid2
                  WHERE {$scope}
                    AND (n.expires_at IS NULL OR n.expires_at > NOW())
                    AND r.notification_id IS NULL"
            );
            $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    private function recentlyPushed(string $dedupeKey, ?int $companyId, int $windowSeconds): bool
    {
        $companyCond = $companyId === null ? 'company_id IS NULL' : 'company_id = :cid';
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM biomie_notifications
              WHERE dedupe_key = :k AND {$companyCond}
                AND created_at >= (NOW() - INTERVAL {$windowSeconds} SECOND)
              LIMIT 1"
        );
        $params = [':k' => $dedupeKey];
        if ($companyId !== null) {
            $params[':cid'] = $companyId;
        }
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Filtro de empresa: globais (NULL) + empresas que o usuário acessa.
     * IDs são inteiros literais (sem injeção).
     *
     * @param list<int> $allowedCompanyIds
     */
    private function companyScopeSql(array $allowedCompanyIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $allowedCompanyIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 'n.company_id IS NULL';
        }
        return '(n.company_id IS NULL OR n.company_id IN (' . implode(',', $ids) . '))';
    }
}
