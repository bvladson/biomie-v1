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
        // Clientes ativos nos últimos 3 meses completos
        $anchor3m = date('Y-m', strtotime('-2 months'));
        $snapshot = $this->coverageSnapshot($anchor3m);
        $total = (int) ($snapshot['total_clients'] ?? 0);
        $geoCovered = (int) ($snapshot['geo_covered'] ?? 0);
        $cnaeCovered = (int) ($snapshot['cnae_covered'] ?? 0);
        $enriched = (int) ($snapshot['enriched_covered'] ?? 0);
        $active3m = (int) ($snapshot['active_3m_clients'] ?? 0);

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
     * Uma única ida ao banco; as subconsultas mantêm cada fonte independente
     * sem multiplicar linhas por JOIN.
     *
     * @return array<string,int>
     */
    private function coverageSnapshot(string $anchor3m): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT base.total_clients,
                        base.cnae_covered,
                        base.enriched_covered,
                        (SELECT COUNT(*) FROM biomie_clientes_geo_dim) AS geo_covered,
                        (SELECT COUNT(DISTINCT codigo_cliente_omie)
                           FROM mart_cliente_periodo
                          WHERE competencia >= :anchor) AS active_3m_clients
                   FROM (
                        SELECT COUNT(*) AS total_clients,
                               COALESCE(SUM(CASE WHEN cnae_codigo IS NOT NULL AND cnae_codigo != '' THEN 1 ELSE 0 END), 0) AS cnae_covered,
                               COALESCE(SUM(CASE WHEN tipo_pessoa IS NOT NULL AND tipo_pessoa != '' THEN 1 ELSE 0 END), 0) AS enriched_covered
                          FROM biomie_clientes_dim
                   ) base"
            );
            $stmt->execute(['anchor' => $anchor3m]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return [
                    'total_clients' => (int) ($row['total_clients'] ?? 0),
                    'geo_covered' => (int) ($row['geo_covered'] ?? 0),
                    'cnae_covered' => (int) ($row['cnae_covered'] ?? 0),
                    'enriched_covered' => (int) ($row['enriched_covered'] ?? 0),
                    'active_3m_clients' => (int) ($row['active_3m_clients'] ?? 0),
                ];
            }
        } catch (Throwable) {
            // Instalações em migração podem não possuir todas as tabelas.
        }

        $clientCoverage = $this->clientCoverage();
        return [
            'total_clients' => (int) ($clientCoverage['total_clients'] ?? 0),
            'geo_covered' => (int) ($this->scalar('SELECT COUNT(*) FROM biomie_clientes_geo_dim') ?? 0),
            'cnae_covered' => (int) ($clientCoverage['cnae_covered'] ?? 0),
            'enriched_covered' => (int) ($clientCoverage['enriched_covered'] ?? 0),
            'active_3m_clients' => (int) ($this->scalar(
                'SELECT COUNT(DISTINCT codigo_cliente_omie) FROM mart_cliente_periodo WHERE competencia >= :c',
                ['c' => $anchor3m]
            ) ?? 0),
        ];
    }

    /**
     * @return array{total_clients: int, cnae_covered: int, enriched_covered: int}|null
     */
    private function clientCoverage(): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COUNT(*) AS total_clients,
                    COALESCE(SUM(CASE
                        WHEN cnae_codigo IS NOT NULL AND cnae_codigo != '' THEN 1
                        ELSE 0
                    END), 0) AS cnae_covered,
                    COALESCE(SUM(CASE
                        WHEN tipo_pessoa IS NOT NULL AND tipo_pessoa != '' THEN 1
                        ELSE 0
                    END), 0) AS enriched_covered
                 FROM biomie_clientes_dim"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            return [
                'total_clients' => (int) ($row['total_clients'] ?? 0),
                'cnae_covered' => (int) ($row['cnae_covered'] ?? 0),
                'enriched_covered' => (int) ($row['enriched_covered'] ?? 0),
            ];
        } catch (Throwable) {
            return null;
        }
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
