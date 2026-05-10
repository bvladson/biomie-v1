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
                    COUNT(*) AS pending_count,
                    MIN(received_at) AS oldest_received_at
                 FROM biomie_webhook_inbox
                 WHERE status IN ('recebido', 'persistido', 'enfileirado', 'falhou_temporariamente')"
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
