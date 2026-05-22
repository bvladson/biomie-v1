<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class WebhookInboxHealthCheck implements CheckInterface
{
    private const PENDING_WARN     = 10;
    private const PENDING_CRITICAL = 50;
    private const AGE_WARN_MIN     = 10;
    private const AGE_CRITICAL_MIN = 60;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'webhook_inbox'; }
    public function label(): string { return 'Webhook Inbox'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new \BIomie\SystemHealth\SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessível — check não executado.', [], [], date('c'), 0,
            );
        }

        try {
            $row = $this->pdo->query(
                "SELECT
                    SUM(CASE WHEN status IN ('recebido', 'persistido', 'enfileirado', 'falhou_temporariamente') THEN 1 ELSE 0 END) AS pending_count,
                    MIN(CASE WHEN status IN ('recebido', 'persistido', 'enfileirado', 'falhou_temporariamente') THEN received_at ELSE NULL END) AS oldest_received_at,
                    MAX(received_at) AS last_received_at,
                    SUM(CASE WHEN status IN ('processado') THEN 1 ELSE 0 END) AS processed_count,
                    SUM(CASE WHEN status IN ('ignorado_por_deduplicacao','duplicate') THEN 1 ELSE 0 END) AS duplicate_count,
                    SUM(CASE WHEN status IN ('falhou_definitivamente','rejected') THEN 1 ELSE 0 END) AS rejected_count
                 FROM biomie_webhook_inbox
                 WHERE status IN ('recebido', 'persistido', 'enfileirado', 'falhou_temporariamente')
                    OR received_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return new \BIomie\SystemHealth\SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Tabela biomie_webhook_inbox indisponível.',
                [], [], date('c'), $ms,
            );
        }

        $ms           = (int) round((microtime(true) - $start) * 1000);
        $pendingCount = (int) ($row['pending_count']    ?? 0);
        $oldestAt     = $row['oldest_received_at'] ?? null;
        $ageMin       = $oldestAt ? (int) round((time() - (int) strtotime($oldestAt)) / 60) : 0;

        $metrics = [
            'pending_count'      => $pendingCount,
            'oldest_received_at' => $oldestAt,
            'oldest_age_min'     => $oldestAt ? $ageMin : null,
            'last_received_at'   => $row['last_received_at'] ?? null,
            'processed_count_7d'  => (int) ($row['processed_count'] ?? 0),
            'duplicate_count_7d'  => (int) ($row['duplicate_count'] ?? 0),
            'rejected_count_7d'   => (int) ($row['rejected_count'] ?? 0),
        ];

        if ($pendingCount >= self::PENDING_CRITICAL || $ageMin >= self::AGE_CRITICAL_MIN) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "{$pendingCount} webhooks pendentes. Mais antigo há {$ageMin} min.",
                $metrics,
                ['Verificar worker de processamento de webhooks.', 'Ver logs em storage/logs/biomie.log.'],
                $ms,
            );
        }

        if ($pendingCount >= self::PENDING_WARN || $ageMin >= self::AGE_WARN_MIN) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "{$pendingCount} webhooks pendentes. Mais antigo há {$ageMin} min.",
                $metrics,
                ['Monitorar fila de webhooks.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            $pendingCount === 0
                ? 'Nenhum webhook pendente.'
                : "{$pendingCount} webhooks pendentes, mais antigo há {$ageMin} min.",
            $metrics,
            $ms,
        );
    }
}
