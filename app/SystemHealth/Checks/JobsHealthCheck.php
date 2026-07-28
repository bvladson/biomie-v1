<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class JobsHealthCheck implements CheckInterface
{
    private const JOB_NAME      = 'gold_mart_refresh';
    private const SLA_OK_MIN    = 60;
    private const SLA_WARN_MIN  = 180;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'jobs'; }
    public function label(): string { return 'Pipeline Jobs'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessível — check não executado.', [], [], date('c'), 0,
            );
        }

        try {
            $row = $this->pdo->prepare(
                "SELECT
                    MAX(CASE WHEN status = 'completed' THEN completed_at END) AS last_success_at,
                    MAX(completed_at) AS last_run_at,
                    SUM(CASE WHEN started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS runs_24h,
                    SUM(CASE WHEN status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS failures_24h
                 FROM biomie_pipeline_jobs
                 WHERE job_name = :job"
            );
            $row->execute([':job' => self::JOB_NAME]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown(
                $this->id(), $this->label(), $e, $ms
            );
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        $lastSuccess = $data['last_success_at'] ?? null;
        $runs24h     = (int) ($data['runs_24h'] ?? 0);
        $failures24h = (int) ($data['failures_24h'] ?? 0);

        $metrics = [
            'last_success_at' => $lastSuccess,
            'last_run_at'     => $data['last_run_at'] ?? null,
            'runs_last_24h'   => $runs24h,
            'failures_last_24h' => $failures24h,
        ];

        if ($lastSuccess === null) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Nenhum job gold_mart_refresh concluído com sucesso encontrado.',
                $metrics,
                ['Verificar se o cron materialize_marts.php está ativo.'],
                $ms,
            );
        }

        $ageMin = max(0, (int) round((time() - (int) strtotime($lastSuccess)) / 60));
        $metrics['last_success_age_min'] = $ageMin;

        if ($ageMin > self::SLA_WARN_MIN) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "Último gold_mart_refresh bem-sucedido há {$ageMin} min (SLA: " . self::SLA_OK_MIN . " min).",
                $metrics,
                ['Verificar cron materialize_marts.php e logs de erro.'],
                $ms,
            );
        }

        if ($ageMin > self::SLA_OK_MIN) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Último gold_mart_refresh bem-sucedido há {$ageMin} min (SLA: " . self::SLA_OK_MIN . " min).",
                $metrics,
                ['Monitorar próxima execução do cron.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "Último gold_mart_refresh bem-sucedido há {$ageMin} min. {$runs24h} execuções nas últimas 24h.",
            $metrics,
            $ms,
        );
    }
}
