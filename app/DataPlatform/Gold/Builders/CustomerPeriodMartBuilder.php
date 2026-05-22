<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use BIomie\DataGovernance\KpiDateContract;

final class CustomerPeriodMartBuilder extends AbstractMartBuilder
{
    /** @return array<string,mixed> */
    public function refreshReplace(string $competencia, string $start, string $end): array
    {
        if (!$this->tableExists('mart_cliente_periodo')) {
            $this->logger->warning('CustomerPeriodMartBuilder: mart_cliente_periodo ausente; materializacao ignorada.');
            return [
                'deleted_rows' => 0,
                'inserted_rows' => 0,
                'inserted_commercial_rows' => 0,
                'inserted_fiscal_rows' => null,
                'inserted_financial_rows' => null,
                'final_rows' => 0,
                'receita_final' => 0.0,
                'valor_nfe_final' => 0.0,
            ];
        }

        $deleted = $this->exec(
            'DELETE FROM mart_cliente_periodo WHERE competencia = :competencia',
            ['competencia' => $competencia],
            'materialize_cliente_periodo',
            'mart_cliente_periodo',
            'delete_mart_cliente_periodo_competencia'
        );

        $commercialRows = $this->refresh($competencia, $start, $end);
        $final = $this->periodSummary($competencia);

        return [
            'deleted_rows' => $deleted,
            'inserted_rows' => (int) ($final['final_rows'] ?? 0),
            'inserted_commercial_rows' => $commercialRows,
            'inserted_fiscal_rows' => null,
            'inserted_financial_rows' => null,
            'final_rows' => (int) ($final['final_rows'] ?? 0),
            'receita_final' => (float) ($final['receita_final'] ?? 0),
            'valor_nfe_final' => (float) ($final['valor_nfe_final'] ?? 0),
        ];
    }

