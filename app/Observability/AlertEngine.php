<?php

declare(strict_types=1);

namespace BIomie\Observability;

use PDO;
use Throwable;

/**
 * Avalia regras de saúde do pipeline e persiste alertas em biomie_health_alerts.
 *
 * Regras avaliadas:
 *   job_stopped_<name>    — job sem execução bem-sucedida há mais de N horas
 *   error_rate_<name>     — ≥30% de falhas nas últimas 20 execuções de um job
 *   geo_coverage_low      — cobertura geográfica abaixo de 40%
 *   cnae_coverage_low     — cobertura CNAE abaixo de 20%
 *   enrichment_stalled    — 0 clientes enriquecidos com ≥ 10 clientes no total
 *
 * Chamado pelo bin/evaluate_health.php (cron: 0 * * * *).
 * Alertas são auto-resolvidos quando a regra deixa de disparar.
 */
final class AlertEngine
{
    private const TABLE = 'biomie_health_alerts';

    /** Job name → máximo de horas sem execução bem-sucedida (jobs noturnos = 26h) */
    private const JOB_MAX_IDLE_HOURS = [
        'gold_mart_refresh'         => 26,
        'compute_opportunities'     => 26,
        'compute_forecasts'         => 26,
        'enrich_clients_governance' => 26,
    ];

    private const ERROR_RATE_THRESHOLD  = 0.30;  // 30 % das últimas 20 runs
    private const ERROR_RATE_MIN_RUNS   = 5;      // Só avalia se houver ≥ 5 runs
    private const GEO_THRESHOLD         = 40.0;   // % mínimo de cobertura geo
    private const CNAE_THRESHOLD        = 20.0;   // % mínimo de cobertura CNAE

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Avalia todas as regras e persiste os alertas detectados.
     * Resolve automaticamente alertas de regras que não mais disparam.
     *
     * @return list<array{rule_code:string,severity:string,title:string,message:string}>
     */
    public function evaluate(CoverageAggregator $coverage): array
    {
        $triggered   = [];
        $activeRules = [];
        $coverageData = $coverage->summarize();

        // ── Regra: job parado ────────────────────────────────────────────────
        foreach (self::JOB_MAX_IDLE_HOURS as $jobName => $maxHours) {
            $lastSuccess = $this->lastSuccessfulRun($jobName);

            if ($lastSuccess === null) {
                $rule = "job_never_ran_{$jobName}";
                $triggered[] = $this->persist(
                    $rule, 'warning',
                    "Job nunca executou: {$jobName}",
                    "O job '{$jobName}' não possui nenhuma execução bem-sucedida registrada. Execute-o manualmente para verificar a configuração.",
                    ['job_name' => $jobName, 'max_idle_hours' => $maxHours]
                );
                $activeRules[] = $rule;
                continue;
            }

            $ageHours = (time() - (int) strtotime($lastSuccess)) / 3600;
            if ($ageHours > $maxHours) {
                $rule = "job_stopped_{$jobName}";
                $triggered[] = $this->persist(
                    $rule, 'critical',
                    "Job parado: {$jobName}",
                    sprintf(
                        "O job '%s' não executa há %.1f horas (máximo esperado: %dh). Verifique o cron e os logs em storage/logs/.",
                        $jobName, $ageHours, $maxHours
                    ),
                    ['job_name' => $jobName, 'age_hours' => round($ageHours, 1), 'max_idle_hours' => $maxHours, 'last_success' => $lastSuccess]
                );
                $activeRules[] = $rule;
            }
        }

        // ── Regra: taxa de erro elevada ──────────────────────────────────────
        foreach (array_keys(self::JOB_MAX_IDLE_HOURS) as $jobName) {
            [$total, $failed] = $this->recentRunStats($jobName, 20);
            if ($total >= self::ERROR_RATE_MIN_RUNS && ($failed / $total) >= self::ERROR_RATE_THRESHOLD) {
                $rule = "error_rate_{$jobName}";
                $triggered[] = $this->persist(
                    $rule, 'warning',
                    "Taxa de erro elevada: {$jobName}",
                    sprintf(
                        "O job '%s' falhou em %d de %d execuções recentes (%.0f%%). Verifique os logs.",
                        $jobName, $failed, $total, $failed / $total * 100
                    ),
                    ['job_name' => $jobName, 'failed' => $failed, 'total' => $total, 'error_rate_pct' => round($failed / $total * 100, 1)]
                );
                $activeRules[] = $rule;
            }
        }

        // ── Regra: cobertura geo baixa ───────────────────────────────────────
        if ($coverageData['total_clients'] > 0 && $coverageData['geo_coverage_pct'] < self::GEO_THRESHOLD) {
            $rule = 'geo_coverage_low';
            $triggered[] = $this->persist(
                $rule, 'warning',
                'Cobertura geográfica baixa',
                sprintf(
                    'Apenas %.1f%% dos clientes possuem dados geográficos (limiar: %.0f%%). Execute bin/process_geo_enrichment.php.',
                    $coverageData['geo_coverage_pct'], self::GEO_THRESHOLD
                ),
                ['coverage_pct' => $coverageData['geo_coverage_pct'], 'threshold' => self::GEO_THRESHOLD, 'total_clients' => $coverageData['total_clients']]
            );
            $activeRules[] = $rule;
        }

        // ── Regra: cobertura CNAE baixa ──────────────────────────────────────
        if ($coverageData['total_clients'] > 0 && $coverageData['cnae_coverage_pct'] < self::CNAE_THRESHOLD) {
            $rule = 'cnae_coverage_low';
            $triggered[] = $this->persist(
                $rule, 'warning',
                'Cobertura CNAE baixa',
                sprintf(
                    'Apenas %.1f%% dos clientes têm CNAE classificado (limiar: %.0f%%). Execute bin/enrich_clients_governance.php.',
                    $coverageData['cnae_coverage_pct'], self::CNAE_THRESHOLD
                ),
                ['coverage_pct' => $coverageData['cnae_coverage_pct'], 'threshold' => self::CNAE_THRESHOLD]
            );
            $activeRules[] = $rule;
        }

        // ── Regra: enriquecimento travado ────────────────────────────────────
        if ($coverageData['total_clients'] >= 10 && $coverageData['enriched_covered'] === 0) {
            $rule = 'enrichment_stalled';
            $triggered[] = $this->persist(
                $rule, 'critical',
                'Enriquecimento de clientes travado',
                'Existem clientes cadastrados mas nenhum possui tipo_pessoa preenchido. O bin/enrich_clients_governance.php pode não estar executando.',
                ['total_clients' => $coverageData['total_clients']]
            );
            $activeRules[] = $rule;
        }

        // ── Auto-resolução ───────────────────────────────────────────────────
        $this->resolveStale($activeRules);

        return $triggered;
    }

