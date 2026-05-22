<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class VendorDayMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        // Delete-before-insert: prevents stale rows from persisting across scope migrations or data corrections.
        $this->exec(
            'DELETE FROM mart_vendedor_dia WHERE dia BETWEEN :start AND :end',
            ['start' => $start, 'end' => $end],
            'materialize_vendedor_dia',
            'mart_vendedor_dia',
            'delete_mart_vendedor_dia_periodo',
        );

        $pedidoScopeFilter      = $this->commercialScopeFragment('biomie_pedidos_canonicos', '');
        $pedidoVisibilityFilter = $this->visibilityFragment('biomie_pedidos_canonicos', '');
        $sqlComercial = <<<SQL
            INSERT INTO mart_vendedor_dia (
                competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(data_faturamento, data_emissao), '%Y-%m'),
                DATE(COALESCE(data_faturamento, data_emissao))             AS dia,
                codigo_vendedor_omie,
                nome_vendedor,
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
            WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end{$pedidoScopeFilter}{$pedidoVisibilityFilter}
            GROUP BY DATE_FORMAT(COALESCE(data_faturamento, data_emissao), '%Y-%m'),
                     DATE(COALESCE(data_faturamento, data_emissao)),
                     codigo_vendedor_omie, nome_vendedor
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

        $rows = $this->exec($sqlComercial, ['start' => $start, 'end' => $end], 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_comercial');

        $nfScopeFilter      = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', '');
        $nfVisibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', '');
        // Fase O: use fiscal basis formula when columns exist; fall back to valor_total pre-migration.
        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('')
            : 'valor_total';
        $sqlFiscal = <<<SQL
            INSERT INTO mart_vendedor_dia (
                competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m'),
                DATE(COALESCE(data_competencia, data_emissao))             AS dia,
                codigo_vendedor_omie,
                nome_vendedor,
                0, 0, 0, 0,
                0, 0, 0,
                0, 0,
                SUM(status_fiscal = 'autorizada'),
                SUM(IF(status_fiscal = 'autorizada', {$nfBasisExpr}, 0)),
                SUM(IF(status_fiscal = 'autorizada', valor_mercadorias, 0)),
                0, 0, 0, 0,
                NOW()
            FROM biomie_notas_fiscais_canonicas
            WHERE COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end{$nfScopeFilter}{$nfVisibilityFilter}
            GROUP BY DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m'),
                     DATE(COALESCE(data_competencia, data_emissao)),
                     codigo_vendedor_omie, nome_vendedor
            ON DUPLICATE KEY UPDATE
                nfes_emitidas   = VALUES(nfes_emitidas),
                valor_nfe       = VALUES(valor_nfe),
                mercadorias_nfe = VALUES(mercadorias_nfe),
                refreshed_at    = NOW()
        SQL;

        $this->exec($sqlFiscal, ['start' => $start, 'end' => $end], 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_fiscal');

        $sqlFinanceiro = <<<'SQL'
            INSERT INTO mart_vendedor_dia (
                competencia, dia,
                codigo_vendedor_omie, nome_vendedor,
                pedidos_faturados, valor_faturado, mercadorias_faturado, descontos_faturado,
                pedidos_autorizados, valor_autorizado, mercadorias_autorizadas,
                pedidos_cancelados, valor_cancelado,
                nfes_emitidas, valor_nfe, mercadorias_nfe,
                titulos_recebidos, valor_recebido, titulos_abertos, valor_aberto,
                refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(data_competencia, data_vencimento), '%Y-%m'),
                DATE(COALESCE(data_competencia, data_vencimento))          AS dia,
                codigo_vendedor_omie,
                nome_vendedor,
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
            WHERE COALESCE(data_competencia, data_vencimento) BETWEEN :start AND :end
            GROUP BY DATE_FORMAT(COALESCE(data_competencia, data_vencimento), '%Y-%m'),
                     DATE(COALESCE(data_competencia, data_vencimento)),
                     codigo_vendedor_omie, nome_vendedor
            ON DUPLICATE KEY UPDATE
                titulos_recebidos = VALUES(titulos_recebidos),
                valor_recebido    = VALUES(valor_recebido),
                titulos_abertos   = VALUES(titulos_abertos),
                valor_aberto      = VALUES(valor_aberto),
                refreshed_at      = NOW()
        SQL;

        $financialVisibilityFilter = $this->visibilityFragment('biomie_titulos_financeiros_canonicos', '');
        if ($financialVisibilityFilter !== '') {
            $sqlFinanceiro = str_replace(
                'WHERE COALESCE(data_competencia, data_vencimento) BETWEEN :start AND :end',
                "WHERE COALESCE(data_competencia, data_vencimento) BETWEEN :start AND :end{$financialVisibilityFilter}",
                $sqlFinanceiro
            );
        }
        $this->exec($sqlFinanceiro, ['start' => $start, 'end' => $end], 'materialize_vendedor_dia', 'mart_vendedor_dia', 'insert_mart_vendedor_dia_financeiro');

        return $rows;
    }
}
