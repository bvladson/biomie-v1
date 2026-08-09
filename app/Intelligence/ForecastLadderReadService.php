<?php

declare(strict_types=1);

namespace BIomie\Intelligence;

use PDO;
use Throwable;

/**
 * Le a camada PRESCRITIVA da escada de metas, calculada pelo servico Python.
 *
 * ─── O QUE ELE ACRESCENTA QUE O PHP NAO TEM ─────────────────────────────────
 * `CommercialPlanning` sabe onde o vendedor esta (realizado, nivel conquistado,
 * quanto falta, quanto por dia util). O que ele NAO tem e o olhar para a frente:
 *
 *   · probabilidade de alcancar CADA nivel da escada, dado o ritmo e o erro
 *     historico daquele vendedor;
 *   · proximo patamar sugerido para quem ja zerou a escada.
 *
 * Isso sai de `target_ladder.recommend()` e de `insights.calcular_forecasts`, e
 * agora e persistido em `intelligence_forecasts`.
 *
 * ─── DEGRADACAO GRACIOSA, PELO MESMO MOTIVO DO QuotePriorityReadService ──────
 * Se a tabela nao existe (migration nao aplicada), se o cron do Python parou ou
 * se a consulta falha, a tela de vendedores continua funcionando sem a camada
 * prescritiva. Ela e enriquecimento — nao pode derrubar a tela que o gestor usa.
 *
 * ─── O PORTAO DE CONFIANCA VIAJA JUNTO ──────────────────────────────────────
 * `ladder_confidence` vem do erro historico do vendedor. Hoje, com 2 a 5 meses
 * de historico, praticamente todos caem em `baixa` — e a probabilidade de cada
 * nivel e gravada mesmo assim, para o erro poder ser cobrado depois. Quem exibe
 * decide o que fazer com isso; este servico nao esconde nem promove.
 */
final class ForecastLadderReadService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Camada prescritiva por vendedor, indexada pelo codigo do vendedor na Omie.
     *
     * @return array<string,array<string,mixed>>
     */
    public function forVendors(int $companyId, string $competencia): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT entidade_codigo, required_daily, projected_level,
                        recommended_next_target, ladder_confidence,
                        level_probabilities_json, projecao, risco_nao_bater
                   FROM intelligence_forecasts
                  WHERE company_id = :company
                    AND competencia = :competencia
                    AND dimensao = 'vendedor'
                    AND is_current = 1"
            );
            $stmt->execute(['company' => $companyId, 'competencia' => $competencia]);
        } catch (Throwable) {
            // Tabela ausente ou coluna nova ainda nao migrada: sem camada
            // prescritiva, e a tela segue.
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $codigo = (string) ($row['entidade_codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }

            $probabilidades = json_decode((string) ($row['level_probabilities_json'] ?? '[]'), true);

            $out[$codigo] = [
                // NULL preservado de proposito: "nao calculado" e diferente de
                // "nao precisa de nada por dia".
                'required_daily' => $row['required_daily'] !== null ? (float) $row['required_daily'] : null,
                'projected_level' => $row['projected_level'] !== null ? (int) $row['projected_level'] : null,
                'recommended_next_target' => $row['recommended_next_target'] !== null
                    ? (float) $row['recommended_next_target']
                    : null,
                'ladder_confidence' => (string) ($row['ladder_confidence'] ?? 'sem_dados'),
                'level_probabilities' => is_array($probabilidades) ? $probabilidades : [],
                'forecast_projection' => $row['projecao'] !== null ? (float) $row['projecao'] : null,
            ];
        }

        return $out;
    }

    /**
     * Funde a camada prescritiva na lista de vendedores do `CommercialPlanning`.
     *
     * O casamento e pelo codigo Omie do vendedor, que e o mesmo dos dois lados
     * (`entidade_codigo` no Python sai de `codigo_vendedor_omie`).
     *
     * Vendedor sem linha no forecast fica SEM os campos, nao com zeros: a tela
     * precisa distinguir "o modelo nao falou sobre este" de "o modelo disse
     * zero".
     *
     * @param  list<array<string,mixed>> $vendors
     * @return list<array<string,mixed>>
     */
    public function mergeInto(array $vendors, int $companyId, string $competencia): array
    {
        $prescricao = $this->forVendors($companyId, $competencia);
        if ($prescricao === []) {
            return $vendors;
        }

        foreach ($vendors as $i => $vendor) {
            $codigo = (string) ($vendor['vendor_id'] ?? '');
            if ($codigo === '' || !isset($prescricao[$codigo])) {
                continue;
            }
            $vendors[$i] = array_merge($vendor, $prescricao[$codigo]);
        }

        return $vendors;
    }
}