    public function refresh(string $competencia, string $start, string $end): int
    {
        $commercialWhere    = KpiDateContract::wherePeriod('commercial', 'p');
        $pedidoScopeFilter      = $this->commercialScopeFragment('biomie_pedidos_canonicos', 'p');
        $pedidoVisibilityFilter = $this->visibilityFragment('biomie_pedidos_canonicos', 'p');
        $sqlComercial = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                p.codigo_cliente_omie,
                p.nome_cliente,
                p.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                SUM(p.status_comercial = 'faturado'),
                SUM(IF(p.status_comercial = 'faturado', p.valor_total, 0)),
                SUM(IF(p.status_comercial = 'faturado', p.valor_mercadorias, 0)),
                0, 0,
                0, 0, 0, 0,
                NOW()
            FROM biomie_pedidos_canonicos p
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = p.codigo_cliente_omie
            WHERE p.codigo_cliente_omie IS NOT NULL
              AND {$commercialWhere}{$pedidoScopeFilter}{$pedidoVisibilityFilter}
            GROUP BY p.codigo_cliente_omie, p.nome_cliente, p.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa          = VALUES(tipo_pessoa),
                segmento_cnae        = VALUES(segmento_cnae),
                pedidos_faturados    = VALUES(pedidos_faturados),
                valor_faturado       = VALUES(valor_faturado),
                mercadorias_faturado = VALUES(mercadorias_faturado),
                refreshed_at         = NOW()
        SQL;

        $rows = $this->exec($sqlComercial, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_comercial');

        $fiscalWhere    = KpiDateContract::wherePeriod('fiscal', 'n');
        $nfScopeFilter      = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', 'n');
        $nfVisibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', 'n');
        // Fase O: use fiscal basis formula when columns exist; fall back to valor_total pre-migration.
        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('n')
            : 'n.valor_total';
        $sqlFiscal = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                n.codigo_cliente_omie,
                n.nome_cliente,
                n.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                0, 0, 0,
                SUM(n.status_fiscal = 'autorizada'),
                SUM(IF(n.status_fiscal = 'autorizada', {$nfBasisExpr}, 0)),
                0, 0, 0, 0,
                NOW()
            FROM biomie_notas_fiscais_canonicas n
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = n.codigo_cliente_omie
            WHERE n.codigo_cliente_omie IS NOT NULL
              AND {$fiscalWhere}{$nfScopeFilter}{$nfVisibilityFilter}
            GROUP BY n.codigo_cliente_omie, n.nome_cliente, n.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa   = IF(tipo_pessoa = 'desconhecido', VALUES(tipo_pessoa), tipo_pessoa),
                segmento_cnae = IF(segmento_cnae = 'Não classificado', VALUES(segmento_cnae), segmento_cnae),
                nfes_emitidas = VALUES(nfes_emitidas),
                valor_nfe     = VALUES(valor_nfe),
                refreshed_at  = NOW()
        SQL;

        $this->exec($sqlFiscal, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_fiscal');

        $financialWhere             = KpiDateContract::wherePeriod('financial', 't');
        $financialVisibilityFilter  = $this->visibilityFragment('biomie_titulos_financeiros_canonicos', 't');
        $diasAtraso                 = KpiDateContract::diasAtraso('t', 'CURDATE()');
        $sqlFinanceiro = <<<SQL
            INSERT INTO mart_cliente_periodo (
                competencia, codigo_cliente_omie, nome_cliente, documento_cliente,
                tipo_pessoa, segmento_cnae,
                pedidos_faturados, valor_faturado, mercadorias_faturado,
                nfes_emitidas, valor_nfe,
                titulos_total, valor_aberto, valor_recebido, dias_atraso_max,
                refreshed_at
            )
            SELECT
                :competencia,
                t.codigo_cliente_omie,
                t.nome_cliente,
                t.documento_cliente,
                COALESCE(MAX(d.tipo_pessoa), 'desconhecido'),
                COALESCE(MAX(d.segmento_macro), 'Não classificado'),
                0, 0, 0,
                0, 0,
                COUNT(*),
                SUM(t.valor_aberto),
                SUM(t.valor_liquido),
                MAX({$diasAtraso}),
                NOW()
            FROM biomie_titulos_financeiros_canonicos t
            LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = t.codigo_cliente_omie
            WHERE t.codigo_cliente_omie IS NOT NULL
              AND {$financialWhere}{$financialVisibilityFilter}
            GROUP BY t.codigo_cliente_omie, t.nome_cliente, t.documento_cliente
            ON DUPLICATE KEY UPDATE
                tipo_pessoa     = IF(tipo_pessoa = 'desconhecido', VALUES(tipo_pessoa), tipo_pessoa),
                segmento_cnae   = IF(segmento_cnae = 'Não classificado', VALUES(segmento_cnae), segmento_cnae),
                titulos_total   = VALUES(titulos_total),
                valor_aberto    = VALUES(valor_aberto),
                valor_recebido  = VALUES(valor_recebido),
                dias_atraso_max = VALUES(dias_atraso_max),
                refreshed_at    = NOW()
        SQL;

        $this->exec($sqlFinanceiro, ['competencia' => $competencia, 'start' => $start, 'end' => $end], 'materialize_cliente_periodo', 'mart_cliente_periodo', 'insert_mart_cliente_periodo_financeiro');

        return $rows;
    }

    /** @return array<string,mixed> */
    private function periodSummary(string $competencia): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS final_rows,
                        COALESCE(SUM(valor_faturado), 0) AS receita_final,
                        COALESCE(SUM(valor_nfe), 0) AS valor_nfe_final
                 FROM mart_cliente_periodo
                 WHERE competencia = :competencia'
            );
            $stmt->execute(['competencia' => $competencia]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (\Throwable $exception) {
            $this->logger->warning('CustomerPeriodMartBuilder: falha ao sumarizar competencia.', [
                'competencia' => $competencia,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}
