<?php

declare(strict_types=1);

namespace BIomie\Intelligence;

use PDO;
use Throwable;

/**
 * SLA de frescor dos jobs da camada Python — fonte unica no lado PHP.
 *
 * ─── POR QUE ESTA CLASSE EXISTE ─────────────────────────────────────────────
 * Tres lugares precisam do mesmo numero: o OpsSentinel (para alertar), o painel
 * administrativo (para mostrar) e o `health` do proprio servico Python (para se
 * autoavaliar). Tres copias do limiar divergem em silencio, e a divergencia tem
 * um sintoma cruel — o painel diz "ok" enquanto o Telegram grita, ou pior, o
 * contrario: ninguem alerta e a tela jura que esta tudo bem.
 *
 * Aqui ficam as duas do lado PHP. A terceira e `MAX_IDLE_HORAS` em
 * services/python/biomie_intel/health.py, e `tests/run_intelligence_freshness_guard.php`
 * compara os dois arquivos e falha se separarem. E a mesma disciplina de
 * `WRITABLE_TABLES` × GRANTs do script de provisionamento.
 *
 * ─── POR QUE O DOBRO DO LIMITE VIRA CRITICAL ────────────────────────────────
 * Passar do limite significa "perdeu uma execucao" — pode ser uma janela de
 * manutencao ou o servidor ocupado. Passar do dobro significa que duas
 * execucoes seguidas nao aconteceram, e isso nao e mais atraso, e parada.
 */
final class IntelligenceJobSla
{
    /**
     * Horas sem execucao `completed` para considerar o job parado.
     *
     * quote_score: cron `20 *\/2` (2h) · insights: `50 *\/6` (6h) ·
     * forecast: `10 5 * * *` (24h). Cada limite cobre uma execucao perdida
     * mais folga — limite menor que a cadencia dispara alarme falso sempre.
     *
     * @var array<string,int>
     */
    public const MAX_IDLE_HOURS = [
        'quote_score'      => 26,
        'insights'         => 14,
        'forecast'         => 30,
        // Acrescentados depois que o guard denunciou a divergencia: os dois
        // tinham SLA declarado no Python e NENHUM aqui. Na pratica isso
        // significa que, se um deles parasse, o `health` do servico acusaria e o
        // OpsSentinel e o painel administrativo ficariam mudos — que e o modo de
        // falha que este arquivo existe para impedir.
        'geo_intelligence' => 30,  // cron `30 5 * * *`, 1x/dia
        'fiscal_mart'      => 30,  // cron `45 1 * * *`, 1x/dia
        // SEMANAL (`20 4 * * 1`): o backtest observa o mes seguinte a cada
        // referencia, entao a resposta so muda quando um mes novo termina.
        // 192h = 8 dias, a mesma folga de uma execucao perdida dos demais.
        'geo_backtest'     => 192,
        // Risco de atraso em recebiveis: cron `40 5 * * *`, 1x/dia. O que ele
        // vigia sao R$ 6,29 mi em aberto — parar sem ninguem ver e caro.
        'receivable_risk'  => 30,
        // Anomalias operacionais: cron `15 6 * * *`, 1x/dia. Compara 7 dias
        // contra 28, entao um dia perdido nao muda a resposta.
        'ops_anomaly'      => 30,
        // Faixa normal por dia da semana: cron `50 5 * * *`, 1x/dia.
        'daily_baseline'   => 30,
    ];

    /** @return list<string> */
    public static function jobNames(): array
    {
        return array_keys(self::MAX_IDLE_HOURS);
    }

    public static function limitFor(string $job): int
    {
        return self::MAX_IDLE_HOURS[$job] ?? 24;
    }

    /**
     * Classifica a idade da ultima execucao bem-sucedida.
     *
     * `null` (nunca executou) e WARNING, nao CRITICAL: pode ser agendamento
     * novo que ainda nao teve a primeira rodada. O que nao pode e sumir do
     * relatorio, que era o comportamento antigo.
     */
    public static function severityFor(string $job, ?float $ageHours): string
    {
        if ($ageHours === null) {
            return 'warning';
        }
        $limit = self::limitFor($job);

        return match (true) {
            $ageHours > $limit * 2 => 'critical',
            $ageHours > $limit     => 'warning',
            default                => 'ok',
        };
    }

    /**
     * Frescor de cada job a partir de `intelligence_job_runs`.
     *
     * Devolve `null` quando a camada de inteligencia nao esta instalada — nao
     * ter o modulo e diferente de o modulo ter quebrado, e tratar os dois igual
     * encheria de alarme os ambientes que nao usam Python.
     *
     * @return array<string,mixed>|null
     */
    public static function snapshot(PDO $pdo): ?array
    {
        try {
            $exists = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'intelligence_job_runs'"
            )->fetchColumn();
            if ((int) $exists === 0) {
                return null;
            }

            $stmt = $pdo->prepare(
                "SELECT MAX(CASE WHEN status = 'completed' THEN finished_at END) AS ultimo_ok,
                        MAX(started_at) AS ultima_tentativa,
                        SUM(status = 'failed') AS falhas_24h
                   FROM intelligence_job_runs
                  WHERE job_name = :job
                    AND started_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"
            );

            $jobs = [];
            $worst = 'ok';
            foreach (self::MAX_IDLE_HOURS as $job => $limit) {
                $stmt->execute(['job' => $job]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $lastOk = $row['ultimo_ok'] ?? null;
                $ageHours = $lastOk !== null
                    ? max(0.0, (time() - (strtotime((string) $lastOk) ?: time())) / 3600)
                    : null;
                $severity = self::severityFor($job, $ageHours);

                $jobs[$job] = [
                    'job' => $job,
                    'last_ok' => $lastOk !== null ? (string) $lastOk : null,
                    'last_attempt' => isset($row['ultima_tentativa']) ? (string) $row['ultima_tentativa'] : null,
                    'age_hours' => $ageHours !== null ? round($ageHours, 1) : null,
                    'limit_hours' => $limit,
                    'failures_7d' => (int) ($row['falhas_24h'] ?? 0),
                    'severity' => $severity,
                ];
                $worst = self::worse($worst, $severity);
            }

            return ['ok' => $worst === 'ok', 'severity' => $worst, 'jobs' => $jobs];
        } catch (Throwable) {
            return null;
        }
    }

    private static function worse(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }
}
