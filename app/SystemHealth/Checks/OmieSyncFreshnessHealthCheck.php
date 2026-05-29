<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

/**
 * Checks whether Omie sync runs are fresh.
 *
 * Domain names stored in biomie_sync_runs:
 *   - 'commercial' — orders, clients, vendors, fiscal NFs
 *   - 'finance'    — financial titles and movements
 *
 * Thresholds differ per domain because financial data is slower-moving.
 * Canonical table fallback uses ONLY sincronizado_em / updated_at columns —
 * never data_competencia or data_vencimento (those are business dates, not sync timestamps).
 */
final class OmieSyncFreshnessHealthCheck implements CheckInterface
{
    /** @var array<string,array{ok:int,warn:int}> */
    private const DOMAIN_THRESHOLDS = [
        'commercial' => ['ok' => 6,  'warn' => 36],
        'finance'    => ['ok' => 8,  'warn' => 48],
    ];

    private const DEFAULT_THRESHOLDS = ['ok' => 24, 'warn' => 72];

    private const SYNC_DOMAINS = ['commercial', 'finance'];

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string { return 'omie_sync_freshness'; }
    public function label(): string { return 'Freshness Sync Omie'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        if ($this->pdo === null) {
            return new SystemHealthCheck($this->id(), $this->label(), 'unknown', 'info', 'Banco indisponivel.', [], [], date('c'), 0);
        }

        try {
            if (!$this->tableExists('biomie_sync_runs')) {
                $ms = (int) round((microtime(true) - $start) * 1000);
                return SystemHealthCheck::warning(
                    $this->id(),
                    $this->label(),
                    'Tabela biomie_sync_runs ausente — cron de sync nunca foi configurado.',
                    ['sync_runs_table' => false, 'suggestion' => 'Configure os crons no Hostinger e execute php cron/sync_realtime_pipeline.php --domains=commercial,finance --json'],
                    ['Configure cron no Hostinger: worker a cada 5min, sync a cada 15min.'],
                    $ms
                );
            }

            $domainStats   = $this->queryDomainStats();
            $canonicalDates = $this->queryCanonicalDates();
        } catch (Throwable $exception) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown($this->id(), $this->label(), $exception, $ms);
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $metrics  = [];
        $statuses = [];
        $neverRanCount = 0;

        foreach (self::SYNC_DOMAINS as $domain) {
            $stats     = $domainStats[$domain] ?? [];
            $lastOk    = $stats['last_success_at'] ?? null;
            $lastFail  = $stats['last_failure_at'] ?? null;
            $canonicalOk = $this->canonicalDateForDomain($domain, $canonicalDates);
            $effectiveOk = $this->freshestDate($lastOk, $canonicalOk);
            $lagMin    = $this->lagMinutes($effectiveOk);
            $domStatus = $this->statusForDomain($domain, $effectiveOk);

            if ($domStatus === 'unknown') {
                $neverRanCount++;
            }

            $statuses[] = $domStatus;
            $metrics["{$domain}_last_success_at"]  = $lastOk;
            $metrics["{$domain}_last_failure_at"]  = $lastFail;
            $metrics["{$domain}_effective_success_at"] = $effectiveOk;
            $metrics["{$domain}_freshness_source"] = $effectiveOk === $lastOk ? 'sync_runs' : 'canonical_fallback';
            $metrics["{$domain}_lag_minutes"]      = $lagMin;
            $metrics["{$domain}_records_written"]  = (int) ($stats['records_written'] ?? 0);
            $metrics["{$domain}_status"]           = $domStatus;
        }

        foreach ($canonicalDates as $name => $date) {
            $metrics["canonical_{$name}_at"] = $date;
        }

        // All domains never ran → cron not configured, not a data-quality CRITICAL
        if ($neverRanCount === count(self::SYNC_DOMAINS)) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Sync Omie nunca executado para nenhum dominio — configure o cron no Hostinger.',
                $metrics,
                [
                    'Execute php cron/sync_realtime_pipeline.php --domains=commercial,finance --json',
                    'Veja docs/hostinger-cron-setup.md para configurar execucao automatica.',
                ],
                $ms
            );
        }

        $worst = $this->worst($statuses);

        if ($worst === 'critical') {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Sync Omie acima do limite de atraso — dados podem estar desatualizados.',
                $metrics,
                [
                    'Execute php cron/sync_realtime_pipeline.php --domains=commercial,finance --json',
                    'Verifique api/omie_status.php para diagnostico de credenciais.',
                ],
                $ms
            );
        }

        if ($worst === 'warning') {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Sync Omie com atraso acima do ideal — monitore e acione reconcile se necessario.',
                $metrics,
                ['Execute php cron/sync_realtime_pipeline.php --reconcile 7 --json para forcar reconciliacao.'],
                $ms
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'Sync Omie recente para todos os dominios.',
            $metrics,
            $ms
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function queryDomainStats(): array
    {
        $result = [];
        foreach (self::SYNC_DOMAINS as $domain) {
            $result[$domain] = ['last_success_at' => null, 'last_failure_at' => null, 'records_written' => 0];
        }

        $stmt = $this->pdo->query(<<<'SQL'
            SELECT
                domain_name,
                MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END) AS last_success_at,
                MAX(CASE WHEN status IN ('failed_retryable','failed_terminal')  THEN finished_at END) AS last_failure_at,
                SUM(CASE WHEN status IN ('completed','completed_with_warnings') THEN COALESCE(items_processed, 0) END) AS records_written
            FROM biomie_sync_runs
            WHERE domain_name IN ('commercial','finance')
            GROUP BY domain_name
        SQL);

        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $domain = (string) ($row['domain_name'] ?? '');
            if (isset($result[$domain])) {
                $result[$domain]['last_success_at'] = ($row['last_success_at'] ?? null) ?: null;
                $result[$domain]['last_failure_at'] = ($row['last_failure_at'] ?? null) ?: null;
                $result[$domain]['records_written'] = (int) ($row['records_written'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @return array<string,?string>
     *
     * Only uses true sync timestamp columns (sincronizado_em, updated_at).
     * Never uses data_competencia, data_emissao, data_vencimento — those are
     * business dates, not sync timestamps, and cause false CRITICAL readings.
     */
    private function queryCanonicalDates(): array
    {
        return [
            'orders'   => $this->lastUpdatedAt('biomie_pedidos_canonicos',          ['sincronizado_em', 'updated_at']),
            'invoices' => $this->lastUpdatedAt('biomie_notas_fiscais_canonicas',     ['sincronizado_em', 'updated_at']),
            'clients'  => $this->lastUpdatedAt('biomie_clientes_dim',               ['sincronizado_em', 'updated_at']),
            'finance'  => $this->lastUpdatedAt('biomie_titulos_financeiros_canonicos', ['sincronizado_em', 'updated_at']),
        ];
    }

    private function statusForDomain(string $domain, ?string $lastSuccessAt): string
    {
        if ($lastSuccessAt === null || $lastSuccessAt === '') {
            return 'unknown';
        }
        $ts = strtotime($lastSuccessAt);
        if ($ts === false) {
            return 'unknown';
        }
        $hours = max(0.0, (time() - $ts) / 3600);
        $t = self::DOMAIN_THRESHOLDS[$domain] ?? self::DEFAULT_THRESHOLDS;
        if ($hours <= $t['ok']) {
            return 'ok';
        }
        return $hours <= $t['warn'] ? 'warning' : 'critical';
    }

    private function lagMinutes(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        $ts = strtotime($date);
        return $ts !== false ? max(0, (int) round((time() - $ts) / 60)) : null;
    }

    /**
     * @param array<string,?string> $canonicalDates
     */
    private function canonicalDateForDomain(string $domain, array $canonicalDates): ?string
    {
        if ($domain === 'finance') {
            return $canonicalDates['finance'] ?? null;
        }

        if ($domain === 'commercial') {
            return $this->freshestDate(
                $canonicalDates['orders'] ?? null,
                $canonicalDates['invoices'] ?? null
            );
        }

        return null;
    }

    private function freshestDate(?string $left, ?string $right): ?string
    {
        $leftTs = $left !== null && $left !== '' ? strtotime($left) : false;
        $rightTs = $right !== null && $right !== '' ? strtotime($right) : false;

        if ($leftTs === false) {
            return $rightTs === false ? null : $right;
        }
        if ($rightTs === false) {
            return $left;
        }

        return $rightTs > $leftTs ? $right : $left;
    }

    /** @param list<string> $columns */
    private function lastUpdatedAt(string $table, array $columns): ?string
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        foreach ($columns as $column) {
            if (!$this->columnExists($table, $column)) {
                continue;
            }
            $stmt = $this->pdo->query(sprintf('SELECT MAX(`%s`) FROM `%s`', $column, $table));
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
            if ($value !== false && $value !== null && (string) $value !== '') {
                return (string) $value;
            }
        }
        return null;
    }

    /** @param list<string> $statuses */
    private function worst(array $statuses): string
    {
        $rank = ['ok' => 1, 'unknown' => 2, 'warning' => 3, 'critical' => 4];
        $worst = 'ok';
        foreach ($statuses as $status) {
            if (($rank[$status] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $status;
            }
        }
        return $worst;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
