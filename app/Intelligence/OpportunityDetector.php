<?php

declare(strict_types=1);

namespace BIomie\Intelligence;

use BIomie\Logger;
use PDO;
use Throwable;

/**
 * Detecta oportunidades comerciais e materializa em mart_oportunidades.
 *
 * Cada método de detecção é idempotente — executa INSERT … ON DUPLICATE KEY UPDATE,
 * então re-rodar para o mesmo período apenas atualiza registros existentes.
 *
 * Detecções implementadas:
 *   1. detectDormantClients()       — clientes que sumiram (dormente/inativo com bom histórico)
 *   2. detectRecompraClients()      — dormentes de alto valor, prime para ativação
 *   3. detectDecliningClients()     — queda de compra vs competência anterior
 *   4. detectConcentrationRisk()    — cliente concentra > CONCENTRATION_THRESHOLD da receita
 *   5. detectVendorUnderutilization() — vendedor com carteira de clientes inativos
 *   6. detectGeoMovements()         — crescimento/retração de regiões vs período anterior
 *
 * Ordem de execução: refreshForCompetencia() chama todos na sequência correta.
 */
final class OpportunityDetector
{
    private const TABLE = 'mart_oportunidades';

    // Limiares — ponto único de verdade para todas as regras de detecção.
    private const DORMANT_MIN_VALUE      = 500.0;   // Faturado 12M mínimo para detectar como dormido
    private const RECOMPRA_MIN_VALUE     = 2000.0;  // Histórico mínimo para marcar como recompra potencial
    private const DECLINE_THRESHOLD_PCT  = 20.0;    // Queda mínima (%) para detectar queda de compra
    private const DECLINE_MIN_VALUE      = 300.0;   // Valor mínimo no período anterior para considerar
    private const CONCENTRATION_PCT      = 25.0;    // Share (%) de receita para alerta de concentração
    private const VENDOR_INACTIVE_PCT    = 35.0;    // % de carteira inativa para alerta de vendedor
    private const VENDOR_MIN_CLIENTS     = 3;       // Mínimo de clientes para analisar vendedor
    private const GEO_MOVEMENT_PCT       = 15.0;    // Variação (%) mínima para detectar crescimento/retração
    private const GEO_MIN_VALUE          = 1000.0;  // Valor mínimo de receita da região para considerar

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Executa todas as detecções para a competência informada.
     * Retorna contagem de oportunidades por tipo.
     *
     * @return array<string,int>
     */
    public function refreshForCompetencia(string $competencia): array
    {
        $prevComp = $this->previousCompetencia($competencia);

        $this->logger->info('OpportunityDetector: iniciando deteccao.', [
            'competencia'  => $competencia,
            'prev_comp'    => $prevComp,
        ]);

        $counts = [
            'dormant'       => $this->detectDormantClients($competencia),
            'recompra'      => $this->detectRecompraClients($competencia),
            'declining'     => $this->detectDecliningClients($competencia, $prevComp),
            'concentration' => $this->detectConcentrationRisk($competencia),
            'vendor'        => $this->detectVendorUnderutilization($competencia),
            'geo'           => $this->detectGeoMovements($competencia, $prevComp),
        ];

        $this->logger->info('OpportunityDetector: deteccao concluida.', $counts);

        return $counts;
    }

    // ── Detecções ────────────────────────────────────────────────────────────

