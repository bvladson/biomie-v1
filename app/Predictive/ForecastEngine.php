<?php

declare(strict_types=1);

namespace BIomie\Predictive;

use DateTimeImmutable;
use PDO;
use Throwable;
use BIomie\Logger;

/**
 * Camada preditiva — Fase 8.
 *
 * Todos os métodos usam projeção linear simples ou proporção de dias.
 * Nenhum modelo de ML: transparência total sobre dados e método.
 *
 * Confiança baseada em dias com dados disponíveis no mês corrente:
 *   < 7 d  → 20 %  (muito cedo, tendência não estabelecida)
 *   7–13 d → 45 %  (primeira semana completa)
 *  14–20 d → 65 %  (metade do mês)
 *  21–27 d → 80 %  (reta final)
 *  ≥ 28 d  → 95 %  (quase fechado)
 *
 * Tendência: compara soma dos 3 meses fechados anteriores
 * com a soma dos 3 meses antes desses (janela de 6 meses).
 */
final class ForecastEngine
{
    private const TABLE = 'mart_forecasts';

    /** @var list<array{0:int,1:int}> [min_days, confidence_pct] */
    private const CONFIDENCE_TIERS = [
        [28, 95],
        [21, 80],
        [14, 65],
        [7,  45],
        [0,  20],
    ];

    private const TREND_STABLE_THRESHOLD = 5.0; // % — abaixo disso é "estável"

    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {
    }

    // ── Ponto de entrada ─────────────────────────────────────────────────────

    /**
     * Computa e materializa todos os forecasts para a competência indicada.
     *
     * @return array<string,int> contagem de linhas upsertadas por tipo
     */
    public function refreshForCompetencia(string $competencia): array
    {
        [$year, $month] = array_map('intval', explode('-', $competencia));
        $daysInMonth    = (int) (new DateTimeImmutable("{$year}-{$month}-01"))->format('t');
        $monthStart     = "{$competencia}-01";
        $monthEnd       = "{$competencia}-{$daysInMonth}";

        // Se estamos no mês corrente: usa dias corridos; se mês passado: usa dias completos.
        $today       = new DateTimeImmutable();
        $daysElapsed = $today->format('Y-m') === $competencia
            ? (int) $today->format('j')
            : $daysInMonth;

        $counts = [];
        $counts['mes_corrente']  = $this->forecastMesCorrente($competencia, $monthStart, $monthEnd, $daysInMonth, $daysElapsed);
        $counts['vendedor']      = $this->forecastByVendor($competencia, $monthStart, $monthEnd, $daysInMonth, $daysElapsed);
        $counts['regiao']        = $this->forecastByRegiao($competencia, $monthStart, $monthEnd, $daysInMonth, $daysElapsed);
        $counts['mix_categoria'] = $this->forecastMixCategoria($competencia, $daysInMonth, $daysElapsed);
        $counts['carteira']      = $this->forecastCarteira($competencia, $monthEnd, $daysInMonth, $daysElapsed);

        return $counts;
    }

    // ── Forecast geral do mês ────────────────────────────────────────────────

