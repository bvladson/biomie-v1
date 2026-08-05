<?php

declare(strict_types=1);

namespace BIomie\Ops;

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Intelligence\IntelligenceJobSla;
use BIomie\Logger;
use PDO;
use Throwable;

/**
 * Sentinela operacional do pipeline de sync.
 *
 * Roda barato (poucas queries) a cada ~15min via cron e:
 *   1. Limpa runs `running` presas (auto-cura — substitui o watchdog avulso).
 *   2. Dead-man's switch: alerta se NENHUM sync_run completa ha > N horas
 *      (pega "pipeline morto" / cron deletado em ~1h, nao em 4 dias).
 *   3. Drift por dominio: alerta se commercial/finance/gold pararam de completar
 *      (pega "job removido do hPanel" — o incidente real desta semana).
 *   4. Dedup: so notifica em mudanca de estado ou apos cooldown (nao faz spam).
 *   5. Grava status file para o dashboard/healthz lerem.
 *
 * Somente leitura no dominio de negocio; a unica escrita e a limpeza de órfãs.
 */
final class OpsSentinel
{
    /** Minutos sem run completa para considerar o dominio em warning/critical. */
    private const DOMAIN_THRESHOLDS = [
        'commercial' => ['warn' => 60,  'crit' => 180],
        'finance'    => ['warn' => 90,  'crit' => 240],
        'gold'       => ['warn' => 90,  'crit' => 240],
    ];


    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly AlertNotifier $notifier,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(bool $notify = true): array
    {
        $stuckMinutes = max(5, (int) $this->config->get('ops.stuck_run_minutes', 30));
        $deadHours    = max(1, (int) $this->config->get('ops.dead_pipeline_hours', 3));

        $cleaned = $this->cleanStuckRuns($stuckMinutes);
        $domains = $this->domainFreshness();
        $databaseNow = (string) $this->pdo->query('SELECT NOW()')->fetchColumn();
        $nowTs = strtotime($databaseNow) ?: time();

        $issues = [];          // list<array{domain,severity,age_min,last_ok}>
        $worst  = 'ok';
        $anyCompleted = false;
        $newestAge = null;

        foreach (self::DOMAIN_THRESHOLDS as $domain => $t) {
            $lastOk = $domains[$domain] ?? null;
            $ageMin = $lastOk !== null ? max(0, (int) floor(($nowTs - (strtotime($lastOk) ?: $nowTs)) / 60)) : null;
            if ($lastOk !== null) {
                $anyCompleted = true;
                $newestAge = $newestAge === null ? $ageMin : min($newestAge, $ageMin);
            }

            $severity = match (true) {
                $ageMin === null      => 'critical',
                $ageMin > $t['crit']  => 'critical',
                $ageMin > $t['warn']  => 'warning',
                default               => 'ok',
            };
            if ($severity !== 'ok') {
                $issues[] = [
                    'domain'   => $domain,
                    'severity' => $severity,
                    'age_min'  => $ageMin,
                    'last_ok'  => $lastOk,
                ];
                $worst = $this->worse($worst, $severity);
            }
        }

        // Dead-man's switch global: nada completou em lugar nenhum ha > deadHours.
        $deadPipeline = !$anyCompleted || ($newestAge !== null && $newestAge > $deadHours * 60);
        if ($deadPipeline) {
            $worst = 'critical';
        }

        // Guard de isolamento multi-empresa: pega regressao de query mart_ sem
        // company_id (ex.: MAG vendo dados da ERGO). Qualquer vazamento => critical.
        $isolation = $this->checkIsolation();
        $isolationLeak = $isolation !== null && ($isolation['ok'] ?? true) === false;
        if ($isolationLeak) {
            $worst = 'critical';
        }

        // Camada Python: o cron dela pode morrer sozinho, e o health do proprio
        // servico so responde se alguem o chamar. Quem alerta precisa ter
        // agendamento independente do que esta sendo vigiado.
        $intelligence = $this->checkIntelligence();
        if ($intelligence !== null && ($intelligence['ok'] ?? true) === false) {
            $worst = $this->worse($worst, (string) ($intelligence['severity'] ?? 'warning'));
        }

        $status = [
            'severity'      => $worst,
            'generated_at'  => date(DATE_ATOM),
            'database_now'  => $databaseNow,
            'dead_pipeline' => $deadPipeline,
            'isolation'     => $isolation,
            'intelligence'  => $intelligence,
            'stuck_cleaned' => $cleaned,
            'domains'       => $this->domainReport($domains, $nowTs),
            'issues'        => $issues,
        ];

        $this->writeStatusFile($status);

        $decision = $this->decideAlert(
            $worst,
            $issues,
            $deadPipeline,
            $isolationLeak,
            $this->intelligenceKey($intelligence),
        );
        $status['alert'] = ['needed' => $decision['notify'], 'reason' => $decision['reason'], 'sent' => false];

        if ($notify && $decision['notify']) {
            $title = $this->buildTitle($worst, $deadPipeline, $issues, $isolationLeak);
            $lines = $this->buildLines($status, $deadHours, $isolation);
            $status['alert']['result'] = $this->notifier->send(
                $worst === 'ok' ? 'recovered' : $worst,
                $title,
                $lines,
            );
            $status['alert']['sent'] = true;

            // Espelha no sininho in-app (mesma dedup do Telegram via assinatura).
            try {
                (new \BIomie\Notifications\NotificationService($this->pdo))->push([
                    'category' => $isolationLeak ? 'security' : 'sync',
                    'severity' => $worst === 'ok' ? 'success' : $worst,
                    'title' => $title,
                    'body' => implode("\n", array_slice($lines, 0, 4)),
                    'company_id' => null,
                    'source' => 'ops_sentinel',
                    'link' => 'admin.php',
                    'dedupe_key' => 'ops_sentinel|' . $decision['signature'],
                    'dedupe_window_seconds' => 1800,
                ]);
            } catch (\Throwable) {
            }

            $this->persistState($worst, $decision['signature']);
        } elseif ($notify && $worst === 'ok') {
            // Estado saudavel — atualiza o state sem notificar (so quando autorizado a notificar).
            $this->persistState('ok', $decision['signature']);
        }

        return $status;
    }

