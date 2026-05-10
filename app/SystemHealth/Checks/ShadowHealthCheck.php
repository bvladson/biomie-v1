<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class ShadowHealthCheck implements CheckInterface
{
    private const WARN_PCT     = 5.0;
    private const CRITICAL_PCT = 15.0;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'shadow'; }
    public function label(): string { return 'Shadow Validation'; }

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
                    COUNT(*) AS total,
                    SUM(CASE WHEN diff_type != 'match' THEN 1 ELSE 0 END) AS diverged
                 FROM biomie_shadow_validations
                 WHERE executed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            // Table may not exist yet — unknown is non-blocking
            return new \BIomie\SystemHealth\SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Tabela biomie_shadow_validations indisponível ou shadow desativado.',
                [], [], date('c'), $ms,
            );
        }

        $ms      = (int) round((microtime(true) - $start) * 1000);
        $total   = (int) ($row['total']   ?? 0);
        $diverged = (int) ($row['diverged'] ?? 0);

        if ($total === 0) {
            return new \BIomie\SystemHealth\SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Sem registros de shadow validation nas últimas 24h. Validação pode estar desativada.',
                ['total_24h' => 0, 'diverged_24h' => 0, 'divergence_pct' => null],
                [], date('c'), $ms,
            );
        }

        $pct = round(($diverged / $total) * 100, 2);
        $metrics = [
            'total_24h'       => $total,
            'diverged_24h'    => $diverged,
            'divergence_pct'  => $pct,
        ];

        if ($pct >= self::CRITICAL_PCT) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "Taxa de divergência: {$pct}% ({$diverged}/{$total} nas últimas 24h).",
                $metrics,
                ['Investigar divergências com tools/dev/shadow_audit.php.'],
                $ms,
            );
        }

        if ($pct >= self::WARN_PCT) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Taxa de divergência: {$pct}% ({$diverged}/{$total} nas últimas 24h).",
                $metrics,
                ['Monitorar tendência de divergências.'],
                $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "Taxa de divergência: {$pct}% ({$diverged}/{$total} nas últimas 24h).",
            $metrics,
            $ms,
        );
    }
}