    /**
     * Clientes que eram ativos/recorrentes e pararam de comprar.
     * Tipo: cliente_sumiu.
     * Score: proporcional a dias sem compra (90-365d → 10-100).
     */
    private function detectDormantClients(string $competencia): int
    {
        // Busca vendedor responsável pela última compra de cada cliente
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'cliente',
                CAST(cd.codigo_cliente_omie AS CHAR),
                cd.nome_cliente,
                'cliente_sumiu',
                LEAST(100, GREATEST(10,
                    ROUND((DATEDIFF(CURDATE(), cd.ultima_compra_em) - 90) * 100.0 / 275)
                )),
                cd.tipo_pessoa,
                cd.segmento_macro,
                lv.codigo_vendedor_omie,
                lv.nome_vendedor,
                :competencia,
                cd.faturado_12m,
                0,
                0,
                DATEDIFF(CURDATE(), cd.ultima_compra_em),
                NULL,
                CONCAT(
                    'Sem compras há ', DATEDIFF(CURDATE(), cd.ultima_compra_em), ' dias. ',
                    'Últimos 12M: R$ ', FORMAT(cd.faturado_12m, 2, 'pt_BR'), '. ',
                    'Classificação: ', cd.recorrencia_faixa, '.'
                ),
                JSON_OBJECT(
                    'ultima_compra_em', cd.ultima_compra_em,
                    'notas_12m', cd.notas_12m,
                    'recorrencia_faixa', cd.recorrencia_faixa,
                    'limiar_dormant_days', 90
                ),
                NOW()
            FROM biomie_clientes_dim cd
            LEFT JOIN (
                SELECT p.codigo_cliente_omie, p.codigo_vendedor_omie, p.nome_vendedor
                FROM biomie_pedidos_canonicos p
                INNER JOIN (
                    SELECT codigo_cliente_omie, MAX(data_emissao) AS max_data
                    FROM biomie_pedidos_canonicos
                    WHERE codigo_cliente_omie IS NOT NULL
                    GROUP BY codigo_cliente_omie
                ) latest ON latest.codigo_cliente_omie = p.codigo_cliente_omie
                         AND latest.max_data = p.data_emissao
            ) lv ON lv.codigo_cliente_omie = cd.codigo_cliente_omie
            WHERE cd.recorrencia_faixa IN ('dormente', 'inativo')
              AND cd.ultima_compra_em IS NOT NULL
              AND cd.ultima_compra_em >= DATE_SUB(CURDATE(), INTERVAL 18 MONTH)
              AND cd.faturado_12m >= :min_value
            ON DUPLICATE KEY UPDATE
                score             = VALUES(score),
                nome_entidade     = VALUES(nome_entidade),
                tipo_pessoa       = VALUES(tipo_pessoa),
                segmento_cnae     = VALUES(segmento_cnae),
                codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                nome_vendedor     = VALUES(nome_vendedor),
                valor_referencia  = VALUES(valor_referencia),
                dias_sem_compra   = VALUES(dias_sem_compra),
                insight_resumo    = VALUES(insight_resumo),
                contexto_json     = VALUES(contexto_json),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia' => $competencia,
            'min_value'   => self::DORMANT_MIN_VALUE,
        ]);
    }

    /**
     * Subconjunto dos dormentes com alto valor histórico — prime para ativação imediata.
     * Tipo: recompra_potencial.
     * Score: proporcional ao faturado_12m (até 100 para os maiores).
     */
    private function detectRecompraClients(string $competencia): int
    {
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'cliente',
                CAST(cd.codigo_cliente_omie AS CHAR),
                cd.nome_cliente,
                'recompra_potencial',
                LEAST(100, GREATEST(50,
                    ROUND(cd.faturado_12m / (
                        SELECT NULLIF(MAX(faturado_12m), 0) FROM biomie_clientes_dim
                        WHERE recorrencia_faixa IN ('dormente', 'inativo')
                    ) * 100)
                )),
                cd.tipo_pessoa,
                cd.segmento_macro,
                lv.codigo_vendedor_omie,
                lv.nome_vendedor,
                :competencia,
                cd.faturado_12m,
                0,
                0,
                DATEDIFF(CURDATE(), cd.ultima_compra_em),
                NULL,
                CONCAT(
                    'Alto potencial de reativação. ',
                    'Histórico: R$ ', FORMAT(cd.faturado_12m, 2, 'pt_BR'), ' em 12M. ',
                    'Parado há ', DATEDIFF(CURDATE(), cd.ultima_compra_em), ' dias.'
                ),
                JSON_OBJECT(
                    'ultima_compra_em', cd.ultima_compra_em,
                    'notas_12m', cd.notas_12m,
                    'faturado_12m', cd.faturado_12m,
                    'limiar_min_value', :min_value
                ),
                NOW()
            FROM biomie_clientes_dim cd
            LEFT JOIN (
                SELECT p.codigo_cliente_omie, p.codigo_vendedor_omie, p.nome_vendedor
                FROM biomie_pedidos_canonicos p
                INNER JOIN (
                    SELECT codigo_cliente_omie, MAX(data_emissao) AS max_data
                    FROM biomie_pedidos_canonicos
                    WHERE codigo_cliente_omie IS NOT NULL
                    GROUP BY codigo_cliente_omie
                ) latest ON latest.codigo_cliente_omie = p.codigo_cliente_omie
                         AND latest.max_data = p.data_emissao
            ) lv ON lv.codigo_cliente_omie = cd.codigo_cliente_omie
            WHERE cd.recorrencia_faixa IN ('dormente', 'inativo')
              AND cd.ultima_compra_em IS NOT NULL
              AND cd.ultima_compra_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              AND cd.faturado_12m >= :min_value
            ON DUPLICATE KEY UPDATE
                score            = VALUES(score),
                nome_entidade    = VALUES(nome_entidade),
                valor_referencia = VALUES(valor_referencia),
                dias_sem_compra  = VALUES(dias_sem_compra),
                insight_resumo   = VALUES(insight_resumo),
                contexto_json    = VALUES(contexto_json),
                refreshed_at     = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia' => $competencia,
            'min_value'   => self::RECOMPRA_MIN_VALUE,
        ]);
    }

    /**
     * Clientes com queda de compra vs período anterior.
     * Tipo: queda_de_compra.
     * Score: proporcional à magnitude da queda.
     */
    private function detectDecliningClients(string $competencia, string $prevComp): int
    {
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'cliente',
                CAST(curr.codigo_cliente_omie AS CHAR),
                curr.nome_cliente,
                'queda_de_compra',
                LEAST(100, GREATEST(20,
                    ROUND(ABS((curr.valor_faturado - prev.valor_faturado) / prev.valor_faturado * 150))
                )),
                curr.tipo_pessoa,
                curr.segmento_cnae,
                lv.codigo_vendedor_omie,
                lv.nome_vendedor,
                :competencia,
                curr.valor_faturado,
                prev.valor_faturado,
                ROUND((curr.valor_faturado - prev.valor_faturado) / prev.valor_faturado * 100, 2),
                NULL,
                NULL,
                CONCAT(
                    'Queda de ',
                    ROUND(ABS((curr.valor_faturado - prev.valor_faturado) / prev.valor_faturado * 100)), '% ',
                    'vs ', :prev_comp, '. ',
                    'R$ ', FORMAT(prev.valor_faturado, 2, 'pt_BR'), ' → R$ ', FORMAT(curr.valor_faturado, 2, 'pt_BR'), '.'
                ),
                JSON_OBJECT(
                    'competencia_atual', :competencia,
                    'competencia_anterior', :prev_comp,
                    'valor_atual', curr.valor_faturado,
                    'valor_anterior', prev.valor_faturado,
                    'limiar_queda_pct', :decline_pct
                ),
                NOW()
            FROM mart_cliente_periodo curr
            JOIN mart_cliente_periodo prev
                ON prev.codigo_cliente_omie = curr.codigo_cliente_omie
               AND prev.competencia = :prev_comp
            LEFT JOIN (
                SELECT p.codigo_cliente_omie, p.codigo_vendedor_omie, p.nome_vendedor
                FROM biomie_pedidos_canonicos p
                INNER JOIN (
                    SELECT codigo_cliente_omie, MAX(data_emissao) AS max_data
                    FROM biomie_pedidos_canonicos
                    WHERE codigo_cliente_omie IS NOT NULL
                    GROUP BY codigo_cliente_omie
                ) latest ON latest.codigo_cliente_omie = p.codigo_cliente_omie
                         AND latest.max_data = p.data_emissao
            ) lv ON lv.codigo_cliente_omie = curr.codigo_cliente_omie
            WHERE curr.competencia = :competencia
              AND prev.valor_faturado >= :min_value
              AND curr.valor_faturado < prev.valor_faturado * (1 - :decline_pct / 100)
            ON DUPLICATE KEY UPDATE
                score             = VALUES(score),
                nome_entidade     = VALUES(nome_entidade),
                valor_referencia  = VALUES(valor_referencia),
                valor_comparativo = VALUES(valor_comparativo),
                variacao_pct      = VALUES(variacao_pct),
                insight_resumo    = VALUES(insight_resumo),
                contexto_json     = VALUES(contexto_json),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia' => $competencia,
            'prev_comp'   => $prevComp,
            'min_value'   => self::DECLINE_MIN_VALUE,
            'decline_pct' => self::DECLINE_THRESHOLD_PCT,
        ]);
    }

    /**
     * Clientes que concentram mais de CONCENTRATION_PCT da receita do período.
     * Tipo: concentracao_cliente.
     * Score: proporcional ao share (25% → 50, 50% → 100).
     */
    private function detectConcentrationRisk(string $competencia): int
    {
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'cliente',
                CAST(cp.codigo_cliente_omie AS CHAR),
                cp.nome_cliente,
                'concentracao_cliente',
                LEAST(100, ROUND(cp.valor_faturado / totals.total * 200)),
                cp.tipo_pessoa,
                cp.segmento_cnae,
                NULL,
                NULL,
                :competencia,
                cp.valor_faturado,
                totals.total,
                ROUND(cp.valor_faturado / totals.total * 100, 2),
                NULL,
                NULL,
                CONCAT(
                    'Concentra ', ROUND(cp.valor_faturado / totals.total * 100, 1), '% da receita de ', :competencia, '. ',
                    'R$ ', FORMAT(cp.valor_faturado, 2, 'pt_BR'), ' de R$ ', FORMAT(totals.total, 2, 'pt_BR'), ' total. ',
                    'Risco de dependência.'
                ),
                JSON_OBJECT(
                    'share_pct', ROUND(cp.valor_faturado / totals.total * 100, 2),
                    'valor_cliente', cp.valor_faturado,
                    'receita_total_periodo', totals.total,
                    'limiar_concentracao_pct', :threshold_pct
                ),
                NOW()
            FROM mart_cliente_periodo cp
            CROSS JOIN (
                SELECT SUM(valor_faturado) AS total
                FROM mart_cliente_periodo
                WHERE competencia = :competencia
            ) totals
            WHERE cp.competencia = :competencia
              AND totals.total > 0
              AND cp.valor_faturado / totals.total >= :threshold_pct / 100
            ON DUPLICATE KEY UPDATE
                score             = VALUES(score),
                nome_entidade     = VALUES(nome_entidade),
                valor_referencia  = VALUES(valor_referencia),
                valor_comparativo = VALUES(valor_comparativo),
                variacao_pct      = VALUES(variacao_pct),
                insight_resumo    = VALUES(insight_resumo),
                contexto_json     = VALUES(contexto_json),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia'   => $competencia,
            'threshold_pct' => self::CONCENTRATION_PCT,
        ]);
    }

    /**
     * Vendedores com carteira de clientes majoritariamente inativa.
     * Tipo: vendedor_sem_ativacao.
     * Score: proporcional ao percentual de clientes inativos.
     */
    private function detectVendorUnderutilization(string $competencia): int
    {
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'vendedor',
                carteira.codigo_vendedor_omie,
                carteira.nome_vendedor,
                'vendedor_sem_ativacao',
                LEAST(100, ROUND(carteira.pct_inativos)),
                NULL,
                NULL,
                carteira.codigo_vendedor_omie,
                carteira.nome_vendedor,
                :competencia,
                carteira.valor_carteira_12m,
                carteira.valor_inativos_12m,
                0 - ROUND(carteira.pct_inativos, 2),
                NULL,
                carteira.total_clientes_inativos,
                CONCAT(
                    ROUND(carteira.pct_inativos), '% da carteira inativa. ',
                    carteira.total_clientes_inativos, ' de ', carteira.total_clientes, ' cliente(s) parado(s). ',
                    'Potencial dormido: R$ ', FORMAT(carteira.valor_inativos_12m, 2, 'pt_BR'), '.'
                ),
                JSON_OBJECT(
                    'total_clientes', carteira.total_clientes,
                    'clientes_ativos', carteira.total_clientes_ativos,
                    'clientes_inativos', carteira.total_clientes_inativos,
                    'pct_inativos', ROUND(carteira.pct_inativos, 2),
                    'valor_carteira_12m', carteira.valor_carteira_12m,
                    'limiar_pct_inativos', :inactive_pct
                ),
                NOW()
            FROM (
                SELECT
                    lv.codigo_vendedor_omie,
                    lv.nome_vendedor,
                    COUNT(*) AS total_clientes,
                    SUM(cd.recorrencia_faixa IN ('recorrente', 'novo')) AS total_clientes_ativos,
                    SUM(cd.recorrencia_faixa IN ('dormente', 'inativo')) AS total_clientes_inativos,
                    SUM(cd.faturado_12m) AS valor_carteira_12m,
                    SUM(
                        IF(cd.recorrencia_faixa IN ('dormente', 'inativo'), cd.faturado_12m, 0)
                    ) AS valor_inativos_12m,
                    SUM(cd.recorrencia_faixa IN ('dormente', 'inativo')) / COUNT(*) * 100 AS pct_inativos
                FROM (
                    SELECT p.codigo_cliente_omie, p.codigo_vendedor_omie, p.nome_vendedor
                    FROM biomie_pedidos_canonicos p
                    INNER JOIN (
                        SELECT codigo_cliente_omie, MAX(data_emissao) AS max_data
                        FROM biomie_pedidos_canonicos
                        WHERE codigo_cliente_omie IS NOT NULL
                        GROUP BY codigo_cliente_omie
                    ) latest ON latest.codigo_cliente_omie = p.codigo_cliente_omie
                             AND latest.max_data = p.data_emissao
                    WHERE p.codigo_vendedor_omie IS NOT NULL
                      AND p.codigo_vendedor_omie != ''
                ) lv
                JOIN biomie_clientes_dim cd ON cd.codigo_cliente_omie = lv.codigo_cliente_omie
                GROUP BY lv.codigo_vendedor_omie, lv.nome_vendedor
                HAVING total_clientes >= :min_clients
                   AND pct_inativos >= :inactive_pct
            ) carteira
            ON DUPLICATE KEY UPDATE
                score            = VALUES(score),
                nome_entidade    = VALUES(nome_entidade),
                valor_referencia = VALUES(valor_referencia),
                valor_comparativo = VALUES(valor_comparativo),
                variacao_pct     = VALUES(variacao_pct),
                clientes_count   = VALUES(clientes_count),
                insight_resumo   = VALUES(insight_resumo),
                contexto_json    = VALUES(contexto_json),
                refreshed_at     = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia'  => $competencia,
            'inactive_pct' => self::VENDOR_INACTIVE_PCT,
            'min_clients'  => self::VENDOR_MIN_CLIENTS,
        ]);
    }

    /**
     * Regiões com crescimento ou retração significativa vs período anterior.
     * Tipos: crescimento_geo, retracao_geo.
     * Depende de biomie_geo_vendas_diaria (alimentada pela camada geo).
     */
    private function detectGeoMovements(string $competencia, string $prevComp): int
    {
        $sql = <<<SQL
            INSERT INTO mart_oportunidades (
                tipo_entidade, codigo_entidade, nome_entidade,
                tipo_oportunidade, score,
                tipo_pessoa, segmento_cnae, uf,
                codigo_vendedor_omie, nome_vendedor,
                competencia_referencia,
                valor_referencia, valor_comparativo, variacao_pct,
                dias_sem_compra, clientes_count,
                insight_resumo, contexto_json, refreshed_at
            )
            SELECT
                'regiao',
                CONCAT(geo.uf, '|', geo.cidade_norm),
                CONCAT(geo.cidade_norm, ' / ', geo.uf),
                IF(geo.variacao_pct >= 0, 'crescimento_geo', 'retracao_geo'),
                LEAST(100, ROUND(ABS(geo.variacao_pct) * 2)),
                NULL,
                NULL,
                geo.uf,
                NULL,
                NULL,
                :competencia,
                geo.valor_atual,
                geo.valor_anterior,
                geo.variacao_pct,
                NULL,
                geo.clientes_atual,
                CONCAT(
                    geo.cidade_norm, ' ',
                    IF(geo.variacao_pct >= 0, 'cresceu', 'retraiu'), ' ',
                    ROUND(ABS(geo.variacao_pct)), '% vs ', :prev_comp, '. ',
                    'R$ ', FORMAT(geo.valor_atual, 2, 'pt_BR'),
                    ' (', geo.clientes_atual, ' cliente(s)).'
                ),
                JSON_OBJECT(
                    'cidade', geo.cidade_norm,
                    'uf', geo.uf,
                    'competencia_atual', :competencia,
                    'competencia_anterior', :prev_comp,
                    'valor_atual', geo.valor_atual,
                    'valor_anterior', geo.valor_anterior,
                    'clientes_atuais', geo.clientes_atual,
                    'limiar_variacao_pct', :movement_pct
                ),
                NOW()
            FROM (
                SELECT
                    curr.cidade_norm,
                    curr.uf,
                    SUM(curr.valor_total) AS valor_atual,
                    COALESCE(SUM(prev.valor_total), 0) AS valor_anterior,
                    SUM(curr.clientes_unicos) AS clientes_atual,
                    ROUND(
                        (SUM(curr.valor_total) - COALESCE(SUM(prev.valor_total), 0))
                        / NULLIF(SUM(prev.valor_total), 0) * 100,
                        2
                    ) AS variacao_pct
                FROM biomie_geo_vendas_diaria curr
                LEFT JOIN biomie_geo_vendas_diaria prev
                    ON prev.cidade_norm = curr.cidade_norm
                   AND prev.uf = curr.uf
                   AND prev.escopo_geo = curr.escopo_geo
                   AND DATE_FORMAT(prev.data_referencia, '%Y-%m') = :prev_comp
                WHERE curr.escopo_geo = 'cidade_ce'
                  AND DATE_FORMAT(curr.data_referencia, '%Y-%m') = :competencia
                  AND curr.cidade_norm != ''
                GROUP BY curr.cidade_norm, curr.uf
                HAVING valor_atual >= :min_value
                   AND valor_anterior > 0
                   AND ABS(variacao_pct) >= :movement_pct
            ) geo
            ON DUPLICATE KEY UPDATE
                score             = VALUES(score),
                nome_entidade     = VALUES(nome_entidade),
                tipo_oportunidade = VALUES(tipo_oportunidade),
                uf                = VALUES(uf),
                valor_referencia  = VALUES(valor_referencia),
                valor_comparativo = VALUES(valor_comparativo),
                variacao_pct      = VALUES(variacao_pct),
                clientes_count    = VALUES(clientes_count),
                insight_resumo    = VALUES(insight_resumo),
                contexto_json     = VALUES(contexto_json),
                refreshed_at      = NOW()
        SQL;

        return $this->exec($sql, [
            'competencia'  => $competencia,
            'prev_comp'    => $prevComp,
            'min_value'    => self::GEO_MIN_VALUE,
            'movement_pct' => self::GEO_MOVEMENT_PCT,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Retorna a competência YYYY-MM do mês anterior à informada.
     */
    private function previousCompetencia(string $competencia): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m', $competencia);
        if ($date === false) {
            $date = new \DateTimeImmutable('first day of this month');
        }

        return $date->modify('-1 month')->format('Y-m');
    }

    /**
     * Executa um INSERT … ON DUPLICATE KEY UPDATE e retorna linhas afetadas.
     * Erros são logados e o método retorna 0 (para não interromper outros detectores).
     */
    private function exec(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->rowCount();
        } catch (Throwable $e) {
            $this->logger->warning('OpportunityDetector: deteccao falhou.', [
                'message' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
