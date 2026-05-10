<?php

declare(strict_types=1);

namespace BIomie\Observability;

use PDO;
use Throwable;

/**
 * Agrega métricas de cobertura de enriquecimento dos clientes.
 *
 * Lê das tabelas existentes (biomie_clientes_dim, biomie_clientes_geo_dim,
 * mart_cliente_periodo) para produzir percentuais de cobertura prontos para
 * exibição no painel de observabilidade.
 *
 * Cobertura geo    → tem entrada em biomie_clientes_geo_dim
 * Cobertura CNAE   → cnae_codigo preenchido em biomie_clientes_dim
 * Clientes enrich. → tipo_pessoa preenchido (GovernanceEnrichment Fase 6)
 * Clientes ativos  → apareceram em mart_cliente_periodo nos últimos 3 meses
 */
final class CoverageAggregator
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{
     *   total_clients: int,
     *   geo_covered: int,
     *   geo_coverage_pct: float,
     *   cnae_covered: int,
     *   cnae_coverage_pct: float,
     *   enriched_covered: int,
     *   enriched_pct: float,
     *   active_3m_clients: int,
     *   active_3m_coverage_pct: float,
     *   computed_at: string
     * }
     */
    public function summarize(): array
    {
        $total      = (int) ($this->scalar('SELECT COUNT(*) FROM biomie_clientes_dim') ?? 0);
        $geoCovered = (int) ($this->scalar('SELECT COUNT(*) FROM biomie_clientes_geo_dim') ?? 0);
        $cnaeCovered = (int) ($this->scalar(
            "SELECT COUNT(*) FROM biomie_clientes_dim
             WHERE cnae_codigo IS NOT NULL AND cnae_codigo != ''"
        ) ?? 0);
        $enriched = (int) ($this->scalar(
            "SELECT COUNT(*) FROM biomie_clientes_dim
             WHERE tipo_pessoa IS NOT NULL AND tipo_pessoa != ''"
        ) ?? 0);

        // Clientes ativos nos últimos 3 meses completos
        $anchor3m = date('Y-m', strtotime('-2 months'));
        $active3m = (int) ($this->scalar(
            'SELECT COUNT(DISTINCT codigo_cliente_omie)
             FROM mart_cliente_periodo
             WHERE competencia >= :c',
            ['c' => $anchor3m]
        ) ?? 0);

        $pct = static fn (int $part, int $base): float =>
            $base > 0 ? round($part / $base * 100, 1) : 0.0;

        return [
            'total_clients'          => $total,
            'geo_covered'            => $geoCovered,
            'geo_coverage_pct'       => $pct($geoCovered, $total),
            'cnae_covered'           => $cnaeCovered,
            'cnae_coverage_pct'      => $pct($cnaeCovered, $total),
            'enriched_covered'       => $enriched,
            'enriched_pct'           => $pct($enriched, $total),
            'active_3m_clients'      => $active3m,
            'active_3m_coverage_pct' => $pct($active3m, $total),
            'computed_at'            => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function scalar(string $sql, array $params = []): int|null
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $val = $stmt->fetchColumn();
            return $val !== false ? (int) $val : null;
        } catch (Throwable) {
            return null;
        }
    }
}