    private function forecastMesCorrente(
        string $competencia,
        string $monthStart,
        string $monthEnd,
        int $daysInMonth,
        int $daysElapsed,
    ): int {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(valor_faturado), 0) AS realizado,
                        COUNT(DISTINCT dia)               AS dias_base
                 FROM mart_vendedor_dia
                 WHERE dia BETWEEN :start AND :end'
            );
            $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $realizado = (float) ($data['realizado'] ?? 0);
            $diasBase  = (int)   ($data['dias_base']  ?? 0);
            $projetado = $this->linearProject($realizado, $diasBase, $daysInMonth);
            $confianca = $this->confidence($diasBase);
            $tendencia = $this->computeTrend('geral', null, $competencia);

            $this->upsert([
                'tipo_forecast'          => 'mes_corrente',
                'codigo_entidade'        => 'geral',
                'nome_entidade'          => 'Faturamento Geral',
                'competencia_referencia' => $competencia,
                'valor_realizado'        => $realizado,
                'valor_projetado'        => $projetado,
                'valor_meta'             => null,
                'confianca_pct'          => $confianca,
                'metodo'                 => 'linear_simples',
                'dias_base'              => $diasBase,
                'dias_restantes'         => max(0, $daysInMonth - $daysElapsed),
                'tendencia'              => $tendencia['label'],
                'tendencia_pct'          => $tendencia['pct'],
                'gap_para_meta'          => null,
                'gap_pct'                => null,
                'risco_level'            => $this->riskFromTrend($tendencia['label']),
            ]);
            return 1;
        } catch (Throwable $e) {
            $this->logger->warning('ForecastEngine::forecastMesCorrente falhou.', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── Forecast por vendedor ────────────────────────────────────────────────

    private function forecastByVendor(
        string $competencia,
        string $monthStart,
        string $monthEnd,
        int $daysInMonth,
        int $daysElapsed,
    ): int {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT codigo_vendedor_omie,
                        nome_vendedor,
                        COALESCE(SUM(valor_faturado), 0) AS realizado,
                        COUNT(DISTINCT dia)               AS dias_base
                 FROM mart_vendedor_dia
                 WHERE dia BETWEEN :start AND :end
                   AND codigo_vendedor_omie != \'\'
                 GROUP BY codigo_vendedor_omie, nome_vendedor'
            );
            $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $count = 0;
            foreach ($rows as $row) {
                $realizado = (float) $row['realizado'];
                $diasBase  = (int)   $row['dias_base'];
                $projetado = $this->linearProject($realizado, $diasBase, $daysInMonth);
                $confianca = $this->confidence($diasBase);
                $tendencia = $this->computeTrend('vendedor', (string) $row['codigo_vendedor_omie'], $competencia);

                $this->upsert([
                    'tipo_forecast'          => 'vendedor',
                    'codigo_entidade'        => (string) $row['codigo_vendedor_omie'],
                    'nome_entidade'          => (string) ($row['nome_vendedor'] ?? ''),
                    'competencia_referencia' => $competencia,
                    'valor_realizado'        => $realizado,
                    'valor_projetado'        => $projetado,
                    'valor_meta'             => null,
                    'confianca_pct'          => $confianca,
                    'metodo'                 => 'linear_simples',
                    'dias_base'              => $diasBase,
                    'dias_restantes'         => max(0, $daysInMonth - $daysElapsed),
                    'tendencia'              => $tendencia['label'],
                    'tendencia_pct'          => $tendencia['pct'],
                    'gap_para_meta'          => null,
                    'gap_pct'                => null,
                    'risco_level'            => $this->riskFromTrend($tendencia['label']),
                ]);
                $count++;
            }
            return $count;
        } catch (Throwable $e) {
            $this->logger->warning('ForecastEngine::forecastByVendor falhou.', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── Forecast por região (UF) ─────────────────────────────────────────────

    private function forecastByRegiao(
        string $competencia,
        string $monthStart,
        string $monthEnd,
        int $daysInMonth,
        int $daysElapsed,
    ): int {
        try {
            // biomie_geo_vendas_diaria agrega por dia × uf × cidade_norm × segmento.
            // Consolida em uf para o forecast de região.
            $stmt = $this->pdo->prepare(
                'SELECT uf                                AS codigo_entidade,
                        uf                                AS nome_entidade,
                        COALESCE(SUM(valor_total), 0)     AS realizado,
                        COUNT(DISTINCT data_referencia)   AS dias_base
                 FROM biomie_geo_vendas_diaria
                 WHERE data_referencia BETWEEN :start AND :end
                   AND uf != \'\'
                 GROUP BY uf
                 HAVING realizado > 0'
            );
            $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $count = 0;
            foreach ($rows as $row) {
                $realizado = (float) $row['realizado'];
                $diasBase  = (int)   $row['dias_base'];
                $projetado = $this->linearProject($realizado, $diasBase, $daysInMonth);
                $confianca = $this->confidence($diasBase);
                $tendencia = $this->computeTrend('regiao', (string) $row['codigo_entidade'], $competencia);

                $this->upsert([
                    'tipo_forecast'          => 'regiao',
                    'codigo_entidade'        => (string) $row['codigo_entidade'],
                    'nome_entidade'          => (string) $row['nome_entidade'],
                    'competencia_referencia' => $competencia,
                    'valor_realizado'        => $realizado,
                    'valor_projetado'        => $projetado,
                    'valor_meta'             => null,
                    'confianca_pct'          => $confianca,
                    'metodo'                 => 'linear_simples',
                    'dias_base'              => $diasBase,
                    'dias_restantes'         => max(0, $daysInMonth - $daysElapsed),
                    'tendencia'              => $tendencia['label'],
                    'tendencia_pct'          => $tendencia['pct'],
                    'gap_para_meta'          => null,
                    'gap_pct'                => null,
                    'risco_level'            => $this->riskFromTrend($tendencia['label']),
                ]);
                $count++;
            }
            return $count;
        } catch (Throwable $e) {
            $this->logger->warning('ForecastEngine::forecastByRegiao falhou.', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── Forecast mix por segmento de cliente ─────────────────────────────────

    private function forecastMixCategoria(
        string $competencia,
        int $daysInMonth,
        int $daysElapsed,
    ): int {
        try {
            // mart_cliente_periodo é agregado por mês (não por dia), então usamos
            // proporção de dias corridos para extrapolar o fechamento.
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(segmento_cnae, 'Não classificado') AS codigo_entidade,
                        COALESCE(segmento_cnae, 'Não classificado') AS nome_entidade,
                        COALESCE(SUM(valor_faturado), 0)             AS realizado
                 FROM mart_cliente_periodo
                 WHERE competencia = :c
                 GROUP BY segmento_cnae
                 HAVING realizado > 0"
            );
            $stmt->execute(['c' => $competencia]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pctElapsed = $daysElapsed > 0 ? ($daysElapsed / $daysInMonth) : 1.0;
            $confianca  = $this->confidence($daysElapsed);

            $count = 0;
            foreach ($rows as $row) {
                $realizado = (float) $row['realizado'];
                $projetado = $pctElapsed > 0 ? round($realizado / $pctElapsed, 2) : $realizado;
                $tendencia = $this->computeTrend('mix_categoria', (string) $row['codigo_entidade'], $competencia);

                $this->upsert([
                    'tipo_forecast'          => 'mix_categoria',
                    'codigo_entidade'        => (string) $row['codigo_entidade'],
                    'nome_entidade'          => (string) $row['nome_entidade'],
                    'competencia_referencia' => $competencia,
                    'valor_realizado'        => $realizado,
                    'valor_projetado'        => $projetado,
                    'valor_meta'             => null,
                    'confianca_pct'          => $confianca,
                    'metodo'                 => 'proporcional_mes',
                    'dias_base'              => $daysElapsed,
                    'dias_restantes'         => max(0, $daysInMonth - $daysElapsed),
                    'tendencia'              => $tendencia['label'],
                    'tendencia_pct'          => $tendencia['pct'],
                    'gap_para_meta'          => null,
                    'gap_pct'                => null,
                    'risco_level'            => $this->riskFromTrend($tendencia['label']),
                ]);
                $count++;
            }
            return $count;
        } catch (Throwable $e) {
            $this->logger->warning('ForecastEngine::forecastMixCategoria falhou.', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── Forecast carteira ativa ──────────────────────────────────────────────

    /**
     * Projeta receita a partir de clientes ativos × ticket médio mensal (12 meses).
     * Método menos preciso — confiança limitada a 60 mesmo com mês quase fechado.
     */
    private function forecastCarteira(
        string $competencia,
        string $monthEnd,
        int $daysInMonth,
        int $daysElapsed,
    ): int {
        try {
            // Clientes ativos: compraram nos últimos 90 dias em relação ao fim do mês.
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT codigo_cliente_omie)  AS clientes_ativos,
                        COALESCE(AVG(faturado_12m / 12), 0) AS ticket_medio_mensal
                 FROM biomie_clientes_dim
                 WHERE ultima_compra_em >= DATE_SUB(:mend, INTERVAL 90 DAY)
                   AND faturado_12m > 0'
            );
            $stmt->execute(['mend' => $monthEnd]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $clientesAtivos  = (int)   ($data['clientes_ativos']    ?? 0);
            $ticketMedio     = (float) ($data['ticket_medio_mensal'] ?? 0);
            $projetadoCarteira = round($clientesAtivos * $ticketMedio, 2);

            // Realizado do mês vem do mart_cliente_periodo.
            $stmtReal = $this->pdo->prepare(
                'SELECT COALESCE(SUM(valor_faturado), 0) AS realizado
                 FROM mart_cliente_periodo
                 WHERE competencia = :c'
            );
            $stmtReal->execute(['c' => $competencia]);
            $realizado = (float) ($stmtReal->fetchColumn() ?? 0);

            // Confiança limitada: ticket médio 12m é uma aproximação grosseira.
            $confianca = min(60, $this->confidence($daysElapsed));
            $tendencia = $this->computeTrend('geral', null, $competencia);

            $this->upsert([
                'tipo_forecast'          => 'carteira',
                'codigo_entidade'        => 'geral',
                'nome_entidade'          => 'Carteira Ativa',
                'competencia_referencia' => $competencia,
                'valor_realizado'        => $realizado,
                'valor_projetado'        => $projetadoCarteira,
                'valor_meta'             => null,
                'confianca_pct'          => $confianca,
                'metodo'                 => 'clientes_ativos_x_ticket_medio',
                'dias_base'              => $daysElapsed,
                'dias_restantes'         => max(0, $daysInMonth - $daysElapsed),
                'tendencia'              => $tendencia['label'],
                'tendencia_pct'          => $tendencia['pct'],
                'gap_para_meta'          => null,
                'gap_pct'                => null,
                'risco_level'            => $this->riskFromTrend($tendencia['label']),
            ]);
            return 1;
        } catch (Throwable $e) {
            $this->logger->warning('ForecastEngine::forecastCarteira falhou.', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ── Tendência histórica (trio recente vs. trio anterior) ─────────────────

    /**
     * Compara os 3 meses fechados imediatamente anteriores à competência
     * com os 3 meses antes desses (janela 4-6 meses atrás).
     *
     * @return array{label:string, pct:float}
     */
    private function computeTrend(string $domain, ?string $entityCode, string $competencia): array
    {
        try {
            $base = new DateTimeImmutable(substr($competencia, 0, 7) . '-01');

            $recentMonths = [];
            $priorMonths  = [];

            for ($i = 1; $i <= 3; $i++) {
                $recentMonths[] = $base->modify("-{$i} month")->format('Y-m');
            }
            for ($i = 4; $i <= 6; $i++) {
                $priorMonths[] = $base->modify("-{$i} month")->format('Y-m');
            }

            $recent = $this->sumForMonths($domain, $entityCode, $recentMonths);
            $prior  = $this->sumForMonths($domain, $entityCode, $priorMonths);

            if ($prior <= 0 && $recent <= 0) {
                return ['label' => 'inconclusivo', 'pct' => 0.0];
            }

            if ($prior <= 0) {
                return ['label' => 'crescimento', 'pct' => 100.0];
            }

            $variacao = (($recent - $prior) / $prior) * 100;

            $label = match (true) {
                abs($variacao) < self::TREND_STABLE_THRESHOLD => 'estavel',
                $variacao > 0                                  => 'crescimento',
                default                                        => 'queda',
            };

            return ['label' => $label, 'pct' => round($variacao, 2)];
        } catch (Throwable) {
            return ['label' => 'inconclusivo', 'pct' => 0.0];
        }
    }

    /**
     * Soma o valor faturado em um conjunto de competências, por domínio.
     *
     * @param list<string> $months YYYY-MM
     */
    private function sumForMonths(string $domain, ?string $entityCode, array $months): float
    {
        if (empty($months)) {
            return 0.0;
        }

        $ph = implode(',', array_fill(0, count($months), '?'));

        try {
            switch ($domain) {
                case 'geral':
                case 'carteira':
                    $stmt = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(valor_faturado), 0)
                         FROM mart_cliente_periodo
                         WHERE competencia IN ({$ph})"
                    );
                    $stmt->execute($months);
                    break;

                case 'vendedor':
                    if ($entityCode === null) {
                        return 0.0;
                    }
                    $stmt = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(valor_faturado), 0)
                         FROM mart_vendedor_dia
                         WHERE competencia IN ({$ph})
                           AND codigo_vendedor_omie = ?"
                    );
                    $stmt->execute([...$months, $entityCode]);
                    break;

                case 'regiao':
                    if ($entityCode === null) {
                        return 0.0;
                    }
                    $stmt = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(valor_total), 0)
                         FROM biomie_geo_vendas_diaria
                         WHERE DATE_FORMAT(data_referencia, '%Y-%m') IN ({$ph})
                           AND uf = ?"
                    );
                    $stmt->execute([...$months, $entityCode]);
                    break;

                case 'mix_categoria':
                    if ($entityCode === null) {
                        return 0.0;
                    }
                    $stmt = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(valor_faturado), 0)
                         FROM mart_cliente_periodo
                         WHERE competencia IN ({$ph})
                           AND COALESCE(segmento_cnae, 'Não classificado') = ?"
                    );
                    $stmt->execute([...$months, $entityCode]);
                    break;

                default:
                    return 0.0;
            }

            return (float) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0.0;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function linearProject(float $realizado, int $diasBase, int $daysInMonth): float
    {
        if ($diasBase <= 0 || $realizado <= 0) {
            return 0.0;
        }
        return round(($realizado / $diasBase) * $daysInMonth, 2);
    }

    private function confidence(int $diasBase): int
    {
        foreach (self::CONFIDENCE_TIERS as [$threshold, $pct]) {
            if ($diasBase >= $threshold) {
                return $pct;
            }
        }
        return 20;
    }

    private function riskFromTrend(string $tendencia): string
    {
        return match ($tendencia) {
            'queda'       => 'alto',
            'crescimento' => 'baixo',
            default       => 'medio',
        };
    }

    /**
     * @param array<string,mixed> $data
     */
    private function upsert(array $data): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
                (tipo_forecast, codigo_entidade, nome_entidade, competencia_referencia,
                 valor_realizado, valor_projetado, valor_meta,
                 confianca_pct, metodo, dias_base, dias_restantes,
                 tendencia, tendencia_pct,
                 gap_para_meta, gap_pct, risco_level,
                 refreshed_at)
             VALUES
                (:tipo_forecast, :codigo_entidade, :nome_entidade, :competencia_referencia,
                 :valor_realizado, :valor_projetado, :valor_meta,
                 :confianca_pct, :metodo, :dias_base, :dias_restantes,
                 :tendencia, :tendencia_pct,
                 :gap_para_meta, :gap_pct, :risco_level,
                 NOW())
             ON DUPLICATE KEY UPDATE
                 nome_entidade   = VALUES(nome_entidade),
                 valor_realizado = VALUES(valor_realizado),
                 valor_projetado = VALUES(valor_projetado),
                 valor_meta      = VALUES(valor_meta),
                 confianca_pct   = VALUES(confianca_pct),
                 metodo          = VALUES(metodo),
                 dias_base       = VALUES(dias_base),
                 dias_restantes  = VALUES(dias_restantes),
                 tendencia       = VALUES(tendencia),
                 tendencia_pct   = VALUES(tendencia_pct),
                 gap_para_meta   = VALUES(gap_para_meta),
                 gap_pct         = VALUES(gap_pct),
                 risco_level     = VALUES(risco_level),
                 refreshed_at    = NOW()'
        )->execute($data);
    }
}
