<?php

declare(strict_types=1);

namespace BIomie\Predictive;

use PDO;
use Throwable;

/**
 * Leitura da camada preditiva (mart_forecasts).
 *
 * Todas as consultas retornam [] / null quando o mart não estiver disponível,
 * permitindo fallback transparente no endpoint.
 */
final class ForecastRepository
{
    private const TABLE = 'mart_forecasts';

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
                'SELECT 1 FROM ' . self::TABLE . ' WHERE competencia_referencia = :c LIMIT 1'
            );
            $stmt->execute(['c' => $competencia]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    // ── Leituras por tipo ────────────────────────────────────────────────────

    /**
     * Forecast geral do mês — uma única linha.
     *
     * @return array<string,mixed>|null
     */
    public function mesCorriente(string $competencia): ?array
    {
        return $this->queryOne(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND tipo_forecast = 'mes_corrente'
             LIMIT 1",
            ['c' => $competencia]
        );
    }

    /**
     * Projeções por vendedor, ordenadas por valor projetado decrescente.
     *
     * @return list<array<string,mixed>>
     */
    public function byVendor(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND tipo_forecast = 'vendedor'
             ORDER BY valor_projetado DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Projeções por UF, ordenadas por valor projetado decrescente.
     *
     * @return list<array<string,mixed>>
     */
    public function byRegiao(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND tipo_forecast = 'regiao'
             ORDER BY valor_projetado DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Projeções por segmento de cliente, ordenadas por valor projetado.
     *
     * @return list<array<string,mixed>>
     */
    public function mixCategoria(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND tipo_forecast = 'mix_categoria'
             ORDER BY valor_projetado DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Forecast de carteira ativa — uma única linha.
     *
     * @return array<string,mixed>|null
     */
    public function carteira(string $competencia): ?array
    {
        return $this->queryOne(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND tipo_forecast = 'carteira'
             LIMIT 1",
            ['c' => $competencia]
        );
    }

    /**
     * Todos os registros com risco_level = 'alto'.
     *
     * @return list<array<string,mixed>>
     */
    public function atRisk(string $competencia): array
    {
        return $this->query(
            "SELECT * FROM " . self::TABLE . "
             WHERE competencia_referencia = :c
               AND risco_level = 'alto'
             ORDER BY tipo_forecast, ABS(tendencia_pct) DESC",
            ['c' => $competencia]
        );
    }

    /**
     * Resumo executivo — contagens e totais por tipo de forecast.
     *
     * @return array<string,mixed>
     */
    public function summary(string $competencia): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                     COUNT(*)                                       AS total_forecasts,
                     SUM(risco_level = 'alto')                     AS em_risco,
                     SUM(tipo_forecast = 'vendedor')               AS vendedores,
                     SUM(tipo_forecast = 'regiao')                 AS regioes,
                     SUM(tipo_forecast = 'mix_categoria')          AS segmentos,
                     ROUND(AVG(confianca_pct))                     AS confianca_media,
                     SUM(tendencia = 'queda')                      AS tendencia_queda,
                     SUM(tendencia = 'crescimento')                AS tendencia_crescimento,
                     MAX(refreshed_at)                             AS ultima_projecao
                 FROM " . self::TABLE . "
                 WHERE competencia_referencia = :c"
            );
            $stmt->execute(['c' => $competencia]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
