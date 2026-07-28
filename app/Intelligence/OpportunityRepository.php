<?php

declare(strict_types=1);

namespace BIomie\Intelligence;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * Leitura da camada de inteligência comercial (mart_oportunidades).
 *
 * Todas as consultas retornam [] quando o mart não estiver disponível,
 * permitindo fallback transparente no endpoint.
 */
final class OpportunityRepository
{
    private const TABLE = 'mart_oportunidades';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ── Disponibilidade ──────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function hasDataForCompetencia(string $competencia): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . '
                 WHERE ' . TenantContext::sqlFilter() . '
                   AND competencia_referencia = :c
                 LIMIT 1'
            );
            $stmt->execute(['c' => $competencia]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    // ── Oportunidades de clientes ────────────────────────────────────────────

    /**
     * Clientes que sumiram — dormentes com bom histórico.
     *
     * @return list<array<string,mixed>>
     */
    public function dormantClients(string $competencia, int $limit = 20): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND tipo_entidade = 'cliente'
               AND tipo_oportunidade IN ('cliente_sumiu', 'recompra_potencial')
             ORDER BY score DESC, valor_referencia DESC
             LIMIT :limit",
            ['c' => $competencia, 'limit' => $limit]
        );
    }

    /**
     * Clientes com queda de compra no período.
     *
     * @return list<array<string,mixed>>
     */
    public function decliningClients(string $competencia, int $limit = 20): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND tipo_entidade = 'cliente'
               AND tipo_oportunidade = 'queda_de_compra'
             ORDER BY score DESC, variacao_pct ASC
             LIMIT :limit",
            ['c' => $competencia, 'limit' => $limit]
        );
    }

    /**
     * Alertas de concentração — clientes e regiões com share excessivo.
     *
     * @return list<array<string,mixed>>
     */
    public function concentrationAlerts(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND tipo_oportunidade IN ('concentracao_cliente', 'concentracao_geo')
             ORDER BY score DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Vendedores com carteira inativa.
     *
     * @return list<array<string,mixed>>
     */
    public function underutilizedVendors(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND tipo_oportunidade = 'vendedor_sem_ativacao'
             ORDER BY score DESC, clientes_count DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Movimentações geográficas — crescimento e retração de regiões.
     *
     * @return list<array<string,mixed>>
     */
    public function geoMovements(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND tipo_entidade = 'regiao'
             ORDER BY ABS(variacao_pct) DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Top oportunidades globais — as N de maior score independente do tipo.
     *
     * @return list<array<string,mixed>>
     */
    public function topOpportunities(string $competencia, int $limit = 15): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
             ORDER BY score DESC
             LIMIT :limit",
            ['c' => $competencia, 'limit' => $limit]
        );
    }

    /**
     * Oportunidades por vendedor — útil para exibir no card do vendedor.
     *
     * @return list<array<string,mixed>>
     */
    public function byVendor(string $competencia, string $codigoVendedor, int $limit = 10): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND " . TenantContext::sqlFilter() . "
               AND codigo_vendedor_omie = :v
             ORDER BY score DESC
             LIMIT :limit",
            ['c' => $competencia, 'v' => $codigoVendedor, 'limit' => $limit]
        );
    }

    /**
     * Resumo executivo — contagens por tipo de oportunidade.
     *
     * @return array<string,mixed>
     */
    public function summary(string $competencia): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                     COUNT(*)                                          AS total,
                     SUM(score >= 80)                                  AS alta_prioridade,
                     SUM(tipo_oportunidade IN ('cliente_sumiu', 'recompra_potencial')) AS clientes_dormentes,
                     SUM(tipo_oportunidade = 'queda_de_compra')        AS queda_de_compra,
                     SUM(tipo_oportunidade IN ('concentracao_cliente', 'concentracao_geo')) AS alertas_concentracao,
                     SUM(tipo_oportunidade = 'vendedor_sem_ativacao')  AS vendedores_com_problema,
                     SUM(tipo_oportunidade = 'crescimento_geo')        AS regioes_em_crescimento,
                     SUM(tipo_oportunidade = 'retracao_geo')           AS regioes_em_retracao,
                     MAX(refreshed_at)                                 AS ultima_deteccao
                 FROM " . self::TABLE . "
                 WHERE " . TenantContext::sqlFilter() . "
                   AND competencia_referencia = :c"
            );
            $stmt->execute(['c' => $competencia]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
