<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class GeoDataQualityCheck implements CheckInterface
{
    private const WARN_PCT     = 50.0;
    private const OK_PCT       = 80.0;
    private const BAIRROS_MIN  = 100;

    public function __construct(private readonly ?PDO $pdo) {}

    public function id(): string    { return 'geo_quality'; }
    public function label(): string { return 'Qualidade de Dados Geográficos'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);

        if ($this->pdo === null) {
            return new SystemHealthCheck(
                $this->id(), $this->label(), 'unknown', 'info',
                'Banco de dados inacessível — check não executado.', [], [], date('c'), 0,
            );
        }

        // Check bairros dim
        $bairrosCount = 0;
        $bairrosOk    = false;
        try {
            $bairrosCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim"
            )->fetchColumn();
            $bairrosOk = $bairrosCount >= self::BAIRROS_MIN;
        } catch (Throwable) {
            // Table may not be seeded yet
        }

        // Check geo enrichment rate
        $totalClients = 0;
        $geoEnriched  = 0;
        try {
            $totalClients = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim"
            )->fetchColumn();

            $geoEnriched = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_geo_dim"
            )->fetchColumn();
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, $ms);
        }

        $ms  = (int) round((microtime(true) - $start) * 1000);
        $pct = $totalClients > 0 ? round(($geoEnriched / $totalClients) * 100, 2) : null;

        $metrics = [
            'total_clients'   => $totalClients,
            'geo_enriched'    => $geoEnriched,
            'enrichment_pct'  => $pct,
            'bairros_count'   => $bairrosCount,
            'bairros_seeded'  => $bairrosOk,
        ];

        $actions = [];

        if (!$bairrosOk) {
            $actions[] = 'Aplicar e executar a migration BairrosFortalezaDim para semear os bairros.';
        }

        if ($totalClients === 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'biomie_clientes_dim vazia — não é possível calcular enrichment geográfico.',
                $metrics, $actions, $ms,
            );
        }

        if ($pct !== null && $pct < self::WARN_PCT) {
            $actions[] = 'Executar geo enrichment: php cron/geo_enrich_clients.php';
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                "Enrichment geográfico baixo: {$pct}% dos clientes com geo (mín. " . self::OK_PCT . "%).",
                $metrics, $actions, $ms,
            );
        }

        if ($pct !== null && $pct < self::OK_PCT) {
            $actions[] = 'Verificar cobertura do geo enrichment.';
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Enrichment geográfico: {$pct}% dos clientes com geo.",
                $metrics, $actions, $ms,
            );
        }

        if (!$bairrosOk) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                "Enrichment {$pct}% OK, mas biomie_bairros_fortaleza_dim com {$bairrosCount} registros (mín. " . self::BAIRROS_MIN . ").",
                $metrics, $actions, $ms,
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            "Enrichment geográfico: {$pct}%. Bairros: {$bairrosCount}.",
            $metrics, $ms,
        );
    }
}
