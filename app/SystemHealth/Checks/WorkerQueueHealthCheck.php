<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Bootstrap;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class WorkerQueueHealthCheck implements CheckInterface
{
    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string { return 'worker_queue'; }
    public function label(): string { return 'Worker e Fila'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $queue = new FileJobQueue(Bootstrap::rootPath('storage/cache'));
        $summary = $queue->summary();
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $pending  = (int) ($counts['queued'] ?? 0) + (int) ($counts['failed_retryable'] ?? 0);
        $running  = (int) ($counts['running'] ?? 0);
        $failedTerminal = (int) ($counts['failed_terminal'] ?? 0);
        $heartbeat = ['status' => 'unknown', 'age_seconds' => null];
        $dead = ['unresolved' => 0, 'last_24h' => 0];
        $hasSyncRuns = false;

        if ($this->pdo !== null) {
            try {
                $heartbeat = (new WorkerHeartbeatRepository($this->pdo))->summary();
                $dead = (new DeadLetterRepository($this->pdo))->counts();
                $hasSyncRuns = $this->hasSyncRunsHistory();
            } catch (Throwable) {
                // Mantem unknown sem quebrar o check.
            }
        }

        $heartbeatStatus = (string) ($heartbeat['status'] ?? 'unknown');
        $ageSeconds      = $heartbeat['age_seconds'] ?? null;
        $stuckRunning    = $this->stuckRunningCount($queue);
        $oldestPendingAge = $this->oldestPendingAgeSeconds($queue);

        $ms = (int) round((microtime(true) - $start) * 1000);
        $metrics = [
            'pending_jobs'              => $pending,
            'running_jobs'              => $running,
            'stuck_running_jobs'        => $stuckRunning,
            'oldest_pending_age_seconds'=> $oldestPendingAge,
            'failed_terminal_files'     => $failedTerminal,
            'dead_letter_unresolved'    => (int) ($dead['unresolved'] ?? 0),
            'dead_letter_last_24h'      => (int) ($dead['last_24h'] ?? 0),
            'worker_heartbeat_status'   => $heartbeatStatus,
            'worker_heartbeat_age_seconds' => $ageSeconds,
            'has_sync_runs_history'     => $hasSyncRuns,
        ];

        // CRITICAL: jobs waiting but no worker active — data pipeline is stuck
        if ($pending > 0 && in_array($heartbeatStatus, ['critical', 'unknown'], true)) {
            $neverConfigured = $heartbeatStatus === 'unknown' && !$hasSyncRuns;
            $hint = $neverConfigured
                ? 'Cron nunca configurado — veja docs/hostinger-cron-setup.md'
                : 'Worker parou — execute php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=120 --json';

            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                $neverConfigured
                    ? "Ha {$pending} job(s) na fila, mas o worker nunca foi configurado."
                    : "Ha {$pending} job(s) aguardando, mas nenhum worker ativo foi observado.",
                $metrics,
                [$hint],
                $ms
            );
        }

        // Worker never configured but queue is empty — informational, not critical
        if ($heartbeatStatus === 'unknown' && !$hasSyncRuns) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Worker nunca configurado — fila vazia por enquanto, mas sync automatico inativo.',
                $metrics,
                ['Configure cron no Hostinger para execucao automatica. Veja docs/hostinger-cron-setup.md'],
                $ms
            );
        }

        if ($stuckRunning > 0) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                "{$stuckRunning} job(s) em estado 'running' ha mais de 30 minutos — possivel travamento.",
                $metrics,
                ['Use php tools/dev/worker_queue_debug.php --stuck --json para investigar.'],
                $ms
            );
        }

        if ((int) ($dead['unresolved'] ?? 0) > 0 || $failedTerminal > 0) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Ha jobs em falha final/dead-letter aguardando tratamento.',
                $metrics,
                ['Use php tools/dev/worker_queue_debug.php --failed --json para investigar.'],
                $ms
            );
        }

        if ($pending > 0) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                "{$pending} job(s) aguardando processamento.",
                $metrics,
                ['Confirme heartbeat do worker e processamento da fila.'],
                $ms
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'Fila sem pendencias criticas.',
            $metrics,
            $ms
        );
    }

    private function hasSyncRunsHistory(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_sync_runs'");
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM biomie_sync_runs LIMIT 1');
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function stuckRunningCount(FileJobQueue $queue): int
    {
        try {
            $jobsDir = Bootstrap::rootPath('storage/cache/jobs');
            $files = glob($jobsDir . '/running_job_*.json') ?: [];
            $threshold = time() - 1800; // 30 minutes
            $stuck = 0;
            foreach ($files as $path) {
                $content = @file_get_contents($path);
                if ($content === false) {
                    continue;
                }
                $job = json_decode($content, true);
                if (!is_array($job)) {
                    continue;
                }
                $startedAt = strtotime((string) ($job['started_at'] ?? ''));
                if ($startedAt !== false && $startedAt > 0 && $startedAt < $threshold) {
                    $stuck++;
                }
            }
            return $stuck;
        } catch (Throwable) {
            return 0;
        }
    }

    private function oldestPendingAgeSeconds(FileJobQueue $queue): ?int
    {
        try {
            $oldest = $queue->summary()['oldest_queued_at'] ?? null;
            if ($oldest === null || $oldest === '') {
                return null;
            }
            $ts = strtotime((string) $oldest);
            return $ts !== false ? max(0, time() - $ts) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
