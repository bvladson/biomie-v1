<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class VendorDayMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        // Delete-before-insert: prevents stale rows from persisting across scope migrations or data corrections.
        $this->exec(
            'DELETE FROM mart_vendedor_dia
              WHERE company_id = :company_id AND dia BETWEEN :start AND :end',
            ['company_id' => $this->companyId(), 'start' => $start, 'end' => $end],
            'materialize_vendedor_dia',
            'mart_vendedor_dia',
            'delete_mart_vendedor_dia_periodo',
        );

        $pedidoScopeFilter      = $this->commercialScopeFragment('biomie_pedidos_canonicos', '');
        $pedidoVisibilityFilter = $this->visibilityFragment('biomie_pedidos_canonicos', '');
        // Coluna gerada (migration 20260712000000) permite range scan por índice;
        // fallback para a expressão original em bases ainda não migradas.
        $pedidoDateExpr = $this->columnExists('biomie_pedidos_canonicos', 'data_ref')
            ? 'data_ref'
            : 'COALESCE(data_faturamento, data_emissao)';
        $sqlComercial = <<<SQL
            INSERT INTO mart_vendedor_dia (
                company_id, business_channel, competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                company_id,
                business_channel,
                DATE_FORMAT({$pedidoDateExpr}, '%Y-%m'),
                {$pedidoDateExpr}                                          AS dia,
                codigo_vendedor_omie,
                MAX(nome_vendedor),
                SUM(status_comercial = 'faturado'),
                SUM(IF(status_comercial = 'faturado', valor_total, 0)),
                SUM(IF(status_comercial = 'faturado', valor_mercadorias, 0)),
                SUM(IF(status_comercial = 'faturado', valor_descontos, 0)),
                SUM(status_comercial = 'autorizado'),
                SUM(IF(status_comercial = 'autorizado', valor_total, 0)),
                SUM(IF(status_comercial = 'autorizado', valor_mercadorias, 0)),
                SUM(status_comercial = 'cancelado'),
                SUM(IF(status_comercial = 'cancelado', valor_total, 0)),
                0, 0, 0,
                0, 0, 0, 0,
                NOW()
            FROM biomie_pedidos_canonicos
            WHERE company_id = :company_id
              AND {$pedidoDateExpr} BETWEEN :start AND :end{$pedidoScopeFilter}{$pedidoVisibilityFilter}
            GROUP BY company_id, business_channel,
                     DATE_FORMAT({$pedidoDateExpr}, '%Y-%m'),
                     {$pedidoDateExpr},
                     codigo_vendedor_omie
            ON DUPLICATE KEY UPDATE
                pedidos_faturados       = VALUES(pedidos_faturados),
                valor_faturado          = VALUES(valor_faturado),
                mercadorias_faturado    = VALUES(mercadorias_faturado),
                descontos_faturado      = VALUES(descontos_faturado),
                pedidos_autorizados     = VALUES(pedidos_autorizados),
                valor_autorizado        = VALUES(valor_autorizado),
                mercadorias_autorizadas = VALUES(mercadorias_autorizadas),
                pedidos_cancelados      = VALUES(pedidos_cancelados),
                valor_cancelado         = VALUES(valor_cancelado),
                refreshed_at            = NOW()
        SQL;

        $params = ['company_id' => $this->companyId(), 'start' => $start, 'end' => $end];
        $rows = $this->exec($sqlComercial, $params, 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_comercial');

        $nfScopeFilter      = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', '');
        $nfVisibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', '');
        // Fase O: use fiscal basis formula when columns exist; fall back to valor_total pre-migration.
        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('')
            : 'valor_total';
        $nfDateExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'data_ref')
            ? 'data_ref'
            : 'COALESCE(data_competencia, data_emissao)';
        $sqlFiscal = <<<SQL
            INSERT INTO mart_vendedor_dia (
                company_id, business_channel, competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                company_id,
                business_channel,
                DATE_FORMAT({$nfDateExpr}, '%Y-%m'),
                {$nfDateExpr}                                              AS dia,
                codigo_vendedor_omie,
                MAX(nome_vendedor),
                0, 0, 0, 0,
                0, 0, 0,
                0, 0,
                SUM(status_fiscal = 'autorizada'),
                SUM(IF(status_fiscal = 'autorizada', {$nfBasisExpr}, 0)),
                SUM(IF(status_fiscal = 'autorizada', valor_mercadorias, 0)),
                0, 0, 0, 0,
                NOW()
            FROM biomie_notas_fiscais_canonicas
            WHERE company_id = :company_id
              AND {$nfDateExpr} BETWEEN :start AND :end{$nfScopeFilter}{$nfVisibilityFilter}
            GROUP BY company_id, business_channel,
                     DATE_FORMAT({$nfDateExpr}, '%Y-%m'),
                     {$nfDateExpr},
                     codigo_vendedor_omie
            ON DUPLICATE KEY UPDATE
                nfes_emitidas   = VALUES(nfes_emitidas),
                valor_nfe       = VALUES(valor_nfe),
                mercadorias_nfe = VALUES(mercadorias_nfe),
                refreshed_at    = NOW()
        SQL;

        $this->exec($sqlFiscal, $params, 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_fiscal');

        // Nota: o filtro de visibilidade agora entra inline (como nos blocos
        // comercial/fiscal) — o str_replace anterior procurava "WHERE COALESCE"
        // e nunca casava com o SQL real ("AND COALESCE"), descartando o filtro.
        $financialVisibilityFilter = $this->visibilityFragment('biomie_titulos_financeiros_canonicos', '');
        $titDateExpr = $this->columnExists('biomie_titulos_financeiros_canonicos', 'data_ref')
            ? 'data_ref'
            : 'COALESCE(data_competencia, data_vencimento)';
        $sqlFinanceiro = <<<SQL
            INSERT INTO mart_vendedor_dia (
                company_id, business_channel, competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                company_id,
                business_channel,
                DATE_FORMAT({$titDateExpr}, '%Y-%m'),
                {$titDateExpr}                                             AS dia,
                codigo_vendedor_omie,
                MAX(nome_vendedor),
                0, 0, 0, 0,
                0, 0, 0,
                0, 0,
                0, 0, 0,
                SUM(status_titulo IN ('liquidado','recebido','pago')),
                SUM(IF(status_titulo IN ('liquidado','recebido','pago'), valor_liquido, 0)),
                SUM(valor_aberto > 0),
                SUM(IF(valor_aberto > 0, valor_aberto, 0)),
                NOW()
            FROM biomie_titulos_financeiros_canonicos
            WHERE company_id = :company_id
              AND {$titDateExpr} BETWEEN :start AND :end{$financialVisibilityFilter}
            GROUP BY company_id, business_channel,
                     DATE_FORMAT({$titDateExpr}, '%Y-%m'),
                     {$titDateExpr},
                     codigo_vendedor_omie
            ON DUPLICATE KEY UPDATE
                titulos_recebidos = VALUES(titulos_recebidos),
                valor_recebido    = VALUES(valor_recebido),
                titulos_abertos   = VALUES(titulos_abertos),
                valor_aberto      = VALUES(valor_aberto),
                refreshed_at      = NOW()
        SQL;

        $this->exec($sqlFinanceiro, $params, 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_financeiro');

        return $rows;
    }
}