    private function cleanStuckRuns(int $minutes): int
    {
        // $minutes e um int validado (sem risco de injecao); inline evita o gotcha
        // de placeholder em INTERVAL ? MINUTE em algumas versoes do MySQL.
        $minutes = max(5, $minutes);
        try {
            $stmt = $this->pdo->query(
                "UPDATE biomie_sync_runs
                    SET status = 'failed_terminal', finished_at = NOW(),
                        error_summary = 'Cancelado pelo ops_sentinel (run preso > {$minutes} min).'
                  WHERE status = 'running'
                    AND started_at < (NOW() - INTERVAL {$minutes} MINUTE)"
            );
            return $stmt !== false ? $stmt->rowCount() : 0;
        } catch (Throwable $e) {
            $this->logger->warning('OpsSentinel: falha ao limpar runs presas.', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /** @return array<string,string> domain => last_ok datetime */
    private function domainFreshness(): array
    {
        $rows = $this->pdo->query(
            "SELECT domain_name,
                    MAX(CASE WHEN status IN ('completed','completed_with_warnings')
                             THEN finished_at END) AS last_ok
               FROM biomie_sync_runs
              WHERE started_at >= (NOW() - INTERVAL 7 DAY)
              GROUP BY domain_name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $domain = $this->normalizeDomain((string) $row['domain_name']);
            $lastOk = trim((string) ($row['last_ok'] ?? ''));
            if ($lastOk === '') {
                continue;
            }
            // Mantem o mais recente quando aliases mapeiam para o mesmo dominio.
            if (!isset($out[$domain]) || $lastOk > $out[$domain]) {
                $out[$domain] = $lastOk;
            }
        }
        return $out;
    }

    private function normalizeDomain(string $domain): string
    {
        return match (strtolower(trim($domain))) {
            'comercial' => 'commercial',
            'financeiro' => 'finance',
            default => strtolower(trim($domain)),
        };
    }

    /**
     * @param array<string,string> $domains
     * @return array<string,array<string,mixed>>
     */
    private function domainReport(array $domains, int $nowTs): array
    {
        $report = [];
        foreach (self::DOMAIN_THRESHOLDS as $domain => $t) {
            $lastOk = $domains[$domain] ?? null;
            $ageMin = $lastOk !== null ? max(0, (int) floor(($nowTs - (strtotime($lastOk) ?: $nowTs)) / 60)) : null;
            $report[$domain] = [
                'last_ok' => $lastOk,
                'age_min' => $ageMin,
                'warn_min' => $t['warn'],
                'crit_min' => $t['crit'],
            ];
        }
        return $report;
    }

    /**
     * Decide se deve notificar, com dedup por assinatura + cooldown.
     *
     * @param list<array<string,mixed>> $issues
     * @return array{notify:bool,reason:string,signature:string}
     */
    private function decideAlert(
        string $severity,
        array $issues,
        bool $deadPipeline,
        bool $isolationLeak = false,
        string $intelligenceKey = '',
    ): array {
        $cooldownMin = max(5, (int) $this->config->get('ops.alert_cooldown_minutes', 120));
        $signature = $this->signature($severity, $issues, $deadPipeline, $isolationLeak, $intelligenceKey);
        $state = $this->readState();
        $prevSeverity = (string) ($state['severity'] ?? 'ok');
        $prevSignature = (string) ($state['signature'] ?? '');
        $lastAlertAt = (int) ($state['last_alert_at'] ?? 0);

        if ($severity === 'ok') {
            return [
                'notify' => in_array($prevSeverity, ['warning', 'critical'], true), // recuperou
                'reason' => $prevSeverity !== 'ok' ? 'recovered' : 'healthy',
                'signature' => $signature,
            ];
        }

        if ($signature !== $prevSignature) {
            return ['notify' => true, 'reason' => 'state_changed', 'signature' => $signature];
        }
        if ((time() - $lastAlertAt) >= $cooldownMin * 60) {
            return ['notify' => true, 'reason' => 'cooldown_elapsed', 'signature' => $signature];
        }
        return ['notify' => false, 'reason' => 'deduped', 'signature' => $signature];
    }

    /** @param list<array<string,mixed>> $issues */
    private function signature(
        string $severity,
        array $issues,
        bool $deadPipeline,
        bool $isolationLeak = false,
        string $intelligenceKey = '',
    ): string {
        $parts = [$severity, $deadPipeline ? 'dead' : 'alive', $isolationLeak ? 'leak' : 'isolated'];
        foreach ($issues as $i) {
            $parts[] = $i['domain'] . ':' . $i['severity'];
        }
        // Sem isto, um job Python que para ENQUANTO um dominio ja esta em
        // warning nao mudaria a assinatura — a dedup engoliria o problema novo
        // por ate duas horas de cooldown.
        if ($intelligenceKey !== '') {
            $parts[] = 'intel:' . $intelligenceKey;
        }
        sort($parts);
        return substr(sha1(implode('|', $parts)), 0, 16);
    }

    /**
     * Assinatura estavel do estado dos jobs Python: `job:severidade` ordenado.
     *
     * @param array<string,mixed>|null $intelligence
     */
    private function intelligenceKey(?array $intelligence): string
    {
        if ($intelligence === null) {
            return '';
        }
        $parts = [];
        foreach ((array) ($intelligence['jobs'] ?? []) as $job => $info) {
            $severity = (string) ($info['severity'] ?? 'ok');
            if ($severity !== 'ok') {
                $parts[] = $job . ':' . $severity;
            }
        }
        sort($parts);
        return implode(',', $parts);
    }

    /** @param list<array<string,mixed>> $issues */
    private function buildTitle(string $severity, bool $deadPipeline, array $issues, bool $isolationLeak = false): string
    {
        if ($severity === 'ok') {
            return 'Pipeline de sync recuperado — todos os dominios voltaram ao SLA.';
        }
        if ($isolationLeak) {
            return '🔴 VAZAMENTO ENTRE EMPRESAS — uma empresa esta vendo dados de outra. Acao imediata.';
        }
        if ($deadPipeline) {
            return 'PIPELINE DE SYNC PARADO — nenhuma sincronizacao recente. Verifique os crons no hPanel.';
        }
        $names = implode(', ', array_map(static fn (array $i): string => (string) $i['domain'], $issues));
        return 'Sync fora do SLA: ' . ($names !== '' ? $names : 'verificar') . '.';
    }

    /**
     * @param array<string,mixed> $status
     * @param array<string,mixed>|null $isolation
     * @return list<string>
     */
    private function buildLines(array $status, int $deadHours, ?array $isolation = null): array
    {
        $lines = [];
        if ($isolation !== null && ($isolation['ok'] ?? true) === false) {
            $lines[] = 'ISOLAMENTO: ' . count((array) ($isolation['failures'] ?? [])) . ' vazamento(s) entre empresas detectado(s):';
            foreach ((array) ($isolation['failures'] ?? []) as $f) {
                $lines[] = sprintf(
                    '  %s · %s · %s (reader=R$%s vs fatia=R$%s)',
                    (string) ($f['company'] ?? '?'),
                    (string) ($f['probe'] ?? '?'),
                    (string) ($f['detail'] ?? ''),
                    number_format((float) ($f['reader'] ?? 0), 0, ',', '.'),
                    number_format((float) ($f['own'] ?? 0), 0, ',', '.'),
                );
            }
        }
        if (($status['dead_pipeline'] ?? false) === true) {
            $lines[] = "Nenhum sync_run completou nas ultimas {$deadHours}h.";
        }
        foreach ((array) ($status['issues'] ?? []) as $issue) {
            $age = $issue['age_min'];
            $ageLabel = $age === null ? 'sem run recente' : $age . ' min atras';
            $lines[] = sprintf('%s: %s (ultimo OK %s)', strtoupper((string) $issue['domain']), strtoupper((string) $issue['severity']), $ageLabel);
        }
        foreach ((array) (($status['intelligence']['jobs'] ?? [])) as $job => $info) {
            if ((string) ($info['severity'] ?? 'ok') === 'ok') {
                continue;
            }
            $lines[] = $info['age_hours'] === null
                ? sprintf('INTELIGENCIA %s: %s', strtoupper((string) $job), (string) ($info['note'] ?? 'sem execucao'))
                : sprintf(
                    'INTELIGENCIA %s: %s — sem sucesso ha %sh (limite %sh)',
                    strtoupper((string) $job),
                    strtoupper((string) $info['severity']),
                    (string) $info['age_hours'],
                    (string) ($info['limit_hours'] ?? '?'),
                );
        }
        if ((int) ($status['stuck_cleaned'] ?? 0) > 0) {
            $lines[] = $status['stuck_cleaned'] . ' run(s) presa(s) limpa(s) automaticamente.';
        }
        $lines[] = 'Diagnostico completo: php bin/audit_company_readiness.php --pretty';
        return $lines;
    }

    private function worse(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    /**
     * Dead-man switch da camada Python.
     *
     * O `health` do proprio servico so responde quando alguem o chama — e quem
     * chamaria e justamente o cron que pode ter morrido. Este check roda no
     * OpsSentinel, que tem agendamento independente, e por isso consegue dizer
     * "o Python parou" quando o Python nao consegue mais dizer nada.
     *
     * Defensivo: ambiente sem a camada de inteligencia (tabela ausente) devolve
     * null e nao vira alarme — nao ter o modulo e diferente de o modulo ter
     * quebrado.
     *
     * @return array<string,mixed>|null
     */
    private function checkIntelligence(): ?array
    {
        try {
            return IntelligenceJobSla::snapshot($this->pdo);
        } catch (Throwable $e) {
            $this->logger->warning('OpsSentinel: check de inteligencia falhou.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Roda o guard de isolamento multi-empresa (gate por ops.isolation_guard_enabled).
     * Defensivo: qualquer erro vira null e nao quebra o sentinel.
     *
     * @return array<string,mixed>|null
     */
    private function checkIsolation(): ?array
    {
        if ((int) $this->config->get('ops.isolation_guard_enabled', 1) !== 1) {
            return null;
        }
        try {
            return (new TenantIsolationGuard($this->pdo, $this->logger))->run();
        } catch (Throwable $e) {
            $this->logger->warning('OpsSentinel: guard de isolamento falhou.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    // ── State / status files ──────────────────────────────────────────────────

    private function opsDir(): string
    {
        $dir = Bootstrap::rootPath('storage/ops');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $path = $this->opsDir() . '/sentinel_state.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function persistState(string $severity, string $signature): void
    {
        $path = $this->opsDir() . '/sentinel_state.json';
        @file_put_contents($path, json_encode([
            'severity' => $severity,
            'signature' => $signature,
            'last_alert_at' => time(),
            'updated_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** @param array<string,mixed> $status */
    private function writeStatusFile(array $status): void
    {
        $path = $this->opsDir() . '/sentinel_status.json';
        @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
