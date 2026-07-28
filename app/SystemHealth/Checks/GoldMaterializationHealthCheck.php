<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

/**
 * Checks freshness of Gold mart materialization.
 *
 * Canonical fallback uses ONLY sincronizado_em / updated_at columns.
 * Never uses data_competencia or data_vencimento — those are business dates,
 * not sync/materialization timestamps, and cause false CRITICAL readings.
 */
final class GoldMaterializationHealthCheck implements CheckInterface
{
    private const OK_HOURS   = 24;
    private const WARN_HOURS = 72;

    /** @var array<string,list<string>> Gold mart tables → freshness columns */
    private const MART_TABLES = [
        'mart_cliente_periodo'       => ['refreshed_at', 'updated_at'],
        'mart_produto_periodo'       => ['refreshed_at', 'updated_at'],
        'mart_vendedor_dia'          => ['refreshed_at', 'updated_at'],
        'mart_compradores_por_geo'   => ['refreshed_at', 'updated_at', 'created_at'],
    ];

    /** @var array<string,list<string>> Canonical source tables → sync timestamp columns only */
    private const CANONICAL_TABLES = [
        'biomie_notas_fiscais_canonicas'     => ['sincronizado_em', 'updated_at'],
        'biomie_pedidos_canonicos'           => ['sincronizado_em', 'updated_at'],
    ];

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string { return 'gold_materialization'; }
    public function label(): string { return 'Materializacao Gold'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        if ($this->pdo === null) {
            return new SystemHealthCheck($this->id(), $this->label(), 'unknown', 'info', 'Banco indisponivel.', [], [], date('c'), 0);
        }

        try {
            $lastGold      = $this->lastGold();
            $perMart       = $this->perMartFreshness();
            $lastCanonical = $this->lastCanonical();
        } catch (Throwable $exception) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown($this->id(), $this->label(), $exception, $ms);
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $goldStatus      = $this->statusForDate($lastGold);
        $canonicalStatus = $this->statusForDate($lastCanonical);

        $metrics = [
            'last_gold_materialized_at' => $lastGold,
            'last_canonical_at'         => $lastCanonical,
            'gold_status'               => $goldStatus,
            'canonical_status'          => $canonicalStatus,
        ];

        foreach ($perMart as $table => $date) {
            $short = str_replace('mart_', '', $table);
            $metrics["mart_{$short}_at"]     = $date;
            $metrics["mart_{$short}_status"] = $this->statusForDate($date);
        }

        // Gold materialized on top of stale canonical → warn about data freshness
        if ($goldStatus === 'ok' && $canonicalStatus === 'critical') {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Gold atualizado, mas origem canonica Omie esta muito desatualizada — marts podem ter dados antigos.',
                $metrics,
                ['Execute sync Omie antes de confiar nos marts: php cron/sync_realtime_pipeline.php --domains=commercial --json'],
                $ms
            );
        }

        if ($goldStatus === 'ok' && in_array($canonicalStatus, ['warning', 'unknown'], true)) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Gold atualizado com dados locais, mas origem Omie/canonica pode estar desatualizada.',
                $metrics,
                ['Execute sync delta/reconcile antes de confiar nos marts.'],
                $ms
            );
        }

        // Gold never materialized but canonical is fresh → warning (not critical)
        if ($goldStatus === 'unknown' && in_array($canonicalStatus, ['ok', 'warning'], true)) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Materializacao Gold nunca executada — dados canonicos disponiveis mas marts vazios.',
                $metrics,
                ['Execute php bin/materialize_marts.php --json para popular os marts Gold.'],
                $ms
            );
        }

        if ($goldStatus === 'critical') {
            return SystemHealthCheck::critical(
                $this->id(),
                $this->label(),
                'Materializacao Gold acima de 72h ou nunca executada sem dados canonicos recentes.',
                $metrics,
                ['Execute php bin/materialize_marts.php --json depois do sync Omie.'],
                $ms
            );
        }

        if ($goldStatus === 'warning') {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Materializacao Gold entre 24h e 72h.',
                $metrics,
                ['Agende materializacao Gold apos o sync: php bin/materialize_marts.php --json'],
                $ms
            );
        }

        return SystemHealthCheck::ok($this->id(), $this->label(), 'Gold e origem canonica recentes.', $metrics, $ms);
    }

    private function lastGold(): ?string
    {
        // Prefer pipeline job record (most reliable signal)
        if ($this->tableExists('biomie_pipeline_jobs')) {
            $stmt = $this->pdo->query(
                "SELECT MAX(completed_at) FROM biomie_pipeline_jobs WHERE job_name = 'gold_mart_refresh' AND status = 'completed'"
            );
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
            if ($value !== false && $value !== null && (string) $value !== '') {
                return (string) $value;
            }
        }

        // Fallback: latest refreshed_at across all mart tables
        $dates = [];
        foreach (self::MART_TABLES as $table => $columns) {
            $date = $this->lastUpdatedAt($table, $columns);
            if ($date !== null) {
                $dates[] = $date;
            }
        }
        if ($dates !== []) {
            rsort($dates);
            return $dates[0];
        }

        return null;
    }

    /** @return array<string,?string> */
    private function perMartFreshness(): array
    {
        $result = [];
        foreach (self::MART_TABLES as $table => $columns) {
            $result[$table] = $this->lastUpdatedAt($table, $columns);
        }
        return $result;
    }

    private function lastCanonical(): ?string
    {
        $dates = [];
        foreach (self::CANONICAL_TABLES as $table => $columns) {
            $date = $this->lastUpdatedAt($table, $columns);
            if ($date !== null) {
                $dates[] = $date;
            }
        }
        if ($dates !== []) {
            rsort($dates);
            return $dates[0];
        }
        return null;
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

    private function statusForDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'unknown';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return 'unknown';
        }
        $hours = (time() - $ts) / 3600;
        if ($hours <= self::OK_HOURS) {
            return 'ok';
        }
        return $hours <= self::WARN_HOURS ? 'warning' : 'critical';
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
