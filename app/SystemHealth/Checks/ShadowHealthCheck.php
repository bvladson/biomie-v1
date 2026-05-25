<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class ShadowHealthCheck implements CheckInterface
{
    private const WARN_PCT = 5.0;
    private const CRITICAL_PCT = 15.0;
    private const MIN_CRITICAL_TOTAL = 10;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'shadow'; }
    public function label(): string { return 'Shadow Validation'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessivel; check nao executado.', [], [], date('c'), 0,
            );
        }

        try {
            $row = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN diff_type != 'match' THEN 1 ELSE 0 END) AS diverged,
                    COUNT(DISTINCT query_signature) AS query_signatures,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical_rows,
                    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS high_rows,
                    MAX(CASE WHEN diff_type != 'match' THEN endpoint_name ELSE NULL END) AS last_divergent_endpoint
                 FROM biomie_shadow_validations
                 WHERE executed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Tabela biomie_shadow_validations indisponivel ou shadow desativado.',
                [], [], date('c'), $ms,
            );
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $total = (int) ($row['total'] ?? 0);
        $diverged = (int) ($row['diverged'] ?? 0);
        $signatures = (int) ($row['query_signatures'] ?? 0);
        $criticalRows = (int) ($row['critical_rows'] ?? 0);
        $highRows = (int) ($row['high_rows'] ?? 0);
        $lastDivergentEndpoint = trim((string) ($row['last_divergent_endpoint'] ?? ''));

        if ($total === 0) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Sem registros de shadow validation nas ultimas 24h. Validacao pode estar desativada.',
                ['total_24h' => 0, 'diverged_24h' => 0, 'divergence_pct' => null],
                [], date('c'), $ms,
            );
        }

        $pct = round(($diverged / $total) * 100, 2);
        $metrics = [
            'total_24h' => $total,
            'diverged_24h' => $diverged,
            'divergence_pct' => $pct,
            'query_signatures_24h' => $signatures,
            'critical_rows_24h' => $criticalRows,
            'high_rows_24h' => $highRows,
            'last_divergent_endpoint' => $lastDivergentEndpoint !== '' ? $lastDivergentEndpoint : null,
        ];

        if ($diverged > 0 && $total < self::MIN_CRITICAL_TOTAL && $criticalRows === 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Amostra shadow baixa: {$diverged}/{$total} divergente(s), {$signatures} assinatura(s), {$pct}% nas ultimas 24h.",
                $metrics,
                ['Auditar causa com tools/dev/shadow_audit.php --hours=24 --pretty.'],
                $ms,
            );
        }

        if ($pct >= self::CRITICAL_PCT) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "Taxa de divergencia: {$pct}% ({$diverged}/{$total} nas ultimas 24h).",
                $metrics,
                ['Investigar divergencias com tools/dev/shadow_audit.php --hours=24 --pretty.'],
                $ms,
            );
        }

        if ($pct >= self::WARN_PCT) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Taxa de divergencia: {$pct}% ({$diverged}/{$total} nas ultimas 24h).",
                $metrics,
                ['Monitorar tendencia de divergencias.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "Taxa de divergencia: {$pct}% ({$diverged}/{$total} nas ultimas 24h).",
            $metrics,
            $ms,
        );
    }
}