    /**
     * Retorna os alertas ativos ordenados por severidade e data.
     *
     * @return list<array<string,mixed>>
     */
    public function getActiveAlerts(int $limit = 30): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, rule_code, severity, title, message, context_json, triggered_at
                 FROM ' . self::TABLE . '
                 WHERE resolved_at IS NULL
                 ORDER BY FIELD(severity, \'critical\', \'warning\', \'info\'), triggered_at DESC
                 LIMIT :lim'
            );
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(static function (array $row): array {
                $ctx = is_string($row['context_json'] ?? null)
                    ? (json_decode($row['context_json'], true) ?? [])
                    : [];
                return [
                    'id'           => (int) $row['id'],
                    'rule_code'    => $row['rule_code'],
                    'severity'     => $row['severity'],
                    'title'        => $row['title'],
                    'message'      => $row['message'],
                    'context'      => $ctx,
                    'triggered_at' => $row['triggered_at'],
                ];
            }, $rows ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $context
     * @return array{rule_code:string,severity:string,title:string,message:string}
     */
    private function persist(string $ruleCode, string $severity, string $title, string $message, array $context): array
    {
        try {
            // INSERT only if no active alert with the same rule exists — avoids duplicates
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (rule_code, severity, title, message, context_json, triggered_at)
                 SELECT :rule, :sev, :title, :msg, :ctx, NOW()
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM ' . self::TABLE . ' sub
                     WHERE sub.rule_code = :rule2 AND sub.resolved_at IS NULL
                 )'
            );
            $stmt->execute([
                'rule'  => $ruleCode,
                'sev'   => $severity,
                'title' => $title,
                'msg'   => $message,
                'ctx'   => json_encode($context, JSON_UNESCAPED_UNICODE),
                'rule2' => $ruleCode,
            ]);
        } catch (Throwable) {
            // Non-fatal: alert display still works via getActiveAlerts()
        }

        return ['rule_code' => $ruleCode, 'severity' => $severity, 'title' => $title, 'message' => $message];
    }

    /**
     * Resolve alertas abertos cujas regras não mais disparam.
     *
     * @param list<string> $stillActiveRules
     */
    private function resolveStale(array $stillActiveRules): void
    {
        try {
            if ($stillActiveRules === []) {
                // Tudo saudável — resolve todos
                $this->pdo->exec(
                    'UPDATE ' . self::TABLE . ' SET resolved_at = NOW() WHERE resolved_at IS NULL'
                );
                return;
            }

            $placeholders = implode(',', array_fill(0, count($stillActiveRules), '?'));
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET resolved_at = NOW()
                 WHERE resolved_at IS NULL AND rule_code NOT IN (' . $placeholders . ')'
            );
            $stmt->execute($stillActiveRules);
        } catch (Throwable) {}
    }

    private function lastSuccessfulRun(string $jobName): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT completed_at FROM biomie_pipeline_jobs
                 WHERE job_name = :name AND status = 'completed'
                 ORDER BY completed_at DESC LIMIT 1"
            );
            $stmt->execute(['name' => $jobName]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{int, int} [total_runs, failed_runs]
     */
    private function recentRunStats(string $jobName, int $n): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT status FROM biomie_pipeline_jobs
                 WHERE job_name = :name
                 ORDER BY id DESC LIMIT :n'
            );
            $stmt->bindValue('name', $jobName);
            $stmt->bindValue('n', $n, PDO::PARAM_INT);
            $stmt->execute();
            $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            $total  = count($statuses);
            $failed = count(array_filter($statuses, static fn ($s): bool => $s === 'failed'));
            return [$total, $failed];
        } catch (Throwable) {
            return [0, 0];
        }
    }
}
